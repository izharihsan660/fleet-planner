import {
    forwardRef,
    InputHTMLAttributes,
    MutableRefObject,
    useEffect,
    useRef,
} from 'react';

import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

export default forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { isFocused?: boolean }>(function TextInput(
    {
        type = 'text',
        className = '',
        isFocused = false,
        ...props
    },
    ref,
) {
    const localRef = useRef<HTMLInputElement | null>(null);

    const setRefs = (element: HTMLInputElement | null) => {
        localRef.current = element;

        if (typeof ref === 'function') {
            ref(element);
        } else if (ref) {
            (ref as MutableRefObject<HTMLInputElement | null>).current = element;
        }
    };

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <Input
            {...props}
            type={type}
            className={cn(className)}
            ref={setRefs}
        />
    );
});
