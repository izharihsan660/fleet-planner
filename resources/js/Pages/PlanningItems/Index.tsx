import DangerButton from '@/Components/DangerButton';
import PaginationLinks from '@/Components/PaginationLinks';
import PrimaryButton from '@/Components/PrimaryButton';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, PaginatedCollection, PlanningItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, planningItems }: PageProps<{ planningItems: PaginatedCollection<PlanningItem> }>) {
    const destroy = (item: PlanningItem) => { if (confirm(`Delete ${item.name}?`)) router.delete(route('planning-items.destroy', item.id)); };
    return <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-foreground">Planning Items</h2>}><Head title="Planning Items" /><div className="py-10"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8"><Link href={route('planning-items.create')}><PrimaryButton>Add Planning Item</PrimaryButton></Link><Card><CardContent><div className="overflow-x-auto"><Table><TableHeader><TableRow>{['Name','Interval KM','Interval Days','Actions'].map((head) => <TableHead key={head}>{head}</TableHead>)}</TableRow></TableHeader><TableBody>{planningItems.data.map((item) => <TableRow key={item.id}><TableCell className="font-medium text-foreground">{item.name}</TableCell><TableCell>{item.interval_km.toLocaleString('id-ID')}</TableCell><TableCell>{item.interval_days.toLocaleString('id-ID')}</TableCell><TableCell><div className="flex flex-wrap gap-2"><Link className="text-sm font-medium text-primary hover:underline" href={route('planning-items.edit', item.id)}>Edit</Link><DangerButton onClick={() => destroy(item)}>Delete</DangerButton></div></TableCell></TableRow>)}</TableBody></Table></div><PaginationLinks meta={planningItems.meta} /></CardContent></Card></div></div></AuthenticatedLayout>;
}
