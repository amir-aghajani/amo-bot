import { useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Eye, EyeOff, RefreshCw, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { Step } from '@/components/bot/connect-steps'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { IconButton } from '@/components/icon-button'
import { useReadFailure } from '@/components/page'
import { depositSettingsQuery } from '@/components/payments/deposits/queries'
import { SectionCard } from '@/components/section-card'
import { StatusDot } from '@/components/status-badge'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'
import { messageOf } from '@/lib/failure'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useSettingsGroup } from '@/lib/use-settings-group'

/** Where ForApp's releases are: the owner downloads the app there, on the phone that gets the bank's SMS. */
const FORAPP_RELEASES = 'https://github.com/erfjab/forapp/releases/latest'

/** The list of the bank SMS, every tab: the count below takes in both kinds that approved nothing. */
const DEPOSITS = '/payments/deposits'

/** How often the settings are read again while on and nothing has come yet: the app's first test shows without a reload. */
const WAITING_POLL_MS = 5_000

/**
 * «تایید خودکار واریز» on the payment methods page, once its settings are read — a placeholder meanwhile, the failure
 * with a retry in its place. While it is on and no SMS has come yet, it reads them again every few seconds.
 */
export function DepositSettings() {
  const read = useQuery({
    ...depositSettingsQuery,
    refetchInterval: (query) => {
      const settings = query.state.data
      return settings?.enabled && settings.last_test_at === null && settings.last_received_at === null ? WAITING_POLL_MS : false
    },
  })
  const failure = useReadFailure(read)
  if (!read.data) {
    return failure.error ? (
      <ErrorState what="تنظیمات تایید خودکار واریز" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />
    ) : (
      <Skeleton className="h-40 w-full max-w-[48rem] rounded-xl" />
    )
  }

  // A settings group's width (as a narrow page's), not the list's above it.
  return (
    <div className="grid w-full max-w-[48rem] gap-6">
      <DepositSettingsCard settings={read.data} />
    </div>
  )
}

/**
 * The switch, as its own card — saved as `PUT /deposits/settings`, the answer put in place of the read —, and once it is
 * on, how to connect ForApp: its steps, the address and the key to paste in it, and whether its SMS have been coming.
 */
function DepositSettingsCard({ settings }: { settings: DepositSettingsResponse }) {
  const group = useSettingsGroup({
    save: (values) => api.put('/deposits/settings', values),
    queryKey: queryKeys.depositSettings,
    apply: (_current: DepositSettingsResponse | undefined, result: DepositSettingsResponse) => result,
    initial: { enabled: settings.enabled },
    saved: 'تنظیمات تایید خودکار واریز ذخیره شد',
  })
  const { values, set, error } = group

  return (
    <>
      <SectionCard
        form={group}
        title="تایید خودکار واریز"
        description="پرداخت‌های کارت به کارت با پیامک واریز بانک خودشان تایید می‌شوند: برنامه رایگان فوراپ روی گوشی‌ای که پیامک‌های بانک به آن می‌رسد، هر پیامک را به فروشگاه می‌فرستد و مشتری فقط مبلغ را دقیق کارت به کارت می‌کند."
      >
        <SwitchRow
          label="تایید خودکار واریزها روشن باشد"
          hint="روشن که باشد، هر پرداخت کارت به کارت مبلغ خودش را دارد: چند تومان (حداکثر 999 تومان) بیشتر از قیمت، تا واریزی که درست همان مبلغ را دارد همان پرداخت را تایید کند. خاموش که باشد، پرداخت‌ها مثل قبل با رسید بررسی می‌شوند."
          checked={values.enabled}
          onCheckedChange={(checked) => set('enabled', checked)}
          error={error('enabled')}
        />
      </SectionCard>
      {settings.enabled && settings.key !== null && <ConnectionCard settings={settings} apiKey={settings.key} />}
    </>
  )
}

/**
 * ForApp connected to the shop: the steps on the phone, each phone app's address and the key it sends (`X-API-Key`), to
 * copy — a new key asked first, since the app stops reaching the shop until it gets it —, and what has come since.
 */
function ConnectionCard({ settings, apiKey }: { settings: DepositSettingsResponse; apiKey: string }) {
  const queryClient = useQueryClient()
  const [asking, setAsking] = useState(false)
  const rotate = useMutation({
    mutationFn: () => api.post('/deposits/settings/key'),
    meta: { quiet: true },
    onSuccess: (result) => {
      queryClient.setQueryData<DepositSettingsResponse>(queryKeys.depositSettings, result)
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
          <CardDescription>فوراپ هر پیامک بانک را با کلید API به آدرس وب‌هوک می‌فرستد؛ تا وصل نشود، پرداختی خودکار تایید نمی‌شود.</CardDescription>
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
          <CopyBox key={source.key} label={several ? `آدرس وب‌هوک ${source.label}` : 'آدرس وب‌هوک'} value={source.webhook_url} copied="آدرس وب‌هوک کپی شد" />
        ))}
        <CopyBox
          label="کلید API"
          value={apiKey}
          copied="کلید API کپی شد"
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
        {settings.waiting > 0 && (
          <Callout tone="warning" icon={TriangleAlert}>
            {formatNumber(settings.waiting)} پیامک واریز در هفت روز گذشته هیچ پرداختی را تایید نکرده است.{' '}
            <TextLink to={DEPOSITS} inline className="font-medium">
              دیدن واریزها
            </TextLink>
          </Callout>
        )}
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

/** A key shown hidden: its last four characters after dots, until «نمایش» is pressed. */
function hidden(value: string): string {
  return `${'•'.repeat(12)}${value.slice(-4)}`
}

interface CopyBoxProps {
  label: string
  value: string
  /** The toast once copied. */
  copied: string
  /** A key: hidden until asked, copied whole either way. */
  secret?: boolean
  /** Beside the box (the key's «ساخت کلید تازه»). */
  action?: ReactNode
}

/** A value to paste in the phone app, left to right, with its copy button named for it. */
function CopyBox({ label, value, copied, secret = false, action }: CopyBoxProps) {
  const [shown, setShown] = useState(!secret)

  return (
    <div className="grid gap-1.5">
      <span className="text-footnote text-muted-foreground">{label}</span>
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex min-w-0 flex-1 basis-64 items-center gap-1 rounded-lg border border-border bg-fill p-1 ps-3">
          <code dir="ltr" className="min-w-0 flex-1 text-start text-footnote break-all select-all">
            {shown ? value : hidden(value)}
          </code>
          {secret && <IconButton aria-label="نمایش" aria-pressed={shown} title={shown ? 'پنهان کردن' : 'نمایش'} icon={shown ? EyeOff : Eye} onClick={() => setShown((was) => !was)} />}
          <Button size="sm" variant="secondary" icon={Copy} aria-label={`کپی ${label}`} onClick={() => void copyText(value, copied)}>
            کپی
          </Button>
        </div>
        {action}
      </div>
    </div>
  )
}

/** Whether ForApp's messages have been coming: its last test and the last bank SMS, by how long ago; none yet said so. */
function Arrivals({ settings }: { settings: DepositSettingsResponse }) {
  const { last_test_at: test, last_received_at: sms } = settings

  if (test === null && sms === null) {
    return (
      <p className="flex items-center gap-2 text-footnote text-muted-foreground">
        <StatusDot tone="warning" />
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
