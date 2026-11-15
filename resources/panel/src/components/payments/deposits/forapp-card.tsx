import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import { toast } from 'sonner'
import { Step } from '@/components/bot/connect-steps'
import { ConfirmModal } from '@/components/confirm-modal'
import { CopyBox } from '@/components/copy-box'
import { keepDepositSettings } from '@/components/payments/deposits/queries'
import { StatusDot } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'

/** Where ForApp's releases are: the owner downloads the app there, on the phone that gets the bank's SMS. */
const FORAPP_RELEASES = 'https://github.com/erfjab/forapp/releases/latest'

interface ForAppCardProps {
  settings: DepositSettingsResponse
  apiKey: string
  /** Its address or key copied: ForApp being set up. */
  onCopied: () => void
}

/**
 * ForApp connected to the shop: the steps on the phone, each phone app's address and the key it sends (`X-API-Key`), to
 * copy — a new key asked first, since the app stops reaching the shop until it gets it —, and what has come since. Beside
 * Rikhtam it is the second way, not needed: its silence is no warning.
 */
export function ForAppCard({ settings, apiKey, onCopied }: ForAppCardProps) {
  const queryClient = useQueryClient()
  const [asking, setAsking] = useState(false)
  const rotate = useMutation({
    mutationFn: () => api.post('/deposits/settings/key'),
    meta: { quiet: true },
    onSuccess: (result) => {
      keepDepositSettings(queryClient, result)
      setAsking(false)
      toast.success('کلید تازه ساخته شد؛ آن را در فوراپ بگذارید')
    },
  })
  const several = settings.sources.length > 1

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">اتصال فوراپ</CardTitle>
          <CardDescription>فوراپ هر پیامک بانک را با کلید API به آدرس وب‌هوک می‌فرستد؛ تا آدرس و کلید زیر را در فوراپ نگذارید، پیامکی از آن به فروشگاه نمی‌رسد.</CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="grid gap-5">
        <ol className="grid gap-2.5 text-body">
          <Step n={1}>
            فوراپ را روی گوشی‌ای که پیامک‌های بانک به آن می‌رسد نصب کنید:{' '}
            <TextLink href={FORAPP_RELEASES} inline>
              دانلود فوراپ
            </TextLink>
          </Step>
          <Step n={2}>در برنامه یک مقصد وب‌هوک تازه اضافه کنید.</Step>
          <Step n={3}>آدرس وب‌هوک و کلید API زیر را در آن بگذارید.</Step>
          <Step n={4}>پیام آزمایشی برنامه را بفرستید؛ رسیدنش همین‌جا دیده می‌شود.</Step>
        </ol>

        {settings.sources.map((source) => (
          <CopyBox key={source.key} label={several ? `آدرس وب‌هوک ${source.label}` : 'آدرس وب‌هوک'} value={source.webhook_url} copied="آدرس وب‌هوک کپی شد" onCopy={onCopied} />
        ))}
        <CopyBox
          label="کلید API"
          value={apiKey}
          copied="کلید API کپی شد"
          onCopy={onCopied}
          secret
          action={
            <Button
              variant="danger-outline"
              size="sm"
              icon={RefreshCw}
              onClick={() => {
                rotate.reset()
                setAsking(true)
              }}
            >
              ساخت کلید تازه
            </Button>
          }
        />

        <Arrivals settings={settings} />
      </CardContent>

      <ConfirmModal
        open={asking}
        onClose={() => setAsking(false)}
        title="ساخت کلید تازه"
        confirmLabel="ساخت کلید تازه"
        destructive
        pending={rotate.isPending}
        error={rotate.error ? messageOf(rotate.error) : null}
        onConfirm={() => rotate.mutate()}
      >
        کلید فعلی همان لحظه از کار می‌افتد و تا کلید تازه را در فوراپ نگذارید، هیچ پیامکی به فروشگاه نمی‌رسد.
      </ConfirmModal>
    </Card>
  )
}

/**
 * Whether ForApp's messages have been coming: its last test and the last bank SMS, by how long ago; none yet said so —
 * a warning while ForApp is the only way, not beside Rikhtam.
 */
function Arrivals({ settings }: { settings: DepositSettingsResponse }) {
  const { last_test_at: test, last_received_at: sms } = settings

  if (test === null && sms === null) {
    return (
      <p className="flex items-center gap-2 text-footnote text-muted-foreground">
        <StatusDot tone={settings.rikhtam.connected ? 'neutral' : 'warning'} />
        هنوز پیامی نرسیده است.
      </p>
    )
  }

  return (
    <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-footnote text-muted-foreground">
      <span className="flex items-center gap-2">
        <StatusDot tone="success" />
        <span>آخرین پیام آزمایشی: {test === null ? 'هنوز نرسیده' : <TimeAgo at={test} />}</span>
      </span>
      <span>آخرین پیامک بانک: {sms === null ? 'هنوز نرسیده' : <TimeAgo at={sms} />}</span>
    </p>
  )
}
