import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex h-5 items-center justify-center rounded-md border px-1.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:ring-2 focus-visible:ring-ring aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        // Chips (design-system §5): quiet neutral by default.
        default:
          "border-transparent bg-hover text-foreground [a&]:hover:bg-border",
        secondary:
          "border-transparent bg-hover text-muted-foreground [a&]:hover:text-foreground",
        outline:
          "border-border-strong text-muted-foreground [a&]:hover:text-foreground",
        // Status chips: soft background + text; pair with a word, never colour alone.
        ok: "border-transparent bg-status-ok-soft text-status-ok",
        progress: "border-transparent bg-status-progress-soft text-status-progress",
        destructive: "border-transparent bg-status-error-soft text-status-error",
        signal: "border-transparent bg-signal-soft text-signal",
        // Staged: dashed signal outline (dashed = staged).
        staged: "border-dashed border-signal bg-signal-soft text-signal",
        // Inverted, for counts on dark floating bars.
        solid: "border-transparent bg-primary text-primary-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
