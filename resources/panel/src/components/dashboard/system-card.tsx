import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ErrorState } from '@/components/error-state'
import { StatusDot } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { Card, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { MAIN_SHOP } from '@/lib/config'
import { handleLabel } from '@/lib/direction'
import { formatNumber, timeAgo } from '@/lib/format'
import { systemQuery, updateQuery } from '@/lib/queries'
import { BOT_MODE, type Tone } from '@/lib/statuses'

/** One part of the machinery: its name and state, and under them what it is doing — a sentence, which wraps (a link in it is never cut). */
function Row({ label, value, detail, health }: { label: string; value: string; detail?: ReactNode; health: Tone }) {
  return (
    <li className="flex items-start gap-3 py-2.5">
      <StatusDot tone={health} className="mt-2" />
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-3">
          <span className="min-w-0 text-body font-medium wrap-anywhere">{label}</span>
          <span className="text-footnote whitespace-nowrap text-muted-foreground">{value}</span>
        </div>
        {detail && <div className="text-footnote text-faint">{detail}</div>}
      </div>
    </li>
  )
}

/**
 * Is the machinery behind the shop alive: the bot of the shop on screen — the main one, or an agent's, said by which and
 * named by its @username once it is known —,
 * the scheduler — the owner's, beside the dashboard's queues; read on its own, again each minute (a heartbeat changes
 * with time alone). A bot that gets no updates at all points at where every bot's webhook is set (the telegram
 * settings), what a host without a shell runs on; an agent's bot without a token waits for its agent to send one. A new
 * version of AmoBot out (the update screen's read, which the scheduler keeps daily) points at the panel's update.
 */
export function SystemCard() {
  const read = useQuery({ ...systemQuery, refetchInterval: 60_000 })
  const system = read.data?.system
  const update = useQuery(updateQuery).data
  const newer = update?.available ? update.latest : null

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>وضعیت سیستم</CardTitle>
          <CardDescription>
            {system ? (
              <>
                نسخه <span dir="ltr">{system.version}</span> · <bdi dir="ltr">PHP {system.php}</bdi>
              </>
            ) : (
              'در حال بررسی…'
            )}
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <div className="px-4 pb-2">
        {!system ? (
          read.error ? (
            <ErrorState what="وضعیت سیستم" error={read.error} onRetry={() => void read.refetch()} retrying={read.isFetching} className="mb-2" />
          ) : (
            <div className="grid gap-2 pb-2">
              {Array.from({ length: 2 }).map((_, i) => (
                <Skeleton key={i} className="h-10 w-full" />
              ))}
            </div>
          )
        ) : (
          <ul className="divide-y divide-border">
            <Row
              label={[system.bot.id === MAIN_SHOP ? 'ربات اصلی' : 'ربات این فروشگاه', system.bot.username && handleLabel(system.bot.username)].filter(Boolean).join(' ')}
              detail={
                !system.bot.configured ? (
                  system.bot.id === MAIN_SHOP ? (
                    'توکن ربات تنظیم نشده است'
                  ) : (
                    'نماینده هنوز توکن رباتش را از ربات اصلی نفرستاده است'
                  )
                ) : system.bot.mode === 'offline' ? (
                  <>
                    پیامی نمی‌رسد: Webhook ثبت نشده است ·{' '}
                    <TextLink inline to="/settings/telegram">
                      ثبت Webhook
                    </TextLink>
                  </>
                ) : (
                  `${system.bot.username ? '' : 'توکن تنظیم شده · '}آخرین پیام: ${timeAgo(system.bot.last_update_at)}`
                )
              }
              value={!system.bot.configured ? 'راه‌اندازی نشده' : system.bot.mode !== 'offline' && !system.bot.enabled ? 'غیرفعال از تنظیمات' : BOT_MODE[system.bot.mode].label}
              health={!system.bot.configured ? 'danger' : system.bot.mode === 'offline' || !system.bot.enabled ? 'warning' : 'success'}
            />
            <Row
              label="Scheduler"
              detail={
                system.cron.last_run_at ? (
                  `آخرین اجرا: ${timeAgo(system.cron.last_run_at)} · ${formatNumber(system.cron.tasks)} وظیفه`
                ) : (
                  <>
                    کارهای زمان‌بندی‌شده هنوز اجرا نشده‌اند ·{' '}
                    <TextLink inline to="/settings/advanced">
                      راه‌اندازی Cron
                    </TextLink>
                  </>
                )
              }
              value={system.cron.last_run_at ? 'فعال' : 'تنظیم نشده'}
              health={system.cron.last_run_at ? 'success' : 'warning'}
            />
            {newer && (
              <Row
                label="نسخه تازه"
                detail={
                  <TextLink inline to="/settings/update">
                    به‌روزرسانی به نسخه <bdi dir="ltr">{newer.version}</bdi>
                  </TextLink>
                }
                value="آماده نصب"
                health="info"
              />
            )}
          </ul>
        )}
      </div>
    </Card>
  )
}
