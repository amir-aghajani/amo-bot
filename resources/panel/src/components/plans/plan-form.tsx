import { useId, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Coins, Package, Server } from 'lucide-react'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { PageTabs, tabPanel, type PageTab } from '@/components/page-tabs'
import { ServerEntries, type ServerEntryDraft } from '@/components/plans/server-entries'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { api } from '@/lib/api'
import type { PlanRow } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { amountDraft, MONEY_UNIT } from '@/lib/format'
import { planOptionsQuery } from '@/lib/queries'
import { useForm } from '@/lib/use-form'
import { cn } from '@/lib/utils'

interface Values {
  name: string
  /** "" = no category. */
  category_id: string
  description: string
  price: string
  duration_days: string
  traffic_gb: string
  ip_limit: string
  servers: ServerEntryDraft[]
  is_active: boolean
}

type Section = 'details' | 'quota' | 'servers'

/** Which section each field lives in, so a validation error can point at its tab. */
const SECTION_OF: Record<string, Section> = {
  name: 'details',
  category_id: 'details',
  description: 'details',
  is_active: 'details',
  price: 'quota',
  traffic_gb: 'quota',
  duration_days: 'quota',
  ip_limit: 'quota',
  servers: 'servers',
}

function initialValues(plan: PlanRow | null): Values {
  return {
    name: plan?.name ?? '',
    category_id: plan?.category ? String(plan.category.id) : '',
    description: plan?.description ?? '',
    price: plan ? amountDraft(plan.price) : '',
    duration_days: String(plan?.duration_days ?? 30),
    traffic_gb: plan ? String(plan.traffic_gb) : '',
    ip_limit: String(plan?.ip_limit ?? 1),
    servers: plan?.servers.map((entry) => ({ server_id: entry.server.id, all_inbounds: entry.all_inbounds, inbound_ids: entry.all_inbounds ? [] : entry.inbounds.map((i) => i.id) })) ?? [],
    is_active: plan?.is_active ?? true,
  }
}

const SECTIONS: Section[] = ['details', 'quota', 'servers']

interface PlanFormProps {
  plan: PlanRow | null
  /** Where servers are added (the owner's servers page), for a shop without any; absent in a panel that adds none. */
  addServers?: string
  onSaved: (plan: PlanRow) => void
  onCancel: () => void
}

/**
 * Create/edit form for one plan, in three sections (what it is, what it gives for how much, where
 * it is sold) so no screen carries everything at once. The caller decides where it lives (a modal)
 * and what happens on save. A refused save jumps to the first section with an error.
 */
export function PlanForm({ plan, addServers, onSaved, onCancel }: PlanFormProps) {
  // An agent's shop sells the traffic it bought: a plan there must have some (the server refuses one without).
  const agentShop = !useMainShop()
  const { values, set, revert, errors, error, formError, busy, dirty, submit, handleSubmit } = useForm<Values>(() => initialValues(plan))
  const [section, setSection] = useState<Section>('details')
  const tabs = useId()

  const options = useQuery(planOptionsQuery)

  const sectionOf = (field: string): Section => SECTION_OF[field.split('.')[0] ?? ''] ?? 'servers'
  const sectionHasErrors = (s: Section) => Object.keys(errors).some((field) => sectionOf(field) === s)

  const save = handleSubmit(async () => {
    const data = await submit(() => (plan ? api.put(`/plans/${plan.id}`, values) : api.post('/plans', values)), {
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
        tabs={[tab('details', 'مشخصات', Package), tab('quota', 'قیمت و سهمیه', Coins), tab('servers', 'سرورها', Server)]}
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
            <Field id="plan_price" label={`قیمت (${MONEY_UNIT})`} error={error('price')} hint="مبلغی که مشتری برای هر خرید می‌پردازد؛ 0 = رایگان.">
              <Input dir="ltr" inputMode="decimal" className="sm:max-w-60" value={values.price} onChange={(e) => set('price', e.target.value)} placeholder="120000" />
            </Field>

            <div className="grid gap-5 sm:grid-cols-3">
              <Field id="plan_traffic" label="حجم (گیگابایت)" error={error('traffic_gb')} hint={agentShop ? 'الزامی؛ هر فروش و تمدید این پلن همین حجم را از حجم نمایندگی کم می‌کند.' : '0 = نامحدود'}>
                <Input dir="ltr" inputMode="decimal" value={values.traffic_gb} onChange={(e) => set('traffic_gb', e.target.value)} placeholder="30" />
              </Field>
              <Field id="plan_duration" label="مدت اعتبار (روز)" error={error('duration_days')} hint="0 = بدون محدودیت زمانی">
                <Input dir="ltr" inputMode="numeric" value={values.duration_days} onChange={(e) => set('duration_days', e.target.value)} placeholder="30" />
              </Field>
              <Field id="plan_devices" label="تعداد دستگاه" error={error('ip_limit')} hint="0 = نامحدود">
                <Input dir="ltr" inputMode="numeric" value={values.ip_limit} onChange={(e) => set('ip_limit', e.target.value)} placeholder="1" />
              </Field>
            </div>

            <p className="text-footnote leading-relaxed text-muted-foreground">حجم و مدت روی پنل اعمال می‌شوند و با پایان هر کدام اشتراک منقضی می‌شود؛ تعداد دستگاه یعنی چند اتصال هم‌زمان مجاز است.</p>
          </>,
        )}

        {panel('servers', <ServerEntries entries={values.servers} onChange={(servers) => set('servers', servers)} options={options} errors={errors.servers} addServers={addServers} />)}
      </div>

      <FormActions error={formError} onCancel={onCancel} submitLabel={plan ? 'ذخیره تغییرات' : 'افزودن پلن'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
