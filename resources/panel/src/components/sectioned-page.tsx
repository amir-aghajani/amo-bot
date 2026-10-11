import { createContext, useContext, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Navigate, useNavigate } from 'react-router'
import { FilterSelect } from '@/components/filter-select'
import { Page, type PageWidth } from '@/components/page'
import { PageHeader, type PageHeaderProps } from '@/components/page-header'
import { useSection, type NavSection } from '@/components/shell/nav'

/** The header's slot for a section's actions, and whether that section is the one on screen (outside a sectioned page, always). */
const SectionContext = createContext<{ slot: HTMLDivElement | null; shown: boolean }>({ slot: null, shown: true })

interface SectionedPageProps<T extends string> {
  /** The page's sections, the first at the page's own address (components/shell/nav). */
  sections: NavSection<T>[]
  width?: PageWidth
  /** The header for the shown section; a section's own actions come from inside it (SectionActions). */
  header: (current: NavSection<T>) => Omit<PageHeaderProps, 'actionsRef'>
  /** A section's label in the phone's picker, when it differs from its title (a count beside it). */
  labels?: Partial<Record<T, ReactNode>>
  /** What sits under the header — on every section, or on some, given the section (a program's notices). */
  above?: (current: NavSection<T>) => ReactNode
  /** Each section's content: all mounted, only the shown one visible, so searches, pages and drafts survive a switch. */
  children: Record<T, ReactNode>
}

/**
 * A page made of sections, which the sidebar lists in a column of the page's own (deliberately, instead of tabs): the
 * section the address names is on screen (any other address under the page goes to the first), with the header on top
 * and, on a phone, a section picker under it.
 */
export function SectionedPage<T extends string>({ sections, width, header, labels, above, children }: SectionedPageProps<T>) {
  const current = useSection(sections)
  const [slot, setSlot] = useState<HTMLDivElement | null>(null)
  const first = sections[0]

  if (!current) return first ? <Navigate to={first.to} replace /> : null

  return (
    <Page width={width}>
      <PageHeader {...header(current)} actionsRef={setSlot} />
      <SectionPicker sections={sections} current={current} labels={labels} />
      {above?.(current)}
      {sections.map((section) => (
        <SectionContext.Provider key={section.value} value={{ slot, shown: section.value === current.value }}>
          <section aria-label={section.title} hidden={section.value !== current.value} className="grid gap-4">
            {children[section.value]}
          </section>
        </SectionContext.Provider>
      ))}
    </Page>
  )
}

/** The sections on a phone, where the sidebar that lists them is behind the menu: a «بخش» pick (hidden from md up). */
function SectionPicker<T extends string>({ sections, current, labels }: { sections: NavSection<T>[]; current: NavSection<T>; labels?: Partial<Record<T, ReactNode>> }) {
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

/** A section's own actions («افزودن گروه»), drawn in the page's header while the section is on screen. */
export function SectionActions({ children }: { children: ReactNode }) {
  const { slot, shown } = useContext(SectionContext)

  return shown && slot ? createPortal(children, slot) : null
}

/**
 * Whether the caller is on screen: false in a section kept mounted but hidden. A list there stops following live
 * updates and reads afresh once its section is shown again.
 */
export function useSectionShown(): boolean {
  return useContext(SectionContext).shown
}
