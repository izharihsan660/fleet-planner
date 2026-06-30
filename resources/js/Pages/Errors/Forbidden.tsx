import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link } from '@inertiajs/react';

export default function Forbidden() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-gray-100 px-6 py-12">
            <Head title="Akses Ditolak" />
            <section className="w-full max-w-lg rounded-2xl bg-white p-8 text-center shadow-sm">
                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gray-50">
                    <ApplicationLogo className="h-10 w-10 fill-current text-gray-500" />
                </div>
                <p className="mt-8 text-sm font-semibold uppercase tracking-wide text-gray-500">403</p>
                <h1 className="mt-3 text-2xl font-bold text-gray-900">Anda tidak memiliki akses ke halaman ini</h1>
                <p className="mt-4 text-sm leading-6 text-gray-600">Silakan kembali ke dashboard atau hubungi administrator jika akses ini seharusnya tersedia untuk role Anda.</p>
                <div className="mt-8">
                    <Link href={route('dashboard')} className="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2">
                        Kembali ke Dashboard
                    </Link>
                </div>
            </section>
        </main>
    );
}