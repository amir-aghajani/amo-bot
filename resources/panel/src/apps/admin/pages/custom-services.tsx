import { Info } from 'lucide-react'
import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { Callout } from '@/components/callout'
import { CustomServicesCard } from '@/components/custom-services/custom-services-card'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { useMainShop } from '@/lib/auth'

/** «سرویس دلخواه» in a paragraph, behind the header's ⓘ. */
const PROGRAM =
  'مشتری در ربات زیر پلن‌ها «ساخت سرویس دلخواه» را می‌زند (یا در وب‌سایت می‌سازد)، حجم و مدت را از دکمه‌ها انتخاب می‌کند یا عددش را می‌نویسد، سرور را انتخاب می‌کند و مثل هر خرید دیگری پرداخت می‌کند. قیمت همه حجم با پله‌ای حساب می‌شود که حجم در آن است — مثلا ۶۰ گیگابایت با پله‌های «از ۱: ۳٬۰۰۰» و «از ۵۰: ۲٬۵۰۰» می‌شود ۶۰ × ۲٬۵۰۰ —، مدت هم همین‌طور، و دو قیمت با هم جمع می‌شوند. تخفیف خودکار مشتری و کد تخفیف مثل خرید بدون پلن حساب می‌شوند: فروش ویژه همه پلن‌ها و کدی که به پلن خاصی محدود نیست. سرویس ساخته‌شده پلن ندارد.'

/** «سرویس دلخواه» (the owner's panel, the main shop alone): its settings card, the price calculator under its tiers. */
export function CustomServicesPage() {
  const main = useMainShop()

  return (
    <Page width="narrow">
      <PageHeader title="سرویس دلخواه" description="مشتری سرویسی با حجم و مدتی که خودش انتخاب می‌کند می‌سازد؛ قیمتش از پله‌های قیمت حجم و مدت حساب می‌شود." info={PROGRAM} />
      {main ? (
        <WithBotSettings what="تنظیمات سرویس دلخواه">{(settings) => <CustomServicesCard settings={settings} />}</WithBotSettings>
      ) : (
        <Callout tone="info" icon={Info}>
          سرویس دلخواه فقط در فروشگاه اصلی است؛ فروشگاه نماینده آن را ندارد.
        </Callout>
      )}
    </Page>
  )
}
