import { demoVideo } from '@/lib/shared';
import { Container } from './section';

/**
 * The demo video slot. Renders nothing until `demoVideo` in lib/shared.ts (or
 * NEXT_PUBLIC_LR_DEMO_VIDEO_URL at build time) names a video, so the page has
 * no empty frame. The video never autoplays with sound and has native controls.
 */
export function DemoVideo() {
    if (!demoVideo) {
        return null;
    }
    return (
        <section aria-labelledby="demo-title" className="pb-8">
            <Container>
                <h2 id="demo-title" className="sr-only">
                    Demo
                </h2>
                <figure className="m-0 overflow-hidden rounded-sheet border border-border bg-recessed shadow-card">
                    <video
                        className="block aspect-video h-auto w-full"
                        src={demoVideo.src}
                        poster={demoVideo.poster}
                        controls
                        playsInline
                        preload="metadata"
                        aria-label={demoVideo.title}
                    />
                    <figcaption className="border-t border-border bg-card px-5 py-3 text-center type-small text-muted-foreground">
                        {demoVideo.title}
                    </figcaption>
                </figure>
            </Container>
        </section>
    );
}
