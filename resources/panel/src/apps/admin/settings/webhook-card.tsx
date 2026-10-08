import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CircleAlert, Link2, Unlink } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { StatusBadge, StatusDot } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { WebhookOutcome } from '@/lib/api-types'
import { MAIN_SHOP } from '@/lib/config'
import { messageOf } from '@/lib/failure'
import { systemQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { BOT_MODE } from '@/lib/statuses'

/** What the owner asks for: every bot on its webhook, or every bot off it. */
type Change = 'enable' | 'disable'

const CHANGES: Record<Change, { title: string; confirm: string; details: string; done: string }> = {
  enable: {
    title: 'ثبت Webhook',
    confirm: 'ثبت Webhook',
    details: 'ربات اصلی و ربات همه نماینده‌ها روی آدرس فروشگاه ثبت می‌شوند و تلگرام پیام‌ها را مستقیم به فروشگاه می‌فرستد. اگر bot:poll روی سرور اجراست، متوقف می‌شود.',
    done: 'Webhook همه ربات‌ها ثبت شد',
  },
  disable: {
    title: 'برداشتن Webhook',
    confirm: 'برداشتن Webhook',
    details: 'Webhook ربات اصلی و ربات همه نماینده‌ها برداشته می‌شود و از این به بعد فقط وقتی پیامی می‌رسد که bot:poll روی سرور اجرا باشد؛ روی هاست اشتراکی معمولا این امکان نیست.',
    done: 'Webhook همه ربات‌ها برداشته شد',
  },
}

interface WebhookCardProps {
  /** APP_URL as config.php holds it: Telegram calls a webhook only over HTTPS. */
  appUrl: string
  /** The main bot's webhook, its secret masked. */
  webhookUrl: string
}

/**
 * How the bots get their updates — every bot the shop runs at once: on webhooks (Telegram calls the shop at its address,
 * what a host without a shell runs on) or by bot:poll on the server. The owner puts them on webhooks, again after a new
 * token or webhook secret, or takes them off here — what bot:webhook:set and bot:webhook:delete do —, and reads what
 * happened to each bot. The mode is the main bot's (GET /system), which the others follow — whichever shop is open.
 */
export function WebhookCard({ appUrl, webhookUrl }: WebhookCardProps) {
  const mode = useQuery(systemQuery).data?.system.main_bot.mode
  // What the dialog asks about stays while it closes; `asking` is whether it is open.
  const [subject, setSubject] = useState<Change>('enable')
  const [asking, setAsking] = useState(false)
  const [outcomes, setOutcomes] = useState<WebhookOutcome[] | null>(null)
  const change = useMutation({
    mutationFn: (to: Change) => (to === 'enable' ? api.post('/system/webhook') : api.delete('/system/webhook')),
    meta: { quiet: true, invalidates: [queryKeys.system] },
    onSuccess: ({ bots }, to) => {
      setAsking(false)
      setOutcomes(bots)
      if (bots.length > 0 && bots.every((bot) => bot.done)) toast.success(CHANGES[to].done)
    },
  })
  const https = appUrl.startsWith('https://')
  const ask = (to: Change) => {
    change.reset()
    setSubject(to)
    setAsking(true)
  }

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">دریافت پیام‌ها</CardTitle>
          <CardDescription>
            با Webhook، تلگرام پیام‌ها را مستقیم به آدرس فروشگاه می‌فرستد و چیزی لازم نیست روی سرور اجرا بماند؛ راه هاست‌های اشتراکی. بدون آن، <code dir="ltr">bot:poll</code> روی سرور پیام‌ها را
            می‌گیرد. ثبت و برداشتن برای ربات اصلی و ربات همه نماینده‌ها با هم انجام می‌شود.
          </CardDescription>
        </CardHeading>
        {mode && (
          <CardAction>
            <StatusBadge status={BOT_MODE[mode]} />
          </CardAction>
        )}
      </CardHeader>

      <CardContent className="grid gap-4">
        {mode === 'webhook' && (
          <p className="text-footnote text-muted-foreground">
            آدرس Webhook ربات اصلی:{' '}
            <bdi dir="ltr" className="break-all">
              {webhookUrl}
            </bdi>
          </p>
        )}

        {!https && (
          <Callout tone="warning" icon={CircleAlert}>
            تلگرام Webhook را فقط روی آدرس https می‌پذیرد و آدرس فروشگاه <bdi dir="ltr">{appUrl || '—'}</bdi> است؛ آن را در بخش{' '}
            <TextLink inline to="/settings/app">
              برنامه
            </TextLink>{' '}
            درست کنید.
          </Callout>
        )}

        <div className="flex flex-wrap gap-2">
          <Button variant={mode === 'webhook' ? 'secondary' : 'default'} icon={Link2} disabled={!https || change.isPending} onClick={() => ask('enable')}>
            {mode === 'webhook' ? 'ثبت دوباره Webhook' : 'ثبت Webhook'}
          </Button>
          {mode === 'webhook' && (
            <Button variant="danger-outline" icon={Unlink} disabled={change.isPending} onClick={() => ask('disable')}>
              برداشتن Webhook
            </Button>
          )}
        </div>

        {outcomes && <Outcomes outcomes={outcomes} />}
      </CardContent>

      <ConfirmModal
        open={asking}
        onClose={() => setAsking(false)}
        title={CHANGES[subject].title}
        confirmLabel={CHANGES[subject].confirm}
        destructive={subject === 'disable'}
        pending={change.isPending}
        error={change.error ? messageOf(change.error) : null}
        onConfirm={() => change.mutate(subject)}
      >
        {CHANGES[subject].details}
      </ConfirmModal>
    </Card>
  )
}

/** What happened to each bot, in the server's words — or that no bot has a token to switch. */
function Outcomes({ outcomes }: { outcomes: WebhookOutcome[] }) {
  if (outcomes.length === 0) {
    return (
      <Callout tone="info" role="status">
        هیچ رباتی توکن ندارد؛ اول توکن ربات را بالاتر ذخیره کنید.
      </Callout>
    )
  }

  return (
    <ul className="grid gap-2 border-t border-border pt-4" aria-label="نتیجه برای هر ربات">
      {outcomes.map(({ bot, done, message }) => (
        <li key={bot.id} className="flex items-start gap-2.5">
          <StatusDot tone={done ? 'success' : 'danger'} className="mt-2" />
          <div className="grid min-w-0 gap-0.5">
            <span className="font-medium">
              {bot.id === MAIN_SHOP ? (
                'ربات اصلی'
              ) : bot.username ? (
                <bdi dir="ltr">@{bot.username}</bdi>
              ) : (
                <>
                  ربات نماینده <bdi dir="ltr">#{bot.id}</bdi>
                </>
              )}
            </span>
            <span className={done ? 'text-footnote text-muted-foreground' : 'text-footnote text-danger'}>{message}</span>
          </div>
        </li>
      ))}
    </ul>
  )
}
