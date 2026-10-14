import { nextKey } from '@/components/add-row'
import type { PaymentCardInput, PaymentCardRow } from '@/lib/api-types'
import { amountDraft } from '@/lib/format'

/*
 * A card method's cards as its form holds them (components/payments/cards-editor) and sends them: the list is the cards'
 * order, a kept card goes back with its `id`, a new one without. How many it keeps: lib/limits' PAYMENT_CARDS.
 */

/**
 * A card as typed. `key` is the draft's own, so rows keep their elements and focus as others come and go — never sent;
 * `id` the kept card's (null for a new one); `payments` and `today` what the server said of it, shown beside it.
 */
export interface CardDraft {
  key: number
  id: number | null
  number: string
  holder: string
  bank: string
  enabled: boolean
  daily_cap: string
  payments: number
  today: string | null
}

/** The method's cards as its form starts from them. */
export function cardsDraft(cards: readonly PaymentCardRow[]): CardDraft[] {
  return cards.map((card, index) => ({
    key: index,
    id: card.id,
    number: card.number,
    holder: card.holder,
    bank: card.bank ?? '',
    enabled: card.enabled,
    daily_cap: card.daily_cap === null ? '' : amountDraft(card.daily_cap),
    payments: card.payments,
    today: card.today,
  }))
}

/** A new card, after the others: switched on, no cap. */
export function newCard(cards: readonly CardDraft[]): CardDraft {
  const key = nextKey(cards.map((card) => card.key))
  return { key, id: null, number: '', holder: '', bank: '', enabled: true, daily_cap: '', payments: 0, today: null }
}

/** What the form sends: every card in its order, a kept one by its id. */
export function cardsPayload(cards: readonly CardDraft[]): PaymentCardInput[] {
  return cards.map((card) => ({
    ...(card.id === null ? {} : { id: card.id }),
    number: card.number,
    holder: card.holder,
    bank: card.bank,
    enabled: card.enabled,
    daily_cap: card.daily_cap,
  }))
}
