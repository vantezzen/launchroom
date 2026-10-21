import * as ProgressPrimitive from "@radix-ui/react-progress"
import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Progress bar (design-system §8: widths animate, reduced motion jumps).
 * `tone` picks the fill: foreground by default, `progress` (amber) while
 * deploying, `signal` for single-series data.
 */
function Progress({
  className,
  value,
  tone = "default",
  ...props
}: React.ComponentProps<typeof ProgressPrimitive.Root> & {
  tone?: "default" | "progress" | "signal" | "ok" | "error"
}) {
  return (
    <ProgressPrimitive.Root
      data-slot="progress"
      className={cn(
        "bg-hover relative h-1.5 w-full overflow-hidden rounded-full",
        className
      )}
      value={value}
      {...props}
    >
      <ProgressPrimitive.Indicator
        data-slot="progress-indicator"
        className={cn(
          "h-full w-full flex-1 rounded-full transition-transform duration-slow ease-enter",
          tone === "default" && "bg-foreground",
          tone === "progress" && "bg-status-progress",
          tone === "signal" && "bg-signal",
          tone === "ok" && "bg-status-ok",
          tone === "error" && "bg-status-error"
        )}
        style={{ transform: `translateX(-${100 - (value ?? 0)}%)` }}
      />
    </ProgressPrimitive.Root>
  )
}

export { Progress }
