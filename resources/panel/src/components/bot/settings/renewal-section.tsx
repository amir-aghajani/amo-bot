import { CalendarClock, Repeat } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field, FormPart } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { api } from '@/lib/api'
import type { BotAutoRenewSettingsRequest, BotRenewalSettingsRequest, BotSettingsData, RenewalMethod } from '@/lib/api-types'
import { formatNumber, wholeNumberOf } from '@/lib/format'
import { RENEWAL_SETTINGS } from '@/lib/limits'
import { RENEWAL_METHOD } from '@/lib/statuses'

/** Each renewal method: its switch and what it does — in the bot's order; its name is RENEWAL_METHOD's. */
const METHODS: { method: RenewalMethod; field: 'renew_extend' | 'renew_reset_traffic' | 'renew_reset_time'; about: string }[] = [
  {
    method: 'extend',
    field: 'renew_extend',
    about: 'روزهای پلن به روزهای مانده و حجم پلن به حجم سرویس اضافه می‌شود؛ حجم مانده طبق تنظیم بالا به دوره جدید می‌رود یا با پایان دوره فعلی از بین می‌رود.',
  },
  {
    method: 'reset_traffic',
    field: 'renew_reset_traffic',
    about: 'روزهای پلن به روزهای مانده اضافه می‌شود و حجم از نو با حجم پلن شمرده می‌شود؛ حجم مانده از بین می‌رود.',
  },
  {
    method: 'reset_time',
    field: 'renew_reset_time',
    about: 'مدت سرویس از همان لحظه با مدت پلن از نو شروع می‌شود و روزهای مانده از بین می‌رود؛ حجم پلن به حجم مانده اضافه می‌شود.',
  },
]

/** «تمدید سرویس»: the rule of every renewal, then «تمدید خودکار» — two groups, a card each. */
export function RenewalSection({ settings }: { settings: BotSettingsData }) {
  return (
    <>
      <RenewalCard settings={settings} />
      <AutoRenewCard settings={settings} />
    </>
  )
}

/**
 * What a renewal does with the traffic left, from when a customer may renew a running service («آستانه مجاز تمدید»),
 * and the ways a renewal adds its plan — one at least on, automatic renewal's default among those on.
 */
function RenewalCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    {
      carry_traffic: settings.carry_traffic,
      renew_window_days: String(settings.renew_window_days),
      renew_window_percent: String(settings.renew_window_percent),
      renew_extend: settings.renew_extend,
      renew_reset_traffic: settings.renew_reset_traffic,
      renew_reset_time: settings.renew_reset_time,
      renew_default_method: settings.renew_default_method,
    } satisfies BotRenewalSettingsRequest,
    (values) => api.put('/bot/settings/renewal', values),
  )
  const { values, set, error } = group
  const on = METHODS.filter(({ field }) => values[field])

  /**
   * A method switched. Automatic renewal's method stays one of those on: once it is not, it moves to the first that is —
   * the next one still on when it is switched off, the one switched on when every method was off.
   */
  const toggle = (method: RenewalMethod, field: (typeof METHODS)[number]['field'], checked: boolean) => {
    set(field, checked)
    const onNow = METHODS.filter((other) => (other.method === method ? checked : values[other.field]))
    const first = onNow[0]
    if (first && !onNow.some((other) => other.method === values.renew_default_method)) set('renew_default_method', first.method)
  }

  return (
    <SectionCard
      form={group}
      title="تمدید سرویس"
      description="قانون تمدید، چه مشتری خودش تمدید کند و چه تمدید خودکار: حجم باقی‌مانده، از کی مشتری می‌تواند سرویسش را تمدید کند، و روش‌هایی که تمدید با آن‌ها انجام می‌شود."
    >
      <SwitchRow
        label="حجم باقی‌مانده به دوره جدید منتقل شود"
        hint="برای روش «تمدید پلن فعلی». روشن: حجم مصرف‌نشده دوره فعلی به حجم دوره جدید اضافه می‌شود. خاموش: حجم مصرف‌نشده تا پایان دوره فعلی قابل استفاده است و بعد از آن صفر می‌شود؛ دوره جدید با حجم پلن شروع می‌شود. تمدید زودتر از پایان دوره، چیزی از حجم فعلی مشتری کم نمی‌کند."
        checked={values.carry_traffic}
        onCheckedChange={(checked) => set('carry_traffic', checked)}
        error={error('carry_traffic')}
      />

      <FormPart
        icon={CalendarClock}
        title="آستانه مجاز تمدید"
        about="تا سرویس فعال به این آستانه نرسیده، مشتری در ربات و سایت نمی‌تواند تمدیدش کند و می‌بیند از کی می‌تواند. با هر دو، هر کدام زودتر برسد؛ با هر دو صفر، هر وقت بخواهد. سرویس تمام‌شده همیشه تمدید می‌شود، و سرویسی که هنوز وصل نشده یا پایان ندارد فقط با حجم. تمدید خودکار روزهای خودش را دارد."
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id="renew_window_days"
            label="روز مانده به پایان"
            error={error('renew_window_days')}
            hint={windowDaysHint(wholeNumberOf(values.renew_window_days), wholeNumberOf(values.renew_window_percent))}
          >
            <Input dir="ltr" inputMode="numeric" value={values.renew_window_days} onChange={(e) => set('renew_window_days', e.target.value)} />
          </Field>
          <Field
            id="renew_window_percent"
            label="درصد حجم مانده"
            error={error('renew_window_percent')}
            hint={windowPercentHint(wholeNumberOf(values.renew_window_percent), wholeNumberOf(values.renew_window_days))}
          >
            <Input dir="ltr" inputMode="numeric" value={values.renew_window_percent} onChange={(e) => set('renew_window_percent', e.target.value)} />
          </Field>
        </div>
      </FormPart>

      <FormPart
        icon={Repeat}
        title="روش‌های تمدید"
        about="با بیش از یک روش روشن، مشتری در صفحه پرداخت تمدید (ربات و سایت) یکی را انتخاب می‌کند و تاریخ پایان و حجم بعد از تمدید را با همان روش می‌بیند؛ صفحه با اولین روش روشن باز می‌شود. قیمت تمدید در همه روش‌ها یکی است."
      >
        {METHODS.map(({ method, field, about }) => (
          <SwitchRow key={method} label={RENEWAL_METHOD[method]} hint={about} checked={values[field]} onCheckedChange={(checked) => toggle(method, field, checked)} error={error(field)} />
        ))}
        <Field
          id="renew_default_method"
          label="روش تمدید خودکار"
          error={error('renew_default_method')}
          hint={`تمدید خودکار از کیف پول با این روش انجام می‌شود؛ یکی از روش‌های روشن. با «${RENEWAL_METHOD.reset_time}» تمدید تا آخرین نیم‌ساعت پیش از پایان سرویس صبر می‌کند تا از مدت پرداخت‌شده بیش از همین نیم‌ساعت از بین نرود؛ اگر موجودی کیف پول کافی نباشد، مشتری همچنان از «چند روز پیش از پایان سرویس» خبردار می‌شود.`}
        >
          {(control) => (
            <Select
              value={values.renew_default_method}
              // Radix's hidden select reports "" while the value has no item drawn (every method off, or the one just
              // switched on not drawn yet): that is no choice, and the method stays as toggle() left it.
              onValueChange={(method) => method !== '' && set('renew_default_method', method as RenewalMethod)}
              disabled={on.length === 0}
            >
              <SelectTrigger {...control} className="w-full sm:max-w-72">
                <SelectValue placeholder="یک روش را روشن کنید" />
              </SelectTrigger>
              <SelectContent>
                {on.map(({ method }) => (
                  <SelectItem key={method} value={method}>
                    {RENEWAL_METHOD[method]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
      </FormPart>
    </SectionCard>
  )
}

/** Both numbers 0: nothing holds a renewal back. */
const WINDOW_ALWAYS = 'با هر دو 0، مشتری هر وقت بخواهد می‌تواند تمدید کند.'

/**
 * What the days typed mean: how near its end a running service may be renewed — 0, never by the days: the percent alone
 * opens it, or with both 0 any time —; the bounds while it is none of them.
 */
function windowDaysHint(days: number | null, percent: number | null): string {
  if (days === null || days > RENEWAL_SETTINGS.WINDOW_DAYS_MAX) return `بین 0 تا ${RENEWAL_SETTINGS.WINDOW_DAYS_MAX} روز؛ 0 یعنی بدون این شرط.`
  if (days === 0) return percent === 0 ? WINDOW_ALWAYS : '0: روزهای مانده تمدید را باز نمی‌کند؛ فقط درصد حجم مانده بازش می‌کند.'
  return `از ${formatNumber(days)} روز مانده به پایان سرویس، مشتری می‌تواند آن را تمدید کند.`
}

/**
 * What the percent typed means: how little traffic left lets a running service be renewed — 0, never by the traffic: the
 * days alone open it, or with both 0 any time —; the bounds while it is none of them.
 */
function windowPercentHint(percent: number | null, days: number | null): string {
  if (percent === null || percent > RENEWAL_SETTINGS.WINDOW_PERCENT_MAX) return `بین 0 تا ${RENEWAL_SETTINGS.WINDOW_PERCENT_MAX} درصد؛ 0 یعنی بدون این شرط.`
  if (percent === 0) return days === 0 ? WINDOW_ALWAYS : '0: حجم مانده تمدید را باز نمی‌کند؛ فقط روزهای مانده بازش می‌کند.'
  return `وقتی ${formatNumber(percent)}٪ از حجم سرویس یا کمتر مانده باشد، مشتری می‌تواند آن را تمدید کند.`
}

/** «تمدید خودکار»: how many days before its deadline a service is renewed from the wallet, and how new services start. */
function AutoRenewCard({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ auto_renew_days: String(settings.auto_renew_days), auto_renew_default: settings.auto_renew_default } satisfies BotAutoRenewSettingsRequest, (values) =>
    api.put('/bot/settings/auto_renew', values),
  )
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="تمدید خودکار"
      description="مشتری می‌تواند تمدید خودکار هر سرویسش را از صفحه همان سرویس در ربات روشن کند: چند روز پیش از پایان سرویس، قیمت پلن از کیف پولش کسر و سرویس تمدید می‌شود. اگر موجودی کافی نباشد، یک بار در ربات خبردار می‌شود."
    >
      <Field id="renewal_days" label="چند روز پیش از پایان سرویس" error={error('auto_renew_days')}>
        <Input dir="ltr" inputMode="numeric" value={values.auto_renew_days} onChange={(e) => set('auto_renew_days', e.target.value)} className="sm:max-w-40" />
      </Field>
      <SwitchRow
        label="برای سرویس‌های جدید روشن باشد"
        hint="روشن که باشد، هر سرویسی که از این به بعد خریده می‌شود با تمدید خودکار روشن شروع می‌شود و مشتری می‌تواند آن را خاموش کند. روی سرویس‌های فعلی اثری ندارد."
        checked={values.auto_renew_default}
        onCheckedChange={(checked) => set('auto_renew_default', checked)}
        error={error('auto_renew_default')}
      />
    </SectionCard>
  )
}
