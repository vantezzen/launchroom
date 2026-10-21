/**
 * Commands are stored as argv arrays and never run through a shell
 * (docs/09 §1). These helpers turn what the user types into argv and back,
 * with POSIX-like quoting: 'single', "double" (with \ escapes) and \x.
 */
export function parseArgv(input: string): string[] | null {
    const args: string[] = [];
    let current = '';
    let quote: '"' | "'" | null = null;
    let inArg = false;

    for (let i = 0; i < input.length; i++) {
        const char = input[i];

        if (quote === "'") {
            if (char === "'") {
                quote = null;
            } else {
                current += char;
            }

            continue;
        }

        if (char === '\\' && i + 1 < input.length && quote !== null) {
            current += input[++i];
            continue;
        }

        if (char === '\\' && i + 1 < input.length) {
            current += input[++i];
            inArg = true;
            continue;
        }

        if (quote === '"') {
            if (char === '"') {
                quote = null;
            } else {
                current += char;
            }

            continue;
        }

        if (char === '"' || char === "'") {
            quote = char;
            inArg = true;
            continue;
        }

        if (/\s/.test(char)) {
            if (inArg) {
                args.push(current);
                current = '';
                inArg = false;
            }

            continue;
        }

        current += char;
        inArg = true;
    }

    if (quote !== null) {
        return null;
    }

    if (inArg) {
        args.push(current);
    }

    return args;
}

export function formatArgv(argv: string[] | null | undefined): string {
    if (!argv || argv.length === 0) {
        return '';
    }

    return argv
        .map((arg) =>
            arg === '' || /[\s"'\\$`]/.test(arg)
                ? `'${arg.replaceAll("'", `'"'"'`)}'`
                : arg,
        )
        .join(' ');
}
