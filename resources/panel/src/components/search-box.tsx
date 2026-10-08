import { Search } from 'lucide-react'
import { Input } from '@/components/ui/input'

interface SearchBoxProps {
  value: string
  onChange: (value: string) => void
  placeholder: string
  'aria-label': string
}

/**
 * The search field of a list page. `dir="auto"`: a Persian name reads right-to-left, a handle or a number
 * left-to-right — so the icon and its padding are physical (left), where an LTR query starts and an RTL one ends.
 */
export function SearchBox({ value, onChange, placeholder, 'aria-label': label }: SearchBoxProps) {
  return (
    <div className="relative w-full sm:w-72">
      <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-faint" aria-hidden />
      <Input type="search" dir="auto" value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} aria-label={label} className="pl-8" />
    </div>
  )
}
