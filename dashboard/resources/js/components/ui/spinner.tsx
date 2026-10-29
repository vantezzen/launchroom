import { ProgressRing } from "@/components/progress-ring"
import { cn } from "@/lib/utils"

/** Busy state of a button or a loading line: the one spinner shape, in the text colour. */
function Spinner({ className }: { className?: string }) {
  return (
    <span role="status" aria-label="Loading" className="inline-flex shrink-0">
      <ProgressRing tone="current" className={cn("size-4", className)} />
    </span>
  )
}

export { Spinner }
