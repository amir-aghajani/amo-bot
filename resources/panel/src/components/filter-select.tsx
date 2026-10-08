import { useId, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { Select as SelectPrimitive } from 'radix-ui'
import { pillClasses } from '@/components/pill'
import { SelectContent, SelectItem, SelectSeparator } from '@/components/ui/select'
import { cn } from '@/lib/utils'

/** Radix keeps '' for "no value"; the filters' «all» travels under this name instead. */
const ALL = '__all__'

interface FilterOption<T extends string> {
  /** '' = not narrowed («همه»). */
  value: T
  label: ReactNode
}

interface FilterSelectProps<T extends string> {
  /** What the filter narrows by, in tertiary ink before the value: «وضعیت», «سرور». */
  label: string
  value: T
  options: FilterOption<T>[]
  onChange: (value: T) => void
  className?: string
}

/**
 * A filter pill's face, which opens its select's choices: «Label  Value ⌄» — named by both words («وضعیت همه»), so
 * assistive tech says what the list is narrowed to, as the eye reads it (a combobox takes no name from what it holds).
 */
export function FilterPillTrigger({ label, value, className }: { label: string; value: ReactNode; className?: string }) {
  const id = useId()

  return (
    <SelectPrimitive.Trigger aria-labelledby={`${id}-label ${id}-value`} className={cn(pillClasses, className)}>
      <span id={`${id}-label`} className="text-faint">
        {label}
      </span>
      <span id={`${id}-value`} className="min-w-0 flex-1 truncate text-start font-medium text-foreground">
        {value}
      </span>
      <ChevronDown className="size-3.5 shrink-0 text-faint" aria-hidden />
    </SelectPrimitive.Trigger>
  )
}

/**
 * A list's filter as the Console draws it: a hairline pill reading «Label  Value ⌄» that opens the choices under it.
 * The first option is usually «همه» (value ''), set apart from the rest by a rule.
 */
export function FilterSelect<T extends string>({ label, value, options, onChange, className }: FilterSelectProps<T>) {
  const current = options.find((option) => option.value === value) ?? options[0]

  return (
    <SelectPrimitive.Root value={value === '' ? ALL : value} onValueChange={(next) => onChange((next === ALL ? '' : next) as T)}>
      <FilterPillTrigger label={label} value={current?.label} className={className} />
      <SelectContent className="min-w-44">
        {options.map((option, index) => (
          <FilterItem key={option.value || ALL} option={option} separated={index === 1 && options[0]?.value === ''} />
        ))}
      </SelectContent>
    </SelectPrimitive.Root>
  )
}

function FilterItem<T extends string>({ option, separated }: { option: FilterOption<T>; separated: boolean }) {
  return (
    <>
      {separated && <SelectSeparator />}
      <SelectItem value={option.value === '' ? ALL : option.value}>{option.label}</SelectItem>
    </>
  )
}
