import { useEffect } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { toast } from 'sonner'
import { FOLD } from '@/components/form-footer'
import { pageOf } from '@/lib/config'
import type { SettingEntry } from '@/lib/settings-search/types'
import { REDUCED_MOTION } from '@/lib/use-media-query'

/*
 * Arriving at a setting the settings search opened: its section is navigated to with the setting named in the router's
 * state (never the address, so a reload or a bookmark opens the section alone), and once the setting is drawn — the page's
 * file and its read may still be on their way — its fold opens, it scrolls to the middle of the screen, its control takes
 * the focus and its field is marked for a moment (`data-arrived`, index.css). A setting that is not drawn after all (one
 * shown only with another choice of its section's) is said so, its section's heading taking the focus.
 */

/** What of a setting is looked for on arrival (SettingEntry's own words for it). */
type SettingTarget = Pick<SettingEntry, 'anchor' | 'anchorText' | 'fold'>

/** The router state that names the setting to arrive at. */
interface ArrivalState {
  settingTarget: SettingTarget
}

/** How long the setting is waited for — its page's file and its read on a slow connection —, before saying it is not shown. */
const WAIT_MS = 5_000

/** How long the field stays marked. */
const MARK_MS = 2_000

/** The mark on the field arrived at (index.css draws it). */
export const ARRIVED = 'data-arrived'

/** What a control the focus can go to is. */
const FOCUSABLE = 'input:not([type="hidden"]), select, textarea, button, [contenteditable="true"], [tabindex]:not([tabindex="-1"])'

/** The router state that opens `entry`'s section at the setting; none for a page or a section, which just open. */
export function arrivalState(entry: SettingEntry): ArrivalState | undefined {
  if (entry.kind !== 'setting' || (entry.anchor === undefined && entry.anchorText === undefined)) return undefined
  return { settingTarget: { anchor: entry.anchor, anchorText: entry.anchorText, fold: entry.fold } }
}

function targetOf(state: unknown): SettingTarget | null {
  if (typeof state !== 'object' || state === null || !('settingTarget' in state)) return null
  const target = (state as ArrivalState).settingTarget
  return typeof target === 'object' && target !== null ? target : null
}

/** The arrival under way, with the page it is on: one at a time, for the one shell. */
let landing: { page: string; stop: () => void } | null = null

/**
 * Arrives at the setting the address's router state names (the shell runs it for every page): the state is dropped at
 * once — the history entry, reloaded or gone back to, opens the section alone —, and the setting is waited for meanwhile.
 * Another page, or another setting, ends the wait.
 */
export function useSettingArrival(): void {
  const { pathname, search, hash, state } = useLocation()
  const navigate = useNavigate()
  const target = targetOf(state)

  useEffect(() => {
    if (landing !== null && landing.page !== pageOf(pathname)) {
      landing.stop()
      landing = null
    }
    if (target === null) return
    landing?.stop()
    landing = { page: pageOf(pathname), stop: arrive(target) }
    void navigate({ pathname, search, hash }, { replace: true, state: null })
  }, [target, pathname, search, hash, navigate])

  useEffect(
    () => () => {
      landing?.stop()
      landing = null
    },
    [],
  )
}

/** Normalised for comparing a label's words: its spaces collapsed. */
function words(text: string | null): string {
  return (text ?? '').replace(/\s+/g, ' ').trim()
}

/** Whether `element` can be shown: nothing hides it but folds, which open (not a section of the page that is not on screen). */
function reachable(element: Element): boolean {
  for (let hidden = element.closest('[hidden]'); hidden !== null; hidden = hidden.parentElement?.closest('[hidden]') ?? null) {
    if (!hidden.hasAttribute(FOLD)) return false
  }
  return true
}

/** The element whose words are `text` in the page's column: a label's, preferably. */
function byText(root: HTMLElement, text: string): HTMLElement | null {
  const wanted = words(text)
  const found: HTMLElement[] = []
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
    const parent = node.parentElement
    if (parent !== null && words(node.textContent) === wanted && reachable(parent)) found.push(parent)
  }
  // A label drawn in parts (a word and a tip beside it): its whole words.
  for (const label of root.querySelectorAll<HTMLElement>('label, legend, [id$="-label"]')) {
    if (words(label.textContent) === wanted && reachable(label)) found.push(label)
  }

  return found.find((element) => element.closest('label, legend, [id$="-label"]') !== null) ?? found[0] ?? null
}

/** The setting as drawn: its control by its id, else its label by its words. */
function locate({ anchor, anchorText }: SettingTarget): HTMLElement | null {
  const root = document.getElementById('main') ?? document.body
  if (anchor !== undefined) {
    const element = document.getElementById(anchor)
    if (element !== null && root.contains(element) && reachable(element)) return element
  }

  return anchorText === undefined ? null : byText(root, anchorText)
}

/** The fold closed over `element`, if any. */
function closedFold(element: Element): Element | null {
  const fold = element.closest(`[${FOLD}][hidden]`)
  return fold !== null && fold.id !== '' ? fold : null
}

/** The toggle of a region (a Disclosure's button, which names it in `aria-controls`). */
function toggleOf(region: Element): HTMLElement | null {
  return [...document.querySelectorAll<HTMLElement>('[aria-controls]')].find((toggle) => toggle.getAttribute('aria-controls') === region.id) ?? null
}

/** A fold shut, by its toggle's words («تنظیمات پیشرفته»). */
function namedFold(label: string): HTMLElement | null {
  const root = document.getElementById('main') ?? document.body
  return [...root.querySelectorAll<HTMLElement>('[aria-expanded="false"][aria-controls]')].find((toggle) => words(toggle.textContent) === words(label) && reachable(toggle)) ?? null
}

/** The control to focus and the field's box to mark, from what was found of the setting. */
function partsOf(found: HTMLElement): { control: HTMLElement | null; box: HTMLElement } {
  // A card's or a part's title: the card or the part is marked, its title takes the focus (read out, the keyboard there).
  if (found.matches('h1, h2, h3, h4') && found.closest('[data-field]') === null) {
    if (!found.matches(FOCUSABLE)) found.tabIndex = -1
    return { control: found, box: found.closest<HTMLElement>('[role="group"], [data-slot="card"]') ?? found }
  }
  const label = found.closest('label')
  let control: HTMLElement | null = found.matches(FOCUSABLE) ? found : null
  if (control === null && label !== null) control = (label.htmlFor === '' ? null : document.getElementById(label.htmlFor)) ?? label.querySelector<HTMLElement>(FOCUSABLE)
  const box = (control ?? found).closest<HTMLElement>('[data-field]') ?? label ?? found
  control ??= box.querySelector<HTMLElement>(FOCUSABLE)

  return { control, box }
}

/** The heading of the section on screen — its first card's title —, else the page's. */
function sectionHeading(): HTMLElement | null {
  const root = document.getElementById('main') ?? document.body
  const section = [...root.querySelectorAll<HTMLElement>('section[aria-label]')].find(reachable)
  const first = (scope: HTMLElement) => [...scope.querySelectorAll<HTMLElement>('h1, h2, h3, h4')].find(reachable) ?? null

  return (section === undefined ? null : first(section)) ?? first(root)
}

/**
 * The setting was not drawn in time — shown only with another choice of its section's (an SMS driver, a mail transport),
 * or its page not read —: said so, and its section's heading takes the focus, unless the admin took it elsewhere since
 * the search gave it back (`left`).
 */
function notShown(left: Element | null): void {
  toast.info('این تنظیم الان نمایش داده نمی‌شود', { description: 'شاید فقط با انتخاب دیگری در همین بخش نمایش داده شود.' })
  const focused = document.activeElement
  if (focused !== null && focused !== document.body && focused !== left) return
  const heading = sectionHeading()
  if (heading === null) return
  if (!heading.matches(FOCUSABLE)) heading.tabIndex = -1
  heading.scrollIntoView({ block: 'nearest', behavior: window.matchMedia(REDUCED_MOTION).matches ? 'auto' : 'smooth' })
  heading.focus({ preventScroll: true })
}

/** Scrolls to the setting, focuses its control and marks its field; returns the way to take the mark off early. */
function land(found: HTMLElement): () => void {
  const { control, box } = partsOf(found)
  box.scrollIntoView({ block: 'center', behavior: window.matchMedia(REDUCED_MOTION).matches ? 'auto' : 'smooth' })
  control?.focus({ preventScroll: true })
  // Marked again from the start, when it still was.
  box.removeAttribute(ARRIVED)
  void box.offsetWidth
  box.setAttribute(ARRIVED, '')
  const timer = window.setTimeout(() => box.removeAttribute(ARRIVED), MARK_MS)

  return () => {
    window.clearTimeout(timer)
    box.removeAttribute(ARRIVED)
  }
}

/**
 * Waits for the setting (up to WAIT_MS), opening the fold it sits in, then lands on it — or says it is not shown. The
 * first look is a tick away: the search's dialog gives the focus back to its opener as it closes, before the control
 * takes it. Returns the way to stop waiting (and to take the mark off).
 */
function arrive(target: SettingTarget): () => void {
  const pressed = new Set<Element>()
  let unmark: (() => void) | null = null
  /** Where the search left the focus as it closed. */
  let left: Element | null = null
  /** A fold's toggle pressed — once: pressed again, it would fold shut. */
  const press = (toggle: HTMLElement | null) => {
    if (toggle === null || pressed.has(toggle)) return
    pressed.add(toggle)
    toggle.click()
  }

  const look = () => {
    const found = locate(target)
    if (found === null) {
      // Not drawn at all: in a fold that draws its content only while open — the one the entry names, opened by its label.
      if (target.fold !== undefined) press(namedFold(target.fold))
      return
    }
    const fold = closedFold(found)
    const toggle = fold === null ? null : toggleOf(fold)
    if (toggle !== null) {
      // Opened, its region shows (a change of `hidden` this observer sees) and the next look lands.
      press(toggle)
      return
    }
    done()
    unmark = land(found)
  }

  const observer = new MutationObserver(look)
  const first = window.setTimeout(() => {
    left = document.activeElement
    observer.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['hidden'] })
    look()
  })
  const giveUp = window.setTimeout(() => {
    done()
    notShown(left)
  }, WAIT_MS)

  function done() {
    observer.disconnect()
    window.clearTimeout(first)
    window.clearTimeout(giveUp)
  }

  return () => {
    done()
    unmark?.()
  }
}
