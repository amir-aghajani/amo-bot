import { matchRoutes, type RouteObject } from 'react-router'
import { isGroup, type PanelNav } from '@/components/shell/nav'

/* A panel's menu against its route table: what the menu leads to, and the page a table draws at an address. */

/** Every address a panel's menu leads to: its pages, their sections, and the settings' link and sections. */
export function navAddresses(nav: PanelNav): string[] {
  const pages = nav.menu.flatMap((entry) => (isGroup(entry) ? entry.items : [entry])).map((item) => item.to)
  const sections = Object.values(nav.sections).flatMap((list) => (list ?? []).map((section) => section.to))
  const settings = nav.settings.groups.flatMap((group) => group.sections.map((section) => section.to))

  return [...pages, ...sections, nav.settings.item.to, ...settings]
}

/** The route a table draws at `address` — its path (`*` is the not-found page; undefined an index route), or null for none. */
export function pageAt(routes: RouteObject[], address: string): string | undefined | null {
  const matches = matchRoutes(routes, address)
  return matches === null ? null : matches[matches.length - 1]?.route.path
}
