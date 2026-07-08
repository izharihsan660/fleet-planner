import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link } from '@inertiajs/react';

export default function Forbidden() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-muted/40 px-6 py-12">
            <Head title="Akses Ditolak" />
            <section className="w-full max-w-lg rounded-2xl border bg-card p-8 text-center shadow-sm">
                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                    <ApplicationLogo className="h-10 w-10 fill-current text-muted-foreground" />
                </div>
                <p className="mt-8 text-sm font-semibold uppercase tracking-wide text-muted-foreground">403</p>
                <h1 className="mt-3 text-2xl font-bold text-foreground">Anda tidak memiliki akses ke halaman ini</h1>
                <p className="mt-4 text-sm leading-6 text-muted-foreground">Silakan kembali ke dashboard atau hubungi administrator jika akses ini seharusnya tersedia untuk role Anda.</p>
                <div className="mt-8">
                    <Link href={route('dashboard')} className="inline-flex items-center rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:bg-primary/80 focus:outline-none">
                        Kembali ke Dashboard
                    </Link>
                </div>
            </section>
        </main>
    );
}