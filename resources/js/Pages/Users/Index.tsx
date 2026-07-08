import DangerButton from '@/Components/DangerButton';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, users }: PageProps<{ users: PaginatedCollection<User> }>) { const destroy = (user: User) => { if (confirm(`Delete user ${user.name}?`)) router.delete(route('users.destroy', user.id)); }; return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Users</h2>}><Head title="Users" /><div className="py-10"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><Link href={route('users.create')}><PrimaryButton>Add User</PrimaryButton></Link><Card><CardContent><div className="overflow-x-auto"><Table><TableHeader><TableRow>{['Name','Email','Role','Site','Actions'].map((head) => <TableHead key={head}>{head}</TableHead>)}</TableRow></TableHeader><TableBody>{users.data.map((user) => <TableRow key={user.id}><TableCell className="font-medium text-foreground">{user.name}</TableCell><TableCell>{user.email}</TableCell><TableCell>{user.role}</TableCell><TableCell>{user.site?.name ?? '-'}</TableCell><TableCell><div className="flex flex-wrap gap-2"><Link className="text-sm font-medium text-primary hover:underline" href={route('users.edit', user.id)}>Edit</Link>{auth.user.id !== user.id && <DangerButton onClick={() => destroy(user)}>Delete</DangerButton>}</div></TableCell></TableRow>)}</TableBody></Table></div><PaginationLinks meta={users.meta} /></CardContent></Card></div></div></AuthenticatedLayout>; }
