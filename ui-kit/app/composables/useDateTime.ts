/**
 * A moment the API sends as ISO 8601, in the reader's locale and time zone.
 *
 *     const dateTime = useDateTime()
 *     dateTime(row.recordedAt)   // "22.11.2026, 23:31:59"
 *
 * Never the raw string: "2026-11-22T22:31:59+00:00" is the server's notation,
 * an hour off for a reader in Warsaw and unreadable at a glance.
 */
export function useDateTime() {
  const { locale } = useI18n()

  return (iso: string | null | undefined): string =>
    iso ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(iso)) : '-'
}
