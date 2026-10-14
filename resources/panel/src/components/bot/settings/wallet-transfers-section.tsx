import { Info } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { MoneyField } from '@/components/money-field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { api } from '@/lib/api'
import type { BotSettingsData, BotWalletTransfersSettingsRequest } from '@/lib/api-types'
import { formatNumber, wholeNumberOf } from '@/lib/format'
import { WALLET_TRANSFERS } from '@/lib/limits'
import { asNumbers } from '@/lib/use-form'

/**
 * «انتقال موجودی», in every shop: whether a customer may send money from their wallet to another customer of the shop by
 * their numeric Telegram id — in the bot or on the website, once they confirmed, with no fee —, the least and the most
 * one transfer moves, and the most a customer sends in a day (0: no cap). How it goes: the wallet's own balance only,
 * never an agent's credit; a line in both ledgers; both told; the report in the wallet's topic.
 */
export function WalletTransfersSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    {
      wallet_transfer_enabled: settings.wallet_transfer_enabled,
      wallet_transfer_min: String(settings.wallet_transfer_min),
      wallet_transfer_max: String(settings.wallet_transfer_max),
      wallet_transfer_daily: String(settings.wallet_transfer_daily),
    } satisfies BotWalletTransfersSettingsRequest,
    (values) => api.put('/bot/settings/wallet_transfers', values),
    // An amount is what the server reads of it: «۵۰٬۰۰۰» is 50000, no change.
    { reads: asNumbers },
  )
  const { values, set, error } = group
  const off = !values.wallet_transfer_enabled

  return (
    <SectionCard
      form={group}
      title="انتقال موجودی"
      description="مشتری از موجودی کیف پولش به مشتری دیگری از همین فروشگاه، با شناسه عددی تلگرام او، پول منتقل می‌کند: در ربات با دکمه «💸 انتقال موجودی» در صفحه کیف پول، یا در سایت. انتقال کارمزد ندارد."
    >
      <SwitchRow
        label="مشتری‌ها بتوانند موجودی کیف پولشان را به مشتری دیگری منتقل کنند"
        hint="روشن که باشد، مشتری شناسه عددی تلگرام گیرنده را می‌فرستد، نام او را می‌بیند، مبلغ را می‌نویسد و با تایید، مبلغ از کیف پول او کم و به کیف پول گیرنده اضافه می‌شود."
        checked={values.wallet_transfer_enabled}
        onCheckedChange={(checked) => set('wallet_transfer_enabled', checked)}
        error={error('wallet_transfer_enabled')}
      />
      <div className="grid gap-4 sm:grid-cols-3">
        <MoneyField
          id="wallet_transfer_min"
          label="کمترین مبلغ انتقال (تومان)"
          value={values.wallet_transfer_min}
          onChange={(amount) => set('wallet_transfer_min', amount)}
          reads={wholeNumberOf}
          error={error('wallet_transfer_min')}
          hint="کمتر از این مبلغ منتقل نمی‌شود."
          disabled={off}
        />
        <MoneyField
          id="wallet_transfer_max"
          label="بیشترین مبلغ هر انتقال (تومان)"
          value={values.wallet_transfer_max}
          onChange={(amount) => set('wallet_transfer_max', amount)}
          reads={wholeNumberOf}
          error={error('wallet_transfer_max')}
          hint="بیشتر از این مبلغ در یک انتقال منتقل نمی‌شود."
          disabled={off}
        />
        <MoneyField
          id="wallet_transfer_daily"
          label="سقف روزانه هر مشتری (تومان)"
          value={values.wallet_transfer_daily}
          onChange={(amount) => set('wallet_transfer_daily', amount)}
          reads={wholeNumberOf}
          error={error('wallet_transfer_daily')}
          says={(money) => `= ${money} در روز برای هر مشتری؛ کمتر از کمترین مبلغ انتقال نمی‌شود.`}
          zero="بدون سقف روزانه؛ فقط موجودی مشتری و بیشترین مبلغ هر انتقال محدودش می‌کند."
          hint="0 یعنی بدون سقف روزانه؛ جز آن، کمتر از کمترین مبلغ انتقال نمی‌شود."
          disabled={off}
        />
      </div>
      <Callout tone="info" icon={Info}>
        <ul className="grid list-disc gap-1.5 ps-4">
          <li>فقط موجودی خود کیف پول منتقل می‌شود، نه اعتبار نمایندگی. گیرنده باید مشتری همین فروشگاه باشد و یک بار ربات را استارت کرده باشد.</li>
          <li>در تاریخچه کیف پول هر دو یک خط ثبت می‌شود که نام دیگری را دارد، و هر دو خبردار می‌شوند. انتقال برگشت ندارد؛ برای اصلاح، موجودی را از پنجره کیف پول مشتری دستی کم و زیاد کنید.</li>
          <li>سقف روزانه جمع انتقال‌های هر مشتری از نیمه‌شب، به ساعت فروشگاه است. {`هر مشتری در ساعت حداکثر ${formatNumber(WALLET_TRANSFERS.TRANSFERS)} انتقال انجام می‌دهد.`}</li>
          <li>
            هر انتقال در{' '}
            <TextLink to="/bot-settings/reports" inline>
              گروه گزارش‌ها
            </TextLink>
            ، تاپیک «شارژ کیف پول»، گزارش می‌شود.
          </li>
        </ul>
      </Callout>
    </SectionCard>
  )
}
