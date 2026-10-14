import { Dices } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field, FormPart } from '@/components/field'
import { MoneyField } from '@/components/money-field'
import { SectionCard } from '@/components/section-card'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotSettingsData, BotWalletSettingsRequest } from '@/lib/api-types'
import { formatMoney, formatNumber, wholeNumberOf, wholesOf } from '@/lib/format'
import { WALLET_SETTINGS } from '@/lib/limits'
import { asNumbers, asSet } from '@/lib/use-form'

/**
 * The top-up amounts: the smallest the bot accepts, the buttons it offers before "any amount" — the presets and those
 * drawn at random within a range —, and the small random sum added to a card-to-card top-up so its receipt is told apart.
 */
export function WalletSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    {
      topup_min: String(settings.topup_min),
      topup_presets: settings.topup_presets.join(', '),
      topup_random_count: String(settings.topup_random_count),
      topup_random_min: String(settings.topup_random_min),
      topup_random_max: String(settings.topup_random_max),
      topup_random_round: String(settings.topup_random_round),
      topup_unique_max: String(settings.topup_unique_max),
    } satisfies BotWalletSettingsRequest,
    (values) => api.put('/bot/settings/wallet', values),
    // A number is what the server reads of it («۵۰٬۰۰۰» is 50000), and the amounts offered as it keeps them, each once in
    // its own order: the same typed otherwise is no change.
    { reads: (values) => asNumbers({ ...values, topup_presets: asSet(wholesOf(values.topup_presets) ?? [values.topup_presets.trim()]) }) },
  )
  const { values, set, error } = group
  // What the typed amounts read as, under them — read as the server reads its whole numbers (a comma between groups of
  // three sets thousands apart), and nothing said of what it would refuse.
  const presets = wholesOf(values.topup_presets) ?? []

  return (
    <SectionCard form={group} title="کیف پول" description="مبلغ‌های شارژ کیف پول در ربات و سایت. مشتری یکی از مبلغ‌های پیشنهادی یا تصادفی را می‌زند یا مبلغ دلخواه را تایپ می‌کند.">
      <div className="grid gap-4 sm:grid-cols-[1fr_2fr]">
        <MoneyField
          id="wallet_topup_min"
          label="حداقل مبلغ شارژ (تومان)"
          value={values.topup_min}
          onChange={(amount) => set('topup_min', amount)}
          reads={wholeNumberOf}
          error={error('topup_min')}
          hint="کمتر از این مبلغ پذیرفته نمی‌شود."
        />
        <Field
          id="wallet_topup_presets"
          label="مبلغ‌های پیشنهادی (تومان)"
          error={error('topup_presets')}
          hint={presets.length > 0 ? presets.map((amount) => formatMoney(amount)).join(' · ') : 'با کاما جدا کنید؛ خالی باشد فقط «مبلغ دلخواه» می‌ماند.'}
        >
          <Input dir="ltr" autoComplete="off" placeholder="50000, 100000, 200000, 500000" value={values.topup_presets} onChange={(e) => set('topup_presets', e.target.value)} />
        </Field>
      </div>

      <FormPart
        icon={Dices}
        title="دکمه‌های مبلغ تصادفی"
        about="کنار مبلغ‌های پیشنهادی، دکمه‌هایی با مبلغ تصادفی؛ هر بار که صفحه شارژ باز می‌شود مبلغ‌های تازه‌ای کشیده می‌شود، هر کدام بین کمترین و بیشترین مبلغ، گردشده و هیچ‌کدام تکراری یا برابر یک مبلغ پیشنهادی نیست."
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id="wallet_topup_random_count"
            label="تعداد دکمه‌ها"
            error={error('topup_random_count')}
            hint={`۰ یعنی بدون دکمه تصادفی؛ حداکثر ${formatNumber(WALLET_SETTINGS.RANDOM_BUTTONS_MOST)}.`}
          >
            <Input dir="ltr" inputMode="numeric" value={values.topup_random_count} onChange={(e) => set('topup_random_count', e.target.value)} />
          </Field>
          <MoneyField
            id="wallet_topup_random_round"
            label="گرد کردن (تومان)"
            value={values.topup_random_round}
            onChange={(amount) => set('topup_random_round', amount)}
            reads={wholeNumberOf}
            error={error('topup_random_round')}
            hint="مثلا ۱۰۰۰ یعنی مبلغ‌ها هزار تومانی گرد می‌شوند."
          />
          <MoneyField
            id="wallet_topup_random_min"
            label="کمترین مبلغ تصادفی (تومان)"
            value={values.topup_random_min}
            onChange={(amount) => set('topup_random_min', amount)}
            reads={wholeNumberOf}
            error={error('topup_random_min')}
            hint="کمتر از حداقل مبلغ شارژ نمی‌شود."
          />
          <MoneyField
            id="wallet_topup_random_max"
            label="بیشترین مبلغ تصادفی (تومان)"
            value={values.topup_random_max}
            onChange={(amount) => set('topup_random_max', amount)}
            reads={wholeNumberOf}
            error={error('topup_random_max')}
            hint="کمتر از کمترین مبلغ تصادفی نمی‌شود."
          />
        </div>
      </FormPart>

      <MoneyField
        id="wallet_topup_unique_max"
        label="مبلغ تصادفی افزوده به شارژ کارت به کارت (تومان)"
        value={values.topup_unique_max}
        onChange={(amount) => set('topup_unique_max', amount)}
        reads={wholeNumberOf}
        error={error('topup_unique_max')}
        says={(money) =>
          `به هر شارژ کیف پول با کارت به کارت، مبلغی تصادفی بین ۱ تا ${money} اضافه می‌شود تا هر واریز مبلغی متفاوت داشته باشد و رسیدش راحت پیدا شود؛ مشتری همه مبلغ را واریز می‌کند و همه به کیف پولش می‌رود.`
        }
        hint={`۰ یعنی خاموش. روشن که باشد، به هر شارژ کیف پول با کارت به کارت مبلغی تصادفی (تا ${formatMoney(WALLET_SETTINGS.UNIQUE_MAX)}) اضافه می‌شود تا هر واریز مبلغی متفاوت داشته باشد و رسیدش راحت پیدا شود.`}
        inputClassName="sm:max-w-xs"
      />
    </SectionCard>
  )
}
