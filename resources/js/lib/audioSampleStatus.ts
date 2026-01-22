export const audioSampleStatusLabels: Record<string, string> = {
    pending_transcript: 'Needs Transcript',
    imported: 'Needs Cleaning',
    cleaning: 'Cleaning...',
    cleaned: 'Ready for Review',
    validated: 'Benchmark Ready',
    failed: 'Failed',
};

export const audioSampleStatusClasses: Record<string, string> = {
    pending_transcript: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    imported: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    cleaning: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    cleaned: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
    validated: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

export const getAudioSampleStatusLabel = (status: string) =>
    audioSampleStatusLabels[status] ?? status;

export const getAudioSampleStatusClass = (status: string) =>
    audioSampleStatusClasses[status] ?? 'bg-muted text-muted-foreground';
