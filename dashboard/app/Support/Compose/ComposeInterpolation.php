<?php

declare(strict_types=1);

namespace App\Support\Compose;

use App\Enums\VariableType;

/**
 * Compose variable interpolation (docs/17 §2.2): `$VAR`, `${VAR}`,
 * `${VAR:-default}`, `${VAR-default}`, `${VAR:?message}`, `${VAR?message}`,
 * `${VAR:+alt}`, `${VAR+alt}`, nested expressions in the argument, and
 * `$$` for a literal `$`.
 *
 * Values come from the user's `.env` ($values). In variable values
 * (forVariable) every interpolated name becomes a reference to a project
 * shared variable, so services that share a password see the same value;
 * secret-looking names nobody set are generated. Everywhere else
 * (substitute) the known value is substituted; generated or missing
 * required values can't be used there.
 */
final class ComposeInterpolation
{
    /** @var array<string, array{provided: bool, generated: bool, required: bool, variable: bool, message: string|null}> */
    private array $names = [];

    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        private readonly array $values = [],
    ) {}

    public static function looksSecret(string $name): bool
    {
        return VariableType::suggestFor($name) === VariableType::Secret;
    }

    public static function contains(string $value): bool
    {
        return str_contains(str_replace('$$', '', $value), '$');
    }

    /**
     * The value with known values substituted.
     *
     * @throws InterpolationFailed
     */
    public function substitute(string $value): string
    {
        return $this->evaluate(self::parse($value), false);
    }

    /**
     * The value in launchroom's variable syntax: interpolated names become
     * `${{shared.NAME}}` references.
     *
     * @throws InterpolationFailed
     */
    public function forVariable(string $value): string
    {
        return $this->evaluate(self::parse($value), true);
    }

    /**
     * Every name seen so far: whether the user gave a value, whether it is
     * generated, whether it is required, and whether a variable uses it.
     *
     * @return array<string, array{provided: bool, generated: bool, required: bool, variable: bool, message: string|null}>
     */
    public function names(): array
    {
        return $this->names;
    }

    public function value(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @return list<array{type: 'text', text: string}|array{type: 'var', name: string, op: string|null, arg: list<mixed>}>
     *
     * @throws InterpolationFailed
     */
    public static function parse(string $value): array
    {
        /** @var list<array{type: 'text', text: string}|array{type: 'var', name: string, op: string|null, arg: list<mixed>}> $tokens */
        $tokens = [];
        $text = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char !== '$') {
                $text .= $char;

                continue;
            }

            $next = $value[$i + 1] ?? '';

            if ($next === '$') {
                $text .= '$';
                $i++;

                continue;
            }

            if ($next === '{') {
                if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $value, $m, 0, $i + 2) !== 1) {
                    throw new InterpolationFailed('Invalid interpolation: “${” needs a variable name.');
                }

                $name = $m[0];
                $pos = $i + 2 + strlen($name);
                $op = null;
                $arg = [];

                if (! isset($value[$pos])) {
                    throw new InterpolationFailed('Invalid interpolation: a “${” is never closed.');
                }

                if ($value[$pos] !== '}') {
                    foreach ([':-', ':?', ':+', '-', '?', '+'] as $candidate) {
                        if (substr($value, $pos, strlen($candidate)) === $candidate) {
                            $op = $candidate;
                            break;
                        }
                    }

                    if ($op === null) {
                        throw new InterpolationFailed("Invalid interpolation in \${{$name}…}: use \${{$name}}, \${{$name}:-default}, \${{$name}:?message} or \${{$name}:+value}.");
                    }

                    $start = $pos + strlen($op);
                    $end = self::closingBrace($value, $start);
                    $arg = self::parse(substr($value, $start, $end - $start));
                    $pos = $end;
                }

                if ($text !== '') {
                    $tokens[] = ['type' => 'text', 'text' => $text];
                    $text = '';
                }

                $tokens[] = ['type' => 'var', 'name' => $name, 'op' => $op, 'arg' => $arg];
                $i = $pos;

                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $value, $m, 0, $i + 1) === 1) {
                if ($text !== '') {
                    $tokens[] = ['type' => 'text', 'text' => $text];
                    $text = '';
                }

                $tokens[] = ['type' => 'var', 'name' => $m[0], 'op' => null, 'arg' => []];
                $i += strlen($m[0]);

                continue;
            }

            // A lone `$` (end of the value, `$1`, `$-`) stays literal.
            $text .= '$';
        }

        if ($text !== '') {
            $tokens[] = ['type' => 'text', 'text' => $text];
        }

        return $tokens;
    }

    /**
     * @throws InterpolationFailed
     */
    private static function closingBrace(string $value, int $start): int
    {
        $depth = 0;
        $length = strlen($value);

        for ($i = $start; $i < $length; $i++) {
            if ($value[$i] === '$' && ($value[$i + 1] ?? '') === '$') {
                $i++;

                continue;
            }

            if ($value[$i] === '$' && ($value[$i + 1] ?? '') === '{') {
                $depth++;
                $i++;

                continue;
            }

            if ($value[$i] === '}') {
                if ($depth === 0) {
                    return $i;
                }

                $depth--;
            }
        }

        throw new InterpolationFailed('Invalid interpolation: a “${” is never closed.');
    }

    /**
     * @param  list<mixed>  $tokens
     *
     * @throws InterpolationFailed
     */
    private function evaluate(array $tokens, bool $variable): string
    {
        $out = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (($token['type'] ?? null) === 'text') {
                $out .= is_string($token['text'] ?? null) ? $token['text'] : '';

                continue;
            }

            $name = is_string($token['name'] ?? null) ? $token['name'] : '';
            $op = is_string($token['op'] ?? null) ? $token['op'] : null;
            /** @var list<mixed> $arg */
            $arg = is_array($token['arg'] ?? null) ? array_values($token['arg']) : [];
            $out .= $this->resolve($name, $op, $arg, $variable);
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $arg
     *
     * @throws InterpolationFailed
     */
    private function resolve(string $name, ?string $op, array $arg, bool $variable): string
    {
        $provided = array_key_exists($name, $this->values);
        $value = $this->values[$name] ?? '';
        $secret = ! $provided && self::looksSecret($name);
        $entry = $this->names[$name] ?? ['provided' => $provided, 'generated' => false, 'required' => false, 'variable' => false, 'message' => null];
        $set = match ($op) {
            '-', '?', '+' => $provided,
            default => $provided && $value !== '',
        };

        $reference = function () use ($name, $secret, &$entry, $variable): string {
            if (! $variable) {
                throw new InterpolationFailed($secret
                    ? "\${{$name}} would be a generated secret, which only variables can use."
                    : "\${{$name}} has no value.");
            }

            $entry['variable'] = true;
            $entry['generated'] = $entry['generated'] || $secret;

            return '${{shared.'.$name.'}}';
        };

        try {
            switch ($op) {
                case null:
                    if ($variable) {
                        return $reference();
                    }

                    // Compose substitutes an empty string for a name nobody set.
                    return $provided ? $value : ($secret ? $reference() : '');

                case ':-':
                case '-':
                    if ($set) {
                        return $variable ? $reference() : $value;
                    }

                    // A secret nobody set is generated rather than taking a placeholder default.
                    return $secret && $variable ? $reference() : $this->evaluate($arg, $variable);

                case ':?':
                case '?':
                    if ($set || ($secret && $variable)) {
                        return $variable ? $reference() : $value;
                    }

                    $entry['required'] = true;
                    $message = trim($this->evaluate($arg, false));
                    $entry['message'] = $message === '' ? null : $message;

                    if ($variable) {
                        return $reference();
                    }

                    throw new InterpolationFailed("\${{$name}} is required".($entry['message'] !== null ? ': '.$entry['message'] : '').'.');
                default:
                    return $set || ($secret && $variable) ? $this->evaluate($arg, $variable) : '';
            }
        } finally {
            $this->names[$name] = $entry;
        }
    }
}
