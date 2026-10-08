import { useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Bold, Check, Code, EyeOff, Italic, Link2, Quote, RotateCcw, Sparkles, Strikethrough, TriangleAlert, Underline, type LucideIcon } from 'lucide-react'
import { toast } from 'sonner'
import { KIND_LABELS } from '@/components/bot-texts/kinds'
import { PremiumEmojiPicker, premiumEmojiTag } from '@/components/bot-texts/premium-emoji-picker'
import { fillSamples, visibleLength } from '@/components/bot-texts/telegram-html'
import { TextPreview } from '@/components/bot-texts/text-preview'
import { WithTokens } from '@/components/bot-texts/tokens'
import { keptWording, wordingProblem } from '@/components/bot-texts/wording-problem'
import { Field } from '@/components/field'
import { FormActions, FormError } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { Modal } from '@/components/modal'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { api } from '@/lib/api'
import type { BotTextKind, BotTextRow } from '@/lib/api-types'
import { isolateMarkup } from '@/lib/direction'
import { formatNumber } from '@/lib/format'
import { useForm } from '@/lib/use-form'
import { cn } from '@/lib/utils'

interface TextModalProps {
  /** The text being edited; null = closed. */
  text: BotTextRow | null
  onClose: () => void
  onSaved: (text: BotTextRow) => void
}

/**
 * One bot text: the wording, the variables it may use, and how Telegram will show it — and, as it is typed, what the
 * save would refuse in it (wordingProblem(), the server's rules mirrored; the server stays the judge). A wording
 * half-written is not lost to a stray Esc, the backdrop or leaving the page — the form's actions ask first.
 */
export function TextModal({ text, onClose, onSaved }: TextModalProps) {
  return (
    <Modal open={text !== null} onClose={onClose} size="xl" title={text?.title ?? ''} description={text && <WithTokens text={text.description} />}>
      {text && <TextForm key={text.key} text={text} onCancel={onClose} onSaved={onSaved} />}
    </Modal>
  )
}

/** Telegram's formatting, as the toolbar wraps a selection in it. */
const FORMATS: { icon: LucideIcon; label: string; open: string; close: string }[] = [
  { icon: Bold, label: 'پررنگ', open: '<b>', close: '</b>' },
  { icon: Italic, label: 'کج', open: '<i>', close: '</i>' },
  { icon: Underline, label: 'زیرخط', open: '<u>', close: '</u>' },
  { icon: Strikethrough, label: 'خط‌خورده', open: '<s>', close: '</s>' },
  { icon: Code, label: 'کد (با یک لمس کپی می‌شود)', open: '<code>', close: '</code>' },
  { icon: EyeOff, label: 'مخفی (با لمس نمایان می‌شود)', open: '<tg-spoiler>', close: '</tg-spoiler>' },
  { icon: Quote, label: 'نقل‌قول', open: '<blockquote>', close: '</blockquote>' },
  { icon: Link2, label: 'لینک', open: '<a href="">', close: '</a>' },
]

function Tag({ children }: { children: ReactNode }) {
  return (
    <bdi dir="ltr" className="text-caption">
      {children}
    </bdi>
  )
}

const HINTS: Record<BotTextKind, ReactNode> = {
  message: (
    <>
      تلگرام پیام را HTML می‌خواند: با دکمه‌های بالا، یا تگ‌هایی مثل <Tag>{'<b>…</b>'}</Tag>. برای نوشتن خود نماد <Tag>{'<'}</Tag> از <Tag>&amp;lt;</Tag> استفاده کنید.
    </>
  ),
  caption: 'متن زیر عکس کد QR لینک؛ قالب‌بندی مثل پیام. تلگرام زیر عکس متن کوتاه‌تری از یک پیام جا می‌دهد؛ اگر متن با مقدارهای واقعی (مثلا یک لینک بلند) از آن بیشتر شود، بدون عکس فرستاده می‌شود.',
  popup: 'اعلان کوتاه تلگرام روی دکمه‌ای که مشتری زده است؛ قالب‌بندی ندارد و همان‌طور که نوشته شود نشان داده می‌شود.',
  button: 'متن روی دکمه؛ یک خط و بدون قالب‌بندی.',
  part: <>این بخش داخل متن دیگری می‌نشیند؛ خط‌های خالی اولش فاصله آن با متن بالایی است. قالب‌بندی مثل پیام؛ اگر متغیر لازمی ندارد، خالی بگذارید تا نشان داده نشود.</>,
}

function TextForm({ text, onCancel, onSaved }: { text: BotTextRow; onCancel: () => void; onSaved: (text: BotTextRow) => void }) {
  // Unsaved is a wording the server would keep otherwise — a space at its end is none; a part's leading line breaks are.
  const { values, set, revert, error, formError, busy, dirty, submit } = useForm({ value: text.value }, { reads: ({ value }) => keptWording(text.kind, value) })
  const field = useRef<HTMLTextAreaElement>(null)
  const [emojiOpen, setEmojiOpen] = useState(false)
  const samples = useMemo(() => Object.fromEntries(text.variables.map((variable) => [variable.name, variable.sample])), [text.variables])
  // As Telegram counts, and the server with it: UTF-16 units (an emoji beyond the basic plane is two) of what the
  // customer sees — a tag's markup, a premium emoji's long one above all, costs nothing.
  const length = text.html ? visibleLength(values.value) : values.value.length
  const tooLong = length > text.limit
  // What the save would refuse, as it is typed; once a save is refused, its own word stands until the next edit. Either
  // says tags: each kept whole and as typed in the Persian sentence (isolateMarkup — bare, «</b>» would read «<b/>»).
  const problem = useMemo(() => wordingProblem(text, values.value), [text, values.value])
  const refused = error('value')

  /**
   * Write `open` and `close` around the selection — the selection, or the caret, stays between them —, or, with
   * `replace`, write `open` in its place, the caret after it.
   */
  const replaceSelection = (open: string, close = '', replace = false) => {
    const textarea = field.current
    const value = values.value
    const start = textarea?.selectionStart ?? value.length
    const end = textarea?.selectionEnd ?? value.length
    const kept = replace ? '' : value.slice(start, end)
    set('value', value.slice(0, start) + open + kept + close + value.slice(end))
    requestAnimationFrame(() => {
      textarea?.focus()
      textarea?.setSelectionRange(start + open.length, start + open.length + kept.length)
    })
  }
  const insert = (token: string) => replaceSelection(token, '', true)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const data = await submit(() => api.put(`/bot/texts/${text.key}`, { value: values.value }))
    if (data) {
      toast.success(data.text.customized ? 'متن ذخیره شد؛ ربات از این به بعد همین را می‌فرستد' : 'متن پیش‌فرض دوباره به کار می‌رود')
      onSaved(data.text)
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-5">
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div className="grid content-start gap-5">
          <Field
            id="bot_text"
            label={
              <>
                متن
                <span className="text-caption font-normal text-muted-foreground">{KIND_LABELS[text.kind]}</span>
              </>
            }
            error={refused && isolateMarkup(refused)}
            hint={HINTS[text.kind]}
          >
            {(control) => (
              <div className="grid gap-1.5">
                {text.html && (
                  <div className="flex flex-wrap items-center gap-0.5 rounded-lg border border-border p-0.5" role="toolbar" aria-label="قالب‌بندی">
                    {FORMATS.map(({ icon: Icon, label, open, close }) => (
                      <IconButton key={label} aria-label={label} title={label} className="size-7" onClick={() => replaceSelection(open, close)}>
                        <Icon className="size-3.5" aria-hidden />
                      </IconButton>
                    ))}
                    <Button
                      type="button"
                      variant={emojiOpen ? 'secondary' : 'ghost'}
                      size="sm"
                      className="ms-auto h-7 gap-1.5 px-2 text-footnote"
                      aria-expanded={emojiOpen}
                      aria-controls={emojiOpen ? 'premium_emoji_picker' : undefined}
                      onClick={() => setEmojiOpen((open) => !open)}
                    >
                      <Sparkles className="size-3.5" aria-hidden />
                      ایموجی پرمیوم
                    </Button>
                  </div>
                )}
                {text.html && emojiOpen && (
                  <div id="premium_emoji_picker">
                    <PremiumEmojiPicker onPick={(emoji) => insert(premiumEmojiTag(emoji))} />
                  </div>
                )}
                <Textarea
                  {...control}
                  ref={field}
                  dir="rtl"
                  value={values.value}
                  onChange={(event) => set('value', event.target.value)}
                  // Grows with its lines. Fixed sizing: sized by its content, the field would claim its longest line as
                  // its narrowest width and push a phone-wide modal sideways.
                  rows={Math.min(18, Math.max(text.kind === 'button' || text.kind === 'popup' ? 2 : 4, values.value.split('\n').length + 1))}
                  // Each line takes the direction of its first letter, so one that starts with a tag reads left to
                  // right instead of scattering its brackets; every line still hugs the right edge.
                  className="field-sizing-fixed min-h-0 text-right leading-relaxed [unicode-bidi:plaintext]"
                />
                {/* An HTML wording counts what the customer sees: its tags cost nothing — said beside the count. */}
                <p className={cn('text-end text-caption tabular', tooLong ? 'text-danger' : 'text-muted-foreground')}>
                  {formatNumber(length)} از {formatNumber(text.limit)} کاراکتر{text.html && '، بدون تگ‌ها'}
                </p>
                {problem && !refused && (
                  <p className="flex items-start gap-1.5 text-footnote text-warning">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    <span>
                      <span className="sr-only">پیش از ذخیره: </span>
                      {isolateMarkup(problem)}
                    </span>
                  </p>
                )}
              </div>
            )}
          </Field>

          <Variables text={text} value={values.value} onPick={insert} />
          <FormError message={formError} />
        </div>

        <div className="lg:sticky lg:top-0 lg:self-start">
          <p className="mb-2 text-footnote font-medium text-muted-foreground">پیش‌نمایش</p>
          <TextPreview kind={text.kind} text={fillSamples(values.value, samples, text.html)} />
          {text.variables.length > 0 && <p className="mt-2 text-caption leading-relaxed text-muted-foreground">به جای متغیرها مقدار نمونه نشسته است.</p>}
        </div>
      </div>

      <FormActions
        onCancel={onCancel}
        submitLabel="ذخیره متن"
        icon={Check}
        busy={busy}
        dirty={dirty}
        onRevert={revert}
        start={
          <Button variant="secondary" icon={RotateCcw} onClick={() => set('value', text.default)} disabled={busy || values.value === text.default}>
            متن پیش‌فرض
          </Button>
        }
      />
    </form>
  )
}

/** The variables the text may use: a tap puts one at the caret; one the text cannot do without says so while it is missing. */
function Variables({ text, value, onPick }: { text: BotTextRow; value: string; onPick: (token: string) => void }) {
  if (text.variables.length === 0) {
    return <p className="text-footnote text-muted-foreground">این متن متغیری ندارد.</p>
  }

  return (
    <div className="grid gap-2">
      <div className="grid gap-0.5">
        <p className="text-body font-medium">متغیرها</p>
        <p className="text-footnote leading-relaxed text-muted-foreground">روی هر متغیر بزنید تا جای مکان‌نما بنشیند؛ ربات هنگام ارسال، مقدار واقعی را جای آن می‌گذارد.</p>
      </div>
      <ul className="grid gap-1.5 sm:grid-cols-2">
        {text.variables.map((variable) => {
          const token = `%${variable.name}%`
          const missing = variable.required && !value.includes(token)
          return (
            <li key={variable.name}>
              <button
                type="button"
                onClick={() => onPick(token)}
                className={cn(
                  'grid h-full w-full gap-0.5 rounded-lg border px-2.5 py-1.5 text-start transition-colors duration-150 outline-none hover:bg-fill focus-visible:focus-ring',
                  missing ? 'border-danger-line' : 'border-border',
                )}
              >
                <span className="flex items-center gap-2">
                  <bdi dir="ltr" className="text-footnote text-info">
                    {token}
                  </bdi>
                  {variable.required && <span className={cn('text-caption', missing ? 'text-danger' : 'text-muted-foreground')}>{missing ? 'لازم است' : 'لازم'}</span>}
                </span>
                <span className="text-footnote leading-relaxed text-muted-foreground">{variable.description}</span>
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
