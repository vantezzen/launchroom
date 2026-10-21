import { createGetUrl } from 'fumadocs-core/source';

export const appName = 'launchroom';
export const siteUrl = 'https://launchroom.vantezzen.io';
export const tagline = 'One place to deploy.';
export const description =
    'launchroom is a free, open-source deploy platform that runs on your own server. Deploy from git, add databases and previews, and add more servers with one command.';

export const docsRoute = '/docs';
export const docsContentRoute = '/llms.mdx/docs';

export const gitConfig = {
    user: 'vantezzen',
    repo: 'launchroom',
    branch: 'main',
};
export const githubUrl = `https://github.com/${gitConfig.user}/${gitConfig.repo}`;
export const licenseUrl = `${githubUrl}/blob/${gitConfig.branch}/LICENSE`;

export const installCommand = 'curl -fsSL https://launchroom.vantezzen.io/install | sh';

/**
 * The demo video slot (components/marketing/demo-video.tsx). Empty: the section
 * is not rendered at all. Set it to an .mp4/.webm URL (or a file in public/) to
 * show the video, e.g. '/video/home-server-joins.mp4' (or set
 * NEXT_PUBLIC_LR_DEMO_VIDEO_URL at build time).
 */
const demoVideoUrl = process.env.NEXT_PUBLIC_LR_DEMO_VIDEO_URL ?? '';
export const demoVideo: { src: string; poster?: string; title: string } | null = demoVideoUrl
    ? { src: demoVideoUrl, title: 'A server at home joins launchroom and takes its first build' }
    : null;

const getContentUrl = createGetUrl(docsContentRoute);

export function getPageMarkdownUrl(page: { slugs: string[]; locale?: string }) {
    const segments = [...page.slugs, 'content.md'];

    return { segments, url: getContentUrl(segments, page.locale) };
}
