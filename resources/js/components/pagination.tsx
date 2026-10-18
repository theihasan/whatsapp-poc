import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

export default function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pagination"
            className="flex items-center justify-between gap-4 text-sm text-muted-foreground"
        >
            <span>
                {page.from}–{page.to} of {page.total}
            </span>
            <div className="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    asChild
                    disabled={!page.prev_page_url}
                >
                    {page.prev_page_url ? (
                        <Link href={page.prev_page_url} preserveScroll>
                            Previous
                        </Link>
                    ) : (
                        <span aria-disabled>Previous</span>
                    )}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    asChild
                    disabled={!page.next_page_url}
                >
                    {page.next_page_url ? (
                        <Link href={page.next_page_url} preserveScroll>
                            Next
                        </Link>
                    ) : (
                        <span aria-disabled>Next</span>
                    )}
                </Button>
            </div>
        </nav>
    );
}
