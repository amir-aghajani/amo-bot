import { Coins, Gift, Info, ShieldAlert, Trophy } from 'lucide-react'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { Field, FieldGroup, FormPart } from '@/components/field'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { PrizeAmountField } from '@/components/rewards/prize-fields'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotReferralSettingsRequest, BotSettingsData, ReferralStartTrigger } from '@/lib/api-types'
import { amountDraft, digitsOnly, formatMoney, formatNumber, wholeNumberOf, wholeOf } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { trimmed } from '@/lib/use-form'

/** When the start gift is paid: on the first purchase (the default — a made-up newcomer buys nothing), or as they join. */
const TRIGGERS: PageTab<ReferralStartTrigger>[] = [
  { value: 'purchase', label: 'با اولین خرید' },
  { value: 'join', label: 'با عضویت' },
]

/** The group as its form holds it: the numbers as typed, the plan's number as text ('' none). */
function draftOf(settings: BotSettingsData) {
  return {
    referral_enabled: settings.referral_enabled,
    referral_rate: String(settings.referral_rate),
    referral_renewal_rate: String(settings.referral_renewal_rate),
    referral_first_only: settings.referral_first_only,
    referral_start_referrer: amountDraft(settings.referral_start_referrer),
    referral_start_invitee: amountDraft(settings.referral_start_invitee),
    referral_start_when: settings.referral_start_when,
    referral_reward_every: String(settings.referral_reward_every),
    referral_reward_plan_id: settings.referral_reward_plan_id === null ? '' : String(settings.referral_reward_plan_id),
    referral_alert_burst: String(settings.referral_alert_burst),
    referral_alert_minutes: String(settings.referral_alert_minutes),
    referral_alert_idle: String(settings.referral_alert_idle),
  } satisfies BotReferralSettingsRequest
}

/**
 * «زیرمجموعه‌گیری»: the program's switch and four parts — the commission (renewals at a rate of their own), the start gift
 * both sides get («هدیه شروع»), the plan given every so many buying referrals («جایزه دعوت»), and the fake-referral alerts,
 * which hold the gifts back until support clears them on the referrals page.
 */
export function ReferralSection({ settings }: { settings: BotSettingsData }) {
  // The referrals page shows the rules with its numbers. A number is what the server reads of it: «۲۰٬۰۰۰» is 20000.
  const group = useBotSettingsGroup(draftOf(settings), (values) => api.put('/bot/settings/referral', values), {
    invalidates: [queryKeys.referrals],
    reads: (values) => trimmed(Object.fromEntries(Object.entries(values).map(([name, value]) => [name, typeof value === 'string' ? (wholeOf(value) ?? value) : value]))),
  })
  const { values, set, error } = group
  const rate = Number(digitsOnly(values.referral_rate))
  const renewalRate = wholeOf(values.referral_renewal_rate)
  const every = wholeNumberOf(values.referral_reward_every) ?? 0
  const off = !values.referral_enabled

  return (
    <>
      {settings.referral_enabled && <MenuButtonNotice action="affiliates" label="زیرمجموعه‌گیری" screen="صفحه لینک دعوتشان" />}
      <SectionCard
        form={group}
        title="زیرمجموعه‌گیری"
        description="هر مشتری در صفحه «زیرمجموعه‌گیری» ربات لینک دعوت خودش را دارد. کسی که اولین بار با آن لینک وارد ربات شود زیرمجموعه اوست و از پرداخت‌هایش، پورسانت به کیف پول معرف اضافه می‌شود؛ کسی که از قبل در ربات ثبت شده باشد، با هیچ لینکی زیرمجموعه کسی نمی‌شود."
      >
        <SwitchRow
          label="زیرمجموعه‌گیری فعال باشد"
          hint="خاموش که باشد، دکمه «زیرمجموعه‌گیری» از منوی ربات برداشته می‌شود، لینک‌ها کسی را زیرمجموعه نمی‌کنند و پرداختی پورسانت یا هدیه‌ای ندارد. با روشن شدن دوباره، دکمه سر جایش برمی‌گردد و زیرمجموعه‌ها و پورسانت‌های قبلی سر جایشان می‌مانند."
          checked={values.referral_enabled}
          onCheckedChange={(checked) => set('referral_enabled', checked)}
          error={error('referral_enabled')}
        />

        <FormPart icon={Coins} title="پورسانت" about="از پولی که زیرمجموعه واقعا واریز می‌کند، سهمی به کیف پول معرف اضافه می‌شود.">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              id="referral_rate"
              label="درصد پورسانت"
              error={error('referral_rate')}
              hint={rate > 0 ? `از پرداخت ${formatMoney(100000)}، ${formatMoney(Math.floor((100000 * rate) / 100))} به کیف پول معرف اضافه می‌شود.` : 'درصدی از مبلغ هر پرداخت'}
            >
              <Input dir="ltr" inputMode="numeric" value={values.referral_rate} onChange={(e) => set('referral_rate', e.target.value)} disabled={off} />
            </Field>
            <Field
              id="referral_renewal_rate"
              label="درصد پورسانت تمدید"
              error={error('referral_renewal_rate')}
              hint={renewalRate === '0' ? 'تمدید سرویس پورسانت ندارد.' : 'پرداخت تمدید سرویس به جای درصد بالا این درصد را دارد؛ 0 یعنی بدون پورسانت.'}
            >
              <Input dir="ltr" inputMode="numeric" value={values.referral_renewal_rate} onChange={(e) => set('referral_renewal_rate', e.target.value)} disabled={off} />
            </Field>
          </div>
          <SwitchRow
            label="فقط اولین پرداخت هر زیرمجموعه"
            hint="روشن: فقط اولین پرداخت موفق هر زیرمجموعه پورسانت دارد. خاموش: هر پرداخت موفق او."
            checked={values.referral_first_only}
            onCheckedChange={(checked) => set('referral_first_only', checked)}
            error={error('referral_first_only')}
          />
        </FormPart>

        <FormPart
          icon={Gift}
          title="هدیه شروع"
          about="به ازای هر زیرمجموعه یک بار، مبلغی به کیف پول معرف و مبلغی به کیف پول خود زیرمجموعه اضافه می‌شود و هر دو در ربات خبردار می‌شوند. 0 یعنی بدون هدیه."
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="referral_start_referrer" label="هدیه معرف (تومان)" error={error('referral_start_referrer')} hint={amountHint(values.referral_start_referrer)}>
              <Input dir="ltr" inputMode="numeric" value={values.referral_start_referrer} onChange={(e) => set('referral_start_referrer', e.target.value)} disabled={off} />
            </Field>
            <Field id="referral_start_invitee" label="هدیه زیرمجموعه (تومان)" error={error('referral_start_invitee')} hint={amountHint(values.referral_start_invitee)}>
              <Input dir="ltr" inputMode="numeric" value={values.referral_start_invitee} onChange={(e) => set('referral_start_invitee', e.target.value)} disabled={off} />
            </Field>
          </div>
          <FieldGroup
            label="زمان هدیه"
            error={error('referral_start_when')}
            hint={
              values.referral_start_when === 'purchase'
                ? 'وقتی اولین خرید زیرمجموعه پرداخت شد — با هر روش، کیف پول هم؛ سرویس هدیه خرید حساب نمی‌شود. حساب‌هایی که فقط برای هدیه ساخته شوند چیزی نمی‌گیرند.'
                : 'همان لحظه که زیرمجموعه با لینک وارد ربات یا وب‌سایت می‌شود.'
            }
          >
            <PageTabs as="choice" size="sm" className="w-fit" aria-label="زمان هدیه شروع" value={values.referral_start_when} tabs={TRIGGERS} onChange={(when) => set('referral_start_when', when)} />
          </FieldGroup>
        </FormPart>

        <FormPart icon={Trophy} title="جایزه دعوت" about="هر بار که تعداد زیرمجموعه‌های خریدار یک معرف به مضرب تازه‌ای از این عدد برسد، یک سرویس از پلن جایزه رایگان به او داده می‌شود.">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              id="referral_reward_every"
              label="به ازای هر چند زیرمجموعه خریدار"
              error={error('referral_reward_every')}
              hint={every > 0 ? `با خرید ${formatNumber(every)}امین، ${formatNumber(every * 2)}امین و … زیرمجموعه` : '0 یعنی بدون جایزه'}
            >
              <Input dir="ltr" inputMode="numeric" value={values.referral_reward_every} onChange={(e) => set('referral_reward_every', e.target.value)} disabled={off} />
            </Field>
            <PrizeAmountField
              id="referral_reward_plan_id"
              kind="plan"
              value={values.referral_reward_plan_id}
              onChange={(id) => set('referral_reward_plan_id', id)}
              error={error('referral_reward_plan_id')}
              hint="سرویس جایزه روی اولین سروری ساخته می‌شود که این پلن را می‌فروشد."
              disabled={off || every === 0}
            />
          </div>
        </FormPart>

        <FormPart
          icon={ShieldAlert}
          title="هشدار زیرمجموعه‌گیری فیک"
          about="معرفی که زیرمجموعه‌هایش ساختگی به نظر برسند، در گروه گزارش‌ها (بخش کاربران جدید) و صفحه «زیرمجموعه‌گیری» هشدار می‌گیرد. تا پشتیبانی هشدار را رفع نکند، هدیه شروع و جایزه دعوت به او داده نمی‌شود — و بعد از رفع هم داده نمی‌شود؛ پورسانت‌ها مثل قبل است. 0 یعنی بدون آن هشدار."
        >
          <div className="grid gap-4 sm:grid-cols-3">
            <Field id="referral_alert_burst" label="عضویت پشت سر هم" error={error('referral_alert_burst')} hint="این تعداد زیرمجموعه با یک لینک در بازه هشدار">
              <Input dir="ltr" inputMode="numeric" value={values.referral_alert_burst} onChange={(e) => set('referral_alert_burst', e.target.value)} disabled={off} />
            </Field>
            <Field id="referral_alert_minutes" label="بازه هشدار (دقیقه)" error={error('referral_alert_minutes')} hint="در هر بازه، هر نوع هشدار یک بار">
              <Input dir="ltr" inputMode="numeric" value={values.referral_alert_minutes} onChange={(e) => set('referral_alert_minutes', e.target.value)} disabled={off} />
            </Field>
            <Field id="referral_alert_idle" label="زیرمجموعه بدون خرید" error={error('referral_alert_idle')} hint="این تعداد زیرمجموعه که هیچ‌کدام خرید نکرده‌اند">
              <Input dir="ltr" inputMode="numeric" value={values.referral_alert_idle} onChange={(e) => set('referral_alert_idle', e.target.value)} disabled={off} />
            </Field>
          </div>
        </FormPart>

        <Callout tone="info" icon={Info}>
          پورسانت فقط از پولی حساب می‌شود که واقعا واریز شده است: پرداخت کارت به کارت یا درگاه، برای خرید، تمدید یا شارژ کیف پول. خرید از کیف پول دوباره پورسانت ندارد، چون پولش وقتی کیف پول شارژ شد
          حساب شده است؛ برگشت وجه هم به کیف پول مشتری است و پورسانت را پس نمی‌گیرد. زیرمجموعه‌ها، پورسانت‌ها و هشدارها در صفحه{' '}
          <TextLink to="/referrals" inline className="font-medium">
            زیرمجموعه‌گیری
          </TextLink>{' '}
          دیده می‌شوند.
        </Callout>
      </SectionCard>
    </>
  )
}

/** What an amount typed comes to, or what 0 means. */
function amountHint(typed: string): string {
  const amount = wholeOf(typed)
  return amount === null ? 'مبلغ به تومان؛ 0 یعنی بدون هدیه' : Number(amount) > 0 ? `= ${formatMoney(amount)}` : 'بدون هدیه'
}
