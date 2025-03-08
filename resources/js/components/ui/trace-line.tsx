"use client";

import { motion } from "motion/react";
import { RefObject, useEffect, useId, useState } from "react";
import { cn } from "@/lib/utils";

export interface TraceLineProps {
  className?: string;
  containerRef: RefObject<HTMLElement | null>;
  fromRef: RefObject<HTMLElement | null>;
  toRef: RefObject<HTMLElement | null>;
  /** Desired corner radius (default 10) */
  cornerRadius?: number;
  reverse?: boolean;
  pathColor?: string;
  pathWidth?: number;
  pathOpacity?: number;
  gradientStartColor?: string;
  gradientStopColor?: string;
  delay?: number;
  duration?: number;
  startXOffset?: number;
  startYOffset?: number;
  endXOffset?: number;
  endYOffset?: number;
}

export const TraceLine: React.FC<TraceLineProps> = ({
  className,
  containerRef,
  fromRef,
  toRef,
  cornerRadius = 10,
  reverse = false,
  duration = Math.random() * 3 + 4,
  delay = 0,
  pathColor = "gray",
  pathWidth = 2,
  pathOpacity = 0.2,
  gradientStartColor = "#ffaa40",
  gradientStopColor = "#9c40ff",
  startXOffset = 0,
  startYOffset = 0,
  endXOffset = 0,
  endYOffset = 0,
}) => {
  const id = useId();
  const [pathD, setPathD] = useState("");
  const [svgDimensions, setSvgDimensions] = useState({ width: 0, height: 0 });

  // Gradient animation settings
  const gradientCoordinates = reverse
    ? {
        x1: ["90%", "-10%"],
        x2: ["100%", "0%"],
        y1: ["0%", "0%"],
        y2: ["0%", "0%"],
      }
    : {
        x1: ["10%", "110%"],
        x2: ["0%", "100%"],
        y1: ["0%", "0%"],
        y2: ["0%", "0%"],
      };

  useEffect(() => {
    const updatePath = () => {
      if (containerRef.current && fromRef.current && toRef.current) {
        const containerRect = containerRef.current.getBoundingClientRect();
        const rectA = fromRef.current.getBoundingClientRect();
        const rectB = toRef.current.getBoundingClientRect();

        // Set the SVG dimensions based on the container.
        const svgWidth = containerRect.width;
        const svgHeight = containerRect.height;
        setSvgDimensions({ width: svgWidth, height: svgHeight });

        // Starting point: center right edge of fromRef.
        const startX = rectA.left - containerRect.left + rectA.width + startXOffset;
        const startY = rectA.top - containerRect.top + rectA.height / 2 + startYOffset;

        // End point: center left edge of toRef.
        const toX = rectB.left - containerRect.left + endXOffset;
        const toY = rectB.top - containerRect.top + rectB.height / 2 + endYOffset;

        // Midpoint X: halfway between startX and toX.
        const midX = (startX + toX) / 2;

        // Determine vertical difference and sign.
        const deltaY = toY - startY;
        const sign = deltaY >= 0 ? 1 : -1;

        // Compute available space for a rounded corner.
        // Ensure the corner radius does not exceed half the vertical gap or half the horizontal gap.
        const horizontalAvailable = (toX - startX) / 2;
        const verticalAvailable = Math.abs(deltaY) / 2;
        const effR = Math.min(cornerRadius, horizontalAvailable, verticalAvailable);

        let path = "";
        if (effR > 0) {
          // Build a path with two rounded corners.
          path = [
            // Horizontal line from start to (midX - effR)
            `M ${startX},${startY}`,
            `H ${midX - effR}`,
            // First corner: arc from (midX - effR, startY) to (midX, startY + effR * sign)
            `A ${effR},${effR} 0 0,${sign > 0 ? 1 : 0} ${midX},${startY + effR * sign}`,
            // Vertical line from (midX, startY + effR * sign) to (midX, toY - effR * sign)
            `V ${toY - effR * sign}`,
            // Second corner: arc from (midX, toY - effR * sign) to (midX + effR, toY)
            `A ${effR},${effR} 0 0,${sign < 0 ? 1 : 0} ${midX + effR},${toY}`,
            // Final horizontal segment into the toRef element
            `H ${toX}`,
          ].join(" ");
        } else {
          // When no rounding is needed, just connect with straight lines.
          path = [
            `M ${startX},${startY}`,
            `H ${midX}`,
            `V ${toY}`,
            `H ${toX}`,
          ].join(" ");
        }

        setPathD(path);
      }
    };

    updatePath();

    const resizeObserver = new ResizeObserver(() => {
      updatePath();
    });

    if (containerRef.current) {
      resizeObserver.observe(containerRef.current);
    }

    return () => {
      resizeObserver.disconnect();
    };
  }, [
    containerRef,
    fromRef,
    toRef,
    cornerRadius,
    startXOffset,
    startYOffset,
    endXOffset,
    endYOffset,
  ]);

  return (
    <svg
      fill="none"
      width={svgDimensions.width}
      height={svgDimensions.height}
      xmlns="http://www.w3.org/2000/svg"
      className={cn(
        "pointer-events-none absolute left-0 top-0 transform-gpu stroke-2",
        className,
      )}
      viewBox={`0 0 ${svgDimensions.width} ${svgDimensions.height}`}
    >
      <path
        d={pathD}
        stroke={pathColor}
        strokeWidth={pathWidth}
        strokeOpacity={pathOpacity}
        strokeLinecap="round"
      />
      <path
        d={pathD}
        strokeWidth={pathWidth}
        stroke={`url(#${id})`}
        strokeOpacity="1"
        strokeLinecap="round"
      />
      <defs>
        <motion.linearGradient
          className="transform-gpu"
          id={id}
          gradientUnits="userSpaceOnUse"
          initial={{
            x1: "0%",
            x2: "0%",
            y1: "0%",
            y2: "0%",
          }}
          animate={{
            x1: gradientCoordinates.x1,
            x2: gradientCoordinates.x2,
            y1: gradientCoordinates.y1,
            y2: gradientCoordinates.y2,
          }}
          transition={{
            delay,
            duration,
            ease: [0.16, 1, 0.3, 1],
            repeat: Infinity,
            repeatDelay: 0,
          }}
        >
          <stop stopColor={gradientStartColor} stopOpacity="0" />
          <stop stopColor={gradientStartColor} />
          <stop offset="32.5%" stopColor={gradientStopColor} />
          <stop offset="100%" stopColor={gradientStopColor} stopOpacity="0" />
        </motion.linearGradient>
      </defs>
    </svg>
  );
};
