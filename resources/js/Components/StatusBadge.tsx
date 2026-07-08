import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';

type StatusTone = 'safe' | 'warning' | 'danger' | 'info' | 'neutral' | 'blocked' | 'rejected' | 'warranty' | 'highUsage';

type StatusBadgeProps = {
    children: React.ReactNode;
    tone?: StatusTone;
    className?: string;
};

const toneClasses: Record<StatusTone, string> = {
    safe: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    warning: 'border-amber-200 bg-amber-50 text-amber-700',
    danger: 'border-red-200 bg-red-50 text-red-700',
    info: 'border-sky-200 bg-sky-50 text-sky-700',
    neutral: 'border-slate-200 bg-slate-50 text-slate-700',
    blocked: 'border-violet-200 bg-violet-50 text-violet-700',
    rejected: 'border-rose-200 bg-rose-50 text-rose-700',
    warranty: 'border-teal-200 bg-teal-50 text-teal-700',
    highUsage: 'border-orange-200 bg-orange-50 text-orange-700',
};

export default function StatusBadge({ children, tone = 'neutral', className }: StatusBadgeProps) {
    return (
        <Badge variant="outline" className={cn(toneClasses[tone], className)}>
            {children}
        </Badge>
    );
}
