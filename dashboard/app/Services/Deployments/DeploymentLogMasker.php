<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Models\Deployment;
use App\Services\Crypto\ProjectKeyring;
use App\Support\Variables\SecretTaint;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * Defence in depth for shipped logs (docs/09 R16, review M2): before a log
 * line of a deployment (its tasks, the deployment itself, its workloads) is
 * stored, the values of the deployment's secrets, and of config variables
 * that embed one, are replaced by `***`, in the same forms the daemon masks
 * (raw, base64, URL-escaped) plus the password of URL values (DSNs). Viewers
 * read deployment logs without the reveal permission.
 *
 * Plaintext lives in memory for one log batch only (this object is bound
 * per request/job and never serialized).
 */
final class DeploymentLogMasker
{
    public const string MASK = '***';

    /** Shorter values (e.g. "1", "true") would mangle every log line. */
    private const int MIN_LENGTH = 4;

    /** @var array<int, array<string, string>> deployment id => replacement pairs */
    private array $pairs = [];

    public function __construct(
        private readonly ProjectKeyring $keyring,
    ) {}

    public function mask(int $deploymentId, string $text): string
    {
        $pairs = $this->pairs[$deploymentId] ??= $this->load($deploymentId);

        return $pairs === [] ? $text : strtr($text, $pairs);
    }

    /**
     * @return array<string, string>
     */
    private function load(int $deploymentId): array
    {
        $deployment = Deployment::query()->with(['environment.project', 'serviceInstance.service', 'revision'])->find($deploymentId);

        if (! $deployment instanceof Deployment || $deployment->revision === null || $deployment->env_snapshot === null) {
            return [];
        }

        $service = $deployment->revision->spec->service($deployment->serviceInstance->service->public_id);

        if ($service === null) {
            return [];
        }

        try {
            $env = $this->keyring->decryptSnapshot($deployment->environment->project, $deployment->public_id, $deployment->env_snapshot);
        } catch (Throwable $e) {
            Log::warning('Deployment logs stored without dashboard-side masking: the env snapshot could not be decrypted.', ['deployment' => $deployment->public_id, 'error' => $e::class]);

            return [];
        }

        $values = [];

        foreach (SecretTaint::fromSpec($deployment->revision->spec)->taintedKeys($service->slug) as $key) {
            if (isset($env[$key])) {
                $values[] = $env[$key];
            }
        }

        return self::pairs($values);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public static function pairs(#[SensitiveParameter] array $values): array
    {
        $pairs = [];
        $add = static function (string $form) use (&$pairs): void {
            if (strlen($form) >= self::MIN_LENGTH) {
                $pairs[$form] = self::MASK;
            }
        };

        foreach ($values as $value) {
            if (strlen($value) < self::MIN_LENGTH) {
                continue;
            }

            $add($value);
            $base64 = base64_encode($value);
            $add($base64);
            $add(rtrim($base64, '='));
            $add(strtr($base64, '+/', '-_'));
            $add(rtrim(strtr($base64, '+/', '-_'), '='));
            $add(urlencode($value));
            $add(rawurlencode($value));

            $password = parse_url($value, PHP_URL_PASS);

            if (is_string($password)) {
                $add($password);
                $add(rawurldecode($password));
            }

            foreach (explode("\n", $value) as $line) {
                if (strlen(trim($line)) >= 16) {
                    $add(trim($line));
                }
            }
        }

        return $pairs;
    }
}
