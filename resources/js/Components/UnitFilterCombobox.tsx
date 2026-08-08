import { cn } from '@/lib/utils';
import { Unit } from '@/types';
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useState } from 'react';

type UnitFilterOption = {
    id: string;
    current_plate: string;
};

type UnitFilterComboboxProps = {
    units: Pick<Unit, 'id' | 'current_plate'>[];
    value: string;
    onChange: (value: string) => void;
};

const allUnitsOption = {
    id: 'all',
    current_plate: 'Semua Unit',
} as const;

export default function UnitFilterCombobox({ units, value, onChange }: UnitFilterComboboxProps) {
    const [query, setQuery] = useState('');
    const normalizedQuery = query.trim().toLocaleLowerCase('id-ID');
    const unitOptions = units.map((unit) => ({ id: unit.id.toString(), current_plate: unit.current_plate }));
    const selectedUnit = unitOptions.find((unit) => unit.id === value) ?? allUnitsOption;
    const filteredUnits = normalizedQuery === ''
        ? unitOptions
        : unitOptions.filter((unit) => unit.current_plate.toLocaleLowerCase('id-ID').includes(normalizedQuery));

    return (
        <Combobox
            value={selectedUnit}
            by="id"
            immediate
            onClose={() => setQuery('')}
            onChange={(unit) => {
                onChange(unit?.id === 'all' ? '' : unit?.id ?? '');
                setQuery('');
            }}
        >
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 z-10 size-4 -translate-y-1/2 text-muted-foreground" />
                <ComboboxInput
                    aria-label="Filter Unit"
                    autoComplete="off"
                    className="h-8 w-full rounded-lg border border-input bg-transparent py-1 pr-9 pl-9 text-sm text-foreground transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-input/30"
                    displayValue={(unit: UnitFilterOption | null) => unit?.current_plate ?? ''}
                    onChange={(event) => setQuery(event.target.value)}
                    onFocus={(event) => event.currentTarget.select()}
                    placeholder="Cari nomor polisi"
                />
                <ComboboxButton className="absolute inset-y-0 right-0 flex w-8 items-center justify-center rounded-r-lg text-muted-foreground outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50">
                    <ChevronsUpDown className="size-4" />
                </ComboboxButton>
                <ComboboxOptions className="absolute z-50 mt-1 max-h-60 w-full min-w-56 overflow-auto rounded-lg bg-popover p-1 text-popover-foreground shadow-md ring-1 ring-foreground/10 outline-none">
                    {normalizedQuery === '' && (
                        <ComboboxOption
                            value={allUnitsOption}
                            className={({ focus }) => cn('relative flex cursor-default select-none items-center rounded-md py-1.5 pr-8 pl-2 text-sm', focus && 'bg-accent text-accent-foreground')}
                        >
                            {({ selected }) => (
                                <>
                                    <span className="truncate">Semua Unit</span>
                                    {selected && <Check className="absolute right-2 size-4" />}
                                </>
                            )}
                        </ComboboxOption>
                    )}
                    {filteredUnits.map((unit) => (
                        <ComboboxOption
                            key={unit.id}
                            value={unit}
                            className={({ focus }) => cn('relative flex cursor-default select-none items-center rounded-md py-1.5 pr-8 pl-2 text-sm', focus && 'bg-accent text-accent-foreground')}
                        >
                            {({ selected }) => (
                                <>
                                    <span className="truncate">{unit.current_plate}</span>
                                    {selected && <Check className="absolute right-2 size-4" />}
                                </>
                            )}
                        </ComboboxOption>
                    ))}
                    {filteredUnits.length === 0 && (
                        <div className="px-2 py-3 text-sm text-muted-foreground">Unit tidak ditemukan.</div>
                    )}
                </ComboboxOptions>
            </div>
        </Combobox>
    );
}
