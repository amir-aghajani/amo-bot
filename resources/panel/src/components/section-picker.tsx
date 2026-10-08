import type { ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { FilterSelect } from '@/components/filter-select'
import type { NavSection } from '@/components/shell/nav'

/**
 * A sectioned page's sections on a phone, where the sidebar that lists them is behind the menu: a «بخش» pick under the
 * page's header (hidden from md up). `labels` words a section its own way — a count beside its name.
 */
export function SectionPicker<T extends string>({ sections, current, labels }: { sections: NavSection<T>[]; current: NavSection<T>; labels?: Partial<Record<T, ReactNode>> }) {
  const navigate = useNavigate()

  if (sections.length < 2) return null

  return (
    <div className="md:hidden">
      <FilterSelect
        label="بخش"
        value={current.value}
        options={sections.map((section) => ({ value: section.value, label: labels?.[section.value] ?? section.title }))}
        onChange={(value) => {
          const section = sections.find((item) => item.value === value)
          if (section) navigate(section.to)
        }}
        className="w-full"
      />
    </div>
  )
}
