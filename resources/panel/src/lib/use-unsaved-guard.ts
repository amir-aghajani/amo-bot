import { createContext, createElement, useContext, useEffect, useState, useSyncExternalStore, type ReactElement } from 'react'
import { useBlocker, type BlockerFunction } from 'react-router'
import { pageOf } from '@/lib/config'
import { createListeners } from '@/lib/listeners'

/*
 * Unsaved changes are never lost without a question. Every edit surface (FormActions, SaveFooter) registers itself
 * while dirty (`useUnsavedGuard`); then:
 * - closing or reloading the tab gets the browser's own prompt (the only one browsers allow there);
 * - a navigation to another page waits for the panel's dialog (`NavigationGuard`, the router's blocker; moving between
 *   the sections of one page asks nothing — their drafts stay mounted);
 * - an action that drops every draft (signing out, opening another shop) asks first (`confirmLeave`);
 * - a modal asks before its ✕, Escape or backdrop drops a draft of its own (`useUnsavedScope`) — a dirty card behind it
 *   does not count.
 * The dialog itself is drawn once by the root route (components/leave-question); only one question is asked at a time.
 */

/** Every dirty surface of the panel. */
const dirty = new Set<symbol>()
/** Drafts the admin agreed to drop (`confirmLeave`): they no longer hold anything back. */
const dropped = new Set<symbol>()
/** Work under way that closing the tab would cut short (`useUnloadGuard`). */
const working = new Set<symbol>()

/** The drafts that still hold the panel: dirty, and not given up. */
const held = () => [...dirty].filter((token) => !dropped.has(token))
const holding = () => held().length > 0

/** Told whenever the set of holding drafts changes: the router's blocker is taken or let go. */
const holds = createListeners()

/** The question on screen, if one is asked: its answer resolves to true to drop the changes. */
let question: { answer: (leave: boolean) => void; answered: Promise<boolean> } | null = null
const questions = createListeners()

/** Ask whether to drop the unsaved changes — or join the question already on screen. */
function ask(): Promise<boolean> {
  if (question) return question.answered
  let settle: ((leave: boolean) => void) | undefined
  const answered = new Promise<boolean>((resolve) => {
    settle = resolve
  })
  question = {
    answered,
    answer: (leave) => {
      question = null
      questions.notify()
      settle?.(leave)
    },
  }
  questions.notify()
  return answered
}

/** For the one component that draws the question: whether it is asked, and how to answer it (true drops the changes). */
export function useLeaveQuestion(): { asking: boolean; answer: (leave: boolean) => void } {
  const asking = useSyncExternalStore(questions.subscribe, () => question !== null)

  return { asking, answer: (leave) => question?.answer(leave) }
}

function onBeforeUnload(event: BeforeUnloadEvent) {
  if (!holding() && working.size === 0) return
  event.preventDefault()
  // Older browsers ask only when this is set.
  event.returnValue = ''
}

let listening = false

/** Install the tab's `beforeunload` question, once, from the first surface that may need it. */
function listen() {
  if (listening) return
  window.addEventListener('beforeunload', onBeforeUnload)
  listening = true
}

/** A navigation to another page (not a section, a query or a fragment of this one) is held while a draft is unsaved. */
const blocks: BlockerFunction = ({ currentLocation, nextLocation }) => holding() && pageOf(currentLocation.pathname) !== pageOf(nextLocation.pathname)

/**
 * The router's blocker, drawn once by the root route. It is mounted only while a draft is unsaved: a router with a
 * blocker warns about every browser-made move it cannot hold back, such as an agent's sign-in link's fragment.
 */
export function NavigationGuard(): ReactElement | null {
  return useSyncExternalStore(holds.subscribe, holding) ? createElement(Blocker) : null
}

/** The blocker itself: a held navigation waits for the admin's answer, then goes on or stays. */
function Blocker(): null {
  const blocker = useBlocker(blocks)

  useEffect(() => {
    if (blocker.state !== 'blocked') return
    let current = true
    void ask().then((leave) => {
      if (!current) return
      if (leave) blocker.proceed()
      else blocker.reset()
    })
    return () => {
      current = false
    }
  }, [blocker])

  return null
}

/**
 * Before an action that drops every draft (signing out, opening another shop): ask while one is unsaved. Resolves to
 * null when the admin stays; otherwise the drafts stop holding back the action's navigation and the tab's unloading,
 * and `keep()` gives them their hold back if the action failed.
 */
export async function confirmLeave(): Promise<{ keep: () => void } | null> {
  const drafts = held()
  if (drafts.length > 0 && !(await ask())) return null
  drafts.forEach((token) => dropped.add(token))
  holds.notify()
  return {
    keep: () => {
      drafts.forEach((token) => dropped.delete(token))
      holds.notify()
    },
  }
}

/** The dirty surfaces inside one modal. */
const ScopeContext = createContext<Set<symbol> | null>(null)

/** What a modal provides to the forms inside it. */
export const UnsavedScope = ScopeContext.Provider

/** Register a surface with unsaved changes while `active`: leaving asks first, and so does closing its modal. */
export function useUnsavedGuard(active: boolean): void {
  const scope = useContext(ScopeContext)

  useEffect(() => {
    if (!active) return
    listen()
    const token = Symbol('unsaved')
    dirty.add(token)
    scope?.add(token)
    holds.notify()
    return () => {
      dirty.delete(token)
      dropped.delete(token)
      scope?.delete(token)
      holds.notify()
    }
  }, [active, scope])
}

/** Ask before the tab closes or reloads while `active`: work the page carries on (a move batch). */
export function useUnloadGuard(active: boolean): void {
  useEffect(() => {
    if (!active) return
    listen()
    const token = Symbol('working')
    working.add(token)
    return () => {
      working.delete(token)
    }
  }, [active])
}

/** Run `discard` at once when the modal holds no unsaved draft, else once the admin agrees to drop it. */
function whenDiscarded(scope: Set<symbol> | null, discard: () => void): void {
  if (scope === null || scope.size === 0) {
    discard()
    return
  }
  void ask().then((leave) => {
    if (leave) discard()
  })
}

/** A modal's own scope (`UnsavedScope`), and the question it asks before its ✕, Escape or backdrop drops a draft. */
export function useUnsavedScope(): { scope: Set<symbol>; confirmDiscard: (discard: () => void) => void } {
  const [scope] = useState(() => new Set<symbol>())

  return { scope, confirmDiscard: (discard) => whenDiscarded(scope, discard) }
}

/** The same question for a control inside a modal that closes it (a form's «انصراف»); outside a modal it never asks. */
export function useConfirmDiscard(): (discard: () => void) => void {
  const scope = useContext(ScopeContext)

  return (discard) => whenDiscarded(scope, discard)
}
