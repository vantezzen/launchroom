/**
 * The command bar's input as argv (D105, docs/08 §22): words split like a
 * shell splits them (quotes and backslashes group), and nothing else. A
 * command runs without a shell (docs/09 R1), so pipes, redirects, `&&`,
 * `;`, variables and substitutions are refused with a sentence instead of
 * being passed on as text the user didn't mean.
 */
export type ParsedArgv =
    | { ok: true; argv: string[] }
    | { ok: false; error: string };

const SHELL_ONLY =
    'Commands run without a shell, so pipes, redirects, && and ; don’t work. Run one program per command.';
const SUBSTITUTION =
    'Commands run without a shell, so $VARIABLES and $(…) aren’t expanded. The service’s variables are set in the container.';

export function parseArgv(input: string): ParsedArgv {
    const argv: string[] = [];
    let word = '';
    let inWord = false;

    for (let i = 0; i < input.length; i++) {
        const char = input[i];

        if (char === ' ' || char === '\t' || char === '\n') {
            if (inWord) {
                argv.push(word);
                word = '';
                inWord = false;
            }

            continue;
        }

        if (char === "'") {
            const end = input.indexOf("'", i + 1);

            if (end === -1) {
                return { ok: false, error: 'A quote isn’t closed.' };
            }

            word += input.slice(i + 1, end);
            inWord = true;
            i = end;
            continue;
        }

        if (char === '"') {
            let j = i + 1;

            for (; j < input.length && input[j] !== '"'; j++) {
                if (
                    input[j] === '\\' &&
                    (input[j + 1] === '"' || input[j + 1] === '\\')
                ) {
                    j++;
                }

                if (input[j] === '$' || input[j] === '`') {
                    return { ok: false, error: SUBSTITUTION };
                }

                word += input[j];
            }

            if (j >= input.length) {
                return { ok: false, error: 'A quote isn’t closed.' };
            }

            inWord = true;
            i = j;
            continue;
        }

        if (char === '\\') {
            if (i + 1 < input.length) {
                word += input[i + 1];
                i++;
            }

            inWord = true;
            continue;
        }

        if ('|&;<>'.includes(char)) {
            return { ok: false, error: SHELL_ONLY };
        }

        if (char === '$' || char === '`') {
            return { ok: false, error: SUBSTITUTION };
        }

        word += char;
        inWord = true;
    }

    if (inWord) {
        argv.push(word);
    }

    return { ok: true, argv };
}

const PLAIN = /^[A-Za-z0-9_@%+=:,./-]+$/;

/** An argv as one line someone could type again (quoted where needed). */
export function formatArgv(argv: string[]): string {
    return argv
        .map((arg) =>
            PLAIN.test(arg) ? arg : `'${arg.replaceAll("'", `'\\''`)}'`,
        )
        .join(' ');
}
