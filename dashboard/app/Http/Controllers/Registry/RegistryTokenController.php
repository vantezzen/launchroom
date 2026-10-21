<?php

declare(strict_types=1);

namespace App\Http\Controllers\Registry;

use App\Actions\Registry\IssueRealmRegistryToken;
use App\Data\Registry\IssuedRegistryToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /registry/token: the Docker token-auth realm Zot advertises (docs/05 §6).
 * Public by necessity (registries' clients call it directly); the Basic
 * password must be a registry token this dashboard issued, and the answer is
 * never broader nor longer-lived than that token. Fails with a generic 401.
 */
final class RegistryTokenController extends Controller
{
    public function __invoke(Request $request, IssueRealmRegistryToken $issueRealmRegistryToken): JsonResponse
    {
        $service = $request->query('service');
        $password = $request->getPassword();

        $issued = null;

        if ((! is_string($service) || $service === config()->string('launchroom.registry.service')) && is_string($password) && $password !== '') {
            // The raw query: Request::getQueryString() normalizes away repeated parameters.
            $query = $request->server->get('QUERY_STRING');
            $issued = $issueRealmRegistryToken->handle($password, $this->scopeParameters(is_string($query) ? $query : ''));
        }

        if (! $issued instanceof IssuedRegistryToken) {
            return new JsonResponse(['errors' => [['code' => 'UNAUTHORIZED', 'message' => 'authentication required']]], 401, [
                'WWW-Authenticate' => 'Basic realm="launchroom registry"',
                'Cache-Control' => 'no-store',
            ]);
        }

        return new JsonResponse([
            'token' => $issued->token,
            'access_token' => $issued->token,
            'expires_in' => max(1, (int) $issued->issuedAt->diffInSeconds($issued->expiresAt)),
            'issued_at' => $issued->issuedAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * All `scope` parameters: Docker clients repeat the parameter, which PHP's
     * query parsing would collapse to the last one.
     *
     * @return list<string>
     */
    private function scopeParameters(string $query): array
    {
        $scopes = [];

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (urldecode($name) === 'scope' && count($scopes) < 32) {
                $scopes[] = urldecode($value);
            }
        }

        return $scopes;
    }
}
