import type { Metadata, Viewport } from 'next';
import { GeistMono } from 'geist/font/mono';
import { GeistSans } from 'geist/font/sans';
import { Provider } from '@/components/provider';
import { appName, description, siteUrl, tagline } from '@/lib/shared';
import './global.css';

export const metadata: Metadata = {
    metadataBase: new URL(siteUrl),
    title: {
        default: `${appName}: ${tagline}`,
        template: `%s · ${appName}`,
    },
    description,
    applicationName: appName,
    openGraph: {
        type: 'website',
        siteName: appName,
        title: `${appName}: ${tagline}`,
        description,
        url: siteUrl,
        images: [{ url: '/og.png', width: 1200, height: 630, alt: `${appName}: ${tagline}` }],
    },
    twitter: { card: 'summary_large_image', images: ['/og.png'] },
};

export const viewport: Viewport = {
    themeColor: [
        { media: '(prefers-color-scheme: light)', color: '#ffffff' },
        { media: '(prefers-color-scheme: dark)', color: '#0a0a0a' },
    ],
};

export default function Layout({ children }: LayoutProps<'/'>) {
    return (
        <html lang="en" className={`${GeistSans.variable} ${GeistMono.variable}`} suppressHydrationWarning>
            <body className="flex min-h-screen flex-col">
                <Provider>{children}</Provider>
            </body>
        </html>
    );
}
