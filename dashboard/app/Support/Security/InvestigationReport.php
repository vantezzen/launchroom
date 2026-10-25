<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Data\Protocol\V1\ContainerInspectionResult;
use App\Data\Security\InvestigationCheckData;
use App\Data\Security\InvestigationLineData;
use App\Data\Security\InvestigationLinkData;
use App\Data\Security\InvestigationReportData;
use App\Data\Security\InvestigationStepData;
use App\Enums\Security\WafCategory;
use Carbon\CarbonImmutable;

/**
 * Turns an investigation's signals into its report (docs/22 §7.5): what
 * was checked, plain sentences with a level (concerning, notable, clear)
 * and next steps mapped to existing launchroom actions. Process commands
 * never enter the report, only program paths. Pure.
 */
final class InvestigationReport
{
    private const string CONCERNING = 'concerning';

    private const string NOTABLE = 'notable';

    private const string CLEAR = 'clear';

    public static function build(InvestigationInputs $in): InvestigationReportData
    {
        $checked = [];
        $found = [];
        $concerning = false;
        $earliest = null;
        $partial = false;

        // Containers, one per replica.
        foreach ($in->replicas as $replica) {
            $label = "Container of {$in->service} on {$replica['server']}";

            if ($replica['result'] === null) {
                $partial = true;
                $checked[] = new InvestigationCheckData($label, 'now', 'partial', self::couldnt($replica));

                continue;
            }

            // Without parent PIDs nothing is judged by what started a process.
            $checked[] = new InvestigationCheckData($label, 'now', 'checked', $replica['result']->parentsUnknown
                ? "Process parents weren't available on {$replica['server']}, so processes couldn't be traced to what started them."
                : null);

            foreach (self::container($replica['result'], $replica['server'], $in, count($in->replicas) > 1) as [$level, $text, $at]) {
                $found[] = new InvestigationLineData($level, $text);

                if ($level === self::CONCERNING) {
                    $concerning = true;
                    $earliest = $at !== null && ($earliest === null || $at->lessThan($earliest)) ? $at : ($earliest ?? $at);
                }
            }
        }

        if ($in->replicas === []) {
            $partial = true;
            $checked[] = new InvestigationCheckData("Containers of {$in->service}", 'now', 'partial', 'No replica was running.');
        }

        // Consoles and SSH sessions explain shells.
        $checked[] = new InvestigationCheckData('Consoles and SSH sessions into the service', 'last 7 days');

        array_push($found, ...self::sessions($in));

        // Web firewall.
        [$wafLines, $wafAt] = self::firewall($in);
        $checked[] = new InvestigationCheckData('Web firewall', 'last 7 days', $in->wafMode === 'off' ? 'skipped' : 'checked', $in->wafMode === 'off' ? 'The firewall is off for this service.' : null);
        array_push($found, ...$wafLines);

        // Requests.
        $checked[] = new InvestigationCheckData('Requests and server errors', 'last 7 days');
        $found[] = self::errors($in);

        if ($in->liveSince instanceof CarbonImmutable && $in->finding !== null) {
            $days = (int) $in->liveSince->diffInDays($in->now);
            $found[] = new InvestigationLineData(self::CLEAR, "{$in->finding['name']} {$in->finding['version']} has been live since ".$in->liveSince->format('j M').($days >= 1 ? " ({$days} ".($days === 1 ? 'day' : 'days').').' : '.'));
        }

        $checked[] = new InvestigationCheckData('What the service can reach', 'now');

        if ($earliest === null && $concerning) {
            $earliest = $wafAt;
        }

        $steps = self::steps($in, $concerning, $earliest);
        $summary = self::summary($in, $found, $partial);

        return new InvestigationReportData($checked, $found, $steps, $partial, $summary);
    }

    /**
     * @param  array{server: string, state: string, message: string|null}  $replica
     */
    private static function couldnt(array $replica): string
    {
        return match ($replica['state']) {
            'offline' => "Couldn’t check: {$replica['server']} is offline.",
            'unsupported' => "Couldn’t check: update {$replica['server']} to check its containers.",
            'pending', 'running' => "Couldn’t check: {$replica['server']} didn’t answer in time.",
            default => 'Couldn’t check: '.($replica['message'] ?? 'the server couldn’t read the container.'),
        };
    }

    /**
     * Sentences about one container.
     *
     * @return list<array{string, string, CarbonImmutable|null}>
     */
    private static function container(ContainerInspectionResult $r, string $server, InvestigationInputs $in, bool $several): array
    {
        $where = $several ? " on {$server}" : '';
        $out = [];
        $added = [];

        if (! $r->imageMatches) {
            $out[] = [self::CONCERNING, "The running container{$where} uses an image launchroom didn’t deploy.", null];
        }

        $changesConcerning = 0;
        $appCode = [];
        $sensitive = [];

        foreach ($r->changes as $change) {
            $flags = $change['flags'] ?? [];
            $at = isset($change['modifiedAt']) ? CarbonImmutable::parse($change['modifiedAt']) : null;

            if ($change['kind'] === 'added') {
                $added[$change['path']] = true;
            }

            if (in_array('executable', $flags, true) && $change['kind'] !== 'deleted' && ($change['type'] ?? null) === 'file') {
                $dir = in_array('temp_dir', $flags, true) ? ' in '.self::topDir($change['path']) : (in_array('system_path', $flags, true) ? ' in a system directory' : '');
                $size = isset($change['size']) ? self::size($change['size']).', ' : '';
                $when = $at instanceof CarbonImmutable ? ($change['kind'] === 'added' ? 'added ' : 'changed ').self::ago($at, $in->now) : $change['kind'];
                $out[] = [self::CONCERNING, ($change['kind'] === 'added' ? 'A new executable file appeared' : 'An executable file changed')."{$dir}{$where}: {$change['path']} ({$size}{$when}).", $at];
                $changesConcerning++;

                continue;
            }

            if (in_array('system_path', $flags, true) && $change['kind'] !== 'deleted') {
                $out[] = [self::CONCERNING, "A file in a system directory changed{$where}: {$change['path']}.", $at];
                $changesConcerning++;

                continue;
            }

            if (in_array('sensitive_file', $flags, true)) {
                $sensitive[] = $change['path'];

                continue;
            }

            if (in_array('app_code', $flags, true) && $change['kind'] !== 'deleted' && ($change['type'] ?? 'file') === 'file') {
                $appCode[] = $change['path'];
            }
        }

        foreach (array_slice($sensitive, 0, 3) as $path) {
            $out[] = [self::NOTABLE, "{$path} changed after the container started{$where}.", null];
        }

        if ($appCode !== []) {
            $more = count($appCode) > 3 ? ' and '.(count($appCode) - 3).' more' : '';
            $out[] = [self::NOTABLE, "Files of the app changed after start{$where}: ".implode(', ', array_slice($appCode, 0, 3))."{$more}.", null];
        }

        if ($changesConcerning === 0 && $sensitive === [] && $appCode === []) {
            $out[] = [self::CLEAR, "No new or changed files outside caches{$where}.", null];
        }

        $unexpected = 0;
        $pids = [];

        foreach ($r->processes as $process) {
            $pids[$process['pid']] = self::program($process['command']);
        }

        foreach ($r->processes as $process) {
            $flags = $process['flags'] ?? [];
            $program = self::program($process['command']);
            $parent = $r->parentsUnknown ? null : ($pids[$process['ppid']] ?? null);

            if (in_array('miner', $flags, true)) {
                $out[] = [self::CONCERNING, "A process matches a known crypto-miner name{$where}: ".basename($program).'.', null];
                $unexpected++;
            } elseif (in_array('temp_executable', $flags, true)) {
                $from = str_starts_with($program, '/') ? self::topDir($program) : 'a temporary directory';
                $out[] = [self::CONCERNING, "A process runs from {$from}{$where}: {$program}.", null];
                $unexpected++;
            } elseif (in_array('added_binary', $flags, true)) {
                $out[] = [self::CONCERNING, "A process runs a file added after the container started{$where}: {$program}.", null];
                $unexpected++;
            } elseif (in_array('shell', $flags, true) && ! self::explained($in)) {
                $out[] = [self::NOTABLE, 'A shell runs that no launchroom console explains'.$where.': '.basename($program)." (PID {$process['pid']})".($parent !== null ? ' started by '.basename($parent) : '').'.', null];
                $unexpected++;
            } elseif (in_array('network_tool', $flags, true)) {
                $out[] = [self::NOTABLE, "A network tool runs{$where}: ".basename($program)." (PID {$process['pid']}).", null];
                $unexpected++;
            } elseif (in_array('inline_code', $flags, true) && ! self::explained($in)) {
                $out[] = [self::NOTABLE, "A process runs code given on its command line{$where}: ".basename($program)." (PID {$process['pid']}).", null];
                $unexpected++;
            }
        }

        if ($unexpected === 0) {
            $out[] = [self::CLEAR, "No unexpected processes{$where}.", null];
        }

        return $out;
    }

    /**
     * @return array{list<InvestigationLineData>, CarbonImmutable|null}
     */
    private static function firewall(InvestigationInputs $in): array
    {
        if ($in->wafMode === 'off') {
            return [[], null];
        }

        $kind = $in->categories === [] ? 'attack rules' : self::categoryWords($in->categories).' rules';
        $total = $in->wafTotals['blocked'] + $in->wafTotals['detected'];

        if ($total === 0) {
            return [[new InvestigationLineData(self::CLEAR, $in->categories === [] ? 'No requests matched the firewall rules in the last 7 days.' : 'No requests matched the firewall rules for this kind of attack.')], null];
        }

        $outcome = match (true) {
            $in->wafTotals['detected'] === 0 => $total === 1 ? 'it was blocked' : ($total === 2 ? 'both were blocked' : 'all were blocked'),
            $in->wafTotals['blocked'] === 0 => $total === 1 ? 'it would have been blocked, the firewall is in Log only' : 'none were blocked, the firewall is in Log only',
            default => number_format($in->wafTotals['blocked']).' were blocked',
        };

        $lines = [new InvestigationLineData(self::NOTABLE, number_format($total).' '.($total === 1 ? 'request' : 'requests')." matched {$kind} in the last 7 days; {$outcome}.")];

        // The same network (docs/22 §7.4): never "the same client".
        $byNetwork = [];
        $first = null;

        foreach ($in->events as $event) {
            if ($in->categories !== [] && array_intersect($event['categories'], array_map(static fn (WafCategory $c): string => $c->value, $in->categories)) === []) {
                continue;
            }

            $byNetwork[$event['network']] = ($byNetwork[$event['network']] ?? 0) + 1;
            $first = $first === null || $event['at']->lessThan($first) ? $event['at'] : $first;
        }

        arsort($byNetwork);
        $network = array_key_first($byNetwork);

        if ($network !== null && $byNetwork[$network] >= 2) {
            $lines[] = new InvestigationLineData(self::NOTABLE, "{$byNetwork[$network]} of the sampled requests came from the same network.", $network);
        }

        return [$lines, $first];
    }

    private static function errors(InvestigationInputs $in): InvestigationLineData
    {
        $rates = [];

        foreach ($in->hours as $hour) {
            if ($hour['requests'] >= 50) {
                $rates[] = $hour['errors'] / $hour['requests'];
            }
        }

        if ($rates === []) {
            return new InvestigationLineData(self::CLEAR, 'Too few requests to judge server errors.');
        }

        sort($rates);
        $median = $rates[intdiv(count($rates), 2)];
        $worst = null;

        foreach ($in->hours as $hour) {
            if ($hour['requests'] < 50) {
                continue;
            }

            $rate = $hour['errors'] / $hour['requests'];

            if ($rate >= 0.05 && $rate >= 3 * $median && ($worst === null || $rate > $worst[1])) {
                $worst = [$hour['at'], $rate];
            }
        }

        if ($worst === null) {
            return new InvestigationLineData(self::CLEAR, 'No unusual bursts of server errors.');
        }

        return new InvestigationLineData(self::NOTABLE, 'Server errors rose to '.round($worst[1] * 100).'% on '.$worst[0]->format('j M').' between '.$worst[0]->format('H:i').' and '.$worst[0]->addHour()->format('H:i').'.');
    }

    /**
     * @return list<InvestigationStepData>
     */
    private static function steps(InvestigationInputs $in, bool $concerning, ?CarbonImmutable $since): array
    {
        $steps = [];
        $f = $in->finding;

        if ($f !== null && $f['fixed'] !== null) {
            if ($f['built']) {
                $command = self::updateCommand($f['ecosystem'], $f['name'], $f['fixed'], $f['lockfiles']);
                $steps[] = new InvestigationStepData(
                    'update_package',
                    $f['ecosystem'] === 'binary'
                        ? "Build with {$f['name']} {$f['fixed']} or later: raise the version your repository sets for it, then push."
                        : "Update {$f['name']} to {$f['fixed']} or later in your repository; the push deploys it.",
                    $command,
                    isset($in->hrefs['repository']) ? [new InvestigationLinkData('Open the repository', $in->hrefs['repository'], true)] : [],
                );
            } else {
                $steps[] = new InvestigationStepData('change_image', 'Change the image to a version with the fix.', null, [new InvestigationLinkData('Image settings', $in->hrefs['source'] ?? '#')]);
            }
        }

        if ($concerning) {
            $steps[] = new InvestigationStepData('redeploy', 'Replace the running containers with fresh ones from the image.', null, [new InvestigationLinkData('Redeploy', $in->hrefs['deployments'] ?? '#')]);

            $before = ($since ?? $in->now)->format('j F H:i');

            foreach ($in->connections as $connection) {
                if ($connection['database']) {
                    $steps[] = new InvestigationStepData('restore', "Restore {$connection['name']} from a backup taken before {$before}.", null, [new InvestigationLinkData('Backups', ($in->hrefs['backups:'.$connection['name']] ?? '#'))]);
                }
            }

            if ($in->secrets !== []) {
                $names = array_slice($in->secrets, 0, 8);
                $steps[] = new InvestigationStepData('rotate_secrets', 'Change these secrets, then deploy: '.implode(', ', $names).(count($in->secrets) > 8 ? ' and '.(count($in->secrets) - 8).' more' : '').'.', null, [new InvestigationLinkData('Variables', $in->hrefs['variables'] ?? '#')]);
            }

            foreach ($in->connections as $connection) {
                if ($connection['database']) {
                    $steps[] = new InvestigationStepData('database_password', "Change the password of {$connection['name']}’s user.", null, [new InvestigationLinkData('How to change it', $in->hrefs['docs:responding'] ?? '#', true)]);
                }
            }
        }

        if ($in->wafMode !== 'block' && $in->domains !== []) {
            $steps[] = new InvestigationStepData('block', "Block attacks on {$in->service}.");
        }

        if ($f !== null && $f['references'] !== []) {
            $steps[] = new InvestigationStepData('advisory', 'Read the advisory.', null, array_map(static fn (string $url): InvestigationLinkData => new InvestigationLinkData((string) parse_url($url, PHP_URL_HOST), $url, true), array_slice($f['references'], 0, 3)));
        }

        return $steps;
    }

    /**
     * Notification and card sentences: no paths, commands or networks.
     *
     * @param  list<InvestigationLineData>  $found
     * @return list<string>
     */
    private static function summary(InvestigationInputs $in, array $found, bool $partial): array
    {
        $concerning = count(array_filter($found, static fn (InvestigationLineData $l): bool => $l->level === self::CONCERNING));
        $notable = count(array_filter($found, static fn (InvestigationLineData $l): bool => $l->level === self::NOTABLE && $l->network === null && ! str_contains($l->text, 'matched')));
        $checked = array_filter($in->replicas, static fn (array $r): bool => $r['result'] !== null) !== [];

        $out = [];

        if ($checked) {
            $out[] = match (true) {
                $concerning > 0 => "Checked the container: {$concerning} concerning ".($concerning === 1 ? 'finding' : 'findings').'.',
                $notable > 0 => "Checked the container: {$notable} ".($notable === 1 ? 'thing' : 'things').' worth a look.',
                default => 'Checked the container: nothing unusual.',
            };
        } elseif ($partial) {
            $out[] = 'The container couldn’t be checked.';
        }

        foreach ($found as $line) {
            if ($line->network === null && str_contains($line->text, 'in the last 7 days;') && str_contains($line->text, 'matched')) {
                $out[] = explode(';', $line->text)[0].'.';
            }
        }

        return $out;
    }

    private static function explained(InvestigationInputs $in): bool
    {
        return array_any($in->sessions, fn (array $session): bool => $session['to'] === null || $session['to']->greaterThan($in->now->subMinutes(5)));
    }

    /**
     * @param  list<string>  $lockfiles
     */
    private static function updateCommand(string $ecosystem, string $name, string $fixed, array $lockfiles): ?string
    {
        if (preg_match('/^[A-Za-z0-9@_.\/:+~-]{1,256}$/D', $name) !== 1 || preg_match('/^[A-Za-z0-9._+~-]{1,64}$/D', $fixed) !== 1) {
            return null;
        }

        $has = static fn (string $kind): bool => in_array($kind, $lockfiles, true);

        return match ($ecosystem) {
            'npm' => match (true) {
                $has('pnpm') => "pnpm up {$name}@^{$fixed}",
                $has('yarn') => "yarn up {$name}@^{$fixed}",
                $has('bun') => "bun update {$name}@^{$fixed}",
                default => "npm install {$name}@^{$fixed}",
            },
            'pypi' => match (true) {
                $has('poetry') => "poetry add {$name}@^{$fixed}",
                $has('uv') => "uv add \"{$name}>={$fixed}\"",
                default => "pip install \"{$name}>={$fixed}\"",
            },
            'packagist' => "composer require {$name}:^".ltrim($fixed, 'v'),
            'rubygems' => "bundle update {$name}",
            'go' => "go get {$name}@v".ltrim($fixed, 'v'),
            'cargo' => "cargo update -p {$name} --precise {$fixed}",
            default => null,
        };
    }

    /**
     * @param  list<WafCategory>  $categories
     */
    private static function categoryWords(array $categories): string
    {
        $labels = array_map(static fn (WafCategory $c): string => $c === WafCategory::Sqli ? 'SQL injection' : ($c === WafCategory::Xss ? 'cross-site scripting' : strtolower($c->label())), $categories);

        return count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)).' and '.$labels[count($labels) - 1] : $labels[0];
    }

    private static function program(string $command): string
    {
        $first = strtok(ltrim($command), ' ');

        return $first === false ? $command : $first;
    }

    private static function topDir(string $path): string
    {
        foreach (['/tmp', '/var/tmp', '/dev/shm'] as $dir) {
            if (str_starts_with($path, $dir.'/') || $path === $dir) {
                return $dir;
            }
        }

        return dirname($path);
    }

    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => round($bytes / 1024 / 1024, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => "{$bytes} bytes",
        };
    }

    private static function ago(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $minutes = (int) $at->diffInMinutes($now);

        return match (true) {
            $minutes < 60 => max(1, $minutes).' min ago',
            $minutes < 1440 => intdiv($minutes, 60).' h ago',
            default => intdiv($minutes, 1440).' '.(intdiv($minutes, 1440) === 1 ? 'day' : 'days').' ago',
        };
    }

    /**
     * One line per person: a single session with its time, several as counts
     * with the latest one.
     *
     * @return list<InvestigationLineData>
     */
    private static function sessions(InvestigationInputs $in): array
    {
        $byUser = [];

        foreach ($in->sessions as $session) {
            $byUser[$session['user']][] = $session;
        }

        $lines = [];

        foreach ($byUser as $user => $sessions) {
            usort($sessions, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
            $last = $sessions[array_key_last($sessions)];

            if (count($sessions) === 1) {
                $lines[] = new InvestigationLineData(self::CLEAR, ($last['kind'] === 'ssh' ? 'SSH session by ' : 'Console session by ').$user.', '.self::span($last['from'], $last['to'], $in->now).'.');

                continue;
            }

            $consoles = count(array_filter($sessions, static fn (array $s): bool => $s['kind'] !== 'ssh'));
            $ssh = count($sessions) - $consoles;
            $counts = array_filter([
                $consoles > 0 ? ($consoles === 1 ? '1 console session' : "{$consoles} console sessions") : null,
                $ssh > 0 ? ($ssh === 1 ? '1 SSH session' : "{$ssh} SSH sessions") : null,
            ]);

            $lines[] = new InvestigationLineData(self::CLEAR, $user.' opened '.implode(' and ', $counts).', the last '.self::span($last['from'], $last['to'], $in->now).'.');
        }

        return $lines;
    }

    private static function span(CarbonImmutable $from, ?CarbonImmutable $to, CarbonImmutable $now): string
    {
        $day = $from->isSameDay($now) ? '' : $from->format('j M').', ';

        return $day.$from->format('H:i').' to '.($to?->format('H:i') ?? 'now');
    }
}
