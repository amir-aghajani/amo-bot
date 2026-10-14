import { useId, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Coins, Package, PackagePlus, Server } from 'lucide-react'
import { toast } from 'sonner'
import { extraPricesAs, extraPricesDraft, extraPricesIn, extraPricesRead, extraPricesRequest, NO_EXTRA_PRICES, priceRead } from '@/components/extras/draft'
import { Field, FieldGroup } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { StylePicker } from '@/components/keyboards/style-picker'
import { BUTTON_STYLES, styleOptions } from '@/components/keyboards/styles'
import { NoteInput } from '@/components/note-input'
import { PageTabs, tabPanel, type PageTab } from '@/components/page-tabs'
import { PLAN_EXTRAS, PlanExtras, type PlanExtrasValues } from '@/components/plans/plan-extras'
import { ServerEntries, type ServerEntryDraft } from '@/components/plans/server-entries'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { api } from '@/lib/api'
import type { ButtonStyle, PlanRequest, PlanRow } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { amountDraft, MONEY_UNIT, wholeOf } from '@/lib/format'
import { planOptionsQuery } from '@/lib/queries'
import { trimmed, useForm } from '@/lib/use-form'
import { cn } from '@/lib/utils'

/** What a plan's term is typed in: days — a daily plan's, sent as days × 24 — or hours (an hourly plan's). */
type TermUnit = 'days' | 'hours'

/** How many buttons share the plan's button's row in the bot's plan list, as the choice holds it. */
type ButtonRow = '1' | '2' | '3'

/** A plan's term as the form types it: an amount of its unit, 0 for none. */
interface TermDraft {
  amount: string
  unit: TermUnit
}

type Values = {
  name: string
  /** "" = no category. */
  category_id: string
  description: string
  price: string
  /** What a customer's first purchase costs, as typed; "" — none: everyone pays `price`. */
  first_purchase_price: string
  /** What an agent, or a customer of a «همکار» group, pays, as typed; "" — none. */
  agent_price: string
  /** What the bot's checkout of the plan says under it; "" — nothing. */
  checkout_note: string
  /** Its button's colour in the bot's plan list; null — the client's own. */
  button_style: ButtonStyle | null
  button_row: ButtonRow
  /** The term as typed — the request's `duration_hours`, the name the server refuses it under. */
  duration_hours: TermDraft
  traffic_gb: string
  ip_limit: string
  servers: ServerEntryDraft[]
  is_active: boolean
} & PlanExtrasValues

type Section = 'details' | 'quota' | 'servers' | 'extras'

/** Which section each field lives in, so a validation error can point at its tab — a price list's own (`extras.traffic_price`) by its head. */
const SECTION_OF: Record<string, Section> = {
  name: 'details',
  category_id: 'details',
  description: 'details',
  checkout_note: 'details',
  button_style: 'details',
  button_row: 'details',
  is_active: 'details',
  price: 'quota',
  first_purchase_price: 'quota',
  agent_price: 'quota',
  traffic_gb: 'quota',
  duration_hours: 'quota',
  ip_limit: 'quota',
  servers: 'servers',
  extras: 'extras',
}

const TERM_UNITS: PageTab<TermUnit>[] = [
  { value: 'days', label: 'روز' },
  { value: 'hours', label: 'ساعت' },
]

const HOURS_IN_DAY = 24

/** The longest checkout note the server keeps (PlanService). */
const CHECKOUT_NOTE_MAX = 500

const BUTTON_ROWS: PageTab<ButtonRow>[] = [
  { value: '1', label: 'یک ردیف کامل' },
  { value: '2', label: 'دو در ردیف' },
  { value: '3', label: 'سه در ردیف' },
]

/** A saved button row as the choice holds it — one it does not offer, a row of its own. */
function buttonRow(row: number | undefined): ButtonRow {
  return row === 2 ? '2' : row === 3 ? '3' : '1'
}

/** A price typed as the request carries it: none (`null`) while blank, else as typed. */
function optionalPrice(typed: string): string | null {
  return typed.trim() === '' ? null : typed
}

/** A saved term as the form opens it, from its hours: whole days in days — none too, 0 days —, any other in hours. */
function termDraft(hours: number): TermDraft {
  return hours % HOURS_IN_DAY === 0 ? { amount: String(hours / HOURS_IN_DAY), unit: 'days' } : { amount: String(hours), unit: 'hours' }
}

/** The term in hours for the request: days × 24, or hours as they are; text that is no whole number goes as typed for the server to refuse. */
function termHours({ amount, unit }: TermDraft): string {
  const whole = wholeOf(amount)
  return whole === null ? amount.trim() : String(Number(whole) * (unit === 'days' ? HOURS_IN_DAY : 1))
}

function initialValues(plan: PlanRow | null): Values {
  const extras = plan?.extras ?? null
  return {
    name: plan?.name ?? '',
    category_id: plan?.category ? String(plan.category.id) : '',
    description: plan?.description ?? '',
    price: plan ? amountDraft(plan.price) : '',
    // A free first purchase opens as 0; none, blank.
    first_purchase_price: plan && plan.first_purchase_price !== null ? amountDraft(plan.first_purchase_price) : '',
    agent_price: plan && plan.agent_price !== null ? amountDraft(plan.agent_price) : '',
    checkout_note: plan?.checkout_note ?? '',
    button_style: plan?.button_style ?? null,
    button_row: buttonRow(plan?.button_row),
    duration_hours: termDraft(plan?.duration_hours ?? 30 * HOURS_IN_DAY),
    traffic_gb: plan ? String(plan.traffic_gb) : '',
    ip_limit: String(plan?.ip_limit ?? 1),
    servers: plan?.servers.map((entry) => ({ server_id: entry.server.id, all_inbounds: entry.all_inbounds, inbound_ids: entry.all_inbounds ? [] : entry.inbounds.map((i) => i.id) })) ?? [],
    is_active: plan?.is_active ?? true,
    // Its own price list, or one that sells nothing to start from: kept while the shop's prices are chosen.
    extras: extras === null ? 'shop' : 'own',
    ...extraPricesAs(PLAN_EXTRAS, extras === null ? NO_EXTRA_PRICES : extraPricesDraft(extras)),
  }
}

/**
 * The request: fields as typed, except the term (in hours), the optional prices and checkout note (`null` while blank),
 * the button row (a number) and the price list (`null` for the shop's prices).
 */
function requestOf(values: Values): PlanRequest {
  const { name, category_id, description, price, traffic_gb, ip_limit, servers, is_active, button_style } = values
  const extras = values.extras === 'own' ? extraPricesRequest(extraPricesIn(PLAN_EXTRAS, values)) : null
  return {
    name,
    category_id,
    description,
    price,
    first_purchase_price: optionalPrice(values.first_purchase_price),
    agent_price: optionalPrice(values.agent_price),
    checkout_note: values.checkout_note.trim() === '' ? null : values.checkout_note,
    button_style,
    button_row: Number(values.button_row),
    duration_hours: termHours(values.duration_hours),
    traffic_gb,
    ip_limit,
    servers,
    is_active,
    extras,
  }
}

/**
 * The form as the server reads it, for `dirty`: the request with prices compared by their digits («۵۰٬۰۰۰» = "50000")
 * and the term in hours («۱ روز» = «۲۴ ساعت»).
 */
function readOf(values: Values): unknown {
  return trimmed({
    ...requestOf(values),
    price: priceRead(values.price),
    first_purchase_price: priceRead(values.first_purchase_price),
    agent_price: priceRead(values.agent_price),
    extras: values.extras === 'own' ? extraPricesRead(extraPricesIn(PLAN_EXTRAS, values)) : null,
  })
}

const SECTIONS: Section[] = ['details', 'quota', 'servers', 'extras']

interface PlanFormProps {
  plan: PlanRow | null
  /** Where servers are added (the owner's servers page), for a shop without any; absent in a panel that adds none. */
  addServers?: string
  onSaved: (plan: PlanRow) => void
  onCancel: () => void
}

/**
 * Create/edit form for one plan, in four tabs: details (with its checkout note and bot button), prices and quota (with
 * the first-purchase and agent prices, the term in days or hours), servers, and extras. A refused save jumps to the first
 * tab with an error.
 */
export function PlanForm({ plan, addServers, onSaved, onCancel }: PlanFormProps) {
  // An agent's shop sells the traffic it bought: a plan there must have some (the server refuses one without).
  const agentShop = !useMainShop()
  const { values, set, patch, revert, errors, error, formError, busy, dirty, submit, handleSubmit } = useForm<Values>(() => initialValues(plan), { reads: readOf })
  const [section, setSection] = useState<Section>('details')
  const tabs = useId()
  const term = values.duration_hours

  const options = useQuery(planOptionsQuery)

  const sectionOf = (field: string): Section => SECTION_OF[field.split('.')[0] ?? ''] ?? 'servers'
  // A refusal of the plan's own prices is not shown while the shop's are chosen: the list is not on the form then.
  const onForm = (field: string) => values.extras === 'own' || !field.startsWith(PLAN_EXTRAS)
  const sectionHasErrors = (s: Section) => Object.keys(errors).some((field) => sectionOf(field) === s && onForm(field))

  const save = handleSubmit(async () => {
    const data = await submit(() => (plan ? api.put(`/plans/${plan.id}`, requestOf(values)) : api.post('/plans', requestOf(values))), {
      // A refused save lands on the first section that has something to fix.
      onInvalid: (refused) => {
        const first = SECTIONS.find((s) => Object.keys(refused).some((field) => sectionOf(field) === s))
        if (first) setSection(first)
      },
    })
    if (data) {
      toast.success(plan ? 'پلن ذخیره شد' : 'پلن اضافه شد')
      onSaved(data.plan)
    }
  })

  const tab = (value: Section, label: string, icon: PageTab<Section>['icon']): PageTab<Section> => ({
    value,
    icon,
    label: (
      <span className="inline-flex items-center gap-1.5">
        {label}
        {sectionHasErrors(value) && (
          <>
            <span aria-hidden className="size-1.5 rounded-full bg-danger" />
            <span className="sr-only">(دارای خطا)</span>
          </>
        )}
      </span>
    ),
  })

  // Every section in one cell of the grid, those not shown out of sight and out of reach: the dialog is as tall as its
  // tallest section whichever is shown, so a tab never moves under the pointer that pressed it.
  const panel = (value: Section, children: ReactNode) => (
    <div {...tabPanel(tabs, value)} inert={section !== value} className={cn('col-start-1 row-start-1 grid content-start gap-5', section !== value && 'invisible')}>
      {children}
    </div>
  )

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <PageTabs
        id={tabs}
        value={section}
        onChange={setSection}
        tabs={[tab('details', 'مشخصات', Package), tab('quota', 'قیمت و سهمیه', Coins), tab('servers', 'سرورها', Server), tab('extras', 'خرید اضافه', PackagePlus)]}
        aria-label="بخش‌های پلن"
      />

      <div className="grid">
        {panel(
          'details',
          <>
            <Field id="plan_name" label="نام پلن" error={error('name')} hint="همان‌طور که مشتری در ربات می‌بیند.">
              <Input value={values.name} onChange={(e) => set('name', e.target.value)} placeholder="یک‌ماهه ۳۰ گیگ" autoFocus={!plan} />
            </Field>

            <Field
              id="plan_category"
              label="دسته"
              optional
              error={error('category_id')}
              hint={
                options.data && options.data.categories.length === 0
                  ? 'هنوز دسته‌ای ندارید؛ از بخش «دسته‌بندی‌ها» بسازید.'
                  : 'تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند؛ پلن بی‌دسته زیر «سایر پلن‌ها» می‌آید.'
              }
            >
              {(control) => (
                <Select value={values.category_id || 'none'} onValueChange={(value) => set('category_id', value === 'none' ? '' : value)}>
                  <SelectTrigger {...control} className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">بدون دسته</SelectItem>
                    {options.data?.categories.map((category) => (
                      <SelectItem key={category.id} value={String(category.id)}>
                        {category.name}
                        {!category.is_active && <span className="text-muted-foreground"> (غیرفعال)</span>}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>

            <Field id="plan_description" label="توضیحات" optional error={error('description')} hint="زیر نام پلن نمایش داده می‌شود؛ مثلا برای چه کسی مناسب است.">
              <Textarea rows={3} value={values.description} onChange={(e) => set('description', e.target.value)} />
            </Field>

            <Field id="plan_checkout_note" label="یادداشت پیش‌فاکتور" optional error={error('checkout_note')} hint="زیر پیش‌فاکتور خرید و تمدید این پلن در ربات نشان داده می‌شود.">
              <NoteInput rows={2} max={CHECKOUT_NOTE_MAX} value={values.checkout_note} onChange={(e) => set('checkout_note', e.target.value)} />
            </Field>

            <PlanButton values={values} set={set} error={error} />

            <SwitchRow
              label="قابل خرید"
              hint="پلن‌های غیرفعال به مشتری نمایش داده نمی‌شوند؛ اشتراک‌های قبلی سر جایشان می‌مانند."
              checked={values.is_active}
              onCheckedChange={(checked) => set('is_active', checked)}
              error={error('is_active')}
            />
          </>,
        )}

        {panel(
          'quota',
          <>
            <div className="grid gap-5 sm:grid-cols-2">
              <Field id="plan_price" label={`قیمت (${MONEY_UNIT})`} error={error('price')} hint="قیمت عادی پلن، برای هر خرید و تمدید؛ 0 = رایگان.">
                <Input dir="ltr" inputMode="decimal" value={values.price} onChange={(e) => set('price', e.target.value)} placeholder="120000" />
              </Field>
              <Field
                id="plan_first_purchase_price"
                label={`قیمت خرید اول (${MONEY_UNIT})`}
                optional
                error={error('first_purchase_price')}
                hint="مشتری‌ای که هنوز سرویسی از فروشگاه نخریده، اولین بار این پلن را با این قیمت می‌خرد. باید کمتر از قیمت پلن باشد؛ 0 = خرید اول رایگان. تمدید همیشه با قیمت عادی است."
              >
                <Input dir="ltr" inputMode="decimal" value={values.first_purchase_price} onChange={(e) => set('first_purchase_price', e.target.value)} />
              </Field>
              <Field
                id="plan_agent_price"
                label={`قیمت نماینده (${MONEY_UNIT})`}
                optional
                error={error('agent_price')}
                hint="نماینده‌ها و مشتری‌های گروه‌های همکار این قیمت را می‌پردازند، در خرید و تمدید؛ باید کمتر از قیمت باشد. خالی یعنی ندارد."
              >
                <Input dir="ltr" inputMode="decimal" value={values.agent_price} onChange={(e) => set('agent_price', e.target.value)} />
              </Field>
            </div>

            <div className="grid gap-5 sm:grid-cols-3">
              <Field id="plan_traffic" label="حجم (گیگابایت)" error={error('traffic_gb')} hint={agentShop ? 'الزامی؛ هر فروش و تمدید این پلن همین حجم را از حجم نمایندگی کم می‌کند.' : '0 = نامحدود'}>
                <Input dir="ltr" inputMode="decimal" value={values.traffic_gb} onChange={(e) => set('traffic_gb', e.target.value)} placeholder="30" />
              </Field>
              <Field id="plan_duration" label="مدت اعتبار" error={error('duration_hours')} hint={`0 = نامحدود؛ ${term.unit === 'days' ? 'روزها' : 'ساعت‌ها'} از اولین اتصال مشتری شمرده می‌شوند.`}>
                {(control) => (
                  // The amount and its unit are one field: either changed, its refusal goes — the term is being fixed.
                  <div className="grid grid-cols-[1fr_auto] items-center gap-3">
                    <Input
                      {...control}
                      dir="ltr"
                      inputMode="numeric"
                      value={term.amount}
                      onChange={(e) => set('duration_hours', { ...term, amount: e.target.value })}
                      placeholder={term.unit === 'days' ? '30' : '1'}
                    />
                    <PageTabs as="choice" size="sm" className="w-fit" aria-label="واحد مدت اعتبار" value={term.unit} tabs={TERM_UNITS} onChange={(unit) => set('duration_hours', { ...term, unit })} />
                  </div>
                )}
              </Field>
              <Field id="plan_devices" label="تعداد کاربر" error={error('ip_limit')} hint="0 = نامحدود">
                <Input dir="ltr" inputMode="numeric" value={values.ip_limit} onChange={(e) => set('ip_limit', e.target.value)} placeholder="1" />
              </Field>
            </div>

            <p className="text-footnote leading-relaxed text-muted-foreground">حجم و مدت روی پنل اعمال می‌شوند و با پایان هر کدام اشتراک منقضی می‌شود؛ تعداد کاربر یعنی چند اتصال هم‌زمان مجاز است.</p>
          </>,
        )}

        {panel('servers', <ServerEntries entries={values.servers} onChange={(servers) => set('servers', servers)} options={options} errors={errors.servers} addServers={addServers} />)}

        {panel('extras', <PlanExtras form={{ values, patch, error }} shown={section === 'extras'} />)}
      </div>

      <FormActions error={formError} onCancel={onCancel} submitLabel={plan ? 'ذخیره تغییرات' : 'افزودن پلن'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}

/** The plan's button in the bot's plan list: its colour and how many buttons share its row. */
function PlanButton({ values, set, error }: { values: Values; set: <K extends keyof Values>(key: K, value: Values[K]) => void; error: (field: string) => string | undefined }) {
  return (
    <FieldGroup
      label="دکمه در ربات"
      error={error('button_style') ?? error('button_row')}
      hint="دکمه پلن‌های پشت سر هم با پهنای یکسان، در یک ردیف کنار هم می‌آیند."
      className="gap-4 rounded-xl border border-border p-4"
    >
      <StylePicker label="رنگ" options={styleOptions(BUTTON_STYLES)} value={values.button_style} onChange={(style) => set('button_style', style)} className="sm:grid-cols-2" />
      <div className="grid gap-2">
        <span className="text-body font-medium">پهنا</span>
        <PageTabs as="choice" size="sm" className="w-fit" aria-label="پهنای دکمه" value={values.button_row} tabs={BUTTON_ROWS} onChange={(row) => set('button_row', row)} />
      </div>
    </FieldGroup>
  )
}
