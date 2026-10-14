import { ReportedInPurchases, SwitchCard } from '@/components/bot/settings/switch-card'
import { TextLink } from '@/components/text-link'
import { api } from '@/lib/api'
import type { BotSettingsData } from '@/lib/api-types'

/**
 * «انتقال سرویس», in every shop: whether a customer may hand one of their services to another customer of the shop by
 * their numeric Telegram id — in the bot or on the website, free. How it goes: the service keeps its link, term and
 * traffic and loses its «تمدید خودکار», the previous owner's devices stay on the link until «تغییر لینک», what may not
 * go, the report it makes, and that support transfers from a service's dialog whatever this says.
 */
export function ServiceTransfersSection({ settings }: { settings: BotSettingsData }) {
  return (
    <SwitchCard
      field="transfer_enabled"
      checked={settings.transfer_enabled}
      save={(values) => api.put('/bot/settings/service_transfers', values)}
      title="انتقال سرویس"
      description="مشتری یکی از سرویس‌هایش را با شناسه عددی تلگرام به مشتری دیگری از همین فروشگاه می‌دهد: در ربات با دکمه «🔁 انتقال سرویس» در صفحه سرویس، یا در سایت. انتقال رایگان است."
      label="مشتری‌ها بتوانند سرویسشان را به مشتری دیگری منتقل کنند"
      hint="روشن که باشد، مشتری شناسه عددی تلگرام گیرنده را می‌فرستد، نام او را می‌بیند و با تایید، سرویس به حساب گیرنده می‌رود. خاموش، فقط پشتیبانی سرویس را منتقل می‌کند."
    >
      <li>سرویس با همان لینک، زمان و حجم منتقل می‌شود و «تمدید خودکار» آن خاموش می‌شود؛ گیرنده در ربات خبردار می‌شود و سرویس در «سرویس‌های من» او می‌آید.</li>
      <li>لینک هم با سرویس می‌رود و دستگاه‌های صاحب قبلی وصل می‌مانند تا گیرنده «تغییر لینک» را بزند؛ پیام گیرنده این را به او می‌گوید.</li>
      <li>گیرنده باید یک بار ربات را استارت کرده باشد. اکانت تست، سرویسی که کلاینتش دیگر روی سرور نیست و سرویسی که سفارشی از آن هنوز باز است (در انتظار پرداخت یا تحویل) منتقل نمی‌شود.</li>
      <li>
        <ReportedInPurchases what="هر انتقال" />
      </li>
      <li>
        پشتیبانی همیشه، حتی وقتی این گزینه خاموش است، سرویس را از پنجره آن در{' '}
        <TextLink to="/subscriptions" inline className="font-medium">
          اشتراک‌ها
        </TextLink>{' '}
        با «انتقال به مشتری دیگر» منتقل می‌کند.
      </li>
    </SwitchCard>
  )
}
