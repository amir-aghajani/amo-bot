import { formatDate, timeAgo } from '@/lib/format'

/**
 * When something happened, as how long ago («۳ روز قبل»), its date and hour on hover; «—» for a row with no time — one
 * written outside AmoBot without it, which the API sends as null.
 */
export function TimeAgo({ at }: { at: string | null }) {
  if (at === null) return <span>—</span>

  return (
    <time dateTime={at} title={formatDate(at)}>
      {timeAgo(at)}
    </time>
  )
}
