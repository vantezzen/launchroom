<?php

declare(strict_types=1);

use App\Support\Compose\CommandLine;

test('strings split into argv like Compose does, without a shell', function (string $command, array $argv): void {
    expect(CommandLine::split($command))->toBe($argv);
})->with([
    ['node dist/server.js', ['node', 'dist/server.js']],
    ['  gunicorn   app:app  --bind 0.0.0.0:8000 ', ['gunicorn', 'app:app', '--bind', '0.0.0.0:8000']],
    ["sh -c 'npm ci && npm start'", ['sh', '-c', 'npm ci && npm start']],
    ['sh -c "echo \"hi\" && exit 0"', ['sh', '-c', 'echo "hi" && exit 0']],
    ['say hello\ world', ['say', 'hello world']],
    ["empty '' \"\" quotes", ['empty', '', '', 'quotes']],
    ['--flag=value --other="a b"', ['--flag=value', '--other=a b']],
    ['single \'$HOME stays\'', ['single', '$HOME stays']],
]);

test('unquoted shell syntax is refused with a hint to write a list', function (string $command): void {
    expect(fn (): array => CommandLine::split($command))->toThrow(InvalidArgumentException::class, 'Write it as a list');
})->with([
    'npm run migrate && npm start',
    'cat file | grep x',
    'echo hi; rm -rf /',
    'node server.js > /tmp/log',
    'echo $(whoami)',
    'echo `whoami`',
    'echo $HOME',
    'run "$(id)"',
    'background &',
]);

test('unbalanced quotes are refused', function (string $command): void {
    expect(fn (): array => CommandLine::split($command))->toThrow(InvalidArgumentException::class);
})->with(["echo 'open", 'echo "open']);

test('the leading words of a health check stop at the first operator', function (): void {
    expect(CommandLine::leadingWords('curl -f http://localhost:3000/health || exit 1'))->toBe(['curl', '-f', 'http://localhost:3000/health'])
        ->and(CommandLine::leadingWords('wget -qO- http://localhost/ping&&true'))->toBe(['wget', '-qO-', 'http://localhost/ping']);
});

test('splitting round-trips quoted argv (property)', function (): void {
    mt_srand(17);
    $alphabet = str_split("abcXYZ019 _-./:=@'\"\\\$|&;<>()*?[]{}\t");

    for ($run = 0; $run < 500; $run++) {
        $argv = [];

        for ($i = 0, $n = mt_rand(1, 6); $i < $n; $i++) {
            $word = '';

            for ($j = 0, $m = mt_rand(0, 10); $j < $m; $j++) {
                $word .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            $argv[] = $word;
        }

        $quoted = implode(' ', array_map(static fn (string $word): string => "'".str_replace("'", "'\\''", $word)."'", $argv));

        expect(CommandLine::split($quoted))->toBe($argv);
    }
});
