import type { ReactNode } from 'react'
import { Copy, CreditCard, Headset, Hourglass, Landmark, ReceiptText } from 'lucide-react'
import { useNavigate } from 'react-router'
import { openContact, orderNumber } from '@/apps/app/screens/pay/parts/order-words'
import { Outcome, PayAgain } from '@/apps/app/screens/pay/parts/outcome'
import { ReceiptForm } from '@/apps/app/screens/pay/parts/receipt'
import { Step } from '@/apps/app/screens/pay/parts/step'
import { tap } from '@/apps/app/telegram/haptics'
import { MainAction } from '@/apps/app/ui/button'
import { CopyValue } from '@/apps/app/ui/fields'
import { Fact, Section } from '@/apps/app/ui/section'
import { Notice } from '@/apps/app/ui/states'
import { copyText } from '@/apps/app/ui/toaster'
import { Price } from '@/apps/app/ui/visuals'
import type { StoreCheckout, StoreOrder, StorePayment } from '@/lib/api-types'
import { formatAmount, formatCardNumber, formatDate, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * Paying an order card to card: the card to transfer to — tapped, its number is copied — and the sum, shown in Toman
 * and copied in Rial as a banking app takes it, the shop's instructions, then the receipt's upload. A shop that takes
 * card payments in support's private chat shows no card: the customer sends the order's number there. Once the receipt
 * is sent the payment waits for support; a receipt refused is paid again as a new attempt.
 */

type Transfer = NonNullable<StoreCheckout['transfer']>

/**
 * What a tap on the block it ends copies, as one pill (32px) for every copy here: its icon and its words — on the card
 * (`onCard`) in the card's ink, elsewhere in the accent.
 */
function CopyMark({ onCard = false, children }: { onCard?: boolean; children: ReactNode }) {
  return (
    <span
      className={cn('inline-flex h-8 shrink-0 items-center gap-2 rounded-full px-3 text-footnote leading-none font-semibold whitespace-nowrap', !onCard && 'text-accent-text')}
      style={{ background: `color-mix(in srgb, ${onCard ? 'var(--app-on-accent)' : 'var(--app-accent-text)'} ${onCard ? 16 : 12}%, transparent)` }}
    >
      <Copy aria-hidden className="size-4" />
      {children}
    </span>
  )
}

/**
 * A card to transfer to, drawn as one: its bank, its number left to right (a tap copies it), its holder. The number stays
 * on one line: 24px where the card is wide enough for it, 20px on a narrow phone (a container query on the card's width).
 */
export function BankCard({ number, holder, bank }: { number: string; holder: string | null; bank: string | null }) {
  return (
    <button
      type="button"
      onClick={() => {
        tap()
        void copyText(number, 'شماره کارت کپی شد')
      }}
      className="press text-on-accent @container relative isolate flex w-full flex-col gap-4 overflow-hidden rounded-2xl p-5 text-start shadow-[0_8px_24px_-12px_rgb(0_0_0/0.4)]"
      style={{ background: 'linear-gradient(135deg, color-mix(in srgb, var(--app-accent) 92%, #000), color-mix(in srgb, var(--app-accent) 60%, #000))' }}
    >
      <span aria-hidden className="absolute -end-10 -top-16 -z-10 size-44 rounded-full bg-white/10" />
      <span aria-hidden className="absolute end-24 -bottom-20 -z-10 size-40 rounded-full bg-white/5" />
      <span className="flex items-center justify-between gap-3">
        <span className="truncate text-body font-semibold">{bank ?? 'کارت مقصد'}</span>
        <Landmark aria-hidden className="size-5 shrink-0 opacity-80" />
      </span>
      <span className="ltr block text-center text-xl font-semibold whitespace-nowrap tabular-nums @min-[248px]:text-title">{formatCardNumber(number)}</span>
      <span className="flex items-center justify-between gap-3">
        <span className="min-w-0 truncate text-body font-medium">{holder ?? ''}</span>
        <CopyMark onCard>کپی شماره</CopyMark>
      </span>
    </button>
  )
}

/** The sum to transfer: shown in Toman, copied in Rial — ten to the Toman, its digits alone, as a banking app takes it. */
export function TransferAmount({ amount, rial }: { amount: string; rial: string }) {
  return (
    <button
      type="button"
      onClick={() => {
        tap()
        void copyText(rial, 'مبلغ به ریال کپی شد')
      }}
      className="press rounded-control flex w-full items-center gap-3 bg-fill p-4 text-start"
    >
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="text-hint text-caption">مبلغ واریز</span>
        <Price amount={amount} className="text-title" />
        <span className="text-hint text-footnote">{formatAmount(rial)} ریال</span>
      </span>
      <CopyMark>کپی به ریال</CopyMark>
    </button>
  )
}

/** `amount` Toman in Rial, as the API writes it: the digits alone. */
const rialOf = (amount: string): string => String(Math.round(Number(amount) * 10))

interface CardTransferProps {
  order: StoreOrder
  payment: StorePayment
  /** What the checkout answered for this payment (its card), when this tab still has it. */
  transfer: Transfer | null
}

/** A pending order paid by card: its card and receipt — or, past that, where the payment stands. */
export function CardTransfer({ order, payment, transfer }: CardTransferProps) {
  if (payment.status === 'awaiting_review') {
    return (
      <>
        <Outcome tone="warning" icon={Hourglass} title="در انتظار بررسی" text="رسید شما دریافت شد؛ پشتیبانی آن را بررسی می‌کند. نتیجه در همین صفحه و در اعلان‌ها می‌آید." />
        <Section>
          <Fact label="سفارش">{orderNumber(order.id)}</Fact>
          <Fact label="مبلغ">{formatMoney(payment.amount)}</Fact>
          {payment.receipt !== null && <Fact label="ارسال رسید">{formatDate(payment.receipt.sent_at)}</Fact>}
        </Section>
        <HomeAction />
      </>
    )
  }
  if (payment.status !== 'pending') {
    return (
      <>
        <Outcome tone="danger" icon={ReceiptText} title="رسید پذیرفته نشد" text="این پرداخت تایید نشد. اگر هنوز می‌خواهید، دوباره پرداخت کنید." />
        {payment.note !== null && <Notice tone="warning">{payment.note}</Notice>}
        <PayAgain order={order} />
      </>
    )
  }
  if (transfer?.contact) {
    const { contact } = transfer

    return (
      <>
        <Outcome
          tone="accent"
          icon={Headset}
          title="پرداخت با پشتیبانی"
          text="کارت این پرداخت را پشتیبانی می‌دهد: شماره سفارش را برای پشتیبانی بفرستید، مبلغ را واریز کنید و رسید را همان‌جا بفرستید."
        />
        <Section>
          <Fact label="سفارش">{orderNumber(order.id)}</Fact>
          <Fact label="مبلغ">{formatMoney(transfer.amount)}</Fact>
        </Section>
        <div className="card p-4">
          <CopyValue label="پشتیبانی" value={contact.text} />
        </div>
        {transfer.instructions !== null && (
          <Notice>
            <p className="whitespace-pre-line">{transfer.instructions}</p>
          </Notice>
        )}
        <MainAction text={contact.url === null ? null : 'پیام به پشتیبانی'} onClick={() => contact.url !== null && openContact(contact.url)} />
      </>
    )
  }

  const amount = transfer?.amount ?? payment.amount

  return (
    <>
      <Step number="۱" title="واریز به کارت">
        {transfer?.card ? (
          <BankCard number={transfer.card} holder={transfer.holder} bank={transfer.bank} />
        ) : (
          // The card is answered with the checkout alone: another tab has it, or a new attempt shows it again.
          <div className="flex flex-col items-start gap-3 rounded-2xl bg-fill p-4">
            <span className="flex items-start gap-3">
              <CreditCard aria-hidden className="text-hint size-5 shrink-0" />
              <span className="text-footnote">کارت این پرداخت در این صفحه نیست. برای دیدن دوباره آن، سفارش را دوباره باز کنید؛ همین سفارش و همین کارت نشان داده می‌شود.</span>
            </span>
            <PayAgain order={order} inline label="نمایش دوباره کارت" className="ms-8" />
          </div>
        )}
        <TransferAmount amount={amount} rial={transfer?.amount_rial ?? rialOf(amount)} />
        {transfer !== null && transfer.instructions !== null && (
          <Notice>
            <p className="whitespace-pre-line">{transfer.instructions}</p>
          </Notice>
        )}
      </Step>
      <ReceiptForm paymentId={payment.id} number="۲" title="ارسال رسید" />
    </>
  )
}

/** Nothing left to do here: back home. */
function HomeAction() {
  const navigate = useNavigate()

  return <MainAction text="بازگشت به خانه" onClick={() => navigate('/', { replace: true })} />
}
