import { CircleCheck, TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { StatusDot } from '@/components/status-badge'
import type { PanelStatusInfo, Probe } from '@/lib/api-types'
import { formatBytes, formatNumber, formatPercent, formatSpan } from '@/lib/format'

/** The panel's core («هسته»), in a word. */
const CORE_STATES: Record<PanelStatusInfo['core_state'], string> = {
  running: 'در حال اجرا',
  stopped: 'متوقف',
  error: 'خطا',
  unknown: 'نامشخص',
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="grid gap-0.5 rounded-lg border border-border bg-card px-3 py-2">
      <span className="text-caption text-muted-foreground">{label}</span>
      <span className="text-body font-medium tabular">{value}</span>
      {sub && <span className="text-caption text-muted-foreground">{sub}</span>}
    </div>
  )
}

/** What a connection test found: a verdict line, the host's vitals, the inbounds on offer, and whether subscription links are served. */
export function ProbeResult({ probe }: { probe: Probe }) {
  if (!probe.ok) {
    return (
      <Callout tone="danger" icon={TriangleAlert} className="py-3">
        <div className="grid min-w-0 gap-1">
          <span className="font-medium">اتصال برقرار نشد</span>
          <span className="text-footnote text-foreground">{probe.error}</span>
          {probe.error_detail && (
            <code dir="ltr" className="block text-start text-caption leading-relaxed break-all text-muted-foreground">
              {probe.error_detail}
            </code>
          )}
        </div>
      </Callout>
    )
  }

  const status = probe.status
  const inbounds = probe.inbounds ?? []
  const enabled = inbounds.filter((inbound) => inbound.enabled).length

  return (
    <Callout tone="success" className="py-3">
      <div className="grid gap-3">
        <div className="flex items-center gap-2">
          <CircleCheck className="size-4 shrink-0 text-success" aria-hidden />
          <span className="font-medium text-success">اتصال برقرار شد</span>
          <span className="text-footnote text-muted-foreground">· {probe.inbounds ? `${formatNumber(inbounds.length)} اینباند (${formatNumber(enabled)} فعال)` : 'اینباندها خوانده نشدند'}</span>
        </div>

        {status && (
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <Stat label={status.core_name ? `هسته ${status.core_name}` : 'هسته'} value={CORE_STATES[status.core_state]} sub={status.core_version || undefined} />
            <Stat label="CPU" value={formatPercent(Math.round(status.cpu_percent))} sub={status.uptime_seconds > 0 ? `روشن از ${formatSpan(status.uptime_seconds)} پیش` : undefined} />
            <Stat label="RAM" value={formatBytes(status.memory_used)} sub={status.memory_total > 0 ? `از ${formatBytes(status.memory_total)}` : undefined} />
            <Stat label="دیسک" value={formatBytes(status.disk_used)} sub={status.disk_total > 0 ? `از ${formatBytes(status.disk_total)}` : undefined} />
          </div>
        )}

        {inbounds.length > 0 && (
          <ul className="grid gap-1 text-footnote">
            {inbounds.slice(0, 6).map((inbound) => (
              <li key={inbound.remote_key} className="flex items-center gap-2">
                <StatusDot tone={inbound.enabled ? 'success' : 'neutral'} className="size-1.5" />
                <span className="truncate font-medium">{inbound.remark || inbound.tag}</span>
                <span className="text-muted-foreground" dir="ltr">
                  {inbound.protocol}
                  {inbound.network && `/${inbound.network}`}
                  {inbound.security && inbound.security !== 'none' && `+${inbound.security}`}
                  {inbound.port !== null && ` :${inbound.port}`}
                </span>
                <span className="ms-auto text-muted-foreground tabular">{formatNumber(inbound.client_count)} کلاینت</span>
              </li>
            ))}
            {inbounds.length > 6 && <li className="text-muted-foreground">و {formatNumber(inbounds.length - 6)} اینباند دیگر…</li>}
          </ul>
        )}

        {probe.serves_subscriptions ? (
          <p className="text-footnote text-muted-foreground">سرور اشتراک (Subscription) پنل فعال است؛ مشتری لینک اشتراک می‌گیرد.</p>
        ) : probe.subscription_probed ? (
          <p className="text-footnote text-warning">سرور اشتراک (Subscription) در پنل فعال نیست؛ لینک اشتراک برای مشتریان ساخته نمی‌شود و این سرور قابل فروش نیست.</p>
        ) : (
          <p className="text-footnote text-warning">وضعیت سرور اشتراک (Subscription) مشخص نشد؛ سرور را دوباره بررسی کنید.</p>
        )}
      </div>
    </Callout>
  )
}
