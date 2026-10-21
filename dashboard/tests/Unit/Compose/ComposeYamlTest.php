<?php

declare(strict_types=1);

use App\Support\Compose\ComposeRejected;
use App\Support\Compose\ComposeYaml;
use App\Support\Compose\YamlLineIndex;

test('files that aren’t Compose mappings are rejected as a whole', function (string $yaml, string $message): void {
    expect(fn (): array => ComposeYaml::parse($yaml))->toThrow(ComposeRejected::class, $message);
})->with([
    ['', 'empty'],
    ["- a\n- b\n", 'mapping'],
    ["services:\n  web: [\n", 'valid YAML'],
    ["a: 1\na: 2\n", 'valid YAML'],
    ["services:\n  web:\n    image: !php/object 'O:8:\"stdClass\":0:{}'\n", 'valid YAML'],
    ["services:\n  web:\n    image: !php/const PHP_EOL\n", 'valid YAML'],
    ["services:\n  web:\n    image: !custom tag\n", 'valid YAML'],
]);

test('a file over 256 KB is rejected', function (): void {
    expect(fn (): array => ComposeYaml::parse("services: {}\n# ".str_repeat('x', ComposeYaml::MAX_BYTES)))->toThrow(ComposeRejected::class, '256 KB');
});

test('a billion-laughs file stops at the node limit instead of expanding', function (): void {
    $yaml = "a: &a [x, x, x, x, x, x, x, x, x]\n";

    foreach (range('b', 'h') as $name) {
        $previous = chr(ord($name) - 1);
        $yaml .= "{$name}: &{$name} [*{$previous}, *{$previous}, *{$previous}, *{$previous}, *{$previous}, *{$previous}, *{$previous}]\n";
    }

    $yaml .= "services: {web: {image: nginx}}\n";

    expect(fn (): array => ComposeYaml::parse($yaml))->toThrow(ComposeRejected::class);
});

test('more aliases than the limit are rejected', function (): void {
    $yaml = "x-base: &base {image: nginx}\nservices:\n".implode('', array_map(static fn (int $i): string => "  s{$i}: *base\n", range(1, 70)));

    expect(fn (): array => ComposeYaml::parse($yaml))->toThrow(ComposeRejected::class, 'aliases');
});

test('deep nesting is rejected', function (): void {
    expect(fn (): array => ComposeYaml::parse('services: '.str_repeat('[', 40).str_repeat(']', 40)))->toThrow(ComposeRejected::class, 'nested');
});

test('the line index points at block keys and sequence items', function (): void {
    $yaml = <<<'YAML'
    # comment
    services:
      web:
        image: nginx
        command: |
          not: a key
        ports:
          - "80:80"
          - 443
        environment:
          - A=1
        deploy: {replicas: 2}
      "db":
        image: postgres
    YAML;
    $lines = YamlLineIndex::of($yaml);

    expect($lines->line('services.web'))->toBe(3)
        ->and($lines->line('services.web.image'))->toBe(4)
        ->and($lines->line('services.web.command.not'))->toBe(5)
        ->and($lines->line('services.web.ports.0'))->toBe(8)
        ->and($lines->line('services.web.ports.1'))->toBe(9)
        ->and($lines->line('services.web.environment.0'))->toBe(11)
        ->and($lines->line('services.web.deploy.replicas'))->toBe(12)
        ->and($lines->line('services.db.image'))->toBe(14);
});
