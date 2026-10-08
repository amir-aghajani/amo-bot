/**
 * Which panel this page is, which shop it shows and where its API lives, read off the page itself, once: each panel's
 * index.html sets the page's base to its own folder — …/admin/ (the owner's) or …/agent/ (an agent's), wherever the
 * shop is installed — and a panel's API is beside it, under its own name (…/api/admin, …/api/agent). The owner's tab
 * keeps the shop it shows in its address — …/admin/… the main bot's, …/admin/s/7/… an agent's —, and names it on every
 * request (lib/api): a reload, a bookmark, a link opened in a new tab are each in the shop they name, whatever another
 * tab opened, and opening another shop loads the panel afresh at its address. An agent's panel names none: its shop is
 * the agent's bot.
 */

/** The main bot's id: its shop is the owner's own. */
export const MAIN_SHOP = 1

const panelPath = new URL(document.baseURI).pathname.replace(/\/$/, '')
// The page's base always ends in its panel's folder (its own inline script sees to it).
const [, sitePath = '', panel = 'admin'] = /^(.*)\/(admin|agent)$/.exec(panelPath) ?? []

/** The address after the panel's folder, found as the page's inline script finds the folder: «/s/7/payments». */
const inPanel = window.location.pathname.replace(new RegExp(`^.*/${panel}(?=/|$)`), '')
/** An agent's shop the owner's address names (`/s/7`); none on an agent's panel. */
const named = panel === 'admin' ? /^\/s\/([1-9]\d{0,8})(?=\/|$)/.exec(inPanel) : null
const shop = panel === 'admin' ? Number(named?.[1] ?? MAIN_SHOP) : null

// The main shop's address is its bare one: …/admin/s/1/… is …/admin/….
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
  /** The shop the owner's tab shows — every request of its names it; null on an agent's panel. */
  shop,
}

/** Router basename: the panel's folder — and, the owner's in an agent's shop, that shop's place in it (…/admin/s/7). */
export const routerBasename = shop === null || shop === MAIN_SHOP ? panelPath : `${panelPath}/s/${shop}`

/** Where a shop of the owner's panel opens: its dashboard's address — the main bot's at the panel's own. */
export function shopHome(id: number): string {
  return `${panelPath}${id === MAIN_SHOP ? '' : `/s/${id}`}/`
}

/** The moment `vite build` (or `pnpm dev`) made the files this page runs, UTC — vite.config.ts writes it in. */
declare const __PANEL_BUILD__: string

/** Which build of the panel this page is — a failure's details and its report name it: an old tab says so. */
export const panelBuild = __PANEL_BUILD__

/** An address as the router reads it: a link's pathname without the panel's folder ("/shop/admin/users" → "/users"). */
export function routePath(pathname: string): string {
  return pathname.startsWith(routerBasename) ? pathname.slice(routerBasename.length) || '/' : pathname
}

/**
 * The page a router address belongs to — its first segment (/bot-settings/general → /bot-settings): a page's sections
 * are one page, which the error boundary, the unsaved-changes guard and the sidebar all go by. One subject's own page —
 * a row of the page's list by its number, /users/12, /servers/4 — is a page of its own: nothing of the list stays
 * mounted beside it, nor of one subject beside another.
 */
export function pageOf(path: string): string {
  const [, page = '', subject = ''] = path.split('/')

  return /^\d+$/.test(subject) ? `/${page}/${subject}` : `/${page}`
}
