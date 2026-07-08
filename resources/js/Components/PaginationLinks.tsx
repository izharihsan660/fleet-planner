import { Link } from '@inertiajs/react';

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginationMeta = {
    from: number | null;
    to: number | null;
    total: number;
    links: PaginationLink[];
};

export default function PaginationLinks({ meta }: { meta?: PaginationMeta }) {
    if (!meta || meta.total === 0 || meta.links.length <= 3) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t px-1 pt-4 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
            <p>
                Menampilkan {meta.from ?? 0}-{meta.to ?? 0} dari {meta.total} data
            </p>
            <div className="flex flex-wrap gap-2">
                {meta.links.map((link) => {
                    const label = link.label.replace('&laquo; Previous', 'Sebelumnya').replace('Next &raquo;', 'Berikutnya');

                    if (!link.url) {
                        return (
                            <span key={link.label} className="rounded-lg border px-3 py-1.5 text-muted-foreground/50">
                                {label}
                            </span>
                        );
                    }

                    return (
                        <Link
                            key={`${link.label}-${link.url}`}
                            href={link.url}
                            preserveScroll
                            preserveState
                            className={`rounded-lg border px-3 py-1.5 font-medium transition ${link.active ? 'bg-primary text-primary-foreground' : 'bg-background text-foreground hover:bg-muted'}`}
                        >
                            {label}
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
