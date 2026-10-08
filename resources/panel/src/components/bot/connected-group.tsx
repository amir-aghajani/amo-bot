import { MessagesSquare, TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { InfoBadge } from '@/components/info-tip'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import type { ReportGroupData, ReportTopicInfo } from '@/lib/api-types'
import { formatDate, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** The connected group: its name and id, whether all is well, what waits to be sent, and its topics. */
export function ConnectedGroup({ group }: { group: ReportGroupData }) {
  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-fill-hover text-muted-foreground">
            <MessagesSquare className="size-4" aria-hidden />
          </span>
          <div className="grid min-w-0 gap-0.5">
            <span className="truncate font-medium">{group.title ?? 'گروه بدون نام'}</span>
            <span className="text-footnote text-muted-foreground">
              <bdi dir="ltr">{group.chat_id}</bdi> · وصل شده در {formatDate(group.connected_at)}
            </span>
          </div>
        </div>
        <StatusBadge status={group.problem ? { label: 'مشکل دارد', tone: 'warning' } : { label: 'وصل است', tone: 'success' }} />
      </div>

      {group.problem && (
        <Callout tone="warning" icon={TriangleAlert}>
          {group.problem} بعد از درست کردن، «بررسی دوباره» را بزنید.
        </Callout>
      )}

      {group.waiting > 0 && (
        <Callout tone="info" role="status">
          {formatNumber(group.waiting)} گزارش در صف ارسال است
          {group.paused_until ? `؛ ارسال تا ساعت ${formatDate(group.paused_until, { timeStyle: 'short' })} صبر می‌کند` : ''}. تلگرام در هر گروه بیشتر از ۲۰ پیام در دقیقه نمی‌پذیرد، پس گزارش‌ها به نوبت
          فرستاده می‌شوند.
        </Callout>
      )}

      <ul className="grid gap-2 sm:grid-cols-2" aria-label="تاپیک‌های گروه">
        {group.topics.map((topic) => (
          <li key={topic.key} className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
            <span className={cn('min-w-0 truncate', !topic.enabled && 'text-muted-foreground')}>{topic.title}</span>
            <TopicState topic={topic} />
          </li>
        ))}
      </ul>
    </>
  )
}

/** A topic's state in a word; one not made yet says when it will be, when pressed. */
function TopicState({ topic }: { topic: ReportTopicInfo }) {
  if (!topic.enabled) return <Badge>خاموش</Badge>

  return topic.ready ? (
    <Badge variant="success">ساخته شده</Badge>
  ) : (
    <InfoBadge variant="neutral" info="ربات این تاپیک را با اولین گزارش همین بخش در گروه می‌سازد.">
      ساخته نشده
    </InfoBadge>
  )
}
