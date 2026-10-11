import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field, FieldGroup } from '@/components/field'
import { HourSelect } from '@/components/hour-select'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { PrizeAmountField } from '@/components/rewards/prize-fields'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotLotterySettingsRequest, BotSettingsData, LotteryPrizeKind } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { amountDraft, decimalInput } from '@/lib/format'
import { WHEEL_PRIZE_KIND } from '@/lib/statuses'
import { trimmed } from '@/lib/use-form'

const PRIZES: PageTab<LotteryPrizeKind>[] = (['wallet', 'traffic', 'days', 'plan'] as const).map((kind) => ({ value: kind, label: WHEEL_PRIZE_KIND[kind] }))

const ENTRIES: PageTab<BotLotterySettingsRequest['lottery_entries']>[] = [
  { value: 'order', label: 'هر خرید یک شانس' },
  { value: 'buyer', label: 'هر خریدار یک شانس' },
]

interface Draft {
  lottery_enabled: boolean
  lottery_hour: string
  lottery_winners: string
  lottery_prize: LotteryPrizeKind
  lottery_wallet: string
  lottery_traffic_gb: string
  lottery_days: string
  lottery_plan_id: string
  lottery_entries: BotLotterySettingsRequest['lottery_entries']
  lottery_exclude_agents: boolean
}

/** Where the draft keeps each kind of prize's amount: the server keeps them apart, so switching kinds loses none. */
const PRIZE_FIELD = {
  wallet: 'lottery_wallet',
  traffic: 'lottery_traffic_gb',
  days: 'lottery_days',
  plan: 'lottery_plan_id',
} as const satisfies Record<LotteryPrizeKind, keyof Draft>

const ON_A_SERVICE = 'به جدیدترین سرویس فعال برنده اضافه می‌شود؛ برنده‌ای که سرویس فعالی ندارد کنار می‌رود و نفر بعدی برنده می‌شود.'

const PRIZE_HINT: Record<LotteryPrizeKind, string> = {
  wallet: 'به کیف پول برنده اضافه می‌شود.',
  traffic: ON_A_SERVICE,
  days: ON_A_SERVICE,
  plan: 'یک سرویس رایگان از این پلن برای برنده ساخته و در ربات فرستاده می‌شود.',
}

/** The request: only the chosen prize kind's amount (the server keeps the others as they were). */
function requestOf(values: Draft): BotLotterySettingsRequest {
  const { lottery_wallet, lottery_traffic_gb, lottery_days, lottery_plan_id, ...rest } = values
  const amount = {
    wallet: { lottery_wallet: decimalInput(lottery_wallet) },
    traffic: { lottery_traffic_gb },
    days: { lottery_days },
    plan: { lottery_plan_id: lottery_plan_id === '' ? null : lottery_plan_id },
  }[values.lottery_prize]

  return { ...rest, ...amount }
}

/**
 * The `lottery` bot-settings group: whether the nightly draw runs, its hour, its winners and prize, how entries count
 * (one per paid order or one per buyer) and whether agents take part.
 */
export function LotterySettingsCard({ settings }: { settings: BotSettingsData }) {
  const main = useMainShop()
  const group = useBotSettingsGroup<Draft>(
    {
      lottery_enabled: settings.lottery_enabled,
      lottery_hour: String(settings.lottery_hour),
      lottery_winners: String(settings.lottery_winners),
      lottery_prize: settings.lottery_prize,
      lottery_wallet: amountDraft(settings.lottery_wallet),
      lottery_traffic_gb: String(settings.lottery_traffic_gb),
      lottery_days: String(settings.lottery_days),
      lottery_plan_id: settings.lottery_plan_id === null ? '' : String(settings.lottery_plan_id),
      lottery_entries: settings.lottery_entries,
      lottery_exclude_agents: settings.lottery_exclude_agents,
    },
    (values) => api.put('/bot/settings/lottery', requestOf(values)),
    { reads: (values) => trimmed(requestOf(values)) },
  )
  const { values, set, error } = group
  const off = !values.lottery_enabled
  const prize = values.lottery_prize

  return (
    <SectionCard
      form={group}
      title="قرعه‌کشی شبانه"
      description="هر شب در ساعتی که تعیین می‌کنید، از میان مشتری‌هایی که در ۲۴ ساعت گذشته خرید کرده‌اند قرعه کشیده می‌شود؛ جایزه همان لحظه به برنده‌ها داده می‌شود و در ربات خبردار می‌شوند. مدیرهای ربات و مشتری‌های مسدود هیچ‌وقت شرکت داده نمی‌شوند."
    >
      <SwitchRow
        label="قرعه‌کشی شبانه فعال باشد"
        hint="خرید، تمدید و حجم و زمان اضافه شانس می‌آورد، اگر پرداخت شده و برگشت نخورده باشد؛ شارژ کیف پول و سرویس هدیه نه."
        checked={values.lottery_enabled}
        onCheckedChange={(checked) => set('lottery_enabled', checked)}
        error={error('lottery_enabled')}
      />

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="lottery_hour" label="ساعت قرعه‌کشی" error={error('lottery_hour')} hint="به وقت منطقه زمانی فروشگاه؛ شانس‌ها خریدهای ۲۴ ساعت پیش از همین ساعت است.">
          {(control) => <HourSelect {...control} value={values.lottery_hour} onChange={(hour) => set('lottery_hour', hour)} disabled={off} className="w-full sm:max-w-40" />}
        </Field>
        <Field id="lottery_winners" label="تعداد برنده‌ها" error={error('lottery_winners')} hint="بین ۱ تا ۲۰؛ هر مشتری حداکثر یک بار برنده می‌شود.">
          <Input dir="ltr" inputMode="numeric" value={values.lottery_winners} onChange={(e) => set('lottery_winners', e.target.value)} disabled={off} className="sm:max-w-28" />
        </Field>
      </div>

      <FieldGroup label="جایزه هر برنده" error={error('lottery_prize')}>
        <PageTabs as="choice" size="sm" className="w-fit" aria-label="نوع جایزه" value={prize} tabs={PRIZES} onChange={(kind) => set('lottery_prize', kind)} />
      </FieldGroup>
      <PrizeAmountField
        id="lottery_prize_amount"
        kind={prize}
        value={values[PRIZE_FIELD[prize]]}
        onChange={(value) => set(PRIZE_FIELD[prize], value)}
        error={error(PRIZE_FIELD[prize])}
        hint={PRIZE_HINT[prize]}
        disabled={off}
      />

      <FieldGroup
        label="شانس شرکت‌کننده‌ها"
        error={error('lottery_entries')}
        hint={values.lottery_entries === 'order' ? 'مشتری‌ای که سه خرید کرده سه برابر کسی که یک خرید کرده شانس دارد.' : 'همه خریدارها شانس برابر دارند، هر چند بار که خرید کرده باشند.'}
      >
        <PageTabs as="choice" size="sm" className="w-fit" aria-label="شانس شرکت‌کننده‌ها" value={values.lottery_entries} tabs={ENTRIES} onChange={(entries) => set('lottery_entries', entries)} />
      </FieldGroup>

      {main && (
        <SwitchRow
          label="نماینده‌ها شرکت داده نشوند"
          hint="خریدهای نماینده‌ها از ربات اصلی در قرعه‌کشی حساب نمی‌شود."
          checked={values.lottery_exclude_agents}
          onCheckedChange={(checked) => set('lottery_exclude_agents', checked)}
          error={error('lottery_exclude_agents')}
        />
      )}
    </SectionCard>
  )
}
