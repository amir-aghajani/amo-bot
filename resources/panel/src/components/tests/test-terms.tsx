import { formatBytes, formatDuration } from '@/lib/format'

/**
 * What a test gets — its traffic and hours — and where the hours count from: the customer's first connection (on hold),
 * or the moment the test is made.
 */
export function TestTerms({ bytes, hours, onHold }: { bytes: number; hours: number; onHold: boolean }) {
  return (
    <div className="grid gap-0.5">
      <span className="whitespace-nowrap tabular">
        {formatBytes(bytes)} · {formatDuration(hours)}
      </span>
      <span className="text-footnote text-muted-foreground">{onHold ? 'از اولین اتصال' : 'از لحظه ساخت'}</span>
    </div>
  )
}
