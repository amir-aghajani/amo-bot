import { isValidElement } from 'react'
import { matchRoutes, type RouteObject } from 'react-router'

/*
 * Pages are their own chunks (lib/lazy-page). The page an address opens is asked for before its turn: the panel's
 * first page as the panel starts — beside its first questions to the server, not after them —, the page a link leads
 * to as soon as the link is about to be followed, and the page the sign-in leads to while its form waits. A page loaded
 * by its turn is drawn at once.
 */

/** How long the pointer rests on a link before its page is asked for — passing over the sidebar on the way elsewhere asks for nothing. */
const HOVER_MS = 50

/** The panel's routes, as it started. */
let panelRoutes: RouteObject[] | null = null

/** A route's page, when it is one of its own chunk: the route's element is a lazy page, which can load itself. */
function preloaderOf(route: RouteObject): (() => void) | undefined {
  const type: unknown = isValidElement(route.element) ? route.element.type : undefined
  return typeof type === 'function' && 'preload' in type && typeof type.preload === 'function' ? (type.preload as () => void) : undefined
}

/** Starts loading the pages an address draws (`basename`: the folder it is under, '/' for a router address). */
function preloadAddress(address: string, basename: string): void {
  if (!panelRoutes) return
  for (const { route } of matchRoutes(panelRoutes, address, basename) ?? []) preloaderOf(route)?.()
}

/** Starts loading the page a router address opens ("/", "/users?status=banned") — where the sign-in leads, as its form waits. */
export function preloadPage(to: string): void {
  preloadAddress(to, '/')
}

/** The panel's own link an event happened on. */
function linkOf(target: EventTarget | null): HTMLAnchorElement | null {
  const link = target instanceof Element ? target.closest('a[href]') : null
  return link instanceof HTMLAnchorElement && link.origin === window.location.origin ? link : null
}

/**
 * Loads the page the panel opens on, now, and from then on the page a link leads to on a sign it is about to be
 * followed: the pointer resting on it, the keyboard's focus reaching it, a finger or a button pressing it. Returns the
 * way to stop.
 */
export function startPrefetching(routes: RouteObject[], basename: string): () => void {
  panelRoutes = routes
  preloadAddress(window.location.pathname, basename)

  let resting: number | undefined
  const intent = (link: HTMLAnchorElement | null) => {
    if (link) preloadAddress(link.pathname, basename)
  }
  const onPress = (event: Event) => intent(linkOf(event.target))
  const onOver = (event: Event) => {
    const link = linkOf(event.target)
    window.clearTimeout(resting)
    if (link) resting = window.setTimeout(() => intent(link), HOVER_MS)
  }
  const onOut = () => window.clearTimeout(resting)

  // A press is a pointer's — a finger's touch too — or the keyboard's focus; a pointer resting on a link is a hand on its way to it.
  const listeners: [string, (event: Event) => void][] = [
    ['pointerover', onOver],
    ['pointerout', onOut],
    ['pointerdown', onPress],
    ['focusin', onPress],
  ]
  for (const [type, listener] of listeners) document.addEventListener(type, listener, { passive: true })

  return () => {
    window.clearTimeout(resting)
    for (const [type, listener] of listeners) document.removeEventListener(type, listener)
    panelRoutes = null
  }
}
