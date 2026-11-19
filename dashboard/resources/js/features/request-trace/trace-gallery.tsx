import { TRACE_FIXTURES } from './fixtures';
import { TracePathDiagram } from './trace-path';

/** Every way a request can end, drawn from the sample traces (/dev/design). */
export function TraceGallery() {
    return (
        <div className="flex flex-col gap-6">
            {Object.entries(TRACE_FIXTURES).map(([key, trace]) => {
                return (
                    <figure
                        key={key}
                        className="flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card shadow-card"
                    >
                        <figcaption className="flex flex-col gap-0.5 px-5 py-3.5">
                            <span className="type-heading">
                                {trace.summary}
                            </span>
                            <span className="type-mono-small text-muted-foreground">
                                {key}
                            </span>
                        </figcaption>
                        <div className="border-t bg-recessed">
                            <TracePathDiagram trace={trace} />
                        </div>
                    </figure>
                );
            })}
        </div>
    );
}
