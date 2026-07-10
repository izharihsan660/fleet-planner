import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { ThemeProvider } from '@/Contexts/ThemeContext';
import { Notification, PageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useMemo, useState } from 'react';

type NotificationSharedProps = {
    notifications?: {
        unread_count: number;
        latest: Notification[];
    };
};

type NavigationItem = {
    label: string;
    href: string;
    active: boolean;
};

const canAccess = (role: string, allowedRoles: string[]): boolean => allowedRoles.includes(role);

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const page = usePage<PageProps & NotificationSharedProps>();
    const user = page.props.auth.user;
    const notifications = page.props.notifications ?? { unread_count: 0, latest: [] };
    const [showingSidebar, setShowingSidebar] = useState(false);

    const mainNavigation = useMemo<NavigationItem[]>(() => {
        if (user.role === 'mekanik') {
            return [
                { label: 'Tugas Saya', href: route('mechanic.tasks'), active: route().current('mechanic.tasks') },
                { label: 'Input KM', href: route('inspections.create'), active: route().current('inspections.create') },
                { label: 'Riwayat Inspeksi', href: route('inspections.index'), active: route().current('inspections.index') },
            ];
        }

        const items: Array<NavigationItem | false> = [
            { label: 'Dashboard', href: route('dashboard'), active: route().current('dashboard') },
            canAccess(user.role, ['superadmin', 'mekanik']) && {
                label: 'Input KM',
                href: route('inspections.create'),
                active: route().current('inspections.create'),
            },
            { label: 'Riwayat Inspeksi', href: route('inspections.index'), active: route().current('inspections.index') },
            { label: 'Work Orders', href: route('work-orders.index'), active: route().current('work-orders.*') },
            canAccess(user.role, ['superadmin', 'spv_ho', 'planner_area']) && {
                label: 'Daftar Kerja',
                href: route('work-list.index'),
                active: route().current('work-list.*'),
            },
            canAccess(user.role, ['superadmin', 'spv_ho']) && {
                label: 'Antrian Approval',
                href: route('approval-queue.index'),
                active: route().current('approval-queue.*'),
            },
            canAccess(user.role, ['superadmin', 'spv_ho', 'planner_area']) && {
                label: 'Pemakaian Tinggi',
                href: route('high-usage.index'),
                active: route().current('high-usage.*'),
            },
            canAccess(user.role, ['superadmin', 'spv_ho', 'planner_area']) && {
                label: 'Proyeksi',
                href: route('projections.index'),
                active: route().current('projections.*'),
            },
            { label: 'Laporan', href: route('reports.index'), active: route().current('reports.*') || route().current('units.history') },
        ];

        return items.filter(Boolean) as NavigationItem[];
    }, [user.role]);

    const masterDataNavigation = useMemo<NavigationItem[]>(() => {
        if (!canAccess(user.role, ['superadmin', 'spv_ho'])) {
            return [];
        }

        return [
            { label: 'Lokasi', href: route('sites.index'), active: route().current('sites.*') },
            { label: 'Region', href: route('regions.index'), active: route().current('regions.*') },
            { label: 'Unit', href: route('units.index'), active: route().current('units.*') && !route().current('units.history') },
            { label: 'Item Perawatan', href: route('planning-items.index'), active: route().current('planning-items.*') },
            { label: 'Pengecualian Interval', href: route('planning-item-overrides.index'), active: route().current('planning-item-overrides.*') },
            { label: 'Import Data', href: route('maintenance-imports.index'), active: route().current('maintenance-imports.*') },
            { label: 'Pengaturan Sistem', href: route('system-thresholds.index'), active: route().current('system-thresholds.*') },
        ];
    }, [user.role]);

    const userNavigation = useMemo<NavigationItem[]>(() => {
        if (user.role !== 'superadmin') {
            return [];
        }

        return [{ label: 'Manajemen Pengguna', href: route('users.index'), active: route().current('users.*') }];
    }, [user.role]);

    const readNotification = (notification: Notification) => {
        router.post(route('notifications.read', notification.id), {
            redirect_to: notification.data?.url ?? route('dashboard'),
        });
    };

    const closeMobileSidebar = () => setShowingSidebar(false);

    const SidebarLink = ({ item }: { item: NavigationItem }) => (
        <Link
            href={item.href}
            onClick={closeMobileSidebar}
            className={[
                'block rounded-lg px-3 py-2 text-sm font-medium transition',
                item.active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            ].join(' ')}
        >
            {item.label}
        </Link>
    );

    const SidebarContent = () => (
        <div className="flex h-full flex-col bg-card text-card-foreground">
            <div className="flex h-16 shrink-0 items-center border-b px-6">
                <Link href={route('dashboard')} onClick={closeMobileSidebar} className="flex items-center gap-3">
                    <ApplicationLogo className="block h-9 w-auto fill-current text-foreground" />
                    <span className="text-sm font-semibold uppercase tracking-wide text-foreground">Fleet Planner</span>
                </Link>
            </div>

            <nav className="flex-1 space-y-6 overflow-y-auto px-4 py-6">
                <div className="space-y-1">
                    {mainNavigation.map((item) => (
                        <SidebarLink key={item.label} item={item} />
                    ))}
                </div>

                {masterDataNavigation.length > 0 && (
                    <div>
                        <div className="px-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Master Data</div>
                        <div className="mt-2 space-y-1">
                            {masterDataNavigation.map((item) => (
                                <SidebarLink key={item.label} item={item} />
                            ))}
                        </div>
                    </div>
                )}

                {userNavigation.length > 0 && (
                    <div>
                        <div className="px-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Administration</div>
                        <div className="mt-2 space-y-1">
                            {userNavigation.map((item) => (
                                <SidebarLink key={item.label} item={item} />
                            ))}
                        </div>
                    </div>
                )}
            </nav>
        </div>
    );

    return (
        <ThemeProvider>
            <div className="min-h-screen bg-background lg:flex">
            <aside className="hidden w-72 shrink-0 border-r lg:sticky lg:top-0 lg:flex lg:h-screen">
                <SidebarContent />
            </aside>

            {showingSidebar && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <button
                        type="button"
                        aria-label="Close navigation menu"
                        className="absolute inset-0 bg-gray-900/50"
                        onClick={closeMobileSidebar}
                    />
                    <aside className="relative h-full w-72 max-w-[85vw] shadow-xl">
                        <SidebarContent />
                    </aside>
                </div>
            )}

            <div className="min-w-0 flex-1 lg:ml-0">
                <header className="sticky top-0 z-30 border-b bg-card/95 text-card-foreground backdrop-blur supports-[backdrop-filter]:bg-card/80">
                    <div className="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div className="flex min-w-0 items-center gap-3">
                            <button
                                type="button"
                                onClick={() => setShowingSidebar((previous) => !previous)}
                                className="inline-flex items-center justify-center rounded-md p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground focus:bg-muted focus:outline-none lg:hidden"
                            >
                                <span className="sr-only">Open navigation menu</span>
                                <svg className="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>

                            <div className="truncate">
                                {header ?? <h2 className="text-lg font-semibold leading-tight text-foreground">Dashboard</h2>}
                            </div>
                        </div>

                        <div className="flex items-center gap-3">
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button type="button" className="relative rounded-full p-2 text-muted-foreground hover:bg-muted hover:text-foreground focus:outline-none">
                                        <span className="sr-only">Notifications</span>
                                        <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M10 2a6 6 0 00-6 6v2.586l-.707.707A1 1 0 004 13h12a1 1 0 00.707-1.707L16 10.586V8a6 6 0 00-6-6z" />
                                            <path d="M10 18a3 3 0 01-2.83-2h5.66A3 3 0 0110 18z" />
                                        </svg>
                                        {notifications.unread_count > 0 && <span className="absolute right-0 top-0 rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-semibold text-white">{notifications.unread_count}</span>}
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content width="48" contentClasses="bg-popover py-2 text-popover-foreground">
                                    <div className="px-4 pb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Notifikasi</div>
                                    {notifications.latest.length === 0 && <div className="px-4 py-3 text-sm text-muted-foreground">Belum ada notifikasi.</div>}
                                    {notifications.latest.map((notification) => (
                                        <button key={notification.id} type="button" onClick={() => readNotification(notification)} className="block w-full px-4 py-3 text-left text-sm hover:bg-muted">
                                            <div className="flex items-start justify-between gap-3">
                                                <span className="font-medium text-foreground">{notification.title}</span>
                                                {!notification.read_at && <span className="mt-1 h-2 w-2 rounded-full bg-indigo-500" />}
                                            </div>
                                            <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{notification.message}</p>
                                        </button>
                                    ))}
                                    <Link href={route('notifications.index')} className="block border-t px-4 py-3 text-center text-sm font-medium text-primary hover:bg-muted">
                                        Lihat semua notifikasi
                                    </Link>
                                </Dropdown.Content>
                            </Dropdown>

                            <Dropdown>
                                <Dropdown.Trigger>
                                    <span className="inline-flex rounded-md">
                                        <button type="button" className="inline-flex max-w-40 items-center truncate rounded-md border border-transparent bg-card px-3 py-2 text-sm font-medium leading-4 text-muted-foreground transition duration-150 ease-in-out hover:bg-muted hover:text-foreground focus:outline-none sm:max-w-none">
                                            <span className="truncate">{user.name}</span>
                                            <svg className="-me-0.5 ms-2 h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                            </svg>
                                        </button>
                                    </span>
                                </Dropdown.Trigger>

                                <Dropdown.Content>
                                    <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">Log Out</Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                </header>

                <main>{children}</main>
            </div>
            </div>
        </ThemeProvider>
    );
}
