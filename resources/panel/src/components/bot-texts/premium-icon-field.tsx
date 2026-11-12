import { useState, type ReactNode } from 'react'
import { Sparkles, TriangleAlert, X } from 'lucide-react'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { PremiumEmojiPicker } from '@/components/bot-texts/premium-emoji-picker'
import { Button } from '@/components/ui/button'
import type { CustomEmojiRow } from '@/lib/api-types'
import { cn } from '@/lib/utils'

interface PremiumIconFieldProps {
  /** The picker's id, for the button that opens it. */
  id: string
  legend: string
  /** The premium emoji chosen — its id and the plain emoji it stands for —; null for none. */
  icon: { id: string; emoji: string } | null
  onPick: (emoji: CustomEmojiRow) => void
  onRemove: () => void
  /** What the chosen one does, and what one would, said beside it. */
  chosen: string
  none: string
  /** Under it: where it shows, and where not. */
  note: ReactNode
  /** What is wrong with it as it stands; null for nothing. */
  warning?: string | null
}

/**
 * One premium emoji chosen from the bot's list (PremiumEmojiPicker) — a bot text's button icon, a plan's or a category's
 * name's —: shown as Telegram draws it, changed or taken off.
 */
export function PremiumIconField({ id, legend, icon, onPick, onRemove, chosen, none, note, warning }: PremiumIconFieldProps) {
  const [picking, setPicking] = useState(false)

  return (
    <fieldset className="grid gap-2">
      <legend className="mb-1 text-body font-medium">{legend}</legend>
      <div className="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5">
        <span aria-hidden className={cn('grid size-9 shrink-0 place-items-center rounded-lg border', icon ? 'border-border bg-fill' : 'border-dashed border-border text-muted-foreground')}>
          {icon ? <PremiumEmoji id={icon.id} fallback={icon.emoji} className="size-6 text-lg leading-none" /> : <Sparkles className="size-4" />}
        </span>
        <span className="grid min-w-0 flex-1 gap-0.5">
          <span className="text-body font-medium">{icon ? 'ایموجی پرمیوم' : 'بدون ایموجی پرمیوم'}</span>
          <span className="text-footnote text-muted-foreground">{icon ? chosen : none}</span>
        </span>
        <span className="flex shrink-0 items-center gap-1">
          {icon && (
            <Button variant="ghost" size="sm" icon={X} onClick={onRemove}>
              برداشتن
            </Button>
          )}
          <Button variant={picking ? 'default' : 'secondary'} size="sm" aria-expanded={picking} aria-controls={picking ? id : undefined} onClick={() => setPicking((open) => !open)}>
            {icon ? 'تغییر' : 'انتخاب'}
          </Button>
        </span>
      </div>
      {picking && (
        <div id={id}>
          <PremiumEmojiPicker
            selected={icon?.id ?? null}
            onPick={(emoji) => {
              onPick(emoji)
              setPicking(false)
            }}
          />
        </div>
      )}
      {warning && (
        <p className="flex items-start gap-1.5 text-footnote text-warning">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
          <span>{warning}</span>
        </p>
      )}
      <p className="text-footnote leading-relaxed text-muted-foreground">{note}</p>
    </fieldset>
  )
}
