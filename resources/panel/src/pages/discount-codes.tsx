import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { CodesList } from '@/components/discounts/codes-list'
import { DiscountSettingsCard } from '@/components/discounts/discount-settings-card'
import { SectionedPage } from '@/components/sectioned-page'
import { DISCOUNT_SECTIONS } from '@/components/shell/nav'

type Section = (typeof DISCOUNT_SECTIONS)[number]['value']

const HEADERS: Record<Section, { title: string; description: string }> = {
  codes: {
    title: 'کدهای تخفیف',
    description: 'کدهایی که مشتری هنگام پرداخت در ربات یا وب‌سایت وارد می‌کند: درصدی، مبلغ ثابت یا رایگان، برای سفارش‌ها، پلن‌ها و سرورهایی که تعیین می‌کنید.',
  },
  settings: {
    title: 'تنظیمات کدهای تخفیف',
    description: 'سفارش پرداخت‌نشده با کد تخفیف تا کی سهم کد را نگه دارد.',
  },
}

/** How the shop's discounts stack, behind the header's ⓘ on every section. */
const PROGRAM =
  'قیمت هر خرید، تمدید، حجم یا زمان اضافه اول با بیشترین تخفیف خودکار مشتری کم می‌شود — فروش ویژه، تخفیف اختصاصی او یا تخفیف سطح وفاداری‌اش؛ هیچ‌وقت دو تا با هم جمع نمی‌شوند — و بعد کد تخفیفی که مشتری وارد کرده روی همان مبلغ اعمال می‌شود. هر سفارش پرداخت‌نشده‌ای که با کد ثبت شود یکی از دفعات استفاده کد را نگه می‌دارد؛ اگر لغو شود یا مهلتش تمام شود، آن سهم آزاد می‌شود. شارژ کیف پول و خرید حجم نمایندگی تخفیف نمی‌گیرند.'

/** «کدهای تخفیف» (both panels), in two sections: the codes, and how long an unpaid order holds a code's use (a bot-settings group). */
export function DiscountCodesPage() {
  return (
    <SectionedPage sections={DISCOUNT_SECTIONS} header={(current) => ({ ...HEADERS[current.value], info: PROGRAM })}>
      {{
        codes: <CodesList />,
        settings: <WithBotSettings what="تنظیمات کدهای تخفیف">{(settings) => <DiscountSettingsCard settings={settings} />}</WithBotSettings>,
      }}
    </SectionedPage>
  )
}
