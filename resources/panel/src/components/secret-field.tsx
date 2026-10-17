import type { ReactNode } from 'react'
import { Field } from '@/components/field'
import { SecretInput } from '@/components/secret-input'
import { Button } from '@/components/ui/button'
import type { SecretState } from '@/lib/api-types'

interface SecretFieldProps {
  id: string
  label: string
  stored: SecretState
  /** What the admin typed (empty = keep the stored value). */
  value: string
  onChange: (value: string) => void
  /** True once "پاک کردن" was pressed: the stored value will be removed on save. */
  clear: boolean
  onClear: (clear: boolean) => void
  error?: string
  hint?: ReactNode
  optional?: boolean
  /** False for a secret the form cannot go without: it is replaced, never emptied. */
  clearable?: boolean
  /**
   * False while the stored one cannot stay (a field it belongs with moved): the field asks a value — no stored hint,
   * nothing said of keeping it, never «اختیاری» — and may still be cleared.
   */
  stays?: boolean
  /** Beside the input (a button that makes a fresh value). */
  action?: ReactNode
}

/** What leaving a kept secret's field blank does, said under it. */
const KEEPS = 'خالی بماند، همان مقدار ذخیره‌شده می‌ماند.'

/**
 * A secret the server never sends back: shows a recognisable hint of the stored value as the placeholder — in the
 * placeholder's faint ink, so it never reads as a value typed —, says under the field that leaving it blank keeps it,
 * takes a replacement, and offers to clear it — unless the form cannot go without it (`clearable`). Sends nothing when
 * left untouched. A stored one that cannot stay (`stays`) is asked again as if none were kept, its clearing still offered.
 */
export function SecretField({ id, label, stored, value, onChange, clear, onClear, error, hint, optional, clearable = true, stays = true, action }: SecretFieldProps) {
  const leftBehind = stored.set && !stays
  const placeholder = clear ? 'با ذخیره حذف می‌شود' : stored.set && !leftBehind ? stored.hint : undefined
  const keeps = stored.set && !leftBehind && !clear
  const keepsId = `${id}-keeps`

  return (
    <Field id={id} label={label} optional={optional && !leftBehind} error={error} hint={hint}>
      {(control) => (
        <>
          <div className="flex items-start gap-2">
            <div className="min-w-0 flex-1">
              <SecretInput
                {...control}
                aria-describedby={[keeps ? keepsId : undefined, control['aria-describedby']].filter(Boolean).join(' ') || undefined}
                value={value}
                onChange={(e) => {
                  onChange(e.target.value)
                  if (clear) onClear(false)
                }}
                placeholder={placeholder}
                className={clear ? 'placeholder:text-danger' : undefined}
              />
            </div>
            {action}
          </div>
          {stored.set && (
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
              {keeps && (
                <p id={keepsId} className="text-caption text-muted-foreground">
                  {KEEPS}
                </p>
              )}
              {/* In the link's own blue, underlined: a press, never read as one more line of the hint. */}
              {clearable && value === '' && (
                <Button variant="link" className="w-fit text-caption underline decoration-link/40 hover:decoration-link" onClick={() => onClear(!clear)}>
                  {clear ? 'انصراف از حذف' : 'پاک کردن مقدار ذخیره‌شده'}
                </Button>
              )}
            </div>
          )}
        </>
      )}
    </Field>
  )
}
