<?php
namespace App\Services\DeploymentTemplates;

class NextjsDeploymentTemplate extends DeploymentTemplate
{
  public function isResponsible(): bool {
    if (!file_exists($this->baseDirectory . '/package.json')) {
      return false;
    }

    $package = json_decode(file_get_contents($this->baseDirectory . '/package.json'), true);
    $containsNextjs = isset($package['dependencies']['next']);
    $containsReact = isset($package['dependencies']['react']);

    return $containsNextjs && $containsReact;
  }

  public function addDeploymentFiles(): void {
    // TODO: Add env

    // Add standalone mode to next.config.js/next.config.mjs/next.config.ts
    $nextConfigFiles = glob($this->baseDirectory . '/next.config.*');
    if (count($nextConfigFiles) === 0) {
      $nextConfig = <<<NEXTCONFIG
module.exports = {
  output: 'standalone',
};
NEXTCONFIG;

      file_put_contents($this->baseDirectory . '/next.config.js', $nextConfig);
    } else {
      foreach ($nextConfigFiles as $nextConfigFile) {
        $nextConfig = file_get_contents($nextConfigFile);
        
        if (!str_contains($nextConfig, 'output:')) {
          
          if (str_contains($nextConfig, 'module.exports = {')) {
            $nextConfig = str_replace('module.exports = {', 'module.exports = { output: \'standalone\',', $nextConfig);
          } else if (str_contains($nextConfig, 'export default {')) {
            $nextConfig = str_replace('export default {', 'export default { output: \'standalone\',', $nextConfig);
          } else if (str_contains($nextConfig, 'const nextConfig = {')) {
            $nextConfig = str_replace('const nextConfig = {', 'const nextConfig = { output: \'standalone\',', $nextConfig);
          } else {
            throw new \Exception('Could not find a valid next.config.js export format. Please add `output: \'standalone\'` manually if you have customized your next config.');
          }

          file_put_contents($nextConfigFile, $nextConfig);
        }

      }
    }

    // Add Dockerfile
    $dockerfile = <<<DOCKERFILE
# syntax=docker.io/docker/dockerfile:1
# Based on https://github.com/vercel/next.js/blob/canary/examples/with-docker/Dockerfile
FROM node:20-alpine AS base

# Install dependencies only when needed
FROM base AS deps
# Check https://github.com/nodejs/docker-node/tree/b4117f9333da4138b03a546ec926ef50a31506c3#nodealpine to understand why libc6-compat might be needed.
RUN apk add --no-cache libc6-compat
WORKDIR /app

# Install dependencies based on the preferred package manager
COPY package.json yarn.lock* package-lock.json* pnpm-lock.yaml* .npmrc* ./
RUN \
  if [ -f yarn.lock ]; then yarn --frozen-lockfile; \
  elif [ -f package-lock.json ]; then npm ci; \
  elif [ -f pnpm-lock.yaml ]; then corepack enable pnpm && pnpm i --frozen-lockfile; \
  else echo "Lockfile not found." && exit 1; \
  fi


# Rebuild the source code only when needed
FROM base AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .

RUN \
  if [ -f yarn.lock ]; then yarn run build; \
  elif [ -f package-lock.json ]; then npm run build; \
  elif [ -f pnpm-lock.yaml ]; then corepack enable pnpm && pnpm run build; \
  else echo "Lockfile not found." && exit 1; \
  fi

# Production image, copy all the files and run next
FROM base AS runner
WORKDIR /app

ENV NODE_ENV=production
# Uncomment the following line in case you want to disable telemetry during runtime.
# ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --system --gid 1001 nodejs
RUN adduser --system --uid 1001 nextjs

COPY --from=builder /app/public ./public

# Automatically leverage output traces to reduce image size
# https://nextjs.org/docs/advanced-features/output-file-tracing
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs

EXPOSE 3000

ENV PORT=3000

# server.js is created by next build from the standalone output
# https://nextjs.org/docs/pages/api-reference/config/next-config-js/output
ENV HOSTNAME="0.0.0.0"
CMD ["node", "server.js"]
DOCKERFILE;

    file_put_contents($this->baseDirectory . '/Dockerfile', $dockerfile);
  }

  public function getDockerComposeContents(): array {
    return [
      'services' => [
        'app' => [
          'build' => '.',
          'container_name' => 'app',
          'restart' => 'unless-stopped',
          
        ],
      ],
    ];
  }
}