import { LabelHTMLAttributes } from 'react';

import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

export default function InputLabel({
    value,
    className = '',
    children,
    ...props
}: LabelHTMLAttributes<HTMLLabelElement> & { value?: string }) {
    return (
        <Label
            {...props}
            className={cn('block', className)}
        >
            {value ? value : children}
        </Label>
    );
}
