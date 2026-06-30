import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Notification, PageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState } from 'react';

type NotificationSharedProps = {
    notifications?: {
        unread_count: number;
        latest: Notification[];
    };
};

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const page = usePage<PageProps & NotificationSharedProps>();
    const user = page.props.auth.user;
    const notifications = page.props.notifications ?? { unread_count: 0, latest: [] };
    const canViewHighUsage = ['superadmin', 'planner_ho', 'admin_site', 'spv_ops'].includes(user.role);
    const canViewProjections = ['superadmin', 'planner_ho', 'admin_site', 'spv_ops', 'logistik'].includes(user.role);
    const canViewReports = ['superadmin', 'planner_ho', 'admin_site', 'spv_ops', 'logistik', 'mekanik'].includes(user.role);

    const [showingNavigationDropdown, setShowingNavigationDropdown] = useState(false);

    const readNotification = (notification: Notification) => {
        router.post(route('notifications.read', notification.id), {
            redirect_to: notification.data?.url ?? route('dashboard'),
        });
    };

    return (
        <div className="min-h-screen bg-gray-100">
            <nav className="border-b border-gray-100 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex shrink-0 items-center">
                                <Link href="/">
                                    <ApplicationLogo className="block h-9 w-auto fill-current text-gray-800" />
                                </Link>
                            </div>

                            <div className="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink href={route('dashboard')} active={route().current('dashboard')}>Dashboard</NavLink>
                                <NavLink href={route('work-orders.index')} active={route().current('work-orders.*')}>Work Orders</NavLink>
                                {canViewHighUsage && <NavLink href={route('high-usage.index')} active={route().current('high-usage.*')}>High Usage</NavLink>}
                                {canViewProjections && <NavLink href={route('projections.index')} active={route().current('projections.*')}>Projections</NavLink>}
                                {canViewReports && <NavLink href={route('reports.index')} active={route().current('reports.*') || route().current('units.history')}>Reports</NavLink>}
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center sm:gap-3">
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button type="button" className="relative rounded-full p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none">
                                        <span className="sr-only">Notifications</span>
                                        <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M10 2a6 6 0 00-6 6v2.586l-.707.707A1 1 0 004 13h12a1 1 0 00.707-1.707L16 10.586V8a6 6 0 00-6-6z" />
                                            <path d="M10 18a3 3 0 01-2.83-2h5.66A3 3 0 0110 18z" />
                                        </svg>
                                        {notifications.unread_count > 0 && <span className="absolute right-0 top-0 rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-semibold text-white">{notifications.unread_count}</span>}
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content width="48" contentClasses="bg-white py-2">
                                    <div className="px-4 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Notifikasi</div>
                                    {notifications.latest.length === 0 && <div className="px-4 py-3 text-sm text-gray-500">Belum ada notifikasi.</div>}
                                    {notifications.latest.map((notification) => (
                                        <button key={notification.id} type="button" onClick={() => readNotification(notification)} className="block w-full px-4 py-3 text-left text-sm hover:bg-gray-50">
                                            <div className="flex items-start justify-between gap-3">
                                                <span className="font-medium text-gray-900">{notification.title}</span>
                                                {!notification.read_at && <span className="mt-1 h-2 w-2 rounded-full bg-indigo-600" />}
                                            </div>
                                            <p className="mt-1 line-clamp-2 text-xs text-gray-500">{notification.message}</p>
                                        </button>
                                    ))}
                                </Dropdown.Content>
                            </Dropdown>

                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-md">
                                            <button type="button" className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none">
                                                {user.name}
                                                <svg className="-me-0.5 ms-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
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

                        <div className="-me-2 flex items-center sm:hidden">
                            <button onClick={() => setShowingNavigationDropdown((previous) => !previous)} className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none">
                                <svg className="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path className={!showingNavigationDropdown ? 'inline-flex' : 'hidden'} strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                                    <path className={showingNavigationDropdown ? 'inline-flex' : 'hidden'} strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div className={(showingNavigationDropdown ? 'block' : 'hidden') + ' sm:hidden'}>
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink href={route('dashboard')} active={route().current('dashboard')}>Dashboard</ResponsiveNavLink>
                        <ResponsiveNavLink href={route('work-orders.index')} active={route().current('work-orders.*')}>Work Orders</ResponsiveNavLink>
                        {canViewHighUsage && <ResponsiveNavLink href={route('high-usage.index')} active={route().current('high-usage.*')}>High Usage</ResponsiveNavLink>}
                        {canViewProjections && <ResponsiveNavLink href={route('projections.index')} active={route().current('projections.*')}>Projections</ResponsiveNavLink>}
                        {canViewReports && <ResponsiveNavLink href={route('reports.index')} active={route().current('reports.*') || route().current('units.history')}>Reports</ResponsiveNavLink>}
                    </div>

                    <div className="border-t border-gray-200 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800">{user.name}</div>
                            <div className="text-sm font-medium text-gray-500">{user.email}</div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>Profile</ResponsiveNavLink>
                            <ResponsiveNavLink method="post" href={route('logout')} as="button">Log Out</ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && <header className="bg-white shadow"><div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">{header}</div></header>}

            <main>{children}</main>
        </div>
    );
}
