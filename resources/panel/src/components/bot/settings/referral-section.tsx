import { Info } from 'lucide-react'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotReferralSettingsRequest, BotSettingsData } from '@/lib/api-types'
import { digitsOnly, formatMoney } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** «زیرمجموعه‌گیری»: whether links bring referrals, and what a referred customer's payment earns the one who brought them. */
export function ReferralSection({ settings }: { settings: BotSettingsData }) {
  // The referrals page shows the rules with its numbers.
  const group = useBotSettingsGroup(
    { referral_enabled: settings.referral_enabled, referral_rate: String(settings.referral_rate), referral_first_only: settings.referral_first_only } satisfies BotReferralSettingsRequest,
    (values) => api.put('/bot/settings/referral', values),
    { invalidates: [queryKeys.referrals] },
  )
  const { values, set, error } = group
  const rate = Number(digitsOnly(values.referral_rate))

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
          hint="خاموش که باشد، دکمه «زیرمجموعه‌گیری» از منوی ربات برداشته می‌شود، لینک‌ها کسی را زیرمجموعه نمی‌کنند و پرداختی پورسانت ندارد. با روشن شدن دوباره، دکمه سر جایش برمی‌گردد و زیرمجموعه‌ها و پورسانت‌های قبلی سر جایشان می‌مانند."
          checked={values.referral_enabled}
          onCheckedChange={(checked) => set('referral_enabled', checked)}
          error={error('referral_enabled')}
        />
        <Field
          id="referral_rate"
          label="درصد پورسانت"
          error={error('referral_rate')}
          hint={rate > 0 ? `از پرداخت ${formatMoney(100000)}، ${formatMoney(Math.floor((100000 * rate) / 100))} به کیف پول معرف اضافه می‌شود.` : 'درصدی از مبلغ هر پرداخت'}
        >
          <Input dir="ltr" inputMode="numeric" value={values.referral_rate} onChange={(e) => set('referral_rate', e.target.value)} disabled={!values.referral_enabled} className="sm:max-w-40" />
        </Field>
        <SwitchRow
          label="فقط اولین پرداخت هر زیرمجموعه"
          hint="روشن: فقط اولین پرداخت موفق هر زیرمجموعه پورسانت دارد. خاموش: هر پرداخت موفق او."
          checked={values.referral_first_only}
          onCheckedChange={(checked) => set('referral_first_only', checked)}
          error={error('referral_first_only')}
        />
        <Callout tone="info" icon={Info}>
          پورسانت فقط از پولی حساب می‌شود که واقعا واریز شده است: پرداخت کارت به کارت یا درگاه، برای خرید، تمدید یا شارژ کیف پول. خرید از کیف پول دوباره پورسانت ندارد، چون پولش وقتی کیف پول شارژ شد
          حساب شده است؛ برگشت وجه هم به کیف پول مشتری است و پورسانت را پس نمی‌گیرد. زیرمجموعه‌ها و پورسانت‌ها در صفحه{' '}
          <TextLink to="/referrals" inline className="font-medium">
            زیرمجموعه‌گیری
          </TextLink>{' '}
          دیده می‌شوند.
        </Callout>
      </SectionCard>
    </>
  )
}
