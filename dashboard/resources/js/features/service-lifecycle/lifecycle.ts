import { Play, RefreshCw, Rocket, RotateCw, Square } from 'lucide-react';

/**
 * One icon and one consequence per service lifecycle action, shared by every
 * place that offers them (design-system §13.4): the service panel header,
 * the canvas node menu, the Deployments tab and ⌘K.
 */
export const LIFECYCLE_ICON = {
    deploy: Rocket,
    redeploy: RefreshCw,
    restart: RotateCw,
    stop: Square,
    start: Play,
} as const;

export const LIFECYCLE_CONSEQUENCE = {
    redeploy:
        'A new deployment of the running version with the configuration deployed last. Staged changes stay staged.',
    restart:
        'New containers of the running version replace the current ones, one replica at a time when there are several.',
    stop: 'Its containers stop and its domains show a stopped page. Settings, domains, volumes and deployments stay.',
    start: 'It runs the version that was live when it stopped, with the configuration deployed last.',
} as const;
