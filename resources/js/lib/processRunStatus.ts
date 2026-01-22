export const processRunStatusLabels: Record<string, string> = {
    pending: 'Pending',
    processing: 'Processing',
    completed: 'Completed',
    completed_with_errors: 'Completed (Errors)',
    failed: 'Failed',
};

export const processRunStatusClasses: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
    completed_with_errors: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

export const getProcessRunStatusLabel = (status: string) =>
    processRunStatusLabels[status] ?? status;

export const getProcessRunStatusClass = (status: string) =>
    processRunStatusClasses[status] ?? 'bg-muted text-muted-foreground';
