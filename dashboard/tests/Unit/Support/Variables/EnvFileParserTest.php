<?php

declare(strict_types=1);

use App\Data\Variables\EnvFileEntry;
use App\Enums\VariableType;
use App\Support\Variables\EnvFileParser;

/**
 * @return array<string, string>
 */
function parsedValues(string $content): array
{
    $values = [];

    foreach (EnvFileParser::parse($content)->entries as $entry) {
        $values[$entry->key] = $entry->value;
    }

    return $values;
}

test('parses simple assignments verbatim', function (): void {
    expect(parsedValues("A=1\nB=hello world\nC=\n"))->toBe(['A' => '1', 'B' => 'hello world', 'C' => '']);
});

test('ignores comments, blank lines and export prefixes', function (): void {
    $content = <<<'ENV'
        # a comment
          # indented comment

        export TOKEN=abc
        export   SPACED=yes
        ENV;

    expect(parsedValues($content))->toBe(['TOKEN' => 'abc', 'SPACED' => 'yes']);
});

test('allows spaces around the equals sign and trims unquoted values', function (): void {
    expect(parsedValues("KEY = value  \nOTHER=\t x \t"))->toBe(['KEY' => 'value', 'OTHER' => 'x']);
});

test('strips inline comments only from unquoted values after whitespace', function (): void {
    expect(parsedValues("A=value # comment\nB=value#not-a-comment\nC=\"quoted # kept\" # comment"))
        ->toBe(['A' => 'value', 'B' => 'value#not-a-comment', 'C' => 'quoted # kept']);
});

test('single quotes are literal', function (): void {
    expect(parsedValues("A='a \\n b \$HOME \${{x}}'"))->toBe(['A' => 'a \\n b $HOME ${{x}}']);
});

test('double quotes interpret escapes', function (): void {
    expect(parsedValues('A="line1\nline2\tTab \"quoted\" back\\\\slash \x"'))
        ->toBe(['A' => "line1\nline2\tTab \"quoted\" back\\slash \\x"]);
});

test('quoted values may span lines', function (string $quote): void {
    $content = "KEY={$quote}-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----{$quote}\nNEXT=1";

    expect(parsedValues($content))->toBe([
        'KEY' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
        'NEXT' => '1',
    ]);
})->with(['double' => '"', 'single' => "'", 'backtick' => '`']);

test('never interpolates variables', function (): void {
    expect(parsedValues("A=1\nB=\$A\nC=\${A}\nD=\"\$A\""))->toBe(['A' => '1', 'B' => '$A', 'C' => '${A}', 'D' => '$A']);
});

test('keeps equals signs and special characters in values', function (): void {
    expect(parsedValues("URL=postgres://u:p@h:5432/db?sslmode=require&a=b\nB64=YWJj==\nJSON={\"a\": 1}"))
        ->toBe(['URL' => 'postgres://u:p@h:5432/db?sslmode=require&a=b', 'B64' => 'YWJj==', 'JSON' => '{"a": 1}']);
});

test('handles CRLF line endings and a byte-order mark', function (): void {
    expect(parsedValues("\u{FEFF}A=1\r\nB=\"x\r\ny\"\r\n"))->toBe(['A' => '1', 'B' => "x\ny"]);
});

test('preserves unicode', function (): void {
    expect(parsedValues('GREETING="hällo 👋"'))->toBe(['GREETING' => 'hällo 👋']);
});

test('the last duplicate wins, with a warning', function (): void {
    $result = EnvFileParser::parse("A=1\nB=2\nA=3");

    expect(parsedValues("A=1\nB=2\nA=3"))->toBe(['B' => '2', 'A' => '3'])
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['line'])->toBe(3);
});

test('reports lines without an equals sign and invalid names', function (): void {
    $result = EnvFileParser::parse("GOOD=1\nnot an assignment\n1BAD=x\nBAD-NAME=y\nALSO_GOOD=2");

    expect($result->keys())->toBe(['GOOD', 'ALSO_GOOD'])
        ->and(array_column($result->errors, 'line'))->toBe([2, 3, 4]);
});

test('reports an unterminated quote', function (): void {
    $result = EnvFileParser::parse("A=\"never closed\nB=1");

    expect($result->errors)->toHaveCount(1)
        ->and($result->errors[0]['message'])->toContain('Unterminated');
});

test('warns about text after a closing quote', function (): void {
    $result = EnvFileParser::parse('A="x" trailing');

    expect(parsedValues('A="x" trailing'))->toBe(['A' => 'x'])
        ->and($result->warnings)->toHaveCount(1);
});

test('records line numbers of multiline entries', function (): void {
    $entries = EnvFileParser::parse("A=\"1\n2\"\nB=3")->entries;

    expect(array_map(static fn (EnvFileEntry $entry): int => $entry->line, $entries))->toBe([1, 3]);
});

test('suggests secret for secret-looking keys', function (string $key, VariableType $expected): void {
    expect(EnvFileParser::suggestType($key, 'value'))->toBe($expected);
})->with([
    ['APP_KEY', VariableType::Secret],
    ['STRIPE_SECRET', VariableType::Secret],
    ['GITHUB_TOKEN', VariableType::Secret],
    ['DB_PASSWORD', VariableType::Secret],
    ['PASSWORD_SALT', VariableType::Secret],
    ['SENTRY_DSN', VariableType::Secret],
    ['APP_NAME', VariableType::Config],
    ['PORT', VariableType::Config],
    // Browser-inlined keys are public by definition (docs/06 §3.3).
    ['NEXT_PUBLIC_API_KEY', VariableType::Config],
    ['VITE_TOKEN', VariableType::Config],
]);

test('suggests secret for secret-looking values', function (string $value): void {
    expect(EnvFileParser::suggestType('SOMETHING', $value))->toBe(VariableType::Secret);
})->with([
    'private key' => ["-----BEGIN RSA PRIVATE KEY-----\nabc"],
    'stripe live key' => ['sk_live_abcdefghijklmnop'],
    'github token' => ['ghp_'.str_repeat('a', 36)],
    'aws key id' => ['AKIAIOSFODNN7EXAMPLE'],
    'url with password' => ['postgres://user:secret@db:5432/app'],
]);

test('plain values stay config, and browser keys stay config even with secret-looking values', function (): void {
    expect(EnvFileParser::suggestType('SOMETHING', 'https://example.com'))->toBe(VariableType::Config)
        ->and(EnvFileParser::suggestType('NEXT_PUBLIC_STRIPE', 'sk_live_abcdefghijklmnop'))->toBe(VariableType::Config);
});

test('entries never print their values', function (): void {
    $entry = EnvFileParser::parse('SECRET=hunter2')->entries[0];

    expect(print_r($entry, true))->not->toContain('hunter2');
});
