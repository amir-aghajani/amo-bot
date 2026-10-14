import { useId } from 'react'
import { BatteryPlus, ClockPlus, PencilLine, UserPlus, type LucideIcon } from 'lucide-react'
import { extraOffer, extraPricesIn, type ExtraField, type ExtraKind, type ExtraOfferPreview, type ExtraPricesAs, type ExtraPricesDraft } from '@/components/extras/draft'
import { Field, FieldGroup, FormPart } from '@/components/field'
import { PricedRowsEditor } from '@/components/priced-rows-editor'
import { Input } from '@/components/ui/input'
import { useMainShop } from '@/lib/auth'
import { formatDays, formatDevices, formatGb, formatMoney, formatNumber, tomanOf, wholeNumberOf } from '@/lib/format'
import { EXTRA_OFFER, EXTRA_OPTIONS, EXTRAS_SETTINGS, PACKAGE } from '@/lib/limits'
import type { Form } from '@/lib/use-form'
import { cn } from '@/lib/utils'

/** How each extra is worded — its unit, the server's own names of its fields (its refusals name them so), its bounds. */
interface KindWords {
  title: string
  icon: LucideIcon
  about: string
  /** Shown in an agent's shop, where it is drawn from the agent's traffic; nothing for one that is not. */
  agent: string | null
  unitPrice: string
  options: string
  /** One amount offered, a row's name: «حجم پیشنهادی ۲». */
  option: string
  unit: string
  typed: string
  /** What typing does: with a unit price, and without one. */
  typedOn: string
  typedOff: string
  none: string
  /** An amount in words: «۱۰ گیگابایت», «۷ روز», «۲ کاربر». */
  amount: (quantity: number) => string
  /** A quantity's placeholder: guidance, in Latin digits. */
  example: string
  /** Said under the bot's preview, for a kind whose prices are not what each service pays; nothing otherwise. */
  charged: string | null
}

const KINDS: Record<ExtraKind, KindWords> = {
  traffic: {
    title: 'حجم اضافه',
    icon: BatteryPlus,
    about: 'گیگابایت‌هایی که مشتری روی حجم سرویسش می‌خرد.',
    agent: 'در فروشگاه نماینده از حجم نمایندگی کم می‌شود، و بیشتر از حجمی که مانده پیشنهاد نمی‌شود.',
    unitPrice: 'قیمت هر گیگابایت',
    options: 'حجم‌های پیشنهادی',
    option: 'حجم پیشنهادی',
    unit: 'گیگابایت',
    typed: 'حجم دلخواه',
    typedOn: `مشتری می‌تواند حجم دلخواهش را هم بنویسد، بین این دو عدد (1 تا ${PACKAGE.MAX_GB} گیگابایت).`,
    typedOff: 'تا قیمت هر گیگابایت خالی است، مشتری حجم دلخواه نمی‌نویسد و این دو به کار نمی‌آیند.',
    none: 'با این قیمت‌ها حجم اضافه فروخته نمی‌شود.',
    amount: formatGb,
    example: '10',
    charged: null,
  },
  time: {
    title: 'زمان اضافه',
    icon: ClockPlus,
    about: 'روزهایی که مشتری به زمان سرویسش اضافه می‌کند.',
    agent: null,
    unitPrice: 'قیمت هر روز',
    options: 'زمان‌های پیشنهادی',
    option: 'زمان پیشنهادی',
    unit: 'روز',
    typed: 'زمان دلخواه',
    typedOn: `مشتری می‌تواند تعداد روز دلخواهش را هم بنویسد، بین این دو عدد (1 تا ${PACKAGE.MAX_DAYS} روز).`,
    typedOff: 'تا قیمت هر روز خالی است، مشتری زمان دلخواه نمی‌نویسد و این دو به کار نمی‌آیند.',
    none: 'با این قیمت‌ها زمان اضافه فروخته نمی‌شود.',
    amount: formatDays,
    example: '7',
    charged: null,
  },
  devices: {
    title: 'کاربر اضافه',
    icon: UserPlus,
    about:
      'کاربرهایی که مشتری به تعداد کاربر هم‌زمان سرویسش اضافه می‌کند، تا پایان دوره فعلی سرویس؛ قیمتشان هم برای روزهای مانده تا همان روز حساب می‌شود و تمدید، تعداد کاربر پلن را می‌دهد. سرویسی که تعداد کاربرش نامحدود است، یا روی سروری است که تعداد کاربر را محدود نمی‌کند (PasarGuard)، کاربر اضافه نمی‌خرد.',
    agent: null,
    unitPrice: `قیمت هر کاربر برای ${EXTRA_OFFER.PERIOD_DAYS} روز`,
    options: 'تعدادهای پیشنهادی',
    option: 'تعداد پیشنهادی',
    unit: 'کاربر',
    typed: 'تعداد دلخواه',
    typedOn: `مشتری می‌تواند تعداد دلخواهش را هم بنویسد، بین این دو عدد (1 تا ${EXTRAS_SETTINGS.MAX_DEVICES} کاربر).`,
    typedOff: 'تا قیمت هر کاربر خالی است، مشتری تعداد دلخواه نمی‌نویسد و این دو به کار نمی‌آیند.',
    none: 'با این قیمت‌ها کاربر اضافه فروخته نمی‌شود.',
    amount: formatDevices,
    example: '1',
    charged: `این قیمت‌ها برای ${EXTRA_OFFER.PERIOD_DAYS} روز است؛ هر سرویس به نسبت روزهای مانده‌اش می‌پردازد، مثلا سرویسی که ${EXTRA_OFFER.PERIOD_DAYS / 2} روز مانده دارد نصف آن را.`,
  },
}

/** What the fields need of the form that holds them: its values, a change, a field's refusal — every field under the form's own name for it. */
type HostForm<P extends string> = Pick<Form<ExtraPricesAs<P>>, 'values' | 'patch' | 'error'>

interface ExtraPricesFieldsProps<P extends string> {
  form: HostForm<P>
  /** The form's names of the price list's fields: `extra_` (the shop's, in the bot settings) or `extras.` (a plan's own) before each. */
  prefix: P
}

/**
 * The «حجم، زمان و کاربر اضافه» price-list editor, for the shop's list and a plan's own: per extra (traffic, time, devices), the
 * unit price, the amounts offered as buttons, the bounds a customer may type, and a live preview of the bot's buttons.
 * Fields live in the host form under `prefix` + name, the names the server refuses them by.
 */
export function ExtraPricesFields<P extends string>({ form, prefix }: ExtraPricesFieldsProps<P>) {
  const prices = extraPricesIn(prefix, form.values)
  // A field's change under the form's own name for it — a key made of the prefix, which no type names.
  const change = <K extends ExtraField>(field: K, value: ExtraPricesDraft[K]) => form.patch({ [`${prefix}${field}`]: value } as Partial<ExtraPricesAs<P>>)
  const error = (field: ExtraField) => form.error(`${prefix}${field}`)

  return (
    <div className="@container">
      <div className="grid gap-4 @xl:grid-cols-2 @5xl:grid-cols-3">
        <ExtraBlock kind="traffic" prices={prices} change={change} error={error} />
        <ExtraBlock kind="time" prices={prices} change={change} error={error} />
        <ExtraBlock kind="devices" prices={prices} change={change} error={error} />
      </div>
    </div>
  )
}

interface ExtraBlockProps {
  kind: ExtraKind
  prices: ExtraPricesDraft
  change: <K extends ExtraField>(field: K, value: ExtraPricesDraft[K]) => void
  error: (field: ExtraField) => string | undefined
}

/** One extra's prices: the unit price, the amounts offered, the bounds of what may be typed, and the bot's offer by them. */
function ExtraBlock({ kind, prices, change, error }: ExtraBlockProps) {
  const id = useId()
  const words = KINDS[kind]
  const agentShop = !useMainShop()
  const field = { price: `${kind}_price`, options: `${kind}_options`, min: `${kind}_min`, max: `${kind}_max` } as const
  const rows = prices[field.options]
  const unit = tomanOf(prices[field.price])
  const priced = unit !== null && unit >= 1
  const optionsRefused = error(field.options)

  return (
    <FormPart icon={words.icon} title={words.title} about={agentShop && words.agent ? `${words.about} ${words.agent}` : words.about}>
      <Field
        id={`${id}-price`}
        label={`${words.unitPrice} (تومان)`}
        error={error(field.price)}
        hint={priced ? `= ${formatMoney(unit)}` : `خالی: فقط ${words.options} با قیمت خودشان فروخته می‌شوند و مشتری ${words.typed} نمی‌نویسد.`}
      >
        <Input dir="ltr" inputMode="numeric" autoComplete="off" value={prices[field.price]} onChange={(e) => change(field.price, e.target.value)} />
      </Field>

      <PricedRowsEditor
        label={words.options}
        description={`دکمه‌هایی که مشتری می‌بیند؛ قیمت خالی یعنی به ${words.unitPrice}.`}
        error={optionsRefused}
        rows={rows}
        onChange={(next) => change(field.options, next)}
        amount="quantity"
        headings={{ amount: words.unit, price: 'قیمت (تومان)' }}
        names={(place) => {
          const row = `${words.option} ${place}`
          return { row, amount: `${row} (${words.unit})`, price: `قیمت ${row} (تومان)` }
        }}
        example={words.example}
        // Left blank, it sells at the unit price: what that comes to, as the field's guidance.
        pricePlaceholder={(row) => {
          const quantity = wholeNumberOf(row.quantity)
          return priced && quantity !== null ? String(unit * quantity) : undefined
        }}
        noun="مقدار"
        most={EXTRA_OPTIONS.MOST}
      />

      <FieldGroup label={words.typed} hint={priced ? words.typedOn : words.typedOff} className="gap-2">
        <div className="grid grid-cols-2 gap-3">
          <Field id={`${id}-min`} label={`کمترین (${words.unit})`} error={error(field.min)}>
            <Input dir="ltr" inputMode="numeric" autoComplete="off" value={prices[field.min]} onChange={(e) => change(field.min, e.target.value)} />
          </Field>
          <Field id={`${id}-max`} label={`بیشترین (${words.unit})`} error={error(field.max)}>
            <Input dir="ltr" inputMode="numeric" autoComplete="off" value={prices[field.max]} onChange={(e) => change(field.max, e.target.value)} />
          </Field>
        </div>
      </FieldGroup>

      <OfferPreview words={words} offer={extraOffer(prices, kind)} refused={optionsRefused !== undefined} />
    </FormPart>
  )
}

/**
 * A preview of the bot's offer as typed: the buttons (two a row) with their prices, the range a customer may type, or
 * that nothing is sold. An amount without a price is flagged unless the server already refused it.
 */
function OfferPreview({ words, offer, refused }: { words: KindWords; offer: ExtraOfferPreview; refused: boolean }) {
  const unpriced = offer.buttons.find((button) => button.price === null)
  const sold = offer.typed !== null || offer.buttons.some((button) => button.price !== null)

  return (
    <div className="grid gap-2">
      <p className="text-footnote font-medium text-muted-foreground">پیش‌نمایش در ربات</p>
      {offer.buttons.length > 0 && (
        <ul aria-label={`دکمه‌های ${words.title}`} className="tg-preview tg-chat grid grid-cols-2 gap-1 rounded-xl border border-white/10 p-2" dir="rtl">
          {offer.buttons.map((button) => (
            <li
              key={button.quantity}
              className={cn(
                'flex min-h-8 min-w-0 items-center justify-center rounded-md px-2 py-1 text-center text-caption leading-snug font-medium',
                button.price === null && 'border border-dashed border-white/30 opacity-70',
              )}
              style={{ background: 'var(--tg-inline)' }}
            >
              {`${words.amount(button.quantity)} · ${button.price === null ? 'بدون قیمت' : formatMoney(button.price)}`}
            </li>
          ))}
        </ul>
      )}
      {offer.typed && (
        <p className="flex items-start gap-1.5 text-footnote leading-relaxed text-muted-foreground">
          <PencilLine className="mt-0.5 size-3.5 shrink-0" aria-hidden />
          {`${offer.buttons.length > 0 ? 'یا ' : ''}${words.typed} بین ${formatNumber(offer.typed.min)} تا ${words.amount(offer.typed.max)}، هر ${words.unit} ${formatMoney(offer.typed.price)}`}
        </p>
      )}
      {!sold && <p className="text-footnote text-muted-foreground">{words.none}</p>}
      {sold && words.charged && <p className="text-footnote leading-relaxed text-muted-foreground">{words.charged}</p>}
      {unpriced && !refused && <p className="text-footnote text-warning">{`${words.amount(unpriced.quantity)} قیمت ندارد؛ قیمت آن، یا ${words.unitPrice} را بنویسید.`}</p>}
    </div>
  )
}
