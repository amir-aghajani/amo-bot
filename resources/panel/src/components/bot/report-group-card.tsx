import { useState } from 'react'
import { Link2, RefreshCw, Send, Unlink } from 'lucide-react'
import { ConnectSteps } from '@/components/bot/connect-steps'
import { ConnectedGroup } from '@/components/bot/connected-group'
import { useReportGroup, useReportGroupActions } from '@/components/bot/use-report-group'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { useReadFailure } from '@/components/page'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useMainShop } from '@/lib/auth'

/**
 * The report group: a Telegram group with a topic per subject (sales, receipts, failures…) where the bot reports. Not
 * connected, the card walks the admin through connecting one; connected, it shows the group, its topics, any problem and
 * the queue, with a test, a check and disconnecting. Every action applies at once.
 */
export function ReportGroupCard() {
  // The agency's requests are reported in the main bot's group alone.
  const mainShop = useMainShop()
  const read = useReportGroup()
  const { data: group, refetch } = read
  const failure = useReadFailure(read)
  const { link, check, test, disconnect, busy } = useReportGroupActions()
  const [replacing, setReplacing] = useState(false)
  const [disconnecting, setDisconnecting] = useState(false)

  // A group got connected (this one or another): the "connect another group" steps are done.
  const connectedAt = group?.connected_at ?? null
  const [seenConnection, setSeenConnection] = useState(connectedAt)
  if (seenConnection !== connectedAt) {
    setSeenConnection(connectedAt)
    setReplacing(false)
  }

  return (
    <Card>
      <CardHeader className="border-b border-border pb-4">
        <CardHeading>
          <CardTitle>گروه گزارش‌ها</CardTitle>
          <CardDescription>
            گزارش خریدها، تمدیدها، شارژ کیف پول، رسیدها، کاربران جدید، خطاها، تیکت‌ها{mainShop ? '، نظرات و درخواست‌های نمایندگی' : ' و نظرات'} در یک گروه تلگرام، هر کدام در تاپیک خودش. ربات تاپیک‌ها
            را خودش می‌سازد و مدیرهای ربات رسیدها را همان‌جا با دکمه تایید یا رد می‌کنند و با ریپلای به تیکت‌ها پاسخ می‌دهند.
          </CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="grid gap-5 pt-5">
        {failure.error && <ErrorState what="گروه گزارش‌ها" error={failure.error} onRetry={() => void refetch()} retrying={failure.retrying} />}

        {!group ? (
          !failure.error && <Skeleton className="h-40 w-full" />
        ) : group.connected ? (
          <>
            <ConnectedGroup group={group} />

            {/* Every operation held while one runs — held, not disabled, so the one pressed keeps the focus. */}
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" icon={Send} busy={test.isPending} aria-disabled={busy} onClick={() => test.mutate()}>
                پیام تست
              </Button>
              <Button variant="secondary" icon={RefreshCw} busy={check.isPending} aria-disabled={busy} onClick={() => check.mutate()}>
                بررسی دوباره
              </Button>
              <Button variant="ghost" icon={Link2} aria-disabled={busy || replacing} onClick={() => setReplacing(true)}>
                اتصال گروه دیگر
              </Button>
              <Button variant="danger" icon={Unlink} aria-disabled={busy} onClick={() => setDisconnecting(true)}>
                قطع اتصال
              </Button>
            </div>

            {replacing && (
              <div className="grid gap-4 border-t border-border pt-5">
                <ConnectSteps group={group} creating={link.isPending} onCreate={() => link.mutate()} />
                <p className="text-footnote text-muted-foreground">با وصل شدن گروه دیگر، گزارش‌ها به آن گروه می‌رود و «{group.title}» کنار گذاشته می‌شود.</p>
              </div>
            )}
          </>
        ) : (
          <ConnectSteps group={group} creating={link.isPending} onCreate={() => link.mutate()} />
        )}
      </CardContent>

      <ConfirmModal
        open={disconnecting}
        onClose={() => setDisconnecting(false)}
        title="قطع اتصال گروه گزارش‌ها"
        description={group?.title ? `گزارش‌ها دیگر به «${group.title}» فرستاده نمی‌شود و گزارش‌هایی که در صف مانده‌اند دور ریخته می‌شوند.` : undefined}
        confirmLabel="قطع اتصال"
        destructive
        pending={disconnect.isPending}
        onConfirm={() => disconnect.mutate(undefined, { onSuccess: () => setDisconnecting(false) })}
      >
        ربات در گروه می‌ماند و پیام‌های قبلی سر جایشان هستند؛ اگر نمی‌خواهید، ربات را از تلگرام از گروه بیرون کنید.
      </ConfirmModal>
    </Card>
  )
}
