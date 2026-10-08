import { CheckCheck, Menu, Mic, MoreVertical, Paperclip, Search, Smile } from 'lucide-react'
import { usePlainEmoji } from '@/components/bot-texts/custom-emojis'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { TelegramHtml } from '@/components/bot-texts/telegram-html'
import { styleOption } from '@/components/keyboards/styles'
import type { KeyboardButtonSpec, KeyboardType } from '@/lib/api-types'
import { cn } from '@/lib/utils'

interface KeyboardPreviewProps {
  type: KeyboardType
  rows: KeyboardButtonSpec[][]
  /** The bot's greeting the keyboard goes with, as Telegram HTML with samples in its variables; undefined while it loads. */
  message: string | undefined
}

/** The chat's title in the mock — the bot's own name is Telegram's, not the panel's. */
const BOT_NAME = 'ربات فروشگاه'

/**
 * The chat as Telegram Desktop draws it in its dark theme: header, the customer's "/start", the
 * bot's greeting, the composer — and the keyboard where Telegram puts it: a reply keyboard under
 * the composer, inline buttons hanging from the greeting. Bubbles keep Telegram's sides (outgoing on
 * the right) whatever the panel's direction; rows are in reading order, so an RTL flex row puts the
 * first button on the right, exactly where Telegram puts it after the server reverses the row. A button's
 * premium emoji icon sits before its label, as Telegram draws it.
 */
export function KeyboardPreview({ type, rows, message }: KeyboardPreviewProps) {
  return (
    <div className="tg-preview flex flex-col overflow-hidden rounded-xl border border-white/10 text-footnote" style={{ minHeight: 400 }} aria-label="پیش‌نمایش در تلگرام">
      {/* header */}
      <div className="flex items-center gap-3 px-3 py-2" style={{ background: 'var(--tg-header)' }} dir="rtl">
        <span aria-hidden className="flex size-9 shrink-0 items-center justify-center rounded-full text-body font-semibold text-white" style={{ background: 'var(--tg-avatar)' }}>
          {BOT_NAME.slice(0, 1)}
        </span>
        <span className="grid min-w-0 flex-1 gap-0.5">
          <span className="truncate text-footnote font-semibold">{BOT_NAME}</span>
          <span className="text-caption" style={{ color: 'var(--tg-muted)' }}>
            ربات
          </span>
        </span>
        <span className="flex items-center gap-3" style={{ color: 'var(--tg-muted)' }} aria-hidden>
          <Search className="size-4" />
          <MoreVertical className="size-4" />
        </span>
      </div>

      {/* chat */}
      <div className="tg-chat flex flex-1 flex-col justify-end gap-1.5 px-3 pt-4 pb-2" dir="ltr">
        <div className="ms-auto max-w-[80%]">
          <div className="relative rounded-xl rounded-br-sm px-3 py-1.5" style={{ background: 'var(--tg-out)' }} dir="ltr">
            <span className="text-caption">/start</span>
            <span className="ms-3 inline-flex translate-y-0.5 items-center gap-0.5 text-caption" style={{ color: 'var(--tg-out-meta)' }} dir="ltr">
              12:00
              <CheckCheck className="size-3" aria-hidden />
            </span>
          </div>
        </div>

        {/* Bubble and inline buttons share one column, as in Telegram, where the buttons are as wide as the message.
            minmax(0,1fr): otherwise the track grows to the buttons' text width and the column overflows its cap. */}
        <div className="me-auto grid max-w-[85%] grid-cols-[minmax(0,1fr)] gap-1">
          {/* The greeting keeps its line breaks and wraps a long run as Telegram does; the time drops to its own line at
              the bubble's end, as Telegram does when the last line is full. */}
          <div className="relative rounded-xl rounded-bl-sm px-3 pt-1.5 pb-1 leading-snug wrap-anywhere whitespace-pre-wrap" style={{ background: 'var(--tg-in)' }} dir="rtl">
            {message === undefined ? <span style={{ color: 'var(--tg-muted)' }}>…</span> : <TelegramHtml html={message.trim()} />}
            <span className="mt-0.5 block text-left text-caption" style={{ color: 'var(--tg-muted)' }} dir="ltr">
              12:00
            </span>
          </div>
          {type === 'inline' && <Rows rows={rows} inline />}
        </div>
      </div>

      {/* composer */}
      <div className="flex items-center gap-2 px-2 py-1.5" style={{ background: 'var(--tg-header)' }} dir="ltr">
        <span className="inline-flex h-7 items-center gap-1 rounded-full px-2.5 text-caption font-medium text-white" style={{ background: 'var(--tg-accent)' }}>
          <Menu className="size-3.5" aria-hidden />
          Menu
        </span>
        <Paperclip className="size-4 shrink-0" style={{ color: 'var(--tg-muted)' }} aria-hidden />
        <span className="flex-1 truncate text-caption" style={{ color: 'var(--tg-muted)' }} dir="rtl">
          پیام
        </span>
        <Smile className="size-4 shrink-0" style={{ color: 'var(--tg-muted)' }} aria-hidden />
        <Mic className="size-4 shrink-0" style={{ color: 'var(--tg-muted)' }} aria-hidden />
      </div>

      {/* reply keyboard */}
      {type === 'reply' && (
        <div className="px-1.5 pt-1.5 pb-2" style={{ background: 'var(--tg-header)' }}>
          <Rows rows={rows} />
        </div>
      )}
    </div>
  )
}

function Rows({ rows, inline }: { rows: KeyboardButtonSpec[][]; inline?: boolean }) {
  const plainOf = usePlainEmoji()

  if (rows.length === 0) {
    return (
      <p className="py-3 text-center text-caption" style={{ color: 'var(--tg-muted)' }}>
        هنوز دکمه‌ای نیست
      </p>
    )
  }

  return (
    <div className={cn('grid min-w-0', inline ? 'gap-1' : 'gap-1.5')} dir="rtl">
      {rows.map((row, r) => (
        <div key={r} className={cn('flex min-w-0', inline ? 'gap-1' : 'gap-1.5')}>
          {row.map((button, b) => {
            const option = styleOption(button.style)
            return (
              <span
                key={b}
                className={cn('flex min-w-0 flex-1 items-center justify-center gap-1 overflow-hidden px-2 font-medium', inline ? 'h-8 rounded-md text-caption' : 'h-10 rounded-lg text-footnote')}
                // Telegram draws styled inline buttons muted, blended into the chat; reply buttons get the full colour.
                style={{
                  background: button.style ? (inline ? `color-mix(in srgb, ${option.swatch} 55%, var(--tg-in))` : option.swatch) : inline ? 'var(--tg-inline)' : 'var(--tg-button)',
                  color: button.style ? option.text : 'var(--tg-button-text)',
                }}
              >
                {button.icon && <PremiumEmoji id={button.icon} fallback={plainOf(button.icon)} className="shrink-0" />}
                {/* The text is its own box: a flex container's anonymous text item never shrinks below its content. */}
                <span className="truncate">{button.label}</span>
              </span>
            )
          })}
        </div>
      ))}
    </div>
  )
}
