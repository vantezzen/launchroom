import { ArrowDown, ArrowUp } from 'lucide-react';
import type { RefObject } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

/**
 * Live log follow, shared by the deployment log viewer and the log
 * explorer (docs/08 §4): while new lines arrive the view stays on the
 * newest one; scrolling away from it pauses following, scrolling back to
 * it (or "Jump to latest") resumes. `edge` is where the newest line is:
 * the end (deployment logs, oldest first) or the start (the explorer,
 * newest first).
 */
export type FollowEdge = 'start' | 'end';

/** How close (px) to the edge still counts as "at the newest line". */
export const EDGE_SLACK = 24;

type ScrollBox = {
    scrollTop: number;
    scrollHeight: number;
    clientHeight: number;
};

export function atEdge(box: ScrollBox, edge: FollowEdge): boolean {
    return edge === 'end'
        ? box.scrollTop + box.clientHeight >= box.scrollHeight - EDGE_SLACK
        : box.scrollTop <= EDGE_SLACK;
}

/**
 * Follow state after a scroll event. Only a scroll *away* from the edge
 * pauses (the reader scrolled up through the history); programmatic
 * scrolls towards it and rows that grow while being measured never do.
 */
export function followAfterScroll(
    follow: boolean,
    previousTop: number,
    box: ScrollBox,
    edge: FollowEdge,
): { follow: boolean; atEdge: boolean } {
    const onEdge = atEdge(box, edge);

    if (onEdge) {
        return { follow: true, atEdge: true };
    }

    const away =
        edge === 'end'
            ? box.scrollTop < previousTop - 1
            : box.scrollTop > previousTop + 1;

    return { follow: away ? false : follow, atEdge: false };
}

export function useFollowScroll({
    scrollRef,
    count,
    live,
    initial = live,
    edge = 'end',
    scrollToEdge,
}: {
    scrollRef: RefObject<HTMLElement | null>;
    /** Number of rows; growth while following scrolls to the newest. */
    count: number;
    /** New lines are still arriving. */
    live: boolean;
    /** Start following (default: when live). */
    initial?: boolean;
    edge?: FollowEdge;
    /** Brings the newest row into view (the virtualizer's scrollToIndex). */
    scrollToEdge: () => void;
}) {
    const [follow, setFollow] = useState(initial);
    const [onEdge, setOnEdge] = useState(true);
    const [unseen, setUnseen] = useState(0);
    const previousTop = useRef(0);
    const previousCount = useRef(count);
    const toEdge = useRef(scrollToEdge);

    useEffect(() => {
        toEdge.current = scrollToEdge;
    });

    // New output: follow it, or count what the paused reader has not seen.
    useEffect(() => {
        const added = count - previousCount.current;
        previousCount.current = count;

        if (!live) {
            return;
        }

        if (follow) {
            if (count > 0) {
                toEdge.current();
            }

            setUnseen(0);
        } else if (added > 0) {
            setUnseen((current) => current + added);
        }
    }, [count, follow, live]);

    const onScroll = useCallback(() => {
        const element = scrollRef.current;

        if (!element) {
            return;
        }

        const next = followAfterScroll(
            follow,
            previousTop.current,
            element,
            edge,
        );
        previousTop.current = element.scrollTop;
        setOnEdge(next.atEdge);

        if (next.atEdge) {
            setUnseen(0);
        }

        if (live && next.follow !== follow) {
            setFollow(next.follow);
        }
    }, [edge, follow, live, scrollRef]);

    const jumpToLatest = useCallback(() => {
        toEdge.current();
        setUnseen(0);

        if (live) {
            setFollow(true);
        }
    }, [live]);

    return {
        follow: live && follow,
        setFollow,
        atEdge: onEdge,
        unseen,
        onScroll,
        jumpToLatest,
    };
}

/**
 * The floating "Jump to latest" button over a scrolled-away log, with the
 * number of lines that arrived meanwhile.
 */
export function JumpToLatest({
    visible,
    live,
    unseen,
    edge = 'end',
    onJump,
}: {
    visible: boolean;
    live: boolean;
    unseen: number;
    edge?: FollowEdge;
    onJump: () => void;
}) {
    if (!visible) {
        return null;
    }

    const Icon = edge === 'end' ? ArrowDown : ArrowUp;

    return (
        <Button
            size="sm"
            variant="secondary"
            className={
                edge === 'end'
                    ? 'absolute right-4 bottom-4 shadow-sm'
                    : 'absolute top-3 right-4 shadow-sm'
            }
            onClick={onJump}
        >
            <Icon aria-hidden="true" />
            {live
                ? 'Jump to latest'
                : edge === 'end'
                  ? 'Jump to bottom'
                  : 'Jump to top'}
            {live && unseen > 0 && (
                <span className="text-muted-foreground tabular-nums">
                    {unseen > 999 ? '999+' : unseen} new
                </span>
            )}
        </Button>
    );
}
