import { fullDay } from '@/components/date-range-picker'
import { dayOf, type WindowEnd } from '@/components/discounts/window'
import { Field } from '@/components/field'
import { clockHour, HourSelect } from '@/components/hour-select'
import { Input } from '@/components/ui/input'

interface WindowFieldProps {
  id: string
  label: string
  value: WindowEnd
  onChange: (value: WindowEnd) => void
  error?: string
  /** What a blank day means on this side: «از همین حالا», «بدون پایان». */
  open: string
}

/** One end of a discount's window: a typed Jalali day («1405/07/20») and an hour, read back in words under them. */
export function WindowField({ id, label, value, onChange, error, open }: WindowFieldProps) {
  const day = value.day.trim() === '' ? null : dayOf(value.day)
  const hint = value.day.trim() === '' ? open : day === null ? 'روز را به شکل 1405/07/20 بنویسید.' : `${fullDay(day)}، ساعت ${clockHour(value.hour)}`

  return (
    <Field id={id} label={label} error={error} hint={hint}>
      {(control) => (
        <div className="flex gap-2">
          <Input
            {...control}
            dir="ltr"
            inputMode="numeric"
            autoComplete="off"
            placeholder="1405/07/20"
            value={value.day}
            onChange={(e) => onChange({ ...value, day: e.target.value })}
            className="min-w-0 flex-1"
          />
          <HourSelect value={value.hour} onChange={(hour) => onChange({ ...value, hour })} className="w-24" aria-label={`ساعت ${label}`} />
        </div>
      )}
    </Field>
  )
}
