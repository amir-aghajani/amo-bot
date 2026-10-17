import { InfoTip } from '@/components/info-tip'
import { StatusDot } from '@/components/status-badge'
import type { ServerRow } from '@/lib/api-types'
import { serverStatus } from '@/lib/statuses'

/** A server's connection as a dot and a word; with `details` (in a list) the panel's last failure behind an ⓘ. */
export function ServerStatus({ server, details = false }: { server: ServerRow; details?: boolean }) {
  const status = serverStatus(server)

  return (
    <span className="inline-flex items-center gap-2 text-body">
      <StatusDot tone={status.tone} />
      {status.label}
      {details && server.last_error && (
        <InfoTip small label={`خطای سرور ${server.name}`}>
          <LastError server={server} />
        </InfoTip>
      )}
    </span>
  )
}

/**
 * Why the server's last contact with its panel failed: the diagnosis, and under it — on a line of its own, left to right,
 * as a probe's (ProbeResult) — the panel's or cURL's words, which in one line with the Persian scramble both.
 */
export function LastError({ server }: { server: Pick<ServerRow, 'last_error' | 'last_error_detail'> }) {
  if (server.last_error === null) return null

  return (
    <span className="grid min-w-0 gap-1">
      <span>{server.last_error}</span>
      {server.last_error_detail && (
        <bdi dir="ltr" className="block text-start text-caption leading-relaxed break-all text-muted-foreground">
          {server.last_error_detail}
        </bdi>
      )}
    </span>
  )
}

/** Why nothing can be sold on the server now (switched off, no subscription links, full…), as the server words it; wraps in a narrow cell. */
export function UnsellableNote({ server }: { server: Pick<ServerRow, 'unsellable_reason'> }) {
  if (server.unsellable_reason === null) return null

  return <span className="max-w-72 text-footnote whitespace-normal text-warning">{server.unsellable_reason}</span>
}
