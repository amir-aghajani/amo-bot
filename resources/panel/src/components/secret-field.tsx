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
  /** Beside the input (a button that makes a fresh value). */
  action?: ReactNode
}

/**
 * A secret the server never sends back: shows a recognisable hint of the stored value as the
 * placeholder, takes a replacement, and offers to clear it. Sends nothing when left untouched.
 */
export function SecretField({ id, label, stored, value, onChange, clear, onClear, error, hint, optional, action }: SecretFieldProps) {
  const placeholder = clear ? 'با ذخیره حذف می‌شود' : stored.set ? stored.hint : undefined

  return (
    <Field id={id} label={label} optional={optional} error={error} hint={hint}>
      {(control) => (
        <>
          <div className="flex items-start gap-2">
            <div className="min-w-0 flex-1">
              <SecretInput
                {...control}
                value={value}
                onChange={(e) => {
                  onChange(e.target.value)
                  if (clear) onClear(false)
                }}
                placeholder={placeholder}
                className={clear ? 'placeholder:text-danger' : stored.set ? 'placeholder:text-foreground/60' : undefined}
              />
            </div>
            {action}
          </div>
          {stored.set && value === '' && (
            <Button variant="link" className="w-fit text-caption text-muted-foreground hover:text-foreground" onClick={() => onClear(!clear)}>
              {clear ? 'انصراف از حذف' : 'پاک کردن مقدار ذخیره‌شده'}
            </Button>
          )}
        </>
      )}
    </Field>
  )
}
