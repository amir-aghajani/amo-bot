import { useId, useRef, useState, type KeyboardEvent } from 'react'
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Check, Sparkles, Trash2, TriangleAlert, X, type LucideIcon } from 'lucide-react'
import { flushSync } from 'react-dom'
import { notePlainEmoji, usePlainEmoji } from '@/components/bot-texts/custom-emojis'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { PremiumEmojiPicker } from '@/components/bot-texts/premium-emoji-picker'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { Creating, FormActions } from '@/components/form-footer'
import { styleOptions, type StyleOption } from '@/components/keyboards/styles'
import { Modal } from '@/components/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { ButtonStyle, KeyboardAction, KeyboardButtonSpec } from '@/lib/api-types'
import { isRtl } from '@/lib/direction'
import { useForm } from '@/lib/use-form'
import { cn } from '@/lib/utils'

type MoveDirection = 'right' | 'left' | 'up' | 'down'

/** Where an existing button sits, and the moves open to it from there (one missing: an end of its row, or a full row). */
export interface ButtonMoves {
  /** «ردیف ۲، دکمه ۱ از ۳». */
  place: string
  to: Partial<Record<MoveDirection, () => void>>
}

interface ButtonModalProps {
  open: boolean
  onClose: () => void
  actions: KeyboardAction[]
  /** The colours Telegram gives a button (the API's list). */
  styles: readonly ButtonStyle[]
  /** Actions already placed on the keyboard (a button stands for one action once). */
  taken: string[]
  /** Present when editing an existing button. */
  button?: KeyboardButtonSpec
  labelMax: number
  /** An existing button's place and moves. */
  moves?: ButtonMoves
  onSave: (button: KeyboardButtonSpec) => void
  onRemove?: () => void
}

/** A premium emoji's tag in a label — pasted from /emoji's template; Telegram would show it as it is. */
const PREMIUM_TAG = /<\/?tg-emoji/i

/** Each such tag with what it holds, whole or cut short by the label's limit (in the tag, in its emoji, in its closing). */
const PREMIUM_TAGS = /<tg-emoji\b[^>]*>[\s\S]*?(?:<\/tg-emoji\s*>|<\/[^>]*$|$)|<tg-emoji\b[^>]*$|<\/tg-emoji\s*>/gi

/** A tag's emoji id, when it survived whole — and the plain emoji it holds, when that did too. */
const PREMIUM_ID = /emoji-id="(\d{1,20})"(?:[^>]*>([^<]+)<)?/

/**
 * The label's premium emoji lifted out: its tags leave the label, and the emoji becomes the icon — when its id survived
 * whole —, the plain emoji its tag held kept for the panel to show in its place (notePlainEmoji()).
 */
function liftPremium(label: string): Partial<KeyboardButtonSpec> {
  const [, id, plain] = label.match(PREMIUM_ID) ?? []
  if (id && plain) notePlainEmoji(id, plain)
  return {
    label: label
      .replace(PREMIUM_TAGS, ' ')
      .replace(/\s{2,}/g, ' ')
      .trim(),
    ...(id ? { icon: id } : {}),
  }
}

/**
 * One button: what it does, what it says, the premium emoji it may show before its label, which of Telegram's colours
 * it wears — and, for one on the keyboard, its place, moved from here as well as by dragging. The label follows the
 * chosen action while it is that action's own. A premium emoji pasted into the label is offered a move to the icon,
 * where Telegram shows it. The rest of the rules (an action twice, a label too long) are the save's.
 */
export function ButtonModal({ open, onClose, button, ...props }: ButtonModalProps) {
  return (
    <Modal open={open} onClose={onClose} size="sm" title={button ? 'ویرایش دکمه' : 'افزودن دکمه'}>
      {/* A new button's form has nothing saved to go back to: no revert. */}
      {open && (
        <Creating value={button === undefined}>
          <ButtonForm key={button ? `${button.action}:${button.label}` : 'new'} button={button} onCancel={onClose} {...props} />
        </Creating>
      )}
    </Modal>
  )
}

function ButtonForm({ actions, styles, taken, button, labelMax, moves, onSave, onCancel, onRemove }: Omit<ButtonModalProps, 'open' | 'onClose'> & { onCancel: () => void }) {
  const form = useForm<KeyboardButtonSpec>(() => {
    if (button) return button
    const free = actions.find((action) => !taken.includes(action.key))
    return { action: free?.key ?? '', label: free?.label ?? '', style: null, icon: null }
  })
  const { values, set, patch } = form
  const [picking, setPicking] = useState(false)
  const plainOf = usePlainEmoji()
  const tagged = PREMIUM_TAG.test(values.label)

  // The label follows the action while it is the action's own wording, not one the admin typed.
  const pick = (key: string) => {
    const own = actions.find((action) => action.key === values.action)?.label ?? ''
    patch(values.label === own ? { action: key, label: actions.find((action) => action.key === key)?.label ?? '' } : { action: key })
  }

  const submit = form.handleSubmit(() => {
    const label = values.label.trim()
    if (values.action === '') return form.setErrors({ action: ['یک دکمه انتخاب کنید.'] })
    if (label === '') return form.setErrors({ label: ['متن دکمه خالی است.'] })
    onSave({ ...values, label })
  })

  return (
    <form onSubmit={submit} noValidate className="grid gap-5">
      <Field id="kb_action" label="دکمه" error={form.error('action')} hint="کاری که این دکمه در ربات انجام می‌دهد.">
        {(control) => (
          <Select value={values.action} onValueChange={pick}>
            <SelectTrigger {...control} className="w-full">
              <SelectValue placeholder="انتخاب دکمه" />
            </SelectTrigger>
            <SelectContent>
              {actions.map((item) => {
                const used = taken.includes(item.key) && item.key !== button?.action
                return (
                  <SelectItem key={item.key} value={item.key} disabled={used}>
                    <span className="flex items-center gap-2">
                      <span>{item.label}</span>
                      <span dir="ltr" className="text-caption text-muted-foreground">
                        {item.key}
                      </span>
                      {used && <span className="text-caption text-muted-foreground">(روی کیبورد هست)</span>}
                    </span>
                  </SelectItem>
                )
              })}
            </SelectContent>
          </Select>
        )}
      </Field>

      <Field id="kb_label" label="متن دکمه" error={form.error('label')} hint="همان چیزی که مشتری روی دکمه می‌بیند؛ ایموجی هم می‌شود.">
        <Input value={values.label} maxLength={labelMax} onChange={(event) => set('label', event.target.value)} />
      </Field>

      {tagged && (
        <Callout tone="warning" icon={TriangleAlert}>
          <p className="leading-relaxed">ایموجی پرمیوم در متن دکمه به صورت کد دیده می‌شود؛ آن را به عنوان آیکون دکمه انتخاب کنید.</p>
          <Button variant="secondary" size="sm" icon={Sparkles} className="mt-2" onClick={() => patch(liftPremium(values.label))}>
            {PREMIUM_ID.test(values.label) ? 'انتقال به آیکون دکمه' : 'پاک کردن از متن'}
          </Button>
        </Callout>
      )}

      <fieldset className="grid gap-2">
        <legend className="mb-1 text-body font-medium">آیکون دکمه</legend>
        <div className="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5">
          <span aria-hidden className={cn('grid size-9 shrink-0 place-items-center rounded-lg border', values.icon ? 'border-border bg-fill' : 'border-dashed border-border text-muted-foreground')}>
            {values.icon ? <PremiumEmoji id={values.icon} fallback={plainOf(values.icon)} className="size-6 text-lg leading-none" /> : <Sparkles className="size-4" />}
          </span>
          <span className="grid min-w-0 flex-1 gap-0.5">
            <span className="text-body font-medium">{values.icon ? 'ایموجی پرمیوم' : 'بدون آیکون'}</span>
            <span className="text-footnote text-muted-foreground">{values.icon ? 'پیش از متن دکمه نشان داده می‌شود.' : 'اختیاری: یک ایموجی پرمیوم پیش از متن دکمه.'}</span>
          </span>
          <span className="flex shrink-0 items-center gap-1">
            {values.icon && (
              <Button variant="ghost" size="sm" icon={X} onClick={() => set('icon', null)}>
                برداشتن
              </Button>
            )}
            <Button variant={picking ? 'default' : 'secondary'} size="sm" aria-expanded={picking} aria-controls={picking ? 'kb_icon_picker' : undefined} onClick={() => setPicking((open) => !open)}>
              {values.icon ? 'تغییر' : 'انتخاب'}
            </Button>
          </span>
        </div>
        {picking && (
          <div id="kb_icon_picker">
            <PremiumEmojiPicker
              selected={values.icon}
              onPick={(emoji) => {
                set('icon', emoji.id)
                setPicking(false)
              }}
            />
          </div>
        )}
        <p className="text-footnote leading-relaxed text-muted-foreground">
          فقط وقتی دیده می‌شود که حسابی که ربات را در <bdi dir="ltr">@BotFather</bdi> ساخته تلگرام پرمیوم داشته باشد؛ وگرنه دکمه بدون آیکون نشان داده می‌شود و همان کار را می‌کند.
        </p>
      </fieldset>

      <StylePicker options={styleOptions(styles)} value={values.style} onChange={(style) => set('style', style)} />

      {moves && <MoveButtons moves={moves} />}

      <FormActions
        onCancel={onCancel}
        submitLabel={button ? 'ذخیره دکمه' : 'افزودن دکمه'}
        icon={Check}
        dirty={form.dirty}
        onRevert={form.revert}
        start={
          onRemove && (
            <Button variant="danger" icon={Trash2} onClick={onRemove}>
              حذف دکمه
            </Button>
          )
        }
      />
    </form>
  )
}

/** Telegram's colours for the button, one picked: a radio group — arrow keys move the pick, Tab leaves the group. */
function StylePicker({ options, value, onChange }: { options: StyleOption[]; value: ButtonStyle | null; onChange: (style: ButtonStyle | null) => void }) {
  const id = useId()
  const radios = useRef(new Map<ButtonStyle | null, HTMLButtonElement>())
  const picked = options.some((option) => option.value === value)

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const [forward, backward] = isRtl() ? ['ArrowLeft', 'ArrowRight'] : ['ArrowRight', 'ArrowLeft']
    const step = event.key === 'ArrowDown' || event.key === forward ? 1 : event.key === 'ArrowUp' || event.key === backward ? -1 : 0
    if (step === 0) return
    event.preventDefault()
    const index = options.findIndex((option) => option.value === value)
    const next = options[(index + step + options.length) % options.length]
    if (!next) return
    onChange(next.value)
    radios.current.get(next.value)?.focus()
  }

  return (
    <div className="grid gap-2">
      <p id={id} className="mb-1 text-body font-medium">
        رنگ دکمه
      </p>
      <div role="radiogroup" aria-labelledby={id} onKeyDown={onKeyDown} className="grid gap-2">
        {options.map((option, i) => {
          const selected = option.value === value
          return (
            <button
              key={option.value ?? 'default'}
              ref={(element) => {
                if (element) radios.current.set(option.value, element)
                else radios.current.delete(option.value)
              }}
              type="button"
              role="radio"
              aria-checked={selected}
              // One stop for Tab: the picked colour (the first, when none of these is).
              tabIndex={selected || (!picked && i === 0) ? 0 : -1}
              onClick={() => onChange(option.value)}
              className={cn(
                'flex items-center gap-3 rounded-lg border px-3 py-2.5 text-start transition-colors duration-150 outline-none focus-visible:focus-ring',
                selected ? 'border-selected bg-info-soft/40' : 'border-border hover:bg-fill',
              )}
            >
              <span aria-hidden className="size-9 shrink-0 rounded-lg border border-white/10" style={{ background: option.swatch }} />
              <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="text-body font-medium">{option.title}</span>
                <span className="text-footnote text-muted-foreground">
                  {option.value ? (
                    <>
                      <span dir="ltr">{option.value}</span> — {option.colorName}
                    </>
                  ) : (
                    option.colorName
                  )}
                </span>
              </span>
              {selected && <Check className="size-4 text-selected" aria-hidden />}
            </button>
          )
        })}
      </div>
    </div>
  )
}

const MOVES: { direction: MoveDirection; opposite: MoveDirection; label: string; icon: LucideIcon }[] = [
  { direction: 'right', opposite: 'left', label: 'به راست', icon: ArrowRight },
  { direction: 'left', opposite: 'right', label: 'به چپ', icon: ArrowLeft },
  { direction: 'up', opposite: 'down', label: 'ردیف بالا', icon: ArrowUp },
  { direction: 'down', opposite: 'up', label: 'ردیف پایین', icon: ArrowDown },
]

/**
 * The button's place, moved from the keyboard (dragging is the pointer's way): along its row — the first button sits on
 * the right, as in Telegram — and to the end of the row above or below. The new place is announced; a move that reaches
 * an end hands the focus to one that can still go.
 */
function MoveButtons({ moves }: { moves: ButtonMoves }) {
  const id = useId()
  const buttons = useRef(new Map<MoveDirection, HTMLButtonElement>())

  const go = (direction: MoveDirection, opposite: MoveDirection) => {
    // Rendered at once, so the buttons say where the button can go from its new place.
    flushSync(() => moves.to[direction]?.())
    if (!buttons.current.get(direction)?.disabled) return
    const next = [opposite, ...MOVES.map((move) => move.direction)].map((key) => buttons.current.get(key)).find((element) => element && !element.disabled)
    next?.focus()
  }

  return (
    <div role="group" aria-labelledby={id} className="grid gap-2">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3">
        <p id={id} className="text-body font-medium">
          جای دکمه
        </p>
        <p className="text-footnote text-muted-foreground" aria-live="polite">
          {moves.place}
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        {MOVES.map(({ direction, opposite, label, icon }) => (
          <Button
            key={direction}
            ref={(element) => {
              if (element) buttons.current.set(direction, element)
              else buttons.current.delete(direction)
            }}
            variant="secondary"
            size="sm"
            icon={icon}
            disabled={!moves.to[direction]}
            onClick={() => go(direction, opposite)}
          >
            {label}
          </Button>
        ))}
      </div>
    </div>
  )
}
