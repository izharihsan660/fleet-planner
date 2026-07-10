import { ButtonHTMLAttributes, forwardRef } from 'react';

import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

const PrimaryButton = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement>>(function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}, ref) {
    return (
        <Button
            ref={ref}
            {...props}
            className={cn('uppercase tracking-widest', className)}
            disabled={disabled}
        >
            {children}
        </Button>
    );
});

export default PrimaryButton;
