import type { ComponentProps } from 'react'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { formatNumber } from '@/lib/format'

const HOURS = Array.from({ length: 24 }, (_, hour) => String(hour))

/** An hour of the day on a clock: «۰۸:۰۰». */
export const clockHour = (hour: string) => `${formatNumber(Number(hour)).padStart(2, '۰')}:۰۰`

type HourSelectProps = {
  /** The hour as text, '0' to '23'. */
  value: string
  onChange: (hour: string) => void
  disabled?: boolean
} & Pick<ComponentProps<typeof SelectTrigger>, 'id' | 'className' | 'aria-label' | 'aria-invalid' | 'aria-describedby'>

/** One of the day's 24 hours, «۰۰:۰۰» to «۲۳:۰۰» (a discount's window, the nightly draw). */
export function HourSelect({ value, onChange, disabled, ...trigger }: HourSelectProps) {
  return (
    <Select value={value} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger {...trigger}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {HOURS.map((hour) => (
          <SelectItem key={hour} value={hour}>
            {clockHour(hour)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
