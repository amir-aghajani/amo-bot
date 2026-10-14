import { Fragment } from 'react'
import type { PaymentCardRow, PaymentMethodRow } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'

/** What a driver's line about a method (`summary`) holds apart with « · »: a masked card, its holder, its bank. */
const SUMMARY_PARTS = ' · '

/** No letter of any script: a card's digits and bullets — an identifier, set apart left to right in the mono. */
const NUMBERS_ONLY = /^[^\p{L}]+$/u

/**
 * A one-line summary of what the customer pays to ("6037 •••• •••• 1119 · امیر رضایی · ملت") — a payment's card, a
 * driver's line about a method —, each part in its own direction: the masked card left to right, the words as written.
 */
export function MethodSummary({ summary, className }: { summary: string; className?: string }) {
  return (
    <span className={className}>
      {summary.split(SUMMARY_PARTS).map((part, index) => (
        <Fragment key={index}>
          {index > 0 && SUMMARY_PARTS}
          {NUMBERS_ONLY.test(part) ? <bdi dir="ltr">{part}</bdi> : <bdi>{part}</bdi>}
        </Fragment>
      ))}
    </span>
  )
}

/** Whether the card took its whole cap today: no payment is shown it till the shop's midnight. */
const isFull = (card: PaymentCardRow): boolean => card.daily_cap !== null && Number(card.today) >= Number(card.daily_cap)

/** A card method's line while the shop takes card payments in support's private chat: no card is shown nor picked. */
const TO_SUPPORT = 'پرداخت کارت به کارت به پیوی پشتیبانی می‌رود؛ کارت‌ها به مشتری نشان داده نمی‌شوند و سقف روزانه‌شان پر نمی‌شود.'

/**
 * A card method's cards, a line each: the server's line for it (`summary`: masked, its holder and bank), and — switched
 * off, or its cap taken today — why no payment is shown it now; and when no card is left to show, that the method is
 * offered to nobody. While the shop takes card payments in support's private chat (`toSupport`), no card is shown at
 * all: that instead of the caps and warnings.
 */
export function CardLines({ cards, toSupport = false }: { cards: readonly PaymentCardRow[]; toSupport?: boolean }) {
  if (cards.length === 0 && toSupport) return <span className="text-muted-foreground">{TO_SUPPORT}</span>
  if (cards.length === 0) return <span className="text-warning">کارتی ندارد؛ به مشتری پیشنهاد نمی‌شود.</span>
  const on = cards.filter((card) => card.enabled)
  const unused = toSupport
    ? null
    : on.length === 0
      ? 'همه کارت‌ها خاموش‌اند؛ به مشتری پیشنهاد نمی‌شود.'
      : on.every(isFull)
        ? 'سقف روزانه کارت‌های روشن پر شده؛ تا فردا به مشتری پیشنهاد نمی‌شود.'
        : null

  return (
    <span className="grid gap-0.5">
      {cards.map((card) => (
        <span key={card.id} className={card.enabled && !toSupport ? undefined : 'text-muted-foreground'}>
          <MethodSummary summary={card.summary} />
          {!card.enabled && ' (خاموش)'}
          {!toSupport && card.enabled && card.daily_cap !== null && ` — امروز ${formatMoney(card.today)} از ${formatMoney(card.daily_cap)}${isFull(card) ? '؛ پر شده' : ''}`}
        </span>
      ))}
      {toSupport && <span className="text-muted-foreground">{TO_SUPPORT}</span>}
      {unused && <span className="text-warning">{unused}</span>}
    </span>
  )
}

/** The amounts a method is offered for, in the words the customer is told them (PaymentMethods::boundsInWords()); null while it takes any. */
export function boundsLine(method: Pick<PaymentMethodRow, 'min_amount' | 'max_amount'>): string | null {
  const { min_amount: min, max_amount: max } = method
  if (min !== null && max !== null) return `برای مبلغ‌های بین ${formatMoney(min)} و ${formatMoney(max)}`
  if (min !== null) return `برای مبلغ‌های از ${formatMoney(min)} به بالا`
  if (max !== null) return `برای مبلغ‌های تا ${formatMoney(max)}`
  return null
}
