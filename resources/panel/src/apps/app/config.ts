/*
 * Which shop this mini app is and where its API lives — read once, off the page's address. A shop's mini app is
 * app/{its store key}/… under the shop's address (wherever the shop is installed: a sub-folder too), and its Store API
 * sits beside the app: the key names the shop to it. The page's <base> is the app's folder (index.html sees to it).
 */

/** The app's folder and the store key after it: 24 lower-case hex characters (Core\Http\Urls::STORE). */
const ADDRESS = /^(.*\/app)\/([0-9a-f]{24})(?:\/|$)/

/**
 * The page's address with one leading slash, as index.html's base reads it: "//app/…" (a link with a slash too many)
 * would make the API's address and the router's another host's. The page is put at it too, so the router finds its
 * screens under its basename and never pushes an address of another host.
 */
const pathname = window.location.pathname.replace(/^\/+/, '/')
if (pathname !== window.location.pathname) window.history.replaceState(window.history.state, '', `${pathname}${window.location.search}${window.location.hash}`)

const match = ADDRESS.exec(pathname)
/** The app's folder ("/app", "/shop/app"); read off the page's base when the address names no key. */
const appPath = match?.[1] ?? new URL(document.baseURI).pathname.replace(/\/$/, '')

/** The shop's store key from the address; null for an address that names none (the app opened by hand). */
export const storeKey: string | null = match?.[2] ?? null

/** The Store API under the shop's key: …/api/store/v1/{key}. */
export const storeBase = `${appPath.replace(/\/app$/, '')}/api/store/v1/${storeKey ?? ''}`

/** The app's folder, where its other pages are (open.html: the bridge to a VPN app). */
export const appFolder = appPath

/** The router's basename: the app's folder and the shop's key. */
export const routerBasename = storeKey === null ? appPath : `${appPath}/${storeKey}`

/** When `vite build` (or `pnpm dev`) made the files this page runs, in UTC — written in by vite.config.ts. */
declare const __PANEL_BUILD__: string

/** Which build this page runs. */
export const appBuild = __PANEL_BUILD__
