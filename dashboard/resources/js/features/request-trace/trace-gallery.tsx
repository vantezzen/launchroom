import { TRACE_FIXTURES } from './fixtures';
import { TracePathCard } from './trace-view';

/** Every way a request can end, drawn from the sample rays (/dev/design). */
export function TraceGallery() {
    return (
        <div className="flex flex-col gap-8">
            {Object.entries(TRACE_FIXTURES).map(([key, trace]) => (
                <figure key={key} className="flex min-w-0 flex-col gap-3">
                    <figcaption className="flex flex-col gap-0.5">
                        <span className="type-label">{trace.summary}</span>
                        <span className="type-mono-small text-muted-foreground">
                            {key}
                        </span>
                    </figcaption>
                    <TracePathCard trace={trace} />
                </figure>
            ))}
        </div>
    );
}
