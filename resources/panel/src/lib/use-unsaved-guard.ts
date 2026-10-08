import { createContext, createElement, useContext, useEffect, useState, useSyncExternalStore, type ReactElement } from 'react'
import { useBlocker, type BlockerFunction } from 'react-router'
import { pageOf } from '@/lib/config'

/*
 * Changes nobody saved are not lost without a word. While an edit surface is dirty — a form's actions (FormActions) or
 * a card's footer (SaveFooter) say so —, closing the tab or reloading asks (the browser's own prompt: browsers allow no
 * other there), and so does every navigation to another page of the panel — a link, a redirect, the browser's back and
 * forward — through the router's blocker (`NavigationGuard`, drawn once by the panels' root route, which holds the
 * router only while a draft is unsaved); a page's sections are one page — their drafts stay mounted — so moving between
 * them asks nothing. What drops every draft at once by its own doing — signing out, opening another shop — asks before
 * it acts (`confirmLeave`). A modal asks before its ✕, Escape or backdrop drops a draft of its own: each modal keeps its
 * own scope, so a dirty card behind it does not make it ask. Work under way that only a closing tab cuts short (a move
 * batch) asks then alone (`useUnloadGuard`). Inside the panel the question is the panel's own dialog, drawn once by the
 * root route (components/leave-question) — one question at a time, staying the default.
 */

/** Every dirty surface of the panel. */
const dirty = new Set<symbol>()
/** Drafts the admin agreed to drop (`confirmLeave`): they hold no navigation back any more. */
const dropped = new Set<symbol>()
/** Work under way that a closing tab would cut short. */
const working = new Set<symbol>()

/** The drafts that still hold the panel: dirty, and not given up by the admin. */
const held = () => [...dirty].filter((token) => !dropped.has(token))

/** What is told whenever the drafts that hold the panel change: the guard takes its hold on the router, or lets go. */
const listeners = new Set<() => void>()
const changed = () => listeners.forEach((listener) => listener())

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

const holding = () => held().length > 0

/** The question on screen: its answer once given — true to drop the changes; null while none is asked. */
let question: { answer: (leave: boolean) => void; answered: Promise<boolean> } | null = null
const asked = new Set<() => void>()
const toldAsked = () => asked.forEach((listener) => listener())

/** Whether to drop the unsaved changes, as the admin answers the panel's question — the one on screen, if it is asked already. */
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
      toldAsked()
      settle?.(leave)
    },
  }
  toldAsked()
  return answered
}

/**
 * The question while it is asked, for the one place that draws it (components/leave-question): whether it is, and the
 * admin's answer — true to drop the changes.
 */
export function useLeaveQuestion(): { asking: boolean; answer: (leave: boolean) => void } {
  const asking = useSyncExternalStore(
    (listener) => {
      asked.add(listener)
      return () => asked.delete(listener)
    },
    () => question !== null,
  )

  return { asking, answer: (leave) => question?.answer(leave) }
}

function onBeforeUnload(event: BeforeUnloadEvent) {
  if (held().length === 0 && working.size === 0) return
  event.preventDefault()
  // Older browsers ask only when this is set.
  event.returnValue = ''
}

let listening = false

/** The tab's own question, from the first surface that may need it. */
function listen() {
  if (listening) return
  window.addEventListener('beforeunload', onBeforeUnload)
  listening = true
}

/** A navigation the guard holds back: to another page — not one that stays on it, a section or a fragment —, while a draft is unsaved. */
const blocks: BlockerFunction = ({ currentLocation, nextLocation }) => holding() && pageOf(currentLocation.pathname) !== pageOf(nextLocation.pathname)

/**
 * The guard's hold on the router, drawn once inside it (the panels' root route — a router supports one blocker): while
 * a draft is unsaved, a navigation to another page waits for the admin's answer, then goes on or stays. It holds the
 * router only then: a router with a blocker warns of every move of the browser's own it cannot hold back — an address's
 * fragment, as an agent's sign-in link carries its code —, and with nothing unsaved there is nothing to hold.
 */
export function NavigationGuard(): ReactElement | null {
  return useSyncExternalStore(subscribe, holding) ? createElement(Blocker) : null
}

/** The router's blocker itself, and the question it asks. */
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
 * Before an action that drops every draft at once by its own doing — signing out, opening another shop —, the same
 * question while one is unsaved: null when the admin would rather stay. Agreed, the drafts no longer hold back the
 * navigation the action makes (nor the tab's unloading); `keep()` takes that back when the action failed and they are
 * still on screen.
 */
export async function confirmLeave(): Promise<{ keep: () => void } | null> {
  const drafts = held()
  if (drafts.length > 0 && !(await ask())) return null
  drafts.forEach((token) => dropped.add(token))
  changed()
  return {
    keep: () => {
      drafts.forEach((token) => dropped.delete(token))
      changed()
    },
  }
}

/** The dirty surfaces inside one modal. */
const ScopeContext = createContext<Set<symbol> | null>(null)

/** What a modal provides to the forms inside it. */
export const UnsavedScope = ScopeContext.Provider

/** Ask before leaving while `active` (a surface with unsaved changes) — and before its modal, if it is in one, closes. */
export function useUnsavedGuard(active: boolean): void {
  const scope = useContext(ScopeContext)

  useEffect(() => {
    if (!active) return
    listen()
    const token = Symbol('unsaved')
    dirty.add(token)
    scope?.add(token)
    changed()
    return () => {
      dirty.delete(token)
      dropped.delete(token)
      scope?.delete(token)
      changed()
    }
  }, [active, scope])
}

/** Ask before the tab closes or reloads while `active`: work under way that the page carries on (a move batch). */
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

/** `discard` the modal's content: at once while no draft of its own is unsaved, else once the admin agreed to drop it. */
function whenDiscarded(scope: Set<symbol> | null, discard: () => void): void {
  if (scope === null || scope.size === 0) {
    discard()
    return
  }
  void ask().then((leave) => {
    if (leave) discard()
  })
}

/** A modal's own scope (`UnsavedScope`), and the question it asks before its ✕, Escape or backdrop drops what is in it. */
export function useUnsavedScope(): { scope: Set<symbol>; confirmDiscard: (discard: () => void) => void } {
  const [scope] = useState(() => new Set<symbol>())

  return { scope, confirmDiscard: (discard) => whenDiscarded(scope, discard) }
}

/** The same question, for a control inside the modal that closes it (a form's «انصراف»); none outside a modal. */
export function useConfirmDiscard(): (discard: () => void) => void {
  const scope = useContext(ScopeContext)

  return (discard) => whenDiscarded(scope, discard)
}
