import { ButtonHTMLAttributes, forwardRef } from 'react';

import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

const SecondaryButton = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement>>(function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}, ref) {
    return (
        <Button
            ref={ref}
            {...props}
            type={type}
            variant="outline"
            className={cn('uppercase tracking-widest', className)}
            disabled={disabled}
        >
            {children}
        </Button>
    );
});

export default SecondaryButton;
