import defaultMdxComponents from 'fumadocs-ui/mdx';
import { Accordion, Accordions } from 'fumadocs-ui/components/accordion';
import { Step, Steps } from 'fumadocs-ui/components/steps';
import { Tab, Tabs } from 'fumadocs-ui/components/tabs';
import type { MDXComponents } from 'mdx/types';
import { Screenshot } from '@/components/screenshot';
import { YouTubeVideo } from '@/components/youtube-video';
import { tutorialVideo } from '@/lib/shared';

/** The walkthrough video, for MDX pages: `<TutorialVideo />`. */
function TutorialVideo() {
    return <YouTubeVideo {...tutorialVideo} className="my-6" />;
}

export function getMDXComponents(components?: MDXComponents) {
    return {
        ...defaultMdxComponents,
        Accordion,
        Accordions,
        Step,
        Steps,
        Tab,
        Tabs,
        Screenshot,
        TutorialVideo,
        ...components,
    } satisfies MDXComponents;
}

export const useMDXComponents = getMDXComponents;

declare global {
    type MDXProvidedComponents = ReturnType<typeof getMDXComponents>;
}
