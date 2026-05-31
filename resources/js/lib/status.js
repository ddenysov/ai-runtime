import { ActivityIcon, CircleCheckIcon, TriangleAlertIcon } from '@lucide/vue';

export const statusClasses = {
    Active: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    Inactive: 'border-border bg-muted text-muted-foreground',
    Healthy: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    Warning: 'border-amber-200 bg-amber-50 text-amber-700',
    Paused: 'border-border bg-muted text-muted-foreground',
};

export const statusIcons = {
    Active: CircleCheckIcon,
    Inactive: ActivityIcon,
    Healthy: CircleCheckIcon,
    Warning: TriangleAlertIcon,
    Paused: ActivityIcon,
};
