import { useId, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Calculator, CalendarDays, HardDrive, Server, type LucideIcon } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { CheckList, optionsRead } from '@/components/check-list'
import { customPrice, customServicesDraft, customServicesRead, customServicesRequest, optionsOf, type CustomServicesDraft, type QuantityKind } from '@/components/custom-services/draft'
import { Field, FormPart } from '@/components/field'
import { PricedRowsEditor } from '@/components/priced-rows-editor'
import { SectionCard } from '@/components/section-card'
import { serversQuery } from '@/components/servers/queries'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotSettingsData } from '@/lib/api-types'
import { formatDays, formatDevices, formatGb, formatMoney, formatNumber, wholeNumberOf } from '@/lib/format'
import { CUSTOM_SERVICE_SETTINGS, EXTRA_OPTIONS, PACKAGE, TIER_PRICES } from '@/lib/limits'
import type { Form } from '@/lib/use-form'

/** How each quantity is worded, and the form's names of its fields (the names the server refuses them by). */
interface QuantityWords {
  title: string
  icon: LucideIcon
  about: string
  unitPrice: string
  unit: string
  /** The server's bound on the quantity (lib/limits). */
  most: number
  amount: (quantity: number) => string
  /** Placeholders — guidance, in Latin digits —: a tier's start, and the amounts offered as buttons. */
  example: string
  examples: string
  fields: {
    tiers: 'custom_traffic_tiers' | 'custom_days_tiers'
    min: 'custom_traffic_min' | 'custom_days_min'
    max: 'custom_traffic_max' | 'custom_days_max'
    options: 'custom_traffic_options' | 'custom_days_options'
  }
}

const QUANTITIES: Record<QuantityKind, QuantityWords> = {
  traffic: {
    title: 'حجم',
    icon: HardDrive,
    about: 'گیگابایت‌هایی که مشتری برای سرویسش انتخاب می‌کند.',
    unitPrice: 'قیمت هر گیگابایت',
    unit: 'گیگابایت',
    most: PACKAGE.MAX_GB,
    amount: formatGb,
    example: '50',
    examples: '10, 50, 100',
    fields: { tiers: 'custom_traffic_tiers', min: 'custom_traffic_min', max: 'custom_traffic_max', options: 'custom_traffic_options' },
  },
  days: {
    title: 'مدت',
    icon: CalendarDays,
    about: 'روزهایی که سرویس از اولین اتصال کار می‌کند.',
    unitPrice: 'قیمت هر روز',
    unit: 'روز',
    most: PACKAGE.MAX_DAYS,
    amount: formatDays,
    example: '30',
    examples: '30, 60, 90',
    fields: { tiers: 'custom_days_tiers', min: 'custom_days_min', max: 'custom_days_max', options: 'custom_days_options' },
  },
}

type HostForm = Pick<Form<CustomServicesDraft>, 'values' | 'set' | 'error'>

/**
 * The `custom_services` bot-settings group (main shop only): the switch, the GB's and the days' price tiers with their
 * bounds and the amounts offered as buttons, the devices of a built service, the servers it is built on — and a
 * calculator of what a customer pays for what they build, from the tiers as typed.
 */
export function CustomServicesCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(customServicesDraft(settings), (values) => api.put('/bot/settings/custom_services', customServicesRequest(values)), {
    reads: customServicesRead,
  })
  const { values, set, error } = group
  const devices = wholeNumberOf(values.custom_devices)

  return (
    <SectionCard
      form={group}
      title="تنظیمات سرویس دلخواه"
      description="مشتری حجم و مدت سرویسش را خودش انتخاب می‌کند و قیمت هر کدام با پله‌های قیمت آن حساب می‌شود؛ قیمت حجم و قیمت مدت با هم جمع می‌شوند و تخفیف‌های مشتری از آن کم می‌شود."
    >
      <SwitchRow
        label="سرویس دلخواه فعال باشد"
        hint="روشن که باشد، در ربات زیر پلن‌ها دکمه «ساخت سرویس دلخواه» می‌آید و وب‌سایت هم آن را می‌فروشد — تا وقتی هر دو قیمت پله دارند و یکی از سرورهای انتخاب‌شده مشتری تازه می‌گیرد."
        checked={values.custom_enabled}
        onCheckedChange={(checked) => set('custom_enabled', checked)}
        error={error('custom_enabled')}
      />

      <div className="@container">
        <div className="grid gap-4 @xl:grid-cols-2">
          <QuantityPart kind="traffic" form={group} />
          <QuantityPart kind="days" form={group} />
        </div>
      </div>

      <FormPart icon={Server} title="سرور و کاربر" about="سرویس روی همه اینباندهای قابل فروش سروری ساخته می‌شود که مشتری انتخاب می‌کند؛ اگر فقط یک سرور در دسترس باشد، انتخاب سرور پرسیده نمی‌شود.">
        <Field
          id="custom_devices"
          label="تعداد کاربر هم‌زمان"
          error={error('custom_devices')}
          hint={devices === null ? `بین 0 تا ${CUSTOM_SERVICE_SETTINGS.MAX_DEVICES}` : devices === 0 ? '0 یعنی نامحدود.' : `هر سرویس دلخواه ${formatDevices(devices)}.`}
        >
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values.custom_devices} onChange={(e) => set('custom_devices', e.target.value)} className="max-w-32" />
        </Field>
        <ServerChoice value={values.custom_servers} onChange={(ids) => set('custom_servers', ids)} error={error('custom_servers')} />
      </FormPart>

      <PriceCalculator draft={values} />
    </SectionCard>
  )
}

/** One quantity's prices: its tiers, the bounds a customer builds within and the amounts offered as buttons. */
function QuantityPart({ kind, form }: { kind: QuantityKind; form: HostForm }) {
  const id = useId()
  const words = QUANTITIES[kind]
  const { values, set, error } = form
  const field = words.fields
  const options = optionsOf(values[field.options])

  return (
    <FormPart icon={words.icon} title={words.title} about={words.about}>
      {/* Tiers may be typed in any order (the server keeps them smallest first); a row with both blank is nothing. */}
      <PricedRowsEditor
        label={words.unitPrice}
        description={`هر پله: از چند ${words.unit} به بالا، هر ${words.unit} چند تومان. همه ${words.unit}‌ها با قیمت پله‌ای حساب می‌شوند که عدد مشتری در آن است.`}
        error={error(field.tiers)}
        rows={values[field.tiers]}
        onChange={(rows) => set(field.tiers, rows)}
        amount="from"
        headings={{ amount: `از (${words.unit})`, price: `قیمت هر ${words.unit} (تومان)` }}
        wide
        names={(place) => {
          const row = `پله ${place}`
          return { row, amount: `${row}: از (${words.unit})`, price: `${row}: قیمت هر ${words.unit} (تومان)` }
        }}
        example={words.example}
        noun="پله"
        most={TIER_PRICES.MOST}
        empty="بدون پله قیمت، سرویس دلخواه فروخته نمی‌شود."
      />
      <div className="grid grid-cols-2 gap-3">
        <Field id={`${id}-min`} label={`کمترین (${words.unit})`} error={error(field.min)}>
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values[field.min]} onChange={(e) => set(field.min, e.target.value)} />
        </Field>
        <Field id={`${id}-max`} label={`بیشترین (${words.unit})`} error={error(field.max)}>
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values[field.max]} onChange={(e) => set(field.max, e.target.value)} />
        </Field>
      </div>
      <Field
        id={`${id}-options`}
        label={`دکمه‌های پیشنهادی (${words.unit})`}
        error={error(field.options)}
        hint={
          options && options.length > 0
            ? `دکمه‌ها: ${options.map(words.amount).join('، ')} — مشتری عدد دیگری بین کمترین و بیشترین را هم می‌نویسد.`
            : `عددها را با کاما جدا کنید (حداکثر ${formatNumber(EXTRA_OPTIONS.MOST)} دکمه، بین 1 تا ${words.most})؛ خالی یعنی مشتری عدد را خودش می‌نویسد.`
        }
      >
        <Input dir="ltr" autoComplete="off" placeholder={words.examples} value={values[field.options]} onChange={(e) => set(field.options, e.target.value)} />
      </Field>
    </FormPart>
  )
}

/**
 * The servers a service may be built on, ticked among the shop's servers; one that cannot sell now says why beside it.
 * While the shop's servers are read, or could not be, the list says so.
 */
function ServerChoice({ value, onChange, error }: { value: number[]; onChange: (ids: number[]) => void; error?: string }) {
  const query = useQuery(serversQuery)
  const servers = query.data?.servers ?? []
  const options = servers.map((server) => ({ value: server.id, label: server.name, hint: server.unsellable_reason === null ? undefined : 'الان نمی‌فروشد' }))
  // A server ticked before it was deleted stays ticked until the next save drops it.
  const known = value.filter((id) => servers.some((server) => server.id === id))

  return (
    <CheckList
      label="سرورها"
      hint="مشتری روی یکی از این سرورها سرویس می‌سازد؛ سروری که الان نمی‌فروشد (خاموش، پر یا بدون لینک اشتراک) به مشتری نشان داده نمی‌شود."
      options={options}
      value={servers.length === 0 ? value : known}
      onChange={onChange}
      error={error}
      {...optionsRead(query, 'سرورها')}
    />
  )
}

/** What a customer pays for the GB and days typed here — before their discounts —, from the tiers as typed now. */
function PriceCalculator({ draft }: { draft: CustomServicesDraft }) {
  const id = useId()
  const [gb, setGb] = useState('50')
  const [days, setDays] = useState('30')
  const price = customPrice(draft, gb, days)

  return (
    <FormPart icon={Calculator} title="ماشین حساب قیمت" about="قیمتی که مشتری برای این حجم و مدت می‌بیند، پیش از تخفیف‌هایش؛ با همین پله‌ها و مرزها، حتی پیش از ذخیره.">
      <div className="grid grid-cols-2 gap-3">
        <Field id={`${id}-gb`} label="حجم (گیگابایت)" hint={'refusals' in price ? price.refusals.traffic : undefined}>
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={gb} onChange={(e) => setGb(e.target.value)} />
        </Field>
        <Field id={`${id}-days`} label="مدت (روز)" hint={'refusals' in price ? price.refusals.days : undefined}>
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={days} onChange={(e) => setDays(e.target.value)} />
        </Field>
      </div>
      <div aria-live="polite">
        {'total' in price && (
          <dl aria-label="قیمت سرویس" className="grid gap-1 text-footnote">
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">{`${formatGb(price.traffic.quantity)} × ${formatMoney(price.traffic.unit)}`}</dt>
              <dd className="tabular">{formatMoney(price.traffic.total)}</dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">{`${formatDays(price.days.quantity)} × ${formatMoney(price.days.unit)}`}</dt>
              <dd className="tabular">{formatMoney(price.days.total)}</dd>
            </div>
            <div className="flex justify-between gap-3 border-t border-border pt-1 text-body font-medium">
              <dt>{`سرویس ${formatNumber(price.traffic.quantity)} گیگابایتی ${formatNumber(price.days.quantity)} روزه`}</dt>
              <dd className="tabular">{formatMoney(price.total)}</dd>
            </div>
          </dl>
        )}
      </div>
    </FormPart>
  )
}
