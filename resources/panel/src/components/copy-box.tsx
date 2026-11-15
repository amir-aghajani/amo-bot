import { useState, type ReactNode } from 'react'
import { Copy, Eye, EyeOff } from 'lucide-react'
import { IconButton } from '@/components/icon-button'
import { Button } from '@/components/ui/button'
import { copyText } from '@/lib/clipboard'
import { cn } from '@/lib/utils'

/** A key shown hidden: its last four characters after dots, until «نمایش» is pressed. */
function hidden(value: string): string {
  return `${'•'.repeat(12)}${value.slice(-4)}`
}

interface CopyBoxProps {
  label: string
  /** What its buttons call the value («کپی …», «نمایش …»), where the label is a sentence; the label itself by default. */
  name?: string
  value: string
  /** The toast once copied. */
  copied: string
  /** A key: hidden until asked, copied whole either way. */
  secret?: boolean
  /** Beside the box (a key's «ساخت کلید تازه»). */
  action?: ReactNode
  /** A line under it: what the value is for. */
  hint?: string
  /** Its copy button pressed. */
  onCopy?: () => void
  className?: string
}

/** A value to paste somewhere else (or to keep for reference), left to right, with its copy button named for it. */
export function CopyBox({ label, name = label, value, copied, secret = false, action, hint, onCopy, className }: CopyBoxProps) {
  const [shown, setShown] = useState(!secret)

  return (
    <div className={cn('grid gap-1.5', className)}>
      <span className="text-footnote text-muted-foreground">{label}</span>
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex min-w-0 flex-1 basis-64 items-center gap-1 rounded-lg border border-border bg-fill p-1 ps-3">
          <code dir="ltr" className="min-w-0 flex-1 text-start text-footnote break-all select-all">
            {shown ? value : hidden(value)}
          </code>
          {secret && <IconButton aria-label={`نمایش ${name}`} aria-pressed={shown} title={shown ? 'پنهان کردن' : 'نمایش'} icon={shown ? EyeOff : Eye} onClick={() => setShown((was) => !was)} />}
          <Button
            size="sm"
            variant="secondary"
            icon={Copy}
            aria-label={`کپی ${name}`}
            onClick={() => {
              onCopy?.()
              void copyText(value, copied)
            }}
          >
            کپی
          </Button>
        </div>
        {action}
      </div>
      {hint && <span className="text-footnote text-muted-foreground">{hint}</span>}
    </div>
  )
}
