import { useState, type ReactNode } from 'react'
import { useQuery, type QueryKey, type UseQueryOptions } from '@tanstack/react-query'
import { ArrowRight, ChevronLeft } from 'lucide-react'
import { ErrorState } from '@/components/error-state'
import { Creating } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { Modal, useHeld } from '@/components/modal'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { useConfirmDiscard } from '@/lib/use-unsaved-guard'
import { cn } from '@/lib/utils'

interface PickerCardProps {
  /** The square at the start: a two-letter mark, an icon. */
  mark: ReactNode
  title: ReactNode
  /** Badges and words beside the title. */
  meta?: ReactNode
  description: string
  /** Short bullet points under the description (requirements, caveats). */
  notes: string[]
  /** Set = listed so the admin knows it exists, but cannot be picked, and this says why («به‌زودی»). */
  disabledReason?: string
  onPick: () => void
  /** Sits under the card's button, above the pick layer (a docs link). */
  footer?: ReactNode
}

/**
 * One option of a picker (a connector, a payment driver). The card is a plain box: the pick button is
 * stretched over it with a pseudo-element (so a link can live inside without nesting interactive
 * content) and the footer sits above that layer.
 */
function PickerCard({ mark, title, meta, description, notes, disabledReason, onPick, footer }: PickerCardProps) {
  const available = disabledReason === undefined

  return (
    <div
      className={cn(
        'group relative grid gap-3 rounded-xl border border-border bg-card p-4 transition-colors duration-150',
        available ? 'hover:border-border-strong hover:bg-fill has-[button:focus-visible]:focus-ring' : 'opacity-55',
      )}
    >
      <button type="button" onClick={onPick} disabled={!available} className="grid gap-3 text-start outline-none after:absolute after:inset-0 after:rounded-xl disabled:cursor-not-allowed">
        <div className="flex items-start gap-3">
          <span aria-hidden className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-fill-hover text-footnote font-semibold text-foreground">
            {mark}
          </span>
          <div className="grid min-w-0 flex-1 gap-1">
            <div className="flex flex-wrap items-center gap-2 font-medium">
              {title}
              {meta}
              {disabledReason && <Badge variant="outline">{disabledReason}</Badge>}
            </div>
            <p className="text-footnote text-muted-foreground">{description}</p>
          </div>
          {available && <ChevronLeft className="mt-2.5 size-4 shrink-0 text-faint transition-colors group-hover:text-foreground ltr:rotate-180" aria-hidden />}
        </div>

        {notes.length > 0 && (
          <ul className="grid gap-1 border-t border-border pt-3 text-caption text-muted-foreground">
            {notes.map((note) => (
              <li key={note} className="flex gap-2">
                <span aria-hidden className="mt-[7px] size-1 shrink-0 rounded-full bg-faint" />
                {note}
              </li>
            ))}
          </ul>
        )}
      </button>

      {footer}
    </div>
  )
}

interface Pickable {
  key: string
  description: string
  notes: string[]
}

interface PickerModalProps<Option extends Pickable, Q extends QueryKey> {
  open: boolean
  onClose: () => void
  /** The picking step: its title and the question under it. */
  title: string
  question: string
  /** The options' read (lib/queries), asked once the dialog opens. */
  options: UseQueryOptions<Option[], Error, Option[], Q>
  /** What each option's card shows (its description and notes come from the option itself). */
  card: (option: Option) => Pick<PickerCardProps, 'mark' | 'title' | 'meta' | 'footer' | 'disabledReason'>
  /** The second step: the picked option's own form. */
  form: {
    title: (option: Option) => ReactNode
    description: (option: Option) => ReactNode
    size?: 'md' | 'lg'
    render: (option: Option) => ReactNode
  }
}

/**
 * Two steps in one dialog: pick an option from the API's list, then fill that option's form — a new one's (`Creating`):
 * nothing saved to go back to —; every opening starts at the picker.
 */
export function PickerModal<Option extends Pickable, Q extends QueryKey>({ open, onClose, title, question, options: query, card, form }: PickerModalProps<Option, Q>) {
  const [picked, setPicked] = useState<Option | null>(null)

  const options = useQuery({ ...query, enabled: open })

  // Back to the picker on every opening (state adjusted during render, the React way to follow a prop).
  const [wasOpen, setWasOpen] = useState(open)
  if (open !== wasOpen) {
    setWasOpen(open)
    if (open) setPicked(null)
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      size={picked ? (form.size ?? 'md') : 'md'}
      title={
        picked ? (
          <span className="flex items-center gap-1.5">
            <BackToPicker onBack={() => setPicked(null)} />
            {form.title(picked)}
          </span>
        ) : (
          title
        )
      }
      description={picked ? form.description(picked) : question}
    >
      {picked ? (
        <Creating value>{form.render(picked)}</Creating>
      ) : (
        <div className="grid gap-2.5">
          {options.isPending ? (
            <>
              <Skeleton className="h-28 w-full rounded-xl" />
              <Skeleton className="h-28 w-full rounded-xl" />
            </>
          ) : options.error ? (
            <ErrorState what="لیست" error={options.error} onRetry={() => void options.refetch()} retrying={options.isFetching} />
          ) : (
            options.data.map((option) => <PickerCard key={option.key} {...card(option)} description={option.description} notes={option.notes} onPick={() => setPicked(option)} />)
          )}
        </div>
      )}
    </Modal>
  )
}

/**
 * Back to the options from the form, which goes with it: as the dialog's ✕, it asks first while the form has unsaved
 * changes, and waits while the form's request runs.
 */
function BackToPicker({ onBack }: { onBack: () => void }) {
  const confirmDiscard = useConfirmDiscard()
  const held = useHeld()

  return (
    <IconButton onClick={() => confirmDiscard(onBack)} disabled={held} aria-label="بازگشت به انتخاب" className="-ms-1.5 size-7">
      <ArrowRight className="size-4 ltr:rotate-180" aria-hidden />
    </IconButton>
  )
}
