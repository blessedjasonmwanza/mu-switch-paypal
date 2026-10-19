import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * Fades and lifts its content in as it scrolls into view, and back out as it
 * leaves, so it animates both scrolling down and scrolling back up. People
 * who prefer reduced motion see the content without animation.
 */
export function Reveal({
    children,
    delay = 0,
    className,
}: {
    children: React.ReactNode;
    /** Milliseconds to wait before animating, to stagger siblings. */
    delay?: number;
    className?: string;
}) {
    const element = useRef<HTMLDivElement | null>(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const node = element.current;

        if (!node || typeof IntersectionObserver === 'undefined') {
            setVisible(true);

            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => setVisible(entry.isIntersecting),
            { rootMargin: '0px 0px -12% 0px', threshold: 0.1 },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={element}
            style={{ transitionDelay: visible ? `${delay}ms` : '0ms' }}
            className={cn(
                'transition-all duration-700 ease-out motion-reduce:translate-y-0 motion-reduce:opacity-100 motion-reduce:transition-none',
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'translate-y-8 opacity-0',
                className,
            )}
        >
            {children}
        </div>
    );
}
