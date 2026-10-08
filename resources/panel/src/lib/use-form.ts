import { useCallback, useState, type FormEvent } from 'react'
import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { ApiError, type FieldErrors } from '@/lib/api'
import { messageOf } from '@/lib/failure'
import { toLatinDigits } from '@/lib/format'
import { runMutation } from '@/lib/query-client'
import { sameData } from '@/lib/utils'

interface SubmitOptions {
  /** Called with the field errors of a 422 (e.g. to open the section that holds them). */
  onInvalid?: (errors: FieldErrors) => void
  /** What the save changes elsewhere on the screens, read again once it is done (its `meta.invalidates`, lib/query-client). */
  invalidates?: readonly QueryKey[]
}

interface FormOptions<T> {
  /**
   * The values come from the server and the form follows them: whenever they change — by value, not by identity (a save
   * of this form came back, another card's save left these as they were) — the form starts again from them.
   */
  follow?: boolean
  /**
   * What the values come to as the server reads them — the measure of `dirty`, taken of the draft and of where it started
   * alike: by default the values with every text trimmed (`trimmed`, Input::text's reading). A form whose request reads
   * them otherwise says so: a driver's fields not shown left out (its payload), a list the server keeps as a set, a
   * password not trimmed, a wording whose leading line breaks count.
   */
  reads?: (values: T) => unknown
}

/** A form's values as the server reads a text field (Input::text): every text trimmed — in the values, and in each list or record they hold. */
export function trimmed(value: unknown): unknown {
  if (typeof value === 'string') return value.trim()
  if (Array.isArray(value)) return value.map(trimmed)
  return isRecord(value) ? Object.fromEntries(Object.entries(value).map(([key, item]) => [key, trimmed(item)])) : value
}

/** A list read as a set — each item once, in no order —, where the server keeps it so: a customer's groups, the amounts offered. */
export function asSet(items: readonly (string | number)[]): Record<string, true> {
  return Object.fromEntries(items.map((item) => [item, true]))
}

/** A plain record — not a list, nor an object of its own kind (a file picked), which is compared by identity. */
function isRecord(value: unknown): value is Record<string, unknown> {
  if (typeof value !== 'object' || value === null) return false
  const prototype: unknown = Object.getPrototypeOf(value)
  return prototype === Object.prototype || prototype === null
}

/** What says nothing in a form: an empty text, null, undefined — or a key the values do not have. */
const nothing = (value: unknown): boolean => value === '' || value === null || value === undefined

/** A number written in digits — Persian, Arabic or Latin —, a decimal part allowed: what its digits read as, Latin; null for any other text. */
function numberText(text: string): string | null {
  const latin = toLatinDigits(text).replace('٫', '.')
  return /^\d+(?:\.\d+)?$/.test(latin) ? latin : null
}

/**
 * Whether two readings of a form say the same to the server, whatever they look like on screen: as sameData, but nothing
 * is one — '', null, undefined, a key missing —, and a number is one with the text that writes it, in Persian digits
 * too (the server's 30, the typed "30" and «۳۰»; «۰۹۱۲» is no «912»: only the digits' script is read past).
 */
function sameValues(a: unknown, b: unknown): boolean {
  if (nothing(a) || nothing(b)) return nothing(a) && nothing(b)
  if (typeof a === 'string' && typeof b === 'string') {
    const number = numberText(a)
    return a === b || (number !== null && number === numberText(b))
  }
  if (typeof a === 'number' && typeof b === 'string') return numberText(b) === String(a)
  if (typeof a === 'string' && typeof b === 'number') return numberText(a) === String(b)
  if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((item, index) => sameValues(item, b[index]))
  if (!isRecord(a) || !isRecord(b)) return Object.is(a, b)
  return [...new Set([...Object.keys(a), ...Object.keys(b)])].every((key) => sameValues(a[key], b[key]))
}

/**
 * The form's state: what is on screen, what it started from (the target of revert and the measure of dirty), and what the
 * server last refused — field by field, and in a word over the fields — with the values that refusal was about.
 */
interface Draft<T> {
  values: T
  baseline: T
  errors: FieldErrors
  /** The form-level error, and the failure it came of when a submit's failure did (a wait the server asked for counts down). */
  refusal: { message: string; failure: unknown } | null
  /** The values a submit's refusal was about; null for a word of the screen's own (setErrors, setFormError). */
  refused: T | null
}

/** A form with nothing refused. */
function fresh<T>(values: T, baseline: T = values): Draft<T> {
  return { values, baseline, errors: {}, refusal: null, refused: null }
}

/** The draft with `changes` typed in: the errors of the fields they change go, since the admin is fixing them. */
function typed<T extends object>(current: Draft<T>, changes: Partial<T>): Draft<T> {
  const values = { ...current.values, ...changes }
  const fixed = Object.keys(changes).filter((key) => key in current.errors)
  if (fixed.length === 0) return { ...current, values }
  const errors = { ...current.errors }
  for (const key of fixed) delete errors[key]
  return { ...current, values, errors }
}

/**
 * State of one form talking to the API: the editable values, the field errors a 422 hands back, a form-level error for
 * anything else, and a `submit()` that runs a request with all of that wired — so pages only describe the fields and the
 * request. `dirty` is whether the values say something else than where the form started — read as the server reads them
 * (`reads`), by value: typing a change back undoes it, whatever was typed on the way — and, with it, a refusal of the
 * values that were sent.
 */
export function useForm<T extends object>(initial: T, options: { follow: true; reads?: (values: T) => unknown }): Form<T>
export function useForm<T extends object>(initial: T | (() => T), options?: { reads?: (values: T) => unknown }): Form<T>
export function useForm<T extends object>(initial: T | (() => T), { follow = false, reads = trimmed }: FormOptions<T> = {}): Form<T> {
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Draft<T>>(() => fresh(typeof initial === 'function' ? initial() : initial))
  const { errors, refusal } = draft
  const [busy, setBusy] = useState(false)
  const same = (a: T, b: T) => sameValues(reads(a), reads(b))

  // State adjusted during render, React's way to follow a prop: the server's copy moved — start again from it; or the
  // values are back where they started, and not at what was refused — every word of a submit's refusal goes: it was about
  // the values sent, which are no longer on screen.
  const [source, setSource] = useState<unknown>(() => (follow ? initial : null))
  if (follow && !sameData(source, initial)) {
    setSource(initial)
    setDraft(fresh(initial as T))
  } else if (draft.refused !== null && same(draft.values, draft.baseline) && !same(draft.values, draft.refused)) {
    setDraft(fresh(draft.values, draft.baseline))
  }

  /** The screen's own word under the fields (a probe's refusal, its own check), about the values on screen. */
  const setErrors = useCallback((next: FieldErrors) => setDraft((current) => ({ ...current, errors: next, refused: null })), [])

  /** The form's own word over its fields — a screen's (a probe's refusal), with no failure behind it. */
  const setFormError = useCallback((message: string | null) => setDraft((current) => ({ ...current, refusal: message === null ? null : { message, failure: null }, refused: null })), [])

  /** Change one value; its error (if any) is cleared since the user is fixing it. */
  const set = useCallback(<K extends keyof T>(key: K, value: T[K]) => setDraft((current) => typed(current, { [key]: value } as unknown as Partial<T>)), [])

  /** Change several values at once (a driver's own fields coming in with the driver); their errors go, as with `set`. */
  const patch = useCallback((changes: Partial<T>) => setDraft((current) => typed(current, changes)), [])

  /** Start again from `next`: what is on screen and what revert() goes back to. */
  const reset = useCallback((next: T) => setDraft(fresh(next)), [])

  /** Discard every unsaved change: back to the values the form started from. */
  const revert = useCallback(() => setDraft((current) => fresh(current.baseline)), [])

  const error = useCallback((field: string) => errors[field]?.[0], [errors])

  /**
   * Run a request — a mutation of the panels' cache, its failure the form's to show (quiet), what it `invalidates` read
   * again once it succeeds: previous errors go, `busy` is on meanwhile; on success what was sent becomes the form's
   * starting point (saved, it is not "unsaved" any more). A 422 becomes field errors — and a message about something the
   * form has no field for (the connection, the file, the subject's state) the form error —, any other failure the form
   * error, in lib/failure's words. Resolves to the result, or undefined when it failed.
   */
  const submit = useCallback(
    async <R>(request: () => Promise<R>, { onInvalid, invalidates }: SubmitOptions = {}): Promise<R | undefined> => {
      const sent = draft.values
      setBusy(true)
      setDraft((current) => ({ ...current, errors: {}, refusal: null, refused: null }))
      try {
        const result = await runMutation(queryClient, request, { quiet: true, invalidates })
        setDraft((current) => ({ ...current, baseline: sent }))
        return result
      } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
          // `servers.0.server_id` is about the `servers` field; a refusal that names no field at all (the subject's state)
          // is the form's in the server's words.
          const fields = Object.entries(e.errors)
          const unplaced = fields.length === 0 ? messageOf(e) : fields.find(([field]) => !((field.split('.')[0] ?? field) in sent))?.[1][0]
          setDraft((current) => ({ ...current, errors: e.errors, refusal: unplaced === undefined ? null : { message: unplaced, failure: e }, refused: sent }))
          onInvalid?.(e.errors)
        } else {
          setDraft((current) => ({ ...current, refusal: { message: messageOf(e), failure: e }, refused: sent }))
        }
        return undefined
      } finally {
        setBusy(false)
      }
    },
    [draft.values, queryClient],
  )

  /** A <form>'s onSubmit: the browser's own submit goes, `run` does the rest. */
  const handleSubmit = useCallback(
    (run: () => unknown) => (event: FormEvent) => {
      event.preventDefault()
      void run()
    },
    [],
  )

  return {
    values: draft.values,
    set,
    patch,
    reset,
    revert,
    errors,
    setErrors,
    error,
    formError: refusal?.message ?? null,
    failure: refusal?.failure ?? null,
    setFormError,
    busy,
    dirty: !same(draft.values, draft.baseline),
    submit,
    handleSubmit,
  }
}

export interface Form<T extends object> {
  values: T
  set: <K extends keyof T>(key: K, value: T[K]) => void
  patch: (changes: Partial<T>) => void
  reset: (next: T) => void
  revert: () => void
  errors: FieldErrors
  setErrors: (errors: FieldErrors) => void
  error: (field: string) => string | undefined
  formError: string | null
  /** The failure the form error came of, when a submit's did: what FormError counts a wait the server asked for from. */
  failure: unknown
  setFormError: (message: string | null) => void
  busy: boolean
  /** The values say something else than where the form started, read as the server reads them (FormOptions' `reads`). */
  dirty: boolean
  submit: <R>(request: () => Promise<R>, options?: SubmitOptions) => Promise<R | undefined>
  handleSubmit: (run: () => unknown) => (event: FormEvent) => void
}
