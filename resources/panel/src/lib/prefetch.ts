import { isValidElement } from 'react'
import { matchRoutes, type RouteObject } from 'react-router'

/*
 * Loading pages (lib/lazy-page) before they are drawn: the first page as the panel starts — alongside its first API
 * reads, not after them —, a link's page as soon as the link is about to be followed, and the page a sign-in leads to
 * while its form waits.
 */

/** How long the pointer rests on a link before its page loads: passing over the sidebar loads nothing. */
const HOVER_MS = 50

/** The panel's routes, as it started. */
let panelRoutes: RouteObject[] | null = null

/** The preload of a route whose element is a lazy page (lib/lazy-page); undefined for any other route. */
function preloaderOf(route: RouteObject): (() => void) | undefined {
  const type: unknown = isValidElement(route.element) ? route.element.type : undefined
  return typeof type === 'function' && 'preload' in type && typeof type.preload === 'function' ? (type.preload as () => void) : undefined
}

/** Starts loading the pages an address draws (`basename`: the folder it is under, '/' for a router address). */
function preloadAddress(address: string, basename: string): void {
  if (!panelRoutes) return
  for (const { route } of matchRoutes(panelRoutes, address, basename) ?? []) preloaderOf(route)?.()
}

/** Start loading the page a router address opens ("/", "/users?status=banned"): where a sign-in leads, while its form waits. */
export function preloadPage(to: string): void {
  preloadAddress(to, '/')
}

/** The panel's own link an event happened on. */
function linkOf(target: EventTarget | null): HTMLAnchorElement | null {
  const link = target instanceof Element ? target.closest('a[href]') : null
  return link instanceof HTMLAnchorElement && link.origin === window.location.origin ? link : null
}

/**
 * Load the page the panel opens on now, and from then on a link's page as soon as it is about to be followed: the
 * pointer resting on it, the keyboard focusing it, a press. Returns the way to stop.
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
