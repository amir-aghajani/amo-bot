import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { useReadFailure } from '@/components/page'
import { ForAppCard } from '@/components/payments/deposits/forapp-card'
import { depositSettingsQuery } from '@/components/payments/deposits/queries'
import { RikhtamCard } from '@/components/payments/deposits/rikhtam-card'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useSettingsGroup } from '@/lib/use-settings-group'

/** The list of the bank SMS, every tab: the count below takes in both kinds that approved nothing. */
const DEPOSITS = '/payments/deposits'

/** How often the settings are read again while ForApp's first message is awaited: the app's test shows without a reload. */
const WAITING_POLL_MS = 5_000

/**
 * Whether ForApp's first message is awaited on screen: on, nothing come from it yet, and ForApp the way being set up —
 * the only one (Rikhtam not connected), or beside Rikhtam once its address or key was copied here (`settingUp`). An owner
 * who uses Rikhtam alone is never asked for again and again.
 */
function awaitsForApp(settings: DepositSettingsResponse | undefined, settingUp: boolean): boolean {
  if (settings === undefined || !settings.enabled || settings.key === null) return false
  return settings.last_test_at === null && settings.last_received_at === null && (!settings.rikhtam.connected || settingUp)
}

/**
 * «تایید خودکار واریز» on the payment methods page, once its settings are read — a placeholder meanwhile, the failure
 * with a retry in its place. While ForApp's first message is awaited (awaitsForApp), it reads them again every few
 * seconds.
 */
export function DepositSettings() {
  const [settingUp, setSettingUp] = useState(false)
  const read = useQuery({
    ...depositSettingsQuery,
    refetchInterval: (query) => (awaitsForApp(query.state.data, settingUp) ? WAITING_POLL_MS : false),
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
      <DepositSettingsCard settings={read.data} onSettingUp={() => setSettingUp(true)} />
    </div>
  )
}

/**
 * The switch, as its own card — saved as `PUT /deposits/settings`, the answer put in place of the read —, with the week's
 * deposits that approved nothing; then the two ways the bank's deposits reach the shop: once it is on, how to connect
 * ForApp (its steps, the address and the key to paste in it, whether its SMS have been coming); and Rikhtam, connected or
 * not, on or off.
 */
function DepositSettingsCard({ settings, onSettingUp }: { settings: DepositSettingsResponse; onSettingUp: () => void }) {
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
        description="پرداخت‌های کارت به کارت با پیامک واریز بانک خودشان تایید می‌شوند و مشتری فقط مبلغ را دقیق کارت به کارت می‌کند. پیامک‌ها از یکی از دو راه به فروشگاه می‌رسند: برنامه رایگان فوراپ روی گوشی‌ای که پیامک‌های بانک به آن می‌رسد، یا ریختم."
      >
        <SwitchRow
          label="تایید خودکار واریزها روشن باشد"
          hint="روشن که باشد، هر پرداخت کارت به کارت مبلغ خودش را دارد: چند تومان (حداکثر 999 تومان) بیشتر از قیمت، تا واریزی که درست همان مبلغ را دارد همان پرداخت را تایید کند. خاموش که باشد، پرداخت‌ها مثل قبل با رسید بررسی می‌شوند."
          checked={values.enabled}
          onCheckedChange={(checked) => set('enabled', checked)}
          error={error('enabled')}
        />
        {/* Either way's deposits — ForApp's or Rikhtam's —: said with the switch, not under one of them. */}
        {settings.enabled && settings.waiting > 0 && (
          <Callout tone="warning" icon={TriangleAlert}>
            {formatNumber(settings.waiting)} پیامک واریز در هفت روز گذشته هیچ پرداختی را تایید نکرده است.{' '}
            <TextLink to={DEPOSITS} inline className="font-medium">
              دیدن واریزها
            </TextLink>
          </Callout>
        )}
      </SectionCard>
      {settings.enabled && settings.key !== null && <ForAppCard settings={settings} apiKey={settings.key} onCopied={onSettingUp} />}
      <RikhtamCard settings={settings} />
    </>
  )
}
