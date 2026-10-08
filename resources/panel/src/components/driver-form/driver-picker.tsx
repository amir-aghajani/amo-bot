import { Field } from '@/components/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { DriverDescription } from '@/lib/api-types'

interface DriverPickerProps {
  id: string
  label: string
  drivers: DriverDescription[]
  /** The driver picked — or `none`'s value. */
  value: string
  onChange: (key: string) => void
  error?: string
  /** No driver at all, offered first («خاموش»): its value and words, and what it means. */
  none?: { value: string; label: string; hint: string }
}

/**
 * Which driver a setting goes by (the database, the way email goes out): a list of the drivers by name — no driver at
 * all first, when that is a choice —, the one picked described under it with its notes. A single driver with nothing
 * else to pick is said, not offered.
 */
export function DriverPicker({ id, label, drivers, value, onChange, error, none }: DriverPickerProps) {
  const picked = drivers.find((driver) => driver.key === value)
  const about = picked === undefined ? none?.hint : [picked.description, ...picked.notes].join(' · ')
  const only = drivers[0]

  if (none === undefined && drivers.length === 1 && only !== undefined) {
    return (
      <p className="text-footnote text-muted-foreground">
        <bdi>{only.label}</bdi>
        {only.notes.length > 0 && ` · ${only.notes.join(' · ')}`}
      </p>
    )
  }

  return (
    <Field id={id} label={label} error={error} hint={about}>
      {(control) => (
        <Select value={value} onValueChange={onChange}>
          <SelectTrigger {...control} className="w-full sm:max-w-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {none && <SelectItem value={none.value}>{none.label}</SelectItem>}
            {drivers.map((driver) => (
              <SelectItem key={driver.key} value={driver.key}>
                <bdi>{driver.label}</bdi>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </Field>
  )
}
