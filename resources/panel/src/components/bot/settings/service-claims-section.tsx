import { ReportedInPurchases, SwitchCard } from '@/components/bot/settings/switch-card'
import { TextLink } from '@/components/text-link'
import { api } from '@/lib/api'
import type { BotSettingsData } from '@/lib/api-types'

/**
 * «افزودن سرویس با لینک», the main shop's alone (its section is `mainShop` in the nav): whether a customer may add to
 * their account a client made by hand on a panel, by sending the bot or the website its subscription link or one of
 * its configs. How it goes: the first to send it gets it, each claim is reported, a wrong one is deleted from the shop
 * alone (the service's dialog) for the right customer to send again, and a claimed service has no plan until its first
 * renewal.
 */
export function ServiceClaimsSection({ settings }: { settings: BotSettingsData }) {
  return (
    <SwitchCard
      field="claim_enabled"
      // The main shop's settings always say it; an agent's shop has no such switch, nor this section.
      checked={settings.claim_enabled ?? false}
      save={(values) => api.put('/bot/settings/service_claims', values)}
      title="افزودن سرویس با لینک"
      description="برای کلاینتی که خودتان روی پنل یکی از سرورها ساخته‌اید و لینکش را به مشتری داده‌اید: مشتری لینک اشتراک یا یکی از کانفیگ‌های همان سرویس را به ربات (یا در سایت) می‌فرستد و سرویس به «سرویس‌های من» او اضافه می‌شود. این امکان فقط در ربات اصلی است."
      label="مشتری‌ها بتوانند سرویس را با لینکش اضافه کنند"
      hint="روشن که باشد، لینکی که مشتری می‌فرستد روی پنل سرورها جستجو می‌شود و سرویسی که پیدا شود به حساب او اضافه می‌شود. خاموش، لینک فرستاده‌شده پذیرفته نمی‌شود و سرویس‌هایی که قبلا اضافه شده‌اند سر جایشان می‌مانند."
    >
      <li>اولین کسی که لینک را بفرستد سرویس را می‌گیرد. سرویسی که در فروشگاه هست، به نام هر مشتری که باشد، دوباره اضافه نمی‌شود.</li>
      <li>
        <ReportedInPurchases what="هر سرویسی که اضافه شود" />
      </li>
      <li>
        اگر سرویس به دست مشتری اشتباهی رسید، آن را در{' '}
        <TextLink to="/subscriptions?kind=claimed" inline className="font-medium">
          اشتراک‌ها › افزوده‌شده با لینک
        </TextLink>{' '}
        باز کنید و در حذف سرویس «حذف فقط از فروشگاه (کلاینت روی پنل می‌ماند)» را بزنید: سرویس فقط از فروشگاه حذف می‌شود، کلاینت روی پنل می‌ماند و مشتری درست می‌تواند لینک را دوباره بفرستد.
      </li>
      <li>سرویس اضافه‌شده تا اولین تمدید پلن ندارد؛ هنگام تمدید، مشتری یکی از پلن‌هایی را که روی سرور همان سرویس فروخته می‌شوند انتخاب می‌کند.</li>
    </SwitchCard>
  )
}
