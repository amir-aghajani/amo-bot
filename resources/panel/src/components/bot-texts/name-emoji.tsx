import { leadingEmoji, withLeadingEmoji } from '@/components/bot-texts/button-icon'
import { PremiumIconField } from '@/components/bot-texts/premium-icon-field'

/** The server's word for a premium emoji with no emoji at the name's start to stand for (PremiumText::NO_EMOJI). */
const NO_EMOJI = 'ایموجی پرمیوم جای ایموجی اول نام می‌نشیند؛ نام را با یک ایموجی شروع کنید، یا ایموجی پرمیوم را بردارید.'

interface NameEmojiProps {
  /** The picker's id. */
  id: string
  name: string
  emojiId: string | null
  /** The server's refusal of `emoji_id`. */
  error?: string
  onChange: (changes: { name?: string; emoji_id: string | null }) => void
}

/**
 * A name's premium emoji — a plan's, a category's, a server's —: picked, its plain emoji becomes the name's leading one —
 * what the mini app, the website and the panels show — and the bot draws it premium there (PremiumText): on its buttons as
 * their icon, in its texts in its place. Taken off, the name keeps its plain emoji.
 */
export function NameEmoji({ id, name, emojiId, error, onChange }: NameEmojiProps) {
  const leading = leadingEmoji(name.trimStart())
  // Drawn by its picture; the name's leading emoji is what it stands for (none while the warning below says so).
  const icon = emojiId === null ? null : { id: emojiId, emoji: leading ?? '✨' }

  return (
    <PremiumIconField
      id={id}
      legend="ایموجی پرمیوم نام"
      icon={icon}
      onPick={(emoji) => onChange({ name: withLeadingEmoji(name.trimStart(), emoji.emoji), emoji_id: emoji.id })}
      onRemove={() => onChange({ emoji_id: null })}
      chosen="ربات آن را جای ایموجی اول نام نشان می‌دهد؛ روی دکمه به شکل آیکون."
      none="اختیاری: ایموجی اول نام، در ربات به شکل پرمیوم."
      warning={emojiId !== null && leading === null ? NO_EMOJI : (error ?? null)}
      note={
        <>
          فقط در ربات، و فقط وقتی دیده می‌شود که حسابی که ربات را در <bdi dir="ltr">@BotFather</bdi> ساخته تلگرام پرمیوم داشته باشد؛ مینی‌اپ، وب‌سایت و جاهای دیگر همان ایموجی معمولی اول نام را نشان
          می‌دهند.
        </>
      }
    />
  )
}
