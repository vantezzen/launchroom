<?php

declare(strict_types=1);

namespace App\Support\Dockerfiles;

use App\Support\Images\ImageReference;

/**
 * The policy gate for generated Dockerfiles (docs/20 §4). Pure and strict:
 * parsed like BuildKit (logical lines with continuations, comments, parser
 * directives, heredocs), then checked instruction by instruction. Every
 * problem carries its line so the model can repair it; external image
 * references come back for digest pinning.
 *
 * It is not a sandbox: builds run on launchroom's rootless BuildKit with its
 * own allow-list either way. It keeps what launchroom stores and offers
 * reviewable: pinned bases, no host features, no secrets in the image.
 */
final class DockerfileLinter
{
    public const int MAX_BYTES = 65536;

    public const int MAX_LINES = 400;

    public const int MAX_STAGES = 16;

    private const array ALLOWED = ['FROM', 'RUN', 'CMD', 'LABEL', 'EXPOSE', 'ENV', 'ADD', 'COPY', 'ENTRYPOINT', 'USER', 'WORKDIR', 'ARG', 'STOPSIGNAL', 'HEALTHCHECK', 'SHELL'];

    private const array REFUSED = [
        'ONBUILD' => 'ONBUILD is not allowed: it runs instructions in images built from this one.',
        'VOLUME' => 'VOLUME is not allowed: launchroom manages volumes in the service settings.',
        'MAINTAINER' => 'MAINTAINER is deprecated; use a LABEL instead.',
    ];

    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    /**
     * @param  list<string>  $buildVariables  names a `RUN --mount=type=secret,id=…` may use (the service's build-time variables)
     * @param  list<string>  $secretVariables  names of the service's secret variables (never as ARG/ENV)
     */
    public static function lint(string $dockerfile, int $port, array $buildVariables = [], array $secretVariables = []): DockerfileLint
    {
        $lint = new DockerfileLintBuilder;

        if (strlen($dockerfile) > self::MAX_BYTES) {
            $lint->problem(1, 'The Dockerfile is larger than 64 KiB.');

            return $lint->result();
        }

        if (! mb_check_encoding($dockerfile, 'UTF-8') || str_contains($dockerfile, "\0")) {
            $lint->problem(1, 'The Dockerfile must be UTF-8 text.');

            return $lint->result();
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $dockerfile));

        if (count($lines) > self::MAX_LINES) {
            $lint->problem(self::MAX_LINES, 'The Dockerfile has more than 400 lines.');

            return $lint->result();
        }

        $escape = '\\';
        $instructions = self::instructions($lines, $escape, $lint);

        $stages = [];
        $stageIndex = -1;
        $final = ['expose' => [], 'cmd' => false];
        $secretNames = array_flip(array_map(strtoupper(...), $secretVariables));
        $buildNames = array_flip($buildVariables);

        foreach ($instructions as [$line, $keyword, $args]) {
            if (isset(self::REFUSED[$keyword])) {
                $lint->problem($line, self::REFUSED[$keyword]);

                continue;
            }

            if (! in_array($keyword, self::ALLOWED, true)) {
                $lint->problem($line, "Unknown instruction {$keyword}.");

                continue;
            }

            if ($keyword !== 'FROM' && $keyword !== 'ARG' && $stageIndex < 0) {
                $lint->problem($line, "{$keyword} comes before the first FROM.");

                continue;
            }

            switch ($keyword) {
                case 'FROM':
                    $stageIndex++;
                    $final = ['expose' => [], 'cmd' => false];
                    $name = self::from($line, $args, $stages, $lint);

                    if ($name !== null) {
                        $stages[] = strtolower($name);
                    } else {
                        $stages[] = '#'.$stageIndex;
                    }

                    if ($stageIndex + 1 > self::MAX_STAGES) {
                        $lint->problem($line, 'The Dockerfile has more than 16 stages.');
                    }

                    break;
                case 'RUN':
                    self::run($line, $args, $stages, $buildNames, $lint);

                    break;
                case 'ADD':
                    self::add($line, $args, $lint);

                    break;
                case 'COPY':
                    self::copy($line, $args, $stages, $lint);

                    break;
                case 'ARG':
                    self::assignments($line, 'ARG', $args, $secretNames, $lint);

                    break;
                case 'ENV':
                    self::assignments($line, 'ENV', $args, $secretNames, $lint);

                    break;
                case 'EXPOSE':
                    foreach (preg_split('/\s+/', trim($args)) ?: [] as $token) {
                        if (preg_match('#^([0-9]{1,5})(?:/(tcp|udp))?$#Di', $token, $m) === 1 && strtolower($m[2] ?? 'tcp') === 'tcp') {
                            $final['expose'][] = (int) $m[1];
                        }
                    }

                    break;
                case 'CMD':
                case 'ENTRYPOINT':
                    $final['cmd'] = true;

                    break;
                case 'LABEL':
                    if (RepositoryContext::looksSecretValue($args)) {
                        $lint->problem($line, 'A LABEL contains something that looks like a credential.');
                    }

                    break;
            }
        }

        if ($stageIndex < 0) {
            $lint->problem(1, 'The Dockerfile has no FROM.');

            return $lint->result();
        }

        $last = $instructions[count($instructions) - 1][0];

        if (! $final['cmd']) {
            $lint->problem($last, 'The final stage needs a CMD or ENTRYPOINT that starts the app.');
        }

        if (! in_array($port, $final['expose'], true)) {
            $lint->problem($last, "The final stage must EXPOSE {$port} (the port the app listens on).");
        }

        return $lint->result();
    }

    /**
     * Logical instructions: parser directives, comments, continuations and
     * heredocs handled as BuildKit does.
     *
     * @param  list<string>  $lines
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private static function instructions(array $lines, string &$escape, DockerfileLintBuilder $lint): array
    {
        $out = [];
        $count = count($lines);
        $i = 0;

        // Parser directives: `# key=value` at the very top.
        for (; $i < $count; $i++) {
            if (preg_match('/^\s*#\s*([a-zA-Z]+)\s*=\s*(\S+)\s*$/', $lines[$i], $m) !== 1) {
                break;
            }

            $directive = strtolower($m[1]);

            if ($directive === 'syntax') {
                $lint->problem($i + 1, 'Remove the "# syntax=" line: launchroom builds with its own Dockerfile frontend.');
            } elseif ($directive === 'escape') {
                if ($m[2] === '`') {
                    $escape = '`';
                } elseif ($m[2] !== '\\') {
                    $lint->problem($i + 1, 'The escape directive must be \\ or `.');
                }
            } elseif ($directive !== 'check') {
                $lint->problem($i + 1, "Unknown parser directive {$m[1]}.");
            }
        }

        while ($i < $count) {
            $raw = $lines[$i];
            $trimmed = trim($raw);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $i++;

                continue;
            }

            $start = $i + 1;
            $logical = '';
            $current = rtrim($raw);

            while (true) {
                if (str_ends_with($current, $escape)) {
                    $logical .= substr($current, 0, -1).' ';
                    $i++;

                    // Comment and blank lines inside a continuation are skipped.
                    while ($i < $count && (trim($lines[$i]) === '' || str_starts_with(trim($lines[$i]), '#'))) {
                        $i++;
                    }

                    if ($i >= $count) {
                        break;
                    }

                    $current = rtrim($lines[$i]);

                    continue;
                }

                $logical .= $current;
                $i++;

                break;
            }

            $logical = trim($logical);

            if (preg_match('/^([A-Za-z]+)(?:\s+(.*))?$/s', $logical, $m) !== 1) {
                $lint->problem($start, 'This line is not a Dockerfile instruction.');

                continue;
            }

            $keyword = strtoupper($m[1]);
            $args = $m[2] ?? '';

            // Heredocs (RUN/COPY/ADD <<EOF … EOF): their body is consumed here.
            if (in_array($keyword, ['RUN', 'COPY', 'ADD'], true) && preg_match_all('/<<(-?)(["\']?)([A-Za-z_][A-Za-z0-9_]*)\2/', $args, $docs, PREG_SET_ORDER) > 0) {
                foreach ($docs as $doc) {
                    $word = $doc[3];
                    $stripTabs = $doc[1] === '-';
                    $closed = false;

                    while ($i < $count) {
                        $body = $stripTabs ? ltrim($lines[$i], "\t") : $lines[$i];
                        $i++;

                        if (rtrim($body) === $word) {
                            $closed = true;

                            break;
                        }
                    }

                    if (! $closed) {
                        $lint->problem($start, "The heredoc {$word} is never closed.");
                    }
                }
            }

            $out[] = [$start, $keyword, $args];
        }

        return $out;
    }

    /**
     * @param  list<string>  $stages
     */
    private static function from(int $line, string $args, array $stages, DockerfileLintBuilder $lint): ?string
    {
        $tokens = preg_split('/\s+/', trim($args)) ?: [];

        while ($tokens !== [] && str_starts_with($tokens[0], '--')) {
            $flag = array_shift($tokens);
            $lint->problem($line, str_starts_with($flag, '--platform') ? 'Remove --platform from FROM: launchroom builds for the servers the service runs on.' : "FROM {$flag} is not allowed.");
        }

        $image = $tokens[0] ?? '';
        $name = null;

        if (count($tokens) === 3 && strtolower($tokens[1]) === 'as') {
            $name = $tokens[2];

            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,62}$/D', $name) !== 1) {
                $lint->problem($line, "{$name} is not a valid stage name.");
            }
        } elseif (count($tokens) !== 1) {
            $lint->problem($line, 'FROM takes an image and an optional "AS name".');
        }

        if ($image !== '') {
            self::image($line, $image, $stages, $lint, 'FROM');
        }

        return $name;
    }

    /**
     * An image reference outside the Dockerfile's own stages must name an
     * explicit tag (or digest) so it can be pinned.
     *
     * @param  list<string>  $stages
     */
    private static function image(int $line, string $image, array $stages, DockerfileLintBuilder $lint, string $where): void
    {
        if (strtolower($image) === 'scratch' || in_array(strtolower($image), $stages, true)) {
            return;
        }

        if (str_contains($image, '$')) {
            $lint->problem($line, "{$where} {$image}: use a literal image reference, not a variable, so it can be pinned.");

            return;
        }

        $reference = ImageReference::tryParse($image);

        if (! $reference instanceof ImageReference) {
            $lint->problem($line, "{$where} {$image} is not a valid image reference.");

            return;
        }

        if ($reference->digest !== null) {
            return;
        }

        if ($reference->tag === null || $reference->tag === 'latest') {
            $lint->problem($line, "{$where} {$image}: name an explicit version tag (for example node:22-bookworm-slim), not latest.");

            return;
        }

        $lint->reference($line, $image);
    }

    /**
     * @param  list<string>  $stages
     * @param  array<string, int>  $buildNames
     */
    private static function run(int $line, string $args, array $stages, array $buildNames, DockerfileLintBuilder $lint): void
    {
        foreach (self::flags($args) as [$flag, $value]) {
            switch ($flag) {
                case 'mount':
                    self::mount($line, $value, $stages, $buildNames, $lint);

                    break;
                case 'network':
                    if (! in_array(strtolower($value), ['default', 'none'], true)) {
                        $lint->problem($line, "RUN --network={$value} is not allowed; only default or none.");
                    }

                    break;
                case 'security':
                    $lint->problem($line, 'RUN --security is not allowed: builds never get privileged access.');

                    break;
                default:
                    $lint->problem($line, "RUN --{$flag} is not allowed.");
            }
        }
    }

    /**
     * @param  list<string>  $stages
     * @param  array<string, int>  $buildNames
     */
    private static function mount(int $line, string $spec, array $stages, array $buildNames, DockerfileLintBuilder $lint): void
    {
        $options = [];

        foreach (explode(',', $spec) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $options[strtolower(trim($key))] = trim($value);
        }

        $type = strtolower($options['type'] ?? 'bind');

        switch ($type) {
            case 'secret':
                $id = $options['id'] ?? '';

                if ($id === '' || ! isset($buildNames[$id])) {
                    $lint->problem($line, $id === ''
                        ? 'A secret mount needs id=<variable name>.'
                        : "The secret {$id} is not one of this service's build-time variables; launchroom only provides those as build secrets.");
                }

                foreach (array_keys($options) as $key) {
                    if (! in_array($key, ['type', 'id', 'target', 'dst', 'destination', 'env', 'required', 'mode', 'uid', 'gid'], true)) {
                        $lint->problem($line, "Secret mounts don't take {$key}.");
                    }
                }

                break;
            case 'cache':
            case 'tmpfs':
                break;
            case 'bind':
                if (isset($options['from'])) {
                    self::image($line, $options['from'], $stages, $lint, 'RUN --mount from');
                }

                break;
            case 'ssh':
                $lint->problem($line, 'SSH mounts are not allowed: launchroom never forwards SSH agents into builds.');

                break;
            default:
                $lint->problem($line, "Mount type {$type} is not allowed.");
        }
    }

    private static function add(int $line, string $args, DockerfileLintBuilder $lint): void
    {
        foreach (self::flags($args) as [$flag]) {
            if ($flag === 'keep-git-dir') {
                $lint->problem($line, 'ADD --keep-git-dir is not allowed.');
            } elseif (! in_array($flag, ['chown', 'chmod', 'link', 'checksum', 'exclude'], true)) {
                $lint->problem($line, "ADD --{$flag} is not allowed.");
            }
        }

        foreach (self::paths(self::withoutFlags($args)) as $source) {
            if (preg_match('#^(?:https?://|git@|ssh://)#i', $source) === 1 || str_ends_with($source, '.git')) {
                $lint->problem($line, "ADD {$source}: remote sources are not allowed. Copy files from the repository, or download in a RUN step with a pinned version.");
            }
        }
    }

    /**
     * @param  list<string>  $stages
     */
    private static function copy(int $line, string $args, array $stages, DockerfileLintBuilder $lint): void
    {
        foreach (self::flags($args) as [$flag, $value]) {
            if ($flag === 'from') {
                if (preg_match('/^[0-9]+$/D', $value) === 1) {
                    continue;
                }

                self::image($line, $value, $stages, $lint, 'COPY --from');
            } elseif (! in_array($flag, ['chown', 'chmod', 'link', 'parents', 'exclude'], true)) {
                $lint->problem($line, "COPY --{$flag} is not allowed.");
            }
        }
    }

    /**
     * @param  array<string, int>  $secretNames
     */
    private static function assignments(int $line, string $keyword, string $args, array $secretNames, DockerfileLintBuilder $lint): void
    {
        $args = trim($args);
        $pairs = [];

        if ($keyword === 'ENV' && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+(?![A-Za-z_][A-Za-z0-9_]*=)(.+)$/s', $args, $m) === 1) {
            // Legacy `ENV NAME value`.
            $pairs[] = [$m[1], $m[2]];
        } else {
            preg_match_all('/([^\s=]+)(?:=("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|\S*))?/', $args, $all, PREG_SET_ORDER);

            foreach ($all as $match) {
                $pairs[] = [$match[1], isset($match[2]) ? trim($match[2], '"\'') : ''];
            }
        }

        foreach ($pairs as [$name, $value]) {
            if (preg_match(self::NAME, $name) !== 1) {
                $lint->problem($line, "{$keyword} {$name} is not a valid variable name.");

                continue;
            }

            if (isset($secretNames[strtoupper($name)])) {
                $lint->problem($line, "{$keyword} {$name}: this is one of the service's secrets. Secrets are never build args or image variables; mount it with RUN --mount=type=secret,id={$name} if the build needs it.");

                continue;
            }

            if (RepositoryContext::looksSecretName($name)) {
                $lint->problem($line, "{$keyword} {$name} looks like a secret. Images keep ARG and ENV values; pass secrets as variables at runtime instead.");

                continue;
            }

            if ($value !== '' && RepositoryContext::looksSecretValue($value)) {
                $lint->problem($line, "{$keyword} {$name} has a value that looks like a credential.");
            }
        }
    }

    /**
     * Leading `--flag=value` / `--flag` options of an instruction.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function flags(string $args): array
    {
        $out = [];

        while (preg_match('/^\s*--([a-z][a-z-]*)(?:=("[^"]*"|\S*))?/i', $args, $m) === 1) {
            $out[] = [strtolower($m[1]), trim($m[2] ?? '', '"')];
            $args = substr($args, strlen($m[0]));
        }

        return $out;
    }

    private static function withoutFlags(string $args): string
    {
        return (string) preg_replace('/^(?:\s*--[a-z][a-z-]*(?:=(?:"[^"]*"|\S*))?)*\s*/i', '', $args);
    }

    /**
     * Sources of ADD/COPY (shell or JSON form), without the destination.
     *
     * @return list<string>
     */
    private static function paths(string $args): array
    {
        $args = trim($args);

        if (str_starts_with($args, '[')) {
            $decoded = json_decode($args, true);
            $items = is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
        } else {
            $items = preg_split('/\s+/', $args) ?: [];
        }

        array_pop($items);

        return $items;
    }
}
