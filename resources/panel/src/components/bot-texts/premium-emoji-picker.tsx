import { useMutation, useQueryClient } from '@tanstack/react-query'
import { TriangleAlert, X } from 'lucide-react'
import { useCustomEmojis } from '@/components/bot-texts/custom-emojis'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { CustomEmojiRow, CustomEmojisResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { cn } from '@/lib/utils'

/** A premium emoji as a text carries it: its id, and the plain emoji Telegram shows where the premium one cannot be. */
export function premiumEmojiTag(emoji: CustomEmojiRow): string {
  return `<tg-emoji emoji-id="${emoji.id}">${emoji.emoji}</tg-emoji>`
}

interface PremiumEmojiPickerProps {
  onPick: (emoji: CustomEmojiRow) => void
  /** The one chosen, when the picker is a choice (a keyboard button's icon): it is marked. Left out, a tap inserts. */
  selected?: string | null
}

/**
 * The premium emoji an admin showed the bot with /emoji, the latest seen first — the bot texts editor puts one at the
 * caret, the keyboards editor makes one a button's icon; the small cross takes one off the list (what uses it keeps
 * it). Warns when the bot's last /emoji found that Telegram takes premium emoji off its messages: the bot's owner needs
 * Telegram Premium for customers to see them.
 */
export function PremiumEmojiPicker({ onPick, selected }: PremiumEmojiPickerProps) {
  const queryClient = useQueryClient()
  const { data, error, refetch } = useCustomEmojis()
  const choosing = selected !== undefined

  const forget = useMutation({
    mutationFn: (emoji: CustomEmojiRow) => api.delete(`/bot/custom-emojis/${emoji.id}`),
    onSuccess: (_, emoji) =>
      queryClient.setQueryData<CustomEmojisResponse>(queryKeys.customEmojis, (current) => current && { ...current, emojis: current.emojis.filter((row) => row.id !== emoji.id) }),
  })

  return (
    <div className="grid gap-2.5 rounded-lg border border-border p-2.5">
      {data?.status?.ok === false && (
        <Callout tone="warning" icon={TriangleAlert}>
          آخرین بار تلگرام ایموجی‌های پرمیوم را از پیام ربات برداشت. ربات فقط وقتی ایموجی پرمیوم می‌فرستد که حسابی که آن را در <bdi dir="ltr">@BotFather</bdi> ساخته تلگرام پرمیوم داشته باشد؛ تا آن
          موقع مشتری‌ها جایشان ایموجی معمولی می‌بینند.
        </Callout>
      )}

      {!data ? (
        error ? (
          <ErrorState what="ایموجی‌های پرمیوم" error={error} onRetry={() => void refetch()} />
        ) : (
          <Skeleton className="h-9 w-full" />
        )
      ) : data.emojis.length === 0 ? (
        <p className="text-footnote text-muted-foreground">هنوز ایموجی پرمیومی ذخیره نشده است.</p>
      ) : (
        // A long list scrolls in place, so the form under it stays in reach; its padding is the room the crosses take.
        <ul className="flex max-h-48 flex-wrap gap-1 overflow-y-auto p-2" aria-label="ایموجی‌های پرمیوم">
          {data.emojis.map((emoji) => {
            const chosen = choosing && emoji.id === selected
            return (
              <li key={emoji.id} className="group relative">
                <button
                  type="button"
                  onClick={() => onPick(emoji)}
                  title={emoji.emoji}
                  aria-label={choosing ? `ایموجی پرمیوم ${emoji.emoji}` : `افزودن ایموجی پرمیوم ${emoji.emoji}`}
                  aria-pressed={choosing ? chosen : undefined}
                  className={cn(
                    'grid size-9 place-items-center rounded-lg border transition-colors duration-150 outline-none focus-visible:focus-ring',
                    chosen ? 'border-selected bg-info-soft/40' : 'border-transparent hover:border-border hover:bg-fill',
                  )}
                >
                  <PremiumEmoji id={emoji.id} fallback={emoji.emoji} className="size-6 text-lg leading-none" />
                </button>
                {/* A 24px target — what a finger needs — around the small cross drawn on the emoji's corner. */}
                <button
                  type="button"
                  onClick={() => forget.mutate(emoji)}
                  disabled={forget.isPending}
                  aria-label={`برداشتن ${emoji.emoji} از لیست`}
                  className="group/remove absolute -end-2 -top-2 hidden size-6 place-items-center rounded-full outline-none group-focus-within:grid group-hover:grid focus-visible:focus-ring"
                >
                  <span className="grid size-4 place-items-center rounded-full border border-border bg-background text-muted-foreground transition-colors group-hover/remove:text-danger">
                    <X className="size-2.5" aria-hidden />
                  </span>
                </button>
              </li>
            )
          })}
        </ul>
      )}

      <p className="text-caption leading-relaxed text-muted-foreground">
        برای ایموجی تازه، در ربات <bdi dir="ltr">/emoji</bdi> را بفرستید و بعد پیامی با ایموجی‌های پرمیوم — همان‌طور که می‌خواهید مشتری ببیند. ایموجی‌ها همین‌جا اضافه می‌شوند و ربات همان پیام را به
        صورت متن آماده هم برمی‌گرداند. (فقط مدیرهای ربات)
      </p>
    </div>
  )
}
