import { useQuery } from '@tanstack/react-query'
import { PowerOff } from 'lucide-react'
import { Callout } from '@/components/callout'
import { extraPricesAs, extraPricesIn, NO_EXTRA_PRICES, shopExtraPricesDraft, unpricedWithin, type ExtraPricesAs } from '@/components/extras/draft'
import { ExtraPricesFields } from '@/components/extras/extra-prices-fields'
import { FieldGroup } from '@/components/field'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { TextLink } from '@/components/text-link'
import { botSettingsQuery } from '@/lib/queries'
import type { Form } from '@/lib/use-form'

/** Where a plan's extra traffic, time and devices are priced: the shop's price list (the bot settings), or the plan's own. */
type ExtrasPricing = 'shop' | 'own'

/** A plan's own price list's names in its form — the names the server refuses its fields by: `extras.` before each. */
export const PLAN_EXTRAS = 'extras.'

/**
 * The plan form's share of the draft: where extras are priced (`extras`) and the plan's own price list, which is kept
 * while the shop's prices are chosen so switching back finds it as it was.
 */
export type PlanExtrasValues = { extras: ExtrasPricing } & ExtraPricesAs<typeof PLAN_EXTRAS>

const PRICINGS: PageTab<ExtrasPricing>[] = [
  { value: 'shop', label: 'قیمت‌های فروشگاه' },
  { value: 'own', label: 'قیمت‌های این پلن' },
]

/** Where the shop-wide prices are set. */
const SHOP_PRICES = '/bot-settings/extras'

interface PlanExtrasProps {
  form: Pick<Form<PlanExtrasValues>, 'values' | 'patch' | 'error'>
  /** The section is on screen: the shop's switch is read then, to say when nothing is sold at all. */
  shown: boolean
}

/**
 * A plan's «خرید اضافه» section (extra traffic, time and devices): its services buy extras at the shop's prices or at
 * the plan's own list, which then replaces the shop's entirely. The shop's switch rules both: while it is off nothing is sold, and the section says so.
 */
export function PlanExtras({ form, shown }: PlanExtrasProps) {
  const settings = useQuery({ ...botSettingsQuery, enabled: shown })
  const pricing = form.values.extras
  const shop = settings.data?.settings

  // A plan without a list of its own starts one with nothing priced within the shop's bounds, once those are read.
  const choose = (next: ExtrasPricing) => {
    const untouched = JSON.stringify(extraPricesIn(PLAN_EXTRAS, form.values)) === JSON.stringify(NO_EXTRA_PRICES)
    form.patch(next === 'own' && untouched && shop ? { extras: next, ...extraPricesAs(PLAN_EXTRAS, unpricedWithin(shopExtraPricesDraft(shop))) } : { extras: next })
  }

  return (
    <>
      {settings.data?.settings.extras_enabled === false && (
        <Callout tone="warning" icon={PowerOff}>
          فروش حجم، زمان و کاربر اضافه در{' '}
          <TextLink to={SHOP_PRICES} inline>
            تنظیمات ربات
          </TextLink>{' '}
          خاموش است؛ تا روشن نشود، مشتری‌ها هیچ‌کدام را نمی‌خرند، نه با قیمت‌های فروشگاه و نه با قیمت‌های این پلن.
        </Callout>
      )}

      <FieldGroup
        label="قیمت حجم، زمان و کاربر اضافه"
        error={form.error('extras')}
        hint={
          pricing === 'shop' ? (
            <>
              سرویس‌های این پلن حجم، زمان و کاربر اضافه را با قیمت‌های فروشگاه می‌خرند؛ آن قیمت‌ها را در{' '}
              <TextLink to={SHOP_PRICES} inline>
                تنظیمات ربات، بخش «خرید اضافه»
              </TextLink>{' '}
              تعیین کنید.
            </>
          ) : (
            'این قیمت‌ها فقط برای سرویس‌های همین پلن است و جای قیمت‌های فروشگاه را می‌گیرد؛ چیزی که این‌جا قیمت ندارد برای این پلن فروخته نمی‌شود.'
          )
        }
      >
        <PageTabs as="choice" size="sm" className="w-fit" aria-label="قیمت حجم، زمان و کاربر اضافه" value={pricing} tabs={PRICINGS} onChange={choose} />
      </FieldGroup>

      {pricing === 'own' && <ExtraPricesFields form={form} prefix={PLAN_EXTRAS} />}
    </>
  )
}
