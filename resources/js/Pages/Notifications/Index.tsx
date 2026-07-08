import PaginationLinks from '@/Components/PaginationLinks';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Notification, PageProps, PaginatedCollection } from '@/types';
import { Head, router } from '@inertiajs/react';

export default function Index({ notifications }: PageProps<{ notifications: PaginatedCollection<Notification> }>) {
    const readNotification = (notification: Notification) => {
        router.post(route('notifications.read', notification.id), {
            redirect_to: notification.data?.url ?? route('notifications.index'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Notifikasi</h2>}>
            <Head title="Notifikasi" />
            <div className="py-10">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Card>
                        <CardHeader>
                            <CardTitle>Semua Notifikasi</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {notifications.data.length === 0 && <p className="text-sm text-muted-foreground">Belum ada notifikasi.</p>}
                            {notifications.data.map((notification) => (
                                <button key={notification.id} type="button" onClick={() => readNotification(notification)} className="w-full rounded-xl border bg-background p-4 text-left transition hover:bg-muted/40">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-medium text-foreground">{notification.title}</p>
                                            <p className="mt-1 text-sm text-muted-foreground">{notification.message}</p>
                                            <p className="mt-2 text-xs text-muted-foreground">{notification.created_at ?? '-'}</p>
                                        </div>
                                        {!notification.read_at && <span className="mt-1 size-2 rounded-full bg-primary" />}
                                    </div>
                                </button>
                            ))}
                            <PaginationLinks meta={notifications.meta} />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
