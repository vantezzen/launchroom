/**
 * What an empty Traffic view says (docs/15 §5.4, D109): the cause comes
 * from the server (`TrafficReadinessData`, the services' real statuses);
 * this only words it for what is selected. Pure, for tests.
 */

type Readiness = App.Data.Analytics.TrafficReadinessData;
type Blocker = App.Data.Analytics.TrafficBlockerData;

export type TrafficEmptyCopy = {
    title: string;
    line: string;
    /** Services that should serve and don't, shown as status rows. */
    blockers: Blocker[];
    /** A serving address to visit ("waiting"). */
    address: string | null;
};

const REMEDY_VERB: Record<App.Enums.ServiceRemedy, string> = {
    start: 'Start',
    start_containers: 'Start',
    restart: 'Restart',
    retry_deploy: 'Retry the deploy of',
};

function waiting(range: string, address: string | null): TrafficEmptyCopy {
    return {
        title:
            range === '24h'
                ? 'No requests in the last 24 hours'
                : 'No requests in this range yet',
        line: address
            ? `Requests show up here a minute after they reach ${address}.`
            : 'Requests show up here a minute after they reach one of your domains.',
        blockers: [],
        address,
    };
}

/** "Start web to see traffic." when one fix gets traffic going, else null. */
function fixLine(blockers: Blocker[], canFix: boolean): string | null {
    const [only] = blockers;

    if (!canFix || blockers.length !== 1 || !only.status.remedy) {
        return null;
    }

    return `${REMEDY_VERB[only.status.remedy]} ${only.name} to see traffic.`;
}

export function trafficEmptyCopy({
    readiness,
    service,
    where,
    range,
    canFix,
}: {
    readiness: Readiness;
    /** The selected service, null for all of them. */
    service: { id: string; name: string } | null;
    /** "production", or "production" across projects on the team page. */
    where: string;
    range: string;
    /** May run the status's remedy (deploy permission, project page only). */
    canFix: boolean;
}): TrafficEmptyCopy {
    if (service) {
        const blocker = readiness.notServing.find(
            (b) => b.serviceId === service.id,
        );

        if (blocker) {
            return {
                title: `${service.name} isn’t serving in ${where}`,
                line:
                    fixLine([blocker], canFix) ??
                    'Its requests show up here once it serves again.',
                blockers: [blocker],
                address: null,
            };
        }

        if (!readiness.publicServices.includes(service.id)) {
            return {
                title: `${service.name} has no address`,
                line: 'Only services with a domain get requests from outside. Add one in its settings to see traffic.',
                blockers: [],
                address: null,
            };
        }

        return waiting(range, null);
    }

    switch (readiness.reason) {
        case 'no_address':
            return {
                title: `No service in ${where} has an address yet`,
                line: 'Requests show up here once a service has a domain and serves.',
                blockers: [],
                address: null,
            };
        case 'not_serving':
            return {
                title: `Nothing is running in ${where}`,
                line:
                    fixLine(readiness.notServing, canFix) ??
                    'Requests show up here once a service with an address serves again.',
                blockers: readiness.notServing,
                address: null,
            };
        default:
            return waiting(range, readiness.address);
    }
}
