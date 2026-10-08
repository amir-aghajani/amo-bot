import { InfoTip } from '@/components/info-tip'
import { StatusDot } from '@/components/status-badge'
import type { ServerRow } from '@/lib/api-types'
import { serverStatus } from '@/lib/statuses'

/**
 * A server's connection in a dot and a word — and, with `details` (a list, where there is no room to say it), the panel's
 * last failure behind an ⓘ beside them; the server's page says it in full under its status.
 */
export function ServerStatus({ server, details = false }: { server: ServerRow; details?: boolean }) {
  const status = serverStatus(server)

  return (
    <span className="inline-flex items-center gap-2 text-body">
      <StatusDot tone={status.tone} />
      {status.label}
      {details && server.last_error && (
        <InfoTip small label={`خطای سرور ${server.name}`}>
          <span dir="auto" className="wrap-anywhere">
            {server.last_error}
          </span>
        </InfoTip>
      )}
    </span>
  )
}

/** Why nothing can be sold on the server now — the server's own words (switched off, without subscription links, full…), wrapping in a list's narrow cell. */
export function UnsellableNote({ server }: { server: Pick<ServerRow, 'unsellable_reason'> }) {
  if (server.unsellable_reason === null) return null

  return <span className="max-w-72 text-footnote whitespace-normal text-warning">{server.unsellable_reason}</span>
}
