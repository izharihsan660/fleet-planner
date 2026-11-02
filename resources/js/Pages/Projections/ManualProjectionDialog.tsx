import InputError from '@/Components/InputError';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { ManualProjectionReason, PlanningItem, Unit } from '@/types';
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { ChevronsUpDown, Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface ManualProjectionDialogProps {
    units: Pick<Unit, 'id' | 'current_plate'>[];
    items: Pick<PlanningItem, 'id' | 'name'>[];
    reasons: { value: ManualProjectionReason; label: string }[];
    today: string;
    onCreated: () => void;
    lockedUnit?: Pick<Unit, 'id' | 'current_plate'>;
}

export default function ManualProjectionDialog({ units, items, reasons, today, onCreated, lockedUnit }: ManualProjectionDialogProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const form = useForm({ unit_id: lockedUnit ? String(lockedUnit.id) : '', planning_item_id: '', target_date: today, reason: 'aus' as ManualProjectionReason, notes: '' });
    const selectedUnit = lockedUnit ?? units.find((unit) => String(unit.id) === form.data.unit_id) ?? null;
    const filteredUnits = units.filter((unit) => unit.current_plate.toLocaleLowerCase('id-ID').includes(query.trim().toLocaleLowerCase('id-ID')));
    const changeOpen = (nextOpen: boolean) => {
        if (form.processing) {
            return;
        }
        setOpen(nextOpen);
        if (!nextOpen) {
            form.reset();
            form.clearErrors();
            setQuery('');
        }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('projections.manual.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
                setQuery('');
                onCreated();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button size={lockedUnit ? 'sm' : 'default'} variant={lockedUnit ? 'outline' : 'default'}>
                    <Plus data-icon="inline-start" aria-hidden="true" />{lockedUnit ? 'Kebutuhan Manual' : 'Tambah Kebutuhan Manual'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Tambah Kebutuhan Manual</DialogTitle>
                        <DialogDescription>Catat kebutuhan penggantian lebih awal. Tidak mengubah due sistem atau membuat perintah kerja.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="manual-unit">Unit</Label>
                        <Combobox value={selectedUnit} by="id" disabled={Boolean(lockedUnit)} onClose={() => setQuery('')} onChange={(unit) => form.setData('unit_id', unit ? String(unit.id) : '')}>
                            <div className="relative">
                                <ComboboxInput
                                    id="manual-unit"
                                    autoComplete="off"
                                    placeholder="Cari nomor polisi"
                                    displayValue={(unit: typeof selectedUnit) => unit?.current_plate ?? ''}
                                    onChange={(event) => setQuery(event.target.value)}
                                    aria-invalid={Boolean(form.errors.unit_id)}
                                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 pr-9 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground"
                                />
                                <ComboboxButton aria-label="Pilih unit" className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-muted-foreground"><ChevronsUpDown className="size-4" /></ComboboxButton>
                                <ComboboxOptions className="absolute z-50 mt-1 max-h-48 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
                                    {filteredUnits.map((unit) => (
                                        <ComboboxOption key={unit.id} value={unit} className="cursor-pointer rounded-sm px-3 py-2 text-sm data-focus:bg-accent data-focus:text-accent-foreground">{unit.current_plate}</ComboboxOption>
                                    ))}
                                    {filteredUnits.length === 0 && <p className="px-3 py-2 text-sm text-muted-foreground">Unit tidak ditemukan.</p>}
                                </ComboboxOptions>
                            </div>
                        </Combobox>
                        <InputError message={form.errors.unit_id} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="manual-item">Item</Label>
                            <Select value={form.data.planning_item_id} onValueChange={(value) => form.setData('planning_item_id', value)}>
                                <SelectTrigger id="manual-item" className="w-full" aria-invalid={Boolean(form.errors.planning_item_id)}><SelectValue placeholder="Pilih item" /></SelectTrigger>
                                <SelectContent>{items.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>)}</SelectContent>
                            </Select>
                            <InputError message={form.errors.planning_item_id} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="manual-target-date">Perkiraan Tanggal Ganti</Label>
                            <Input id="manual-target-date" type="date" required min={today} value={form.data.target_date} onChange={(event) => form.setData('target_date', event.target.value)} aria-invalid={Boolean(form.errors.target_date)} />
                            <InputError message={form.errors.target_date} />
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="manual-reason">Alasan</Label>
                        <Select value={form.data.reason} onValueChange={(value) => form.setData('reason', value as ManualProjectionReason)}>
                            <SelectTrigger id="manual-reason" className="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>{reasons.map((reason) => <SelectItem key={reason.value} value={reason.value}>{reason.label}</SelectItem>)}</SelectContent>
                        </Select>
                        <InputError message={form.errors.reason} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="manual-notes">Catatan{form.data.reason === 'lainnya' ? ' (wajib)' : ' (opsional)'}</Label>
                        <Textarea id="manual-notes" required={form.data.reason === 'lainnya'} rows={3} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} aria-invalid={Boolean(form.errors.notes)} />
                        <InputError message={form.errors.notes} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => changeOpen(false)}>Batal</Button>
                        <Button type="submit" disabled={form.processing || !form.data.unit_id || !form.data.planning_item_id}>{form.processing ? 'Menyimpan...' : 'Simpan Kebutuhan'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
