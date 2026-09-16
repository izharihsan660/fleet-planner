import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

type ConfirmDialogProps = {
    show: boolean;
    children?: React.ReactNode;
    title?: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    processing?: boolean;
    onCancel: () => void;
    onConfirm: () => void;
};

export default function ConfirmDialog({
    show,
    children,
    title = 'Apakah kamu yakin?',
    message,
    confirmLabel = 'Ya, lanjutkan',
    cancelLabel = 'Batal',
    processing = false,
    onCancel,
    onConfirm,
}: ConfirmDialogProps) {
    return (
        <Dialog open={show} onOpenChange={(open) => !open && onCancel()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{message}</DialogDescription>
                </DialogHeader>

                {children}

                <DialogFooter>
                    <SecondaryButton type="button" onClick={onCancel} disabled={processing}>
                        {cancelLabel}
                    </SecondaryButton>
                    <PrimaryButton type="button" onClick={onConfirm} disabled={processing}>
                        {confirmLabel}
                    </PrimaryButton>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
