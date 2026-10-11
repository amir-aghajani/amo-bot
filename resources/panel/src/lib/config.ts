/*
 * Which panel this page is, which shop it shows and where its API lives — read once, off the page itself. Each panel's
 * index.html sets the page's <base> to its own folder (…/admin/ or …/agent/, wherever the shop is installed), and the
 * panel's API sits beside it (…/api/admin, …/api/agent).
 *
 * The owner's tab keeps the shop it shows in its address — …/admin/… is the main bot's, …/admin/s/7/… agent bot 7's —
 * and names it on every request (lib/api). So a reload, a bookmark or a new tab opens the shop its address names,
 * whatever another tab shows; opening another shop loads the panel afresh at that address. An agent's panel names none:
 * its shop is the agent's bot.
 */

/** The main bot's id: its shop is the owner's own. */
export const MAIN_SHOP = 1

const panelPath = new URL(document.baseURI).pathname.replace(/\/$/, '')
// The page's base always ends in its panel's folder (its inline script sees to it).
const [, sitePath = '', panel = 'admin'] = /^(.*)\/(admin|agent)$/.exec(panelPath) ?? []

/** The address after the panel's folder, found as the inline script finds the folder: «/s/7/payments». */
const inPanel = window.location.pathname.replace(new RegExp(`^.*/${panel}(?=/|$)`), '')
/** The agent shop the owner's address names (`/s/7`); none on an agent's panel. */
const named = panel === 'admin' ? /^\/s\/([1-9]\d{0,8})(?=\/|$)/.exec(inPanel) : null
const shop = panel === 'admin' ? Number(named?.[1] ?? MAIN_SHOP) : null

// The main shop's address is the bare one: …/admin/s/1/… becomes …/admin/….
if (named && shop === MAIN_SHOP) {
  window.history.replaceState(window.history.state, '', `${panelPath}${inPanel.slice(named[0].length) || '/'}${window.location.search}${window.location.hash}`)
}

export const appConfig = {
  /** The site's own prefix ("/shop" in a sub-folder install, "" at the root). */
  basePath: sitePath,
  /** This panel's API (session-authenticated). */
  apiBase: `${sitePath}/api/${panel}`,
  /** The API's root: /api/app, and the web installer's /api/install. */
  apiRoot: `${sitePath}/api`,
  /** The shop the owner's tab shows (every request names it); null on an agent's panel. */
  shop,
}

/** The router's basename: the panel's folder, plus `/s/<id>` when the owner's tab shows an agent's shop. */
export const routerBasename = shop === null || shop === MAIN_SHOP ? panelPath : `${panelPath}/s/${shop}`

/** A shop's dashboard address in the owner's panel (the main shop's is the panel's own). */
export function shopHome(id: number): string {
  return `${panelPath}${id === MAIN_SHOP ? '' : `/s/${id}`}/`
}

/** When `vite build` (or `pnpm dev`) made the files this page runs, in UTC — written in by vite.config.ts. */
declare const __PANEL_BUILD__: string

/** Which build this page runs: a failure's details and its report name it, so a tab left open across an upgrade shows. */
export const panelBuild = __PANEL_BUILD__

/** An address as the router reads it: a link's pathname without the panel's folder ("/shop/admin/users" → "/users"). */
export function routePath(pathname: string): string {
  return pathname.startsWith(routerBasename) ? pathname.slice(routerBasename.length) || '/' : pathname
}

/**
 * The page an address belongs to: its first segment (/bot-settings/general → /bot-settings), since a page's sections
 * are one page — or, for one row's own page (/users/12, /servers/4), the first two. The error boundary, the
 * unsaved-changes guard and the sidebar all go by it.
 */
export function pageOf(path: string): string {
  const [, page = '', subject = ''] = path.split('/')

  return /^\d+$/.test(subject) ? `/${page}/${subject}` : `/${page}`
}
