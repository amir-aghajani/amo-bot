import { useId, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Ban, PackagePlus } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { Field, FieldGroup } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { MoneyField } from '@/components/money-field'
import { NoteInput } from '@/components/note-input'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { userLabel } from '@/components/user-identity'
import { api, ApiError } from '@/lib/api'
import type { ManualOrderRequest, ManualOrderResponse, ManualPayment, PlanRow, UserRow } from '@/lib/api-types'
import { useMainShop, useSession } from '@/lib/auth'
import { decimalInput, formatBalance, formatBytes, formatDevices, formatDuration, formatGb, formatMoney, tomanOf, wholeNumberOf } from '@/lib/format'
import { planOptionsQuery, plansQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { randomToken } from '@/lib/random'
import { MANUAL_PAYMENT } from '@/lib/statuses'
import { useForm } from '@/lib/use-form'

/** What the order gives: one of the shop's plans, or a service of support's own numbers. */
type Kind = 'plan' | 'custom'

const KINDS: PageTab<Kind>[] = [
  { value: 'plan', label: 'پلن' },
  { value: 'custom', label: 'سرویس دلخواه' },
]

const PAYMENTS: PageTab<ManualPayment>[] = (Object.keys(MANUAL_PAYMENT) as ManualPayment[]).map((payment) => ({ value: payment, label: MANUAL_PAYMENT[payment] }))

/** The form as support fills it in: every field as typed. */
const BLANK = { kind: 'plan' as Kind, plan_id: '', server_id: '', traffic_gb: '', days: '', devices: '', client_name: '', payment: 'free' as ManualPayment, amount: '', note: '' }

type Draft = typeof BLANK

/** What the order changes beside the customer's page: their services, orders, payments and wallet, the lists and figures, an agent's traffic. */
const CHANGES = [
  queryKeys.subscriptions,
  queryKeys.orders,
  queryKeys.payments,
  queryKeys.users,
  queryKeys.everyUser,
  queryKeys.everyUserWallet,
  queryKeys.dashboards,
  queryKeys.plans,
  queryKeys.account,
  queryKeys.accountTraffic,
]

interface ManualOrderDialogProps {
  /** The customer the order is for; null when closed. */
  customer: UserRow | null
  /** The traffic the shop's bot may still sell, where the panel reads it (an agent's): what the service's GB come out of. */
  traffic?: number
  onClose: () => void
}

/**
 * «افزودن سفارش» on a customer's page (both panels): support makes them a service by hand — a plan, or its own numbers —
 * on a server it picks, named as it types or by the shop's pattern, free, from the customer's wallet or paid outside the
 * shop, with a note; a preview says what it delivers, and it is delivered at once (`POST /users/{id}/orders`).
 */
export function ManualOrderDialog({ customer, traffic, onClose }: ManualOrderDialogProps) {
  return (
    <Modal
      open={customer !== null}
      onClose={onClose}
      size="lg"
      title="افزودن سفارش"
      description={customer ? `سرویسی برای ${userLabel(customer)} که بلافاصله تحویل و به او خبر داده می‌شود.` : undefined}
    >
      {customer && <ManualOrderForm key={customer.id} customer={customer} traffic={traffic} onClose={onClose} />}
    </Modal>
  )
}

/** A server the order may go to, and why it cannot now (null when it can). */
interface ServerChoice {
  id: number
  name: string
  reason: string | null
}

function ManualOrderForm({ customer, traffic, onClose }: { customer: UserRow; traffic?: number; onClose: () => void }) {
  const id = useId()
  const agentShop = !useMainShop()
  // An agent's shop whose selling is suspended takes no order, by hand neither: said before the form (the API decides).
  const suspended = useSession().shop.selling_suspended !== null
  const plans = useQuery(plansQuery)
  const options = useQuery(planOptionsQuery)
  const { values, patch, error, setErrors, formError, busy, dirty, submit, handleSubmit } = useForm<Draft>(BLANK)
  // One key per order: a retry after no answer finds the order it made; a refusal made none, and the next try is another.
  const [key, setKey] = useState(() => randomToken(16))
  // What the kind picked picks from — the plans (their servers with them), or the servers —: a read that failed leaves
  // its pickers empty, so it says so over them, with a retry.
  const choices = values.kind === 'plan' ? { read: plans, what: 'لیست پلن‌ها' } : { read: options, what: 'لیست سرورها' }

  const plan = plans.data?.plans.find((row) => String(row.id) === values.plan_id) ?? null
  const servers: ServerChoice[] =
    values.kind === 'plan'
      ? (plan?.servers.map((entry) => ({ id: entry.server.id, name: entry.server.name, reason: entry.unsellable_reason })) ?? [])
      : (options.data?.servers.map((server) => ({ id: server.id, name: server.name, reason: server.unsellable_reason })) ?? [])
  const server = servers.find((choice) => String(choice.id) === values.server_id) ?? null

  /** A plan picked: its server too when it has one that can deliver it, and only one. */
  const pickPlan = (planId: string) => {
    const picked = plans.data?.plans.find((row) => String(row.id) === planId)
    const open = picked?.servers.filter((entry) => entry.unsellable_reason === null) ?? []
    patch({ plan_id: planId, server_id: open.length === 1 && open[0] ? String(open[0].server.id) : '' })
  }

  const make = handleSubmit(async () => {
    const missing = {
      ...(values.kind === 'plan' && values.plan_id === '' ? { plan_id: ['یک پلن انتخاب کنید.'] } : {}),
      ...(values.server_id === '' ? { server_id: ['سرور سرویس را انتخاب کنید.'] } : {}),
    }
    if (Object.keys(missing).length > 0) {
      setErrors(missing)
      return
    }
    const result = await submit(
      () =>
        api.post(`/users/${customer.id}/orders`, requestOf(values), { idempotencyKey: key }).catch((failure: unknown) => {
          // A refusal made nothing: the next try is a new order. No answer at all keeps the key, so a retry finds it.
          if (failure instanceof ApiError && failure.status >= 400 && failure.status < 500) setKey(randomToken(16))
          throw failure
        }),
      { invalidates: CHANGES },
    )
    if (result) {
      told(result, customer)
      onClose()
    }
  })

  return (
    <form onSubmit={make} noValidate className="grid gap-4">
      {suspended && (
        <Callout tone="warning" icon={Ban}>
          فروش این فروشگاه متوقف است و تا دوباره باز نشود سفارشی در آن ثبت نمی‌شود.
        </Callout>
      )}
      <PageTabs as="choice" value={values.kind} onChange={(kind) => patch({ kind, server_id: '' })} tabs={KINDS} aria-label="نوع سرویس" size="sm" className="w-fit" />
      {choices.read.error && !choices.read.data && <ErrorState what={choices.what} error={choices.read.error} onRetry={() => void choices.read.refetch()} retrying={choices.read.isFetching} />}

      {values.kind === 'plan' ? (
        <Field id={`${id}-plan`} label="پلن" error={error('plan_id')}>
          {(control) => (
            <Select value={values.plan_id} onValueChange={(planId) => planId !== '' && pickPlan(planId)}>
              <SelectTrigger {...control} data-autofocus className="w-full">
                <SelectValue placeholder={plans.isPending ? 'در حال بارگذاری…' : 'یک پلن انتخاب کنید'} />
              </SelectTrigger>
              <SelectContent>
                {plans.data?.plans.map((row) => (
                  // A plan the owner keeps from an agent's shop is shown, not to pick: nothing new is made of it.
                  <SelectItem key={row.id} value={String(row.id)} disabled={row.hidden_by_owner} hint={planHint(row)}>
                    {row.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
      ) : (
        <div className="grid gap-4 sm:grid-cols-3">
          <Field id={`${id}-gb`} label="حجم (گیگابایت)" error={error('traffic_gb')} hint="0 یعنی نامحدود">
            <Input dir="ltr" inputMode="numeric" autoComplete="off" placeholder="50" value={values.traffic_gb} onChange={(e) => patch({ traffic_gb: e.target.value })} />
          </Field>
          <Field id={`${id}-days`} label="مدت (روز)" error={error('days')} hint="0 یعنی بدون پایان">
            <Input dir="ltr" inputMode="numeric" autoComplete="off" placeholder="30" value={values.days} onChange={(e) => patch({ days: e.target.value })} />
          </Field>
          <Field id={`${id}-devices`} label="تعداد کاربر" error={error('devices')} hint="0 یعنی نامحدود">
            <Input dir="ltr" inputMode="numeric" autoComplete="off" placeholder="2" value={values.devices} onChange={(e) => patch({ devices: e.target.value })} />
          </Field>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field id={`${id}-server`} label="سرور" error={error('server_id')}>
          {(control) => (
            <Select
              value={values.server_id}
              // Radix's hidden select reports "" while the value has no item drawn (the server a plan's pick just set): no
              // choice, and the server stays as picked.
              onValueChange={(serverId) => serverId !== '' && patch({ server_id: serverId })}
              disabled={values.kind === 'plan' && plan === null}
            >
              <SelectTrigger {...control} className="w-full">
                <SelectValue placeholder={values.kind === 'plan' && plan === null ? 'اول پلن را انتخاب کنید' : 'یک سرور انتخاب کنید'} />
              </SelectTrigger>
              <SelectContent>
                {servers.map((choice) => (
                  // A server that cannot take the service now shows the server's reason.
                  <SelectItem key={choice.id} value={String(choice.id)} disabled={choice.reason !== null} hint={choice.reason ?? undefined}>
                    {choice.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
        <Field id={`${id}-name`} label="نام کاربری سرویس" optional error={error('client_name')} hint="خالی بماند، با الگوی فروشگاه نام‌گذاری می‌شود.">
          <Input dir="ltr" autoComplete="off" placeholder="ali_vip" value={values.client_name} onChange={(e) => patch({ client_name: e.target.value })} />
        </Field>
      </div>

      <FieldGroup label="پرداخت" error={error('payment')}>
        <PageTabs as="choice" value={values.payment} onChange={(payment) => patch({ payment })} tabs={PAYMENTS} aria-label="پرداخت" size="sm" className="w-fit" />
      </FieldGroup>
      {values.payment !== 'free' && (
        <MoneyField
          id={`${id}-amount`}
          label="مبلغ (تومان)"
          placeholder="150000"
          value={values.amount}
          onChange={(amount) => patch({ amount })}
          error={error('amount')}
          says={(money) => (values.payment === 'wallet' ? `= ${money}؛ موجودی کیف پول مشتری: ${formatBalance(customer.balance)}` : `= ${money}`)}
          hint={values.payment === 'wallet' ? `موجودی کیف پول مشتری: ${formatBalance(customer.balance)}` : 'مبلغی که بیرون از فروشگاه گرفته‌اید؛ در درآمد فروشگاه حساب می‌شود.'}
        />
      )}
      <Field id={`${id}-note`} label="یادداشت" optional error={error('note')} hint="کنار سفارش می‌ماند و در گروه گزارش‌ها گفته می‌شود؛ مشتری آن را نمی‌بیند.">
        <NoteInput rows={2} value={values.note} onChange={(e) => patch({ note: e.target.value })} placeholder="مثلا: پرداخت نقدی در دفتر" />
      </Field>

      <Preview values={values} plan={plan} server={server} customer={customer} agentShop={agentShop} traffic={traffic} />

      <FormActions error={formError} onCancel={onClose} submitLabel="ثبت و تحویل سفارش" busy={busy} icon={PackagePlus} dirty={dirty} />
    </form>
  )
}

/** A plan in the picker: its price and package, and that it is switched off or kept from the shop by the owner. */
function planHint(plan: PlanRow): string {
  return [formatMoney(plan.price), formatGb(plan.traffic_gb), formatDuration(plan.duration_hours), !plan.is_active && 'خاموش', plan.hidden_by_owner && 'پنهان‌شده توسط مدیر']
    .filter(Boolean)
    .join(' · ')
}

/** The draft as the API takes it: a plan or the numbers, the server, the name, the way to pay, the amount unless free. */
function requestOf(values: Draft): ManualOrderRequest {
  return {
    ...(values.kind === 'plan' ? { plan_id: Number(values.plan_id) } : { traffic_gb: values.traffic_gb, days: values.days, devices: values.devices }),
    server_id: Number(values.server_id),
    client_name: values.client_name,
    payment: values.payment,
    ...(values.payment === 'free' ? {} : { amount: decimalInput(values.amount) }),
    note: values.note,
  }
}

/**
 * What support is told once the order went through: delivered, or paid and waiting for a retry — or, a retry with its key
 * answered by the order as it stands, still on its way or cancelled (the wallet spent in the same moment).
 */
function told({ order }: ManualOrderResponse, customer: UserRow) {
  switch (order.status) {
    case 'fulfilled':
      toast.success(`سفارش برای ${userLabel(customer)} ثبت و تحویل شد`)
      return
    case 'failed':
      toast.error('سفارش ثبت شد ولی تحویلش ناموفق بود', { description: `${order.notes ?? ''} از صفحه سفارش‌ها دوباره امتحان کنید.`.trim() })
      return
    case 'cancelled':
      toast.error('سفارش ثبت نشد', { description: order.notes ?? undefined })
      return
    default:
      toast.warning('سفارش ثبت شد و تحویلش در جریان است', { description: 'نتیجه را در صفحه سفارش‌ها ببینید.' })
  }
}

interface PreviewProps {
  values: Draft
  plan: PlanRow | null
  server: ServerChoice | null
  customer: UserRow
  agentShop: boolean
  traffic?: number
}

/** What the order delivers, said before it is made: the service, where, how it is paid, and what it takes of an agent's traffic. */
function Preview({ values, plan, server, customer, agentShop, traffic }: PreviewProps) {
  const gb = values.kind === 'plan' ? (plan?.traffic_gb ?? null) : wholeNumberOf(values.traffic_gb)
  const days = wholeNumberOf(values.days)
  const devices = wholeNumberOf(values.devices)
  const service =
    values.kind === 'plan'
      ? plan && `«${plan.name}»: ${formatGb(plan.traffic_gb)} · ${formatDuration(plan.duration_hours)} · ${formatDevices(plan.ip_limit)}`
      : gb !== null && days !== null && devices !== null && `سرویس دلخواه: ${formatGb(gb)} · ${formatDuration(days * 24)} · ${formatDevices(devices)}`
  const amount = tomanOf(values.amount)
  const paid =
    values.payment === 'free'
      ? 'رایگان؛ هدیه است و خرید مشتری حساب نمی‌شود.'
      : amount === null || amount <= 0
        ? null
        : values.payment === 'wallet'
          ? `${formatMoney(amount)} از کیف پول مشتری کم می‌شود (موجودی ${formatBalance(customer.balance)}).`
          : `${formatMoney(amount)} پرداخت خارج از فروشگاه ثبت و در درآمد فروشگاه حساب می‌شود.`
  if (!service) return null

  return (
    <div role="status" className="grid gap-1 rounded-xl bg-fill px-4 py-3 text-body">
      <span className="font-medium">تحویل می‌شود</span>
      <span>
        {service}
        {server && ` · روی ${server.name}`}
      </span>
      {values.client_name.trim() !== '' && (
        <span>
          نام کاربری سرویس: <bdi dir="ltr">{values.client_name.trim()}</bdi>
        </span>
      )}
      {paid && <span>{paid}</span>}
      {agentShop && gb !== null && gb > 0 && (
        <span className="text-muted-foreground">{`${formatGb(gb)} از حجم ربات این فروشگاه کم می‌شود${traffic === undefined ? '' : ` (${formatBytes(traffic)} مانده)`}.`}</span>
      )}
    </div>
  )
}
