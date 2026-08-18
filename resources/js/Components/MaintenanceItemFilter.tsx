import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { PlanningItem } from '@/types';
import { ChevronDown } from 'lucide-react';

type MaintenanceItemFilterProps = {
    items: Pick<PlanningItem, 'id' | 'name'>[];
    selectedIds: number[];
    onChange: (selectedIds: number[]) => void;
    className?: string;
};

export default function MaintenanceItemFilter({ items, selectedIds, onChange, className }: MaintenanceItemFilterProps) {
    const selectedItems = items.filter((item) => selectedIds.includes(item.id));
    const triggerLabel = selectedItems.length === 0
        ? 'Semua Item Perawatan'
        : selectedItems.length === 1
            ? selectedItems[0].name
            : `${selectedItems.length} item dipilih`;

    const toggleItem = (itemId: number) => {
        onChange(selectedIds.includes(itemId)
            ? selectedIds.filter((selectedId) => selectedId !== itemId)
            : [...selectedIds, itemId]);
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" className={cn('w-full justify-between font-normal', className)} aria-label="Filter Item Perawatan">
                    <span className="truncate">{triggerLabel}</span>
                    <ChevronDown className="size-4 shrink-0 opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="max-h-80 min-w-72">
                <DropdownMenuLabel>Item Perawatan</DropdownMenuLabel>
                {selectedIds.length > 0 && (
                    <>
                        <DropdownMenuItem onSelect={() => onChange([])}>Tampilkan semua item</DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </>
                )}
                {items.map((item) => (
                    <DropdownMenuCheckboxItem
                        key={item.id}
                        checked={selectedIds.includes(item.id)}
                        onCheckedChange={() => toggleItem(item.id)}
                        onSelect={(event) => event.preventDefault()}
                    >
                        {item.name}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
