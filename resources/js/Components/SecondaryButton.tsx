import { ButtonHTMLAttributes } from 'react';

import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <Button
            {...props}
            type={type}
            variant="outline"
            className={cn('uppercase tracking-widest', className)}
            disabled={disabled}
        >
            {children}
        </Button>
    );
}
