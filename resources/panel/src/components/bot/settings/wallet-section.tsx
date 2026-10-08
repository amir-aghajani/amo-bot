import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotSettingsData, BotWalletSettingsRequest } from '@/lib/api-types'
import { formatMoney, wholeOf, wholesOf } from '@/lib/format'
import { asSet, trimmed } from '@/lib/use-form'

/** The top-up amounts: the smallest the bot accepts, and the buttons it offers before "any amount". */
export function WalletSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    { topup_min: String(settings.topup_min), topup_presets: settings.topup_presets.join(', ') } satisfies BotWalletSettingsRequest,
    (values) => api.put('/bot/settings/wallet', values),
    // The amounts offered as the server keeps them, each once in its own order: the same list typed otherwise is no change.
    { reads: (values) => trimmed({ ...values, topup_presets: asSet(wholesOf(values.topup_presets) ?? [values.topup_presets.trim()]) }) },
  )
  const { values, set, error } = group
  // What the typed amounts read as, under them — read as the server reads them (a comma between groups of three sets
  // thousands apart), and nothing said of what it would refuse.
  const least = wholeOf(values.topup_min)
  const presets = wholesOf(values.topup_presets) ?? []

  return (
    <SectionCard form={group} title="کیف پول" description="مبلغ‌های شارژ کیف پول در ربات. مشتری یکی از مبلغ‌های پیشنهادی را می‌زند یا مبلغ دلخواه را تایپ می‌کند.">
      <div className="grid gap-4 sm:grid-cols-[1fr_2fr]">
        <Field id="wallet_topup_min" label="حداقل مبلغ شارژ (تومان)" error={error('topup_min')} hint={least ? `= ${formatMoney(least)}` : 'کمتر از این مبلغ پذیرفته نمی‌شود.'}>
          <Input dir="ltr" inputMode="numeric" value={values.topup_min} onChange={(e) => set('topup_min', e.target.value)} />
        </Field>
        <Field
          id="wallet_topup_presets"
          label="مبلغ‌های پیشنهادی (تومان)"
          error={error('topup_presets')}
          hint={presets.length > 0 ? presets.map((amount) => formatMoney(amount)).join(' · ') : 'با کاما جدا کنید؛ خالی باشد فقط «مبلغ دلخواه» می‌ماند.'}
        >
          <Input dir="ltr" autoComplete="off" placeholder="50000, 100000, 200000, 500000" value={values.topup_presets} onChange={(e) => set('topup_presets', e.target.value)} />
        </Field>
      </div>
    </SectionCard>
  )
}
