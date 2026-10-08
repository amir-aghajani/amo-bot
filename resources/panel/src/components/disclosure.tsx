import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { FOLD, INVALID_FIELD } from '@/components/form-footer'
import { cn } from '@/lib/utils'

/** A change that marks a field of the fold invalid: its mark set, or a field so marked put in it. */
function marksInvalid(record: MutationRecord): boolean {
  if (record.type === 'attributes') return record.target instanceof Element && record.target.matches(INVALID_FIELD)
  return [...record.addedNodes].some((node) => node instanceof Element && (node.matches(INVALID_FIELD) || node.querySelector(INVALID_FIELD) !== null))
}

/**
 * A "show more" toggle for the rarely-needed part of a form (advanced options). Folded, the part stays in the page,
 * hidden — what was typed there survives the fold, and the toggle always has the region it controls. A field of it the
 * form marks invalid — a refusal, whatever the form — opens it, and the form's footer gives that field the focus.
 */
export function Disclosure({ label, defaultOpen = false, children }: { label: string; defaultOpen?: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(defaultOpen)
  const id = useId()
  const region = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const node = region.current
    if (node === null) return
    const observer = new MutationObserver((records) => {
      if (records.some(marksInvalid)) setOpen(true)
    })
    observer.observe(node, { subtree: true, childList: true, attributeFilter: ['aria-invalid', 'data-invalid'] })
    return () => observer.disconnect()
  }, [])

  return (
    <div className="grid gap-4">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-controls={id}
        className="-ms-1 flex h-7 w-fit items-center gap-1.5 rounded-md px-1 text-body text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:focus-ring"
      >
        <ChevronDown className={cn('size-4 transition-transform duration-150', open && 'rotate-180')} aria-hidden />
        {label}
      </button>
      <div ref={region} id={id} hidden={!open} {...{ [FOLD]: '' }} className="grid gap-4">
        {children}
      </div>
    </div>
  )
}
