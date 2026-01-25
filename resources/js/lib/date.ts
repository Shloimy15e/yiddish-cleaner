export const formatDateTime = (value: string | null, options?: Intl.DateTimeFormatOptions) =>
    value ? new Date(value).toLocaleString('en-US', options) : 'N/A';

export const formatDate = (value: string | null, options?: Intl.DateTimeFormatOptions) =>
    value
        ? new Date(value).toLocaleDateString(
              'en-US',
              options ?? {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
              },
          )
        : 'N/A';
