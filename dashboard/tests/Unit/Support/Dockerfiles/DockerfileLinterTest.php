<?php

declare(strict_types=1);

use App\Support\Dockerfiles\DockerfileLinter;

function lintMessages(string $dockerfile, int $port = 3000, array $build = [], array $secrets = []): string
{
    return DockerfileLinter::lint($dockerfile, $port, $build, $secrets)->summary();
}

test('a production multi-stage Dockerfile passes and lists its images for pinning', function (): void {
    $lint = DockerfileLinter::lint(<<<'DF'
        # escape=\
        FROM node:22-bookworm-slim AS deps
        WORKDIR /app
        COPY package.json package-lock.json ./
        RUN --mount=type=cache,target=/root/.npm \
            npm ci
        RUN --mount=type=secret,id=NPM_TOKEN,env=NPM_TOKEN npm run build

        FROM node:22-bookworm-slim
        WORKDIR /app
        COPY --from=deps /app ./
        COPY --from=ghcr.io/astral-sh/uv:0.5.4 /uv /bin/uv
        ENV NODE_ENV=production PORT=3000
        USER node
        EXPOSE 3000/tcp
        CMD ["node", "server.js"]
        DF, 3000, ['NPM_TOKEN']);

    expect($lint->problems)->toBe([])
        ->and(array_column($lint->references, 'reference'))->toBe(['node:22-bookworm-slim', 'node:22-bookworm-slim', 'ghcr.io/astral-sh/uv:0.5.4']);
});

test('base images must be pinnable', function (string $from, string $message): void {
    expect(lintMessages("FROM {$from}\nEXPOSE 3000\nCMD [\"x\"]\n"))->toContain($message);
})->with([
    'latest' => ['node:latest', 'not latest'],
    'untagged' => ['node', 'explicit version tag'],
    'variable' => ['node:${VERSION}', 'not a variable'],
    'platform flag' => ['--platform=$BUILDPLATFORM node:22', '--platform'],
    'invalid' => ['Not/Valid', 'not a valid image reference'],
]);

test('host features are refused', function (string $line, string $message): void {
    expect(lintMessages("FROM node:22\n{$line}\nEXPOSE 3000\nCMD [\"x\"]\n"))->toContain($message);
})->with([
    'host network' => ['RUN --network=host curl x', '--network=host'],
    'insecure' => ['RUN --security=insecure ls', '--security'],
    'ssh mount' => ['RUN --mount=type=ssh git clone x', 'SSH mounts'],
    'remote add' => ['ADD https://example.com/x.tgz /x', 'remote sources'],
    'git add' => ['ADD git@github.com:a/b.git /b', 'remote sources'],
    'keep git dir' => ['ADD --keep-git-dir=true . /src', 'keep-git-dir'],
    'onbuild' => ['ONBUILD RUN ls', 'ONBUILD'],
    'volume' => ['VOLUME /data', 'VOLUME'],
]);

test('secrets never become ARG, ENV or LABEL', function (): void {
    $messages = lintMessages(<<<'DF'
        FROM node:22
        ARG GITHUB_TOKEN
        ENV DB_PASSWORD=hunter2
        ENV SESSION_SECRET=x
        ENV DATABASE_URL=postgres://app:pw@db:5432/app
        ENV STRIPE sk_live_abcdefghijklmnopqrstuv
        LABEL key="AKIAABCDEFGHIJKLMNOP"
        EXPOSE 3000
        CMD ["x"]
        DF, 3000, [], ['DATABASE_URL']);

    expect($messages)
        ->toContain('line 2: ARG GITHUB_TOKEN looks like a secret')
        ->toContain('line 3: ENV DB_PASSWORD looks like a secret')
        ->toContain('line 4: ENV SESSION_SECRET looks like a secret')
        ->toContain("line 5: ENV DATABASE_URL: this is one of the service's secrets")
        ->toContain('line 6: ENV STRIPE has a value that looks like a credential')
        ->toContain('line 7: A LABEL contains');
});

test('secret mounts only for the service’s build variables', function (): void {
    expect(lintMessages("FROM node:22\nRUN --mount=type=secret,id=AWS_KEY cat /run/secrets/AWS_KEY\nEXPOSE 3000\nCMD [\"x\"]\n", 3000, ['NPM_TOKEN']))
        ->toContain('not one of this service\'s build-time variables');
});

test('the final stage must expose the port and start something', function (): void {
    expect(lintMessages("FROM node:22 AS build\nEXPOSE 3000\nCMD [\"x\"]\nFROM node:22\nEXPOSE 8080\n"))
        ->toContain('needs a CMD or ENTRYPOINT')
        ->toContain('must EXPOSE 3000');
});

test('parser directives, heredocs and continuations parse like BuildKit', function (): void {
    expect(lintMessages("# syntax=docker/dockerfile:1\nFROM node:22\nEXPOSE 3000\nCMD [\"x\"]\n"))->toContain('# syntax=');

    $lint = DockerfileLinter::lint(<<<'DF'
        FROM python:3.13-slim
        RUN <<EOF
        VOLUME this is script text, not an instruction
        pip install -r requirements.txt
        EOF
        RUN apt-get update \
            # a comment inside a continuation
            && apt-get install -y curl
        EXPOSE 8000
        CMD ["python", "app.py"]
        DF, 8000);

    expect($lint->problems)->toBe([]);
});

test('size and shape limits', function (): void {
    expect(lintMessages(str_repeat('a', 70000)))->toContain('larger than 64 KiB')
        ->and(lintMessages("EXPOSE 3000\n"))->toContain('before the first FROM')
        ->and(lintMessages("RUN ls\n"))->toContain('no FROM');
});
