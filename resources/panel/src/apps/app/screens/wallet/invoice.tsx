import { useEffect, useState, type CSSProperties } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Download, ImageDown, ReceiptText, Wifi } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { keys } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { store } from '@/apps/app/lib/store'
import { idOf } from '@/apps/app/screens/support/parts/words'
import { invoiceImage, type InvoiceLook } from '@/apps/app/screens/wallet/parts/invoice-image'
import { FAILURE, invoiceGroups, invoiceNumber, orderStatus, orderTitle, paymentStatus } from '@/apps/app/screens/wallet/parts/orders'
import { result } from '@/apps/app/telegram/haptics'
import { onPhone } from '@/apps/app/telegram/webapp'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { Sheet } from '@/apps/app/ui/sheet'
import { Empty, ErrorView, LoadingBlocks, Notice, Skeleton } from '@/apps/app/ui/states'
import { Badge } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { StoreOrder, StoreOrderResponse } from '@/lib/api-types'
import { formatDate, formatMoney, initials } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «فاکتور»: an order's electronic invoice, as a receipt — the shop's logo and name over its number, date and status, then
 * what was bought, the amounts with each discount, and the payment with its gateway's tracking code when it has one —;
 * every payment tried for it, by number, with the shop's note on each; «ذخیره تصویر», the invoice drawn as a picture to
 * keep; and «پرداخت» while it awaits one (not while support holds or reviews its payment).
 */

/** A row's hairline across the receipt's text column alone, edge to edge of it, as the picture draws it. */
const RECEIPT_ROW = { '--row-inset': '0px' } as CSSProperties

/** The receipt: the header in the shop's colour, a ticket's tear, the facts by group, the thanks. */
function Receipt({ order, look }: { order: StoreOrder; look: InvoiceLook }) {
  const status = orderStatus(order.status)

  return (
    <article aria-label={`فاکتور ${invoiceNumber(order)}`} className="card animate-rise relative">
      <header
        className="text-on-accent rounded-t-card relative flex flex-col gap-4 overflow-hidden px-5 pt-5 pb-6"
        style={{ background: 'linear-gradient(135deg, color-mix(in srgb, var(--app-accent) 80%, white), var(--app-accent) 45%, color-mix(in srgb, var(--app-accent) 70%, black))' }}
      >
        {/* The shop beside the invoice's number: two lines each, of the same heights, centred on the 48px logo. */}
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            {look.logo === null ? (
              <span className="grid size-12 shrink-0 place-items-center rounded-full bg-[color-mix(in_srgb,var(--app-on-accent)_14%,transparent)] text-heading font-extrabold">
                {initials(look.title) || '؟'}
              </span>
            ) : (
              <img src={look.logo} alt="" className="size-12 shrink-0 rounded-full bg-[color-mix(in_srgb,var(--app-on-accent)_14%,transparent)] object-cover" />
            )}
            <div className="flex min-w-0 flex-col">
              <p className="truncate text-heading font-bold">{look.title}</p>
              <p className="text-caption opacity-85">فاکتور الکترونیکی</p>
            </div>
          </div>
          <div className="flex shrink-0 flex-col items-end">
            <p className="ltr text-title leading-6.5 font-extrabold">{invoiceNumber(order)}</p>
            <p className="text-caption opacity-85">{formatDate(order.created_at)}</p>
          </div>
        </div>
        <div className="flex items-center justify-between gap-3">
          <p className="min-w-0 truncate text-body font-semibold">{orderTitle(order)}</p>
          <span className="inline-flex h-6 shrink-0 items-center rounded-full bg-[color-mix(in_srgb,var(--app-on-accent)_18%,transparent)] px-2.5 text-caption leading-none font-bold">
            {status.label}
          </span>
        </div>
      </header>

      {/* The tear: a notch at each edge, a dashed line between. */}
      <div aria-hidden className="relative h-0">
        <span className="bg-page absolute -start-3 -top-3 size-6 rounded-full" />
        <span className="bg-page absolute -end-3 -top-3 size-6 rounded-full" />
        <span className="border-separator absolute inset-x-5 top-0 border-t-2 border-dashed" />
      </div>

      <div className="flex flex-col gap-4 px-5 pt-5 pb-4">
        {invoiceGroups(order).map((group) => (
          <section key={group.title} aria-label={group.title} className="flex flex-col">
            <h2 className="text-accent-text pb-1 text-footnote font-bold">{group.title}</h2>
            {group.rows.map((row) => (
              <div key={row.label} className="list-row flex items-baseline justify-between gap-4 py-2.5" style={RECEIPT_ROW}>
                <span className="text-hint shrink-0 text-footnote">{row.label}</span>
                <span
                  className={cn(
                    'min-w-0 text-end wrap-anywhere',
                    row.ltr && 'ltr',
                    row.strong ? 'text-accent-text text-heading font-extrabold' : 'text-body font-medium',
                    row.tone === 'success' && 'text-success',
                  )}
                >
                  {row.value}
                </span>
              </div>
            ))}
          </section>
        ))}
      </div>

      <p className="text-hint border-separator mx-5 border-t border-dashed pt-4 pb-6 text-center text-footnote">
        با سپاس از خرید شما{look.bot === null ? '' : ' · '}
        {look.bot !== null && <span className="ltr">@{look.bot}</span>}
      </p>
      {/* A torn bottom edge: a half circle of the page every 18px along it. */}
      <div aria-hidden className="absolute inset-x-0 -bottom-[5px] h-2.5 bg-[radial-gradient(circle,var(--app-page)_4px,transparent_5px)] bg-[length:18px_10px] bg-repeat-x" />
    </article>
  )
}

/** Every payment tried for the order, each where it stands and the shop's note on it — when there is more to say than the receipt. */
function Payments({ order }: { order: StoreOrder }) {
  if (order.payments.length < 2 && order.payments.every((payment) => payment.note === null)) return null

  return (
    <Section title="پرداخت‌ها">
      {order.payments.map((payment) => {
        const status = paymentStatus(payment.status)

        return (
          <div key={payment.id} className="list-row flex flex-col gap-1 px-4 py-3">
            <div className="flex items-center justify-between gap-3">
              <span className="min-w-0 truncate text-body">{payment.method.label}</span>
              <Badge tone={status.tone}>{status.label}</Badge>
            </div>
            <div className="text-hint flex items-start justify-between gap-3 text-footnote">
              <span className="min-w-0">
                شماره پرداخت <span className="ltr">{payment.id}</span> · {formatDate(payment.paid_at ?? payment.created_at)}
              </span>
              <span className="shrink-0">{formatMoney(payment.amount)}</span>
            </div>
            {payment.reference !== null && (
              <p className="text-hint text-footnote">
                کد رهگیری <span className="ltr break-all">{payment.reference}</span>
              </p>
            )}
            {payment.note !== null && <p className="text-footnote">{payment.note}</p>}
          </div>
        )
      })}
    </Section>
  )
}

export default function InvoiceScreen() {
  // A whole number as written, nothing else (`0x1F`, `1e2`, ` 7 ` name no order).
  const orderId = idOf(useParams().id)
  const navigate = useNavigate()
  const { data: shop } = useShop()
  const order = useQuery({
    queryKey: keys.order(orderId ?? 0),
    queryFn: () => store.get<StoreOrderResponse>(`/orders/${orderId}`),
    enabled: orderId !== null,
  })
  const [picture, setPicture] = useState<string | null>(null)
  const look: InvoiceLook = { title: shop?.app?.title ?? shop?.shop.name ?? '', logo: shop?.app?.logo_url ?? null, bot: shop?.shop.bot?.username ?? null }

  const draw = useMutation({
    mutationFn: (of: StoreOrder) => invoiceImage(of, look),
    meta: { quiet: true },
    onSuccess: (blob) => {
      result('success')
      setPicture(URL.createObjectURL(blob))
    },
    onError: () => {
      result('error')
      toast.error('ساخت تصویر فاکتور انجام نشد؛ دوباره تلاش کنید.')
    },
  })

  // The picture's address lives while its sheet is open.
  useEffect(() => {
    if (picture === null) return
    return () => URL.revokeObjectURL(picture)
  }, [picture])

  if (orderId === null || (order.error instanceof ApiError && order.error.status === 404)) {
    return (
      <Screen back title="فاکتور">
        <Empty icon={ReceiptText} title="این سفارش پیدا نشد" text="ممکن است نشانی آن اشتباه باشد یا سفارش به حساب دیگری تعلق داشته باشد." />
      </Screen>
    )
  }
  if (order.data === undefined) {
    return (
      <Screen back title="فاکتور">
        {order.isError ? (
          <ErrorView error={order.error} retry={() => void order.refetch()} />
        ) : (
          <>
            <Skeleton className="h-40" />
            <LoadingBlocks count={2} />
          </>
        )}
      </Screen>
    )
  }

  const invoice = order.data.order
  const reviewing = invoice.payments.some((payment) => payment.status === 'awaiting_review')
  // An online payment held for support: the order is paid no more meanwhile.
  const held = invoice.payments.some((payment) => payment.held)
  const payable = invoice.status === 'pending' && !reviewing && !held

  return (
    <Screen back title="فاکتور" subtitle={`سفارش ${invoiceNumber(invoice)}`} bottom={payable ? 'action' : 'none'}>
      {invoice.failure !== null && <Notice tone="danger">{FAILURE[invoice.failure]}</Notice>}
      {payable && <Notice tone="warning">این سفارش هنوز پرداخت نشده است؛ سفارشی که مدتی پرداخت نشود لغو می‌شود.</Notice>}
      {reviewing && <Notice>رسید پرداخت این سفارش نزد پشتیبانی است و به زودی بررسی می‌شود.</Notice>}
      {held && <Notice tone="warning">پرداخت این سفارش برای بررسی نزد پشتیبانی است؛ دوباره پرداخت نکنید و برای پیگیری به پشتیبانی پیام بدهید.</Notice>}

      <Receipt order={invoice} look={look} />

      <Button variant="secondary" block icon={<ImageDown />} busy={draw.isPending} onClick={() => draw.mutate(invoice)}>
        ذخیره تصویر فاکتور
      </Button>

      {invoice.subscription !== null && (
        <Section>
          <Row icon={Wifi} title="مشاهده سرویس" subtitle={<span className="ltr">{invoice.subscription.name}</span>} to={`/services/${invoice.subscription.id}`} />
        </Section>
      )}

      <Payments order={invoice} />

      <Sheet open={picture !== null} onClose={() => setPicture(null)} title="تصویر فاکتور">
        {picture !== null && (
          <div className="flex flex-col gap-3">
            <img src={picture} alt={`فاکتور ${invoiceNumber(invoice)}`} className="rounded-card w-full shadow-sm" />
            <p className="text-hint text-center text-footnote">برای ذخیره، روی تصویر نگه دارید.</p>
            {!onPhone() && (
              <a
                href={picture}
                download={`invoice-${invoice.id}.png`}
                className="press rounded-control bg-accent text-on-accent flex h-12 items-center justify-center gap-2 px-5 text-body leading-none font-semibold"
              >
                <Download aria-hidden className="size-5 shrink-0" />
                دانلود تصویر
              </a>
            )}
          </div>
        )}
      </Sheet>

      {payable && <MainAction text="پرداخت" shine onClick={() => navigate(`/pay/${invoice.id}`)} />}
    </Screen>
  )
}
