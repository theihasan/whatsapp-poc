import { useState } from 'react';

type Day = { date: string; incoming: number; outgoing: number };

const SERIES = [
    { key: 'incoming', label: 'Incoming', color: 'var(--series-incoming)' },
    { key: 'outgoing', label: 'Outgoing', color: 'var(--series-outgoing)' },
] as const;

const HEIGHT = 180;
const BAR_WIDTH = 8;
const GAP = 2;
const day = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
});

function niceMax(value: number): number {
    if (value <= 4) {
        return 4;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));

    return Math.ceil(value / magnitude) * magnitude;
}

/**
 * Grouped columns per day (one y-axis, two validated categorical hues), with a legend,
 * hover tooltip and an equivalent data table.
 */
export default function ActivityChart({ days }: { days: Day[] }) {
    const [hovered, setHovered] = useState<number | null>(null);
    const [showTable, setShowTable] = useState(false);
    const max = niceMax(
        Math.max(
            0,
            ...days.flatMap((entry) => [entry.incoming, entry.outgoing]),
        ),
    );
    const slot = 2 * BAR_WIDTH + GAP + 14;
    const width = days.length * slot;
    const scale = (value: number) => (value / max) * HEIGHT;
    const active = hovered === null ? null : days[hovered];

    return (
        <div className="viz-root space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <ul
                    className="flex gap-4 text-xs text-muted-foreground"
                    aria-label="Legend"
                >
                    {SERIES.map((series) => (
                        <li
                            key={series.key}
                            className="flex items-center gap-1.5"
                        >
                            <span
                                aria-hidden
                                className="size-2.5 rounded-sm"
                                style={{ background: series.color }}
                            />
                            {series.label}
                        </li>
                    ))}
                </ul>
                <button
                    type="button"
                    onClick={() => setShowTable((value) => !value)}
                    className="text-xs text-muted-foreground underline underline-offset-4"
                >
                    {showTable ? 'Show chart' : 'Show table'}
                </button>
            </div>

            {showTable ? (
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="py-1 font-medium">Day</th>
                            <th className="py-1 text-right font-medium">
                                Incoming
                            </th>
                            <th className="py-1 text-right font-medium">
                                Outgoing
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y tabular-nums">
                        {days.map((entry) => (
                            <tr key={entry.date}>
                                <td className="py-1">
                                    {day.format(
                                        new Date(`${entry.date}T00:00:00`),
                                    )}
                                </td>
                                <td className="py-1 text-right">
                                    {entry.incoming}
                                </td>
                                <td className="py-1 text-right">
                                    {entry.outgoing}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <div className="relative overflow-x-auto">
                    <svg
                        viewBox={`-28 -8 ${width + 36} ${HEIGHT + 32}`}
                        className="h-56 w-full min-w-[480px]"
                        role="img"
                        aria-label={`Messages per day for the last ${days.length} days`}
                        onMouseLeave={() => setHovered(null)}
                    >
                        {[0, max / 2, max].map((tick) => (
                            <g key={tick}>
                                <line
                                    x1={0}
                                    x2={width}
                                    y1={HEIGHT - scale(tick)}
                                    y2={HEIGHT - scale(tick)}
                                    className="stroke-border"
                                    strokeWidth={1}
                                />
                                <text
                                    x={-6}
                                    y={HEIGHT - scale(tick)}
                                    textAnchor="end"
                                    dominantBaseline="middle"
                                    className="fill-muted-foreground text-[10px] tabular-nums"
                                >
                                    {tick}
                                </text>
                            </g>
                        ))}
                        {days.map((entry, index) => {
                            const x = index * slot + 7;

                            return (
                                <g
                                    key={entry.date}
                                    onMouseEnter={() => setHovered(index)}
                                >
                                    <rect
                                        x={index * slot}
                                        y={0}
                                        width={slot}
                                        height={HEIGHT}
                                        fill="transparent"
                                    />
                                    {SERIES.map((series, seriesIndex) => {
                                        const value = entry[series.key];
                                        const height = Math.max(
                                            value > 0 ? 2 : 0,
                                            scale(value),
                                        );

                                        return (
                                            <path
                                                key={series.key}
                                                d={roundedTop(
                                                    x +
                                                        seriesIndex *
                                                            (BAR_WIDTH + GAP),
                                                    HEIGHT - height,
                                                    BAR_WIDTH,
                                                    height,
                                                )}
                                                fill={series.color}
                                                opacity={
                                                    hovered === null ||
                                                    hovered === index
                                                        ? 1
                                                        : 0.45
                                                }
                                            />
                                        );
                                    })}
                                    {(index % 2 === 0 || days.length <= 7) && (
                                        <text
                                            x={x + BAR_WIDTH}
                                            y={HEIGHT + 16}
                                            textAnchor="middle"
                                            className="fill-muted-foreground text-[10px]"
                                        >
                                            {day.format(
                                                new Date(
                                                    `${entry.date}T00:00:00`,
                                                ),
                                            )}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>
                    {active && (
                        <div
                            role="status"
                            className="pointer-events-none absolute top-0 rounded-md border bg-popover px-3 py-2 text-xs shadow-md"
                            style={{
                                left: `min(calc(${((hovered ?? 0) * slot + 36) / (width + 36)} * 100%), calc(100% - 9rem))`,
                            }}
                        >
                            <p className="font-medium">
                                {day.format(
                                    new Date(`${active.date}T00:00:00`),
                                )}
                            </p>
                            {SERIES.map((series) => (
                                <p
                                    key={series.key}
                                    className="flex items-center gap-1.5 tabular-nums"
                                >
                                    <span
                                        aria-hidden
                                        className="size-2 rounded-sm"
                                        style={{ background: series.color }}
                                    />
                                    {series.label}: {active[series.key]}
                                </p>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function roundedTop(
    x: number,
    y: number,
    width: number,
    height: number,
): string {
    if (height <= 0) {
        return '';
    }

    const r = Math.min(4, height, width / 2);

    return `M${x},${y + height} V${y + r} Q${x},${y} ${x + r},${y} H${x + width - r} Q${x + width},${y} ${x + width},${y + r} V${y + height} Z`;
}
