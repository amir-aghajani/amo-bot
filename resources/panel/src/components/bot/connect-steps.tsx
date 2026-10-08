import type { ReactNode } from 'react'
import { CircleAlert, Copy, Link2, LoaderCircle, RefreshCw, TriangleAlert, UserPlus } from 'lucide-react'
import { Callout } from '@/components/callout'
import { Disclosure } from '@/components/disclosure'
import { IconButton } from '@/components/icon-button'
import { externalHref } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import type { ReportGroupData } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { copyText } from '@/lib/clipboard'
import { formatDate, formatNumber } from '@/lib/format'

/**
 * Handing the bot a group: what the admin does in Telegram, then the link — Telegram's own "add to group as admin"
 * link, which asks for the "manage topics" right and carries a one-time code — or the command that does the same
 * typed into the group. The screen waits (useReportGroup polls) and turns when the bot reports the group connected.
 */
export function ConnectSteps({ group, creating, onCreate }: { group: ReportGroupData; creating: boolean; onCreate: () => void }) {
  const link = group.link
  const join = link && externalHref(link.url)
  // A bot's @username comes from where its token was given: the main bot's from the owner's settings, an agent's with
  // its token, sent in the main bot.
  const mainShop = useMainShop()

  return (
    <div className="grid gap-4">
      <ol className="grid gap-2.5 text-body">
        <Step n={1}>
          در تلگرام یک گروه بسازید (یا گروهی خالی که دارید) و از تنظیمات گروه گزینه <span dir="ltr">Topics</span> (تاپیک‌ها) را روشن کنید.
        </Step>
        <Step n={2}>دکمه «افزودن ربات به گروه» را بزنید و همان گروه را انتخاب کنید؛ ربات با دسترسی «مدیریت تاپیک‌ها» مدیر گروه می‌شود.</Step>
        <Step n={3}>ربات تاپیک‌ها را خودش می‌سازد و وصل شدن گروه همین‌جا دیده می‌شود.</Step>
      </ol>

      {group.bot_username === null ? (
        <Callout tone="warning" icon={TriangleAlert}>
          {mainShop
            ? 'نام کاربری ربات هنوز مشخص نیست: در «تنظیمات پنل»، بخش «ربات تلگرام»، «بررسی توکن» را بزنید و ذخیره کنید (یا Webhook را ثبت کنید) تا نامش از تلگرام گرفته شود؛ بعد لینک اتصال ساخته می‌شود.'
            : 'ربات این فروشگاه هنوز وصل نشده است: توکنش در ربات اصلی از «نمایندگی» ← «ربات من» فرستاده می‌شود؛ بعد لینک اتصال ساخته می‌شود.'}
        </Callout>
      ) : link ? (
        <div className="grid gap-3">
          <div className="flex flex-wrap items-center gap-2">
            {join && (
              <Button asChild>
                <a href={join} target="_blank" rel="noreferrer">
                  <UserPlus aria-hidden />
                  افزودن ربات به گروه
                </a>
              </Button>
            )}
            <Button variant="secondary" icon={Copy} onClick={() => void copyText(link.url, 'لینک کپی شد')}>
              کپی لینک
            </Button>
            <Button variant="ghost" icon={RefreshCw} busy={creating} onClick={onCreate}>
              لینک تازه
            </Button>
          </div>
          <p className="flex items-center gap-2 text-footnote text-muted-foreground" role="status">
            <LoaderCircle className="size-3.5 shrink-0 animate-spin" aria-hidden />
            در انتظار وصل شدن گروه… لینک فقط یک بار و تا {formatDate(link.expires_at, { timeStyle: 'short' })} کار می‌کند.
          </p>
          <Disclosure label="لینک در تلگرام باز نشد؟">
            <p className="text-body text-muted-foreground">ربات را خودتان مدیر گروه کنید و دسترسی «مدیریت تاپیک‌ها» را به آن بدهید، بعد این پیام را در گروه بفرستید:</p>
            <div className="flex items-center gap-2">
              <div className="min-w-0 flex-1 rounded-lg border border-border bg-fill px-3 py-2">
                <code dir="ltr" className="block truncate text-start text-footnote">
                  {link.command}
                </code>
              </div>
              <IconButton aria-label="کپی پیام اتصال" title="کپی" icon={Copy} onClick={() => void copyText(link.command, 'پیام کپی شد')} />
            </div>
          </Disclosure>
        </div>
      ) : (
        <Button className="w-fit" icon={Link2} busy={creating} onClick={onCreate}>
          ساخت لینک اتصال
        </Button>
      )}

      {group.attempt && (
        <Callout tone="danger" icon={CircleAlert}>
          گروه «{group.attempt.title}» وصل نشد: {group.attempt.message}
        </Callout>
      )}
    </div>
  )
}

function Step({ n, children }: { n: number; children: ReactNode }) {
  return (
    <li className="flex gap-3">
      <span className="grid size-5 shrink-0 place-items-center rounded-full bg-fill-hover text-caption font-medium text-muted-foreground" aria-hidden>
        {formatNumber(n)}
      </span>
      <span className="leading-relaxed">{children}</span>
    </li>
  )
}
