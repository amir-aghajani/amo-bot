import { QrCode } from 'lucide-react'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { TelegramText } from '@/components/bot-texts/telegram-html'
import { leadingPremium, plainShown } from '@/components/bot-texts/wording-problem'
import type { BotTextKind } from '@/lib/api-types'

interface TextPreviewProps {
  kind: BotTextKind
  /** The wording with the samples in place of its variables. */
  text: string
}

/**
 * The text as the customer sees it in Telegram Desktop's dark theme: a message bubble (a caption under the
 * QR card's picture, a part after the text it goes in), a popup over the chat, or a button under a message.
 */
export function TextPreview({ kind, text }: TextPreviewProps) {
  return (
    <div role="figure" className="tg-preview overflow-hidden rounded-xl border border-white/10 text-footnote" aria-label="پیش‌نمایش در تلگرام">
      <div className="tg-chat flex min-h-72 flex-col justify-end gap-1 px-3 pt-4 pb-3" dir="ltr">
        {kind === 'popup' ? <Popup text={text} /> : kind === 'button' ? <InlineButton label={text} /> : <Bubble kind={kind} text={text} />}
      </div>
    </div>
  )
}

/*
 * Every shape below is a one-column grid of `minmax(0, 1fr)`: an `auto` column grows to its longest unbreakable run
 * (a long word, a link) and the whole text lays out that wide and is cut off. The text wraps `anywhere`, as Telegram
 * breaks a run too long for the bubble — and unlike `break-word`, that also keeps the run out of the column's minimum.
 */

function Bubble({ kind, text }: { kind: BotTextKind; text: string }) {
  return (
    <div className="me-auto grid max-w-[88%] min-w-40 grid-cols-[minmax(0,1fr)] overflow-hidden rounded-xl rounded-bl-sm" style={{ background: 'var(--tg-in)' }}>
      {kind === 'caption' && (
        <div className="flex aspect-square items-center justify-center" style={{ background: 'var(--tg-header)' }} aria-hidden>
          <span className="flex size-24 items-center justify-center rounded-2xl bg-white">
            <QrCode className="size-16 text-[#0e1621]" strokeWidth={1.5} />
          </span>
        </div>
      )}
      <div className="px-3 pt-1.5 pb-1 leading-relaxed wrap-anywhere whitespace-pre-wrap" dir="rtl">
        {kind === 'part' && (
          <span className="block" style={{ color: 'var(--tg-muted)' }}>
            …
          </span>
        )}
        {text === '' ? <span style={{ color: 'var(--tg-muted)' }}>این بخش خالی است و نشان داده نمی‌شود.</span> : <TelegramText html={kind === 'part' ? text : text.trim()} />}
        <span className="mt-0.5 block text-left text-caption" style={{ color: 'var(--tg-muted)' }} dir="ltr">
          12:00
        </span>
      </div>
    </div>
  )
}

/** A callback's alert: Telegram shows it over the chat, as typed — a premium emoji as its plain one —, with an OK. */
function Popup({ text }: { text: string }) {
  return (
    <div className="mx-auto my-auto grid w-64 max-w-full grid-cols-[minmax(0,1fr)] gap-3 rounded-lg px-4 pt-4 pb-2.5 shadow-lg" style={{ background: 'var(--tg-header)' }}>
      <p className="leading-relaxed wrap-anywhere whitespace-pre-wrap" dir="rtl">
        {plainShown(text)}
      </p>
      <span className="justify-self-end px-2 py-1 text-caption font-semibold" style={{ color: 'var(--tg-accent)' }} dir="ltr">
        OK
      </span>
    </div>
  )
}

/**
 * A button hanging under a message, as wide as it: a premium emoji at the start of its label drawn as its icon, where the
 * label's own emoji would sit — as the bot sends it (PlainWording::icon()) —, any other as its plain emoji.
 */
function InlineButton({ label }: { label: string }) {
  const lead = leadingPremium(label)
  const words = lead ? plainShown(label.slice(lead.tag.length)).replace(/^[ \t]+/, '') : ''
  const icon = lead && words !== '' ? lead : null

  return (
    <div className="me-auto grid w-64 max-w-[88%] grid-cols-[minmax(0,1fr)] gap-1">
      <div className="h-9 rounded-xl rounded-bl-sm" style={{ background: 'var(--tg-in)' }} aria-hidden />
      <span className="flex h-8 min-w-0 items-center justify-center overflow-hidden rounded-md px-2 text-caption font-medium" style={{ background: 'var(--tg-inline)' }} dir="rtl">
        <span className="truncate">
          {icon ? (
            <>
              <PremiumEmoji id={icon.id} fallback={icon.emoji} /> {words}
            </>
          ) : (
            plainShown(label)
          )}
        </span>
      </span>
    </div>
  )
}
