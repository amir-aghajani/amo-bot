import { createContext, useContext, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Navigate } from 'react-router'
import { Page, type PageWidth } from '@/components/page'
import { PageHeader, type PageHeaderProps } from '@/components/page-header'
import { SectionPicker } from '@/components/section-picker'
import { useSection, type NavSection } from '@/components/shell/nav'

/** The header's place for a section's actions, and whether that section is the one on screen (outside a sectioned page, all is). */
const SectionContext = createContext<{ slot: HTMLDivElement | null; shown: boolean }>({ slot: null, shown: true })

interface SectionedPageProps<T extends string> {
  /** The page's sections, its first one at the page's own address (components/shell/nav). */
  sections: NavSection<T>[]
  width?: PageWidth
  /** The header — the page's own, or the shown section's words; a section's own actions come from inside it (SectionActions). */
  header: (current: NavSection<T>) => Omit<PageHeaderProps, 'actionsRef'>
  /** A section's name in the phone's picker, its own way (with a count beside it). */
  labels?: Partial<Record<T, ReactNode>>
  /** What sits under the header on every section — or, given the section, on some (the program's notices, its numbers). */
  above?: (current: NavSection<T>) => ReactNode
  /** Each section's content: every one mounted and the shown one visible, so searches, pages and drafts survive a switch. */
  children: Record<T, ReactNode>
}

/**
 * A page made of sections, which the sidebar lists in a column of the page's own (by decision, instead of tabs): the
 * section the address names is on screen — any other address under the page goes to the first —, its header on top,
 * the phone's section picker under it.
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

/**
 * A section's own actions, drawn in the page's header while the section is on screen («افزودن گروه») — so the section
 * keeps what they open (its editor) to itself.
 */
export function SectionActions({ children }: { children: ReactNode }) {
  const { slot, shown } = useContext(SectionContext)

  return shown && slot ? createPortal(children, slot) : null
}

/**
 * Whether what reads this is on screen: false in a section kept mounted and hidden. A list there stops following the
 * shop (its read unsubscribed — the live updates pass it by) and reads afresh once its section is shown again.
 */
export function useSectionShown(): boolean {
  return useContext(SectionContext).shown
}
