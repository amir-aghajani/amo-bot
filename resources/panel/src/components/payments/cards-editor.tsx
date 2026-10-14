import { X } from 'lucide-react'
import { AddRow, useRowFocus } from '@/components/add-row'
import { Field, FieldGroup } from '@/components/field'
import { IconButton } from '@/components/icon-button'
import { MoneyField } from '@/components/money-field'
import { OrderControls } from '@/components/order-controls'
import { newCard, type CardDraft } from '@/components/payments/cards'
import { Switch } from '@/components/switch'
import { Input } from '@/components/ui/input'
import { digitsOnly, formatCardNumber, formatMoney, formatNumber } from '@/lib/format'
import { PAYMENT_CARDS } from '@/lib/limits'
import { cn } from '@/lib/utils'

/** A bank card's digits: what its number holds at most. */
const CARD_DIGITS = 16

interface CardsEditorProps {
  cards: CardDraft[]
  onChange: (cards: CardDraft[]) => void
  /** The server's refusals of the list, each naming its card by its place. */
  errors?: readonly string[]
  /** What the fields' ids begin with: unique on the page. */
  idPrefix: string
}

/**
 * A card method's cards: each its number (as printed, in groups of four), holder, bank, daily cap and switch, moved up
 * or down — the list's order is theirs —, then an add button that is held, not disabled, once the list is full (AddRow).
 * A card payments were made to keeps its number and is switched off, never removed. Each payment is shown one of the
 * cards switched on at random, among those whose cap leaves room for it today. The focus never falls to the document as
 * cards come and go (useRowFocus): a new card's number takes it.
 */
export function CardsEditor({ cards, onChange, errors, idPrefix }: CardsEditorProps) {
  const { block, added, removed } = useRowFocus((card: CardDraft) => card.key)

  const add = () => {
    const card = newCard(cards)
    added(card, 'number')
    onChange([...cards, card])
  }
  const remove = (index: number) => {
    const next = cards.filter((_, i) => i !== index)
    removed(next, index)
    onChange(next)
  }
  const move = (index: number, delta: -1 | 1) => {
    const next = [...cards]
    const [card] = next.splice(index, 1)
    if (card) next.splice(index + delta, 0, card)
    onChange(next)
  }
  const edit = <K extends keyof CardDraft>(index: number, field: K, value: CardDraft[K]) => onChange(cards.map((card, i) => (i === index ? { ...card, [field]: value } : card)))

  return (
    <div ref={block}>
      <FieldGroup
        label="کارت‌ها"
        description="به هر پرداخت یکی از کارت‌های روشن به تصادف نشان داده می‌شود، از میان کارت‌هایی که سقف روزانه‌شان جای آن مبلغ را دارد؛ همان پرداخت همیشه همان کارت را نشان می‌دهد."
        error={errors && errors.length > 0 ? errors : undefined}
        className="gap-2"
      >
        {cards.length > 0 && (
          <ul className="grid gap-3">
            {cards.map((card, index) => {
              const place = formatNumber(index + 1)
              const name = `کارت ${place}`
              const id = `${idPrefix}_card_${card.key}`
              const paid = card.payments > 0
              return (
                <li key={card.key} data-row={card.key} className={cn('grid gap-3 rounded-lg border border-border p-3', !card.enabled && 'bg-fill-hover')}>
                  <div className="flex items-center gap-2">
                    <OrderControls label={name} index={index} count={cards.length} onMove={(delta) => move(index, delta)} />
                    <span className="text-body font-medium">{name}</span>
                    <span className="ms-auto flex items-center gap-2">
                      <span className="text-footnote text-muted-foreground">{card.enabled ? 'روشن' : 'خاموش'}</span>
                      <Switch checked={card.enabled} onCheckedChange={(enabled) => edit(index, 'enabled', enabled)} aria-label={`روشن بودن ${name}`} />
                      {/* A card paid to stays, for the payments that name it: switched off instead. */}
                      {!paid && (
                        <IconButton data-part="remove" aria-label={`حذف ${name}`} onClick={() => remove(index)}>
                          <X className="size-4" aria-hidden />
                        </IconButton>
                      )}
                    </span>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Field id={`${id}_number`} label="شماره کارت" hint={paid ? 'با این کارت پرداخت شده؛ شماره‌اش عوض نمی‌شود.' : undefined}>
                      <Input
                        data-part="number"
                        dir="ltr"
                        inputMode="numeric"
                        autoComplete="off"
                        spellCheck={false}
                        readOnly={paid}
                        value={formatCardNumber(card.number)}
                        onChange={(e) => edit(index, 'number', digitsOnly(e.target.value).slice(0, CARD_DIGITS))}
                        placeholder="6037 9977 0000 1119"
                      />
                    </Field>
                    <Field id={`${id}_holder`} label="نام صاحب کارت">
                      <Input value={card.holder} onChange={(e) => edit(index, 'holder', e.target.value)} placeholder="نام و نام خانوادگی" />
                    </Field>
                    <Field id={`${id}_bank`} label="بانک" optional hint="مشتری آن را زیر شماره کارت می‌بیند، در ربات و سایت؛ مثلا «ملت».">
                      <Input value={card.bank} onChange={(e) => edit(index, 'bank', e.target.value)} />
                    </Field>
                    <MoneyField
                      id={`${id}_cap`}
                      label="سقف روزانه (تومان)"
                      optional
                      value={card.daily_cap}
                      onChange={(cap) => edit(index, 'daily_cap', cap)}
                      says={(money) => (card.today === null ? `= ${money}` : `= ${money}؛ امروز ${formatMoney(card.today)} از این سقف پر شده.`)}
                      hint={card.today === null ? 'خالی یعنی بدون سقف.' : `امروز ${formatMoney(card.today)}؛ خالی یعنی بدون سقف.`}
                    />
                  </div>
                </li>
              )
            })}
          </ul>
        )}

        <AddRow noun="کارت" count={cards.length} most={PAYMENT_CARDS.MOST} onAdd={add} empty="دست کم یک کارت لازم است." />
      </FieldGroup>
    </div>
  )
}
