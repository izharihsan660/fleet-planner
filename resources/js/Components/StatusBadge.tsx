import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';

type StatusTone = 'safe' | 'warning' | 'danger' | 'info' | 'neutral' | 'blocked' | 'rejected' | 'warranty' | 'highUsage';

type StatusBadgeProps = {
    children: React.ReactNode;
    tone?: StatusTone;
    className?: string;
};

const toneClasses: Record<StatusTone, string> = {
    safe: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/15 dark:text-emerald-200',
    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-200',
    danger: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-200',
    info: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-200',
    neutral: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-500/40 dark:bg-slate-500/15 dark:text-slate-200',
    blocked: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/40 dark:bg-violet-500/15 dark:text-violet-200',
    rejected: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/15 dark:text-rose-200',
    warranty: 'border-teal-200 bg-teal-50 text-teal-700 dark:border-teal-500/40 dark:bg-teal-500/15 dark:text-teal-200',
    highUsage: 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/40 dark:bg-orange-500/15 dark:text-orange-200',
};

export default function StatusBadge({ children, tone = 'neutral', className }: StatusBadgeProps) {
    return (
        <Badge variant="outline" className={cn(toneClasses[tone], className)}>
            {children}
        </Badge>
    );
}
