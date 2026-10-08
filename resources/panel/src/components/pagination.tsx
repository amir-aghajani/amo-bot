import { useId, useState, type KeyboardEvent } from 'react'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { digitsOnly, formatNumber } from '@/lib/format'

interface PaginationProps {
  page: number
  lastPage: number
  total: number
  /** One row, as the count names it: "کاربر" → "۱۲۳ کاربر". */
  unit: string
  onChange: (page: number) => void
  /** The next page is on its way: the buttons wait for it. */
  disabled?: boolean
}

/**
 * The foot of a paged table: the count on one side; on the other the first and previous pages, «صفحه [x] از y» — the
 * field takes a page's number (Persian digits too) and goes there on Enter or as it loses the focus, held to the list's
 * pages, and keeps the focus as the page turns —, the next and the last. A button with nowhere to go, or waiting for the
 * next page, keeps the focus it has (aria-disabled, not disabled): pressing «صفحه آخر» never drops the keyboard on the
 * document. On a phone every control is a finger's size (44px).
 */
export function Pagination({ page, lastPage, total, unit, onChange, disabled = false }: PaginationProps) {
  const go = (to: number) => {
    if (to !== page) onChange(Math.min(lastPage, Math.max(1, to)))
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 pt-1 text-footnote text-muted-foreground">
      <span className="tabular">
        {formatNumber(total)} {unit}
      </span>
      {lastPage > 1 && (
        <nav aria-label="صفحه‌بندی" className="flex items-center gap-1">
          {/* Right to left, the first page is on the right: the arrows point the way the pages run. */}
          <PageButton label="صفحه اول" icon={ChevronsRight} off={disabled || page <= 1} onPress={() => go(1)} />
          <PageButton label="صفحه قبل" icon={ChevronRight} off={disabled || page <= 1} onPress={() => go(page - 1)} />
          <PageJump page={page} lastPage={lastPage} onJump={go} />
          <PageButton label="صفحه بعد" icon={ChevronLeft} off={disabled || page >= lastPage} onPress={() => go(page + 1)} />
          <PageButton label="صفحه آخر" icon={ChevronsLeft} off={disabled || page >= lastPage} onPress={() => go(lastPage)} />
        </nav>
      )}
    </div>
  )
}

function PageButton({ label, icon: Icon, off, onPress }: { label: string; icon: LucideIcon; off: boolean; onPress: () => void }) {
  return (
    <Button variant="secondary" size="icon-sm" aria-label={label} aria-disabled={off} onClick={onPress} className="max-md:size-11">
      <Icon className="ltr:rotate-180" aria-hidden />
    </Button>
  )
}

/** «صفحه [x] از y»: the page on screen, and the field to type another one's number into. */
function PageJump({ page, lastPage, onJump }: { page: number; lastPage: number; onJump: (page: number) => void }) {
  // What the admin is typing; null while they are not, when the field shows the page on screen.
  const [draft, setDraft] = useState<string | null>(null)
  // A new page on screen starts the field afresh — the same field, so the focus stays in it as the page turns (state
  // adjusted during render).
  const [shown, setShown] = useState(page)
  if (shown !== page) {
    setShown(page)
    setDraft(null)
  }
  const of = useId()

  const jump = () => {
    if (draft === null) return
    setDraft(null)
    const wanted = Number(digitsOnly(draft))
    if (wanted >= 1) onJump(wanted)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') {
      event.preventDefault()
      jump()
    } else if (event.key === 'Escape' && draft !== null) {
      // Back to the page on screen — and not a dialog's dismissal, when the list is in one.
      event.preventDefault()
      setDraft(null)
    }
  }

  return (
    <span className="flex items-center gap-1.5 px-1">
      صفحه
      <Input
        value={draft ?? formatNumber(page)}
        onChange={(event) => setDraft(event.target.value)}
        onFocus={(event) => event.currentTarget.select()}
        onBlur={jump}
        onKeyDown={onKeyDown}
        inputMode="numeric"
        autoComplete="off"
        aria-label="برو به صفحه"
        aria-describedby={of}
        className="h-7 w-12 px-1 text-center text-footnote tabular max-md:h-11 max-md:w-14"
      />
      <span id={of} className="tabular">
        از {formatNumber(lastPage)}
      </span>
    </span>
  )
}
