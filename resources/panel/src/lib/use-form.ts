import { useCallback, useState, type FormEvent } from 'react'
import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { ApiError, type FieldErrors } from '@/lib/api'
import { messageOf } from '@/lib/failure'
import { toLatinDigits, wholeOf } from '@/lib/format'
import { runMutation } from '@/lib/query-client'
import { sameData } from '@/lib/utils'

interface SubmitOptions {
  /** Called with a 422's field errors (to open the section that holds them, say). */
  onInvalid?: (errors: FieldErrors) => void
  /** Reads the save changes on other screens, refetched once it succeeds (`meta.invalidates`, lib/query-client). */
  invalidates?: readonly QueryKey[]
}

interface FormOptions<T> {
  /**
   * The values come from the server: whenever they change by value (not merely by identity), the form starts again
   * from them.
   */
  follow?: boolean
  /**
   * The values as the server reads them — what `dirty` compares. By default every text trimmed (`trimmed`, as
   * Input::text reads it). A form whose request reads its values otherwise passes its own: a driver form's payload,
   * a list the server keeps as a set (`asSet`), a password that is not trimmed.
   */
  reads?: (values: T) => unknown
}

/** A form's values as the server reads text fields (Input::text): every string trimmed, in nested lists and records too. */
export function trimmed(value: unknown): unknown {
  if (typeof value === 'string') return value.trim()
  if (Array.isArray(value)) return value.map(trimmed)
  return isRecord(value) ? Object.fromEntries(Object.entries(value).map(([key, item]) => [key, trimmed(item)])) : value
}

/**
 * A form of amounts and counts as the server reads it: each text that writes a whole number as its digits («۲۰٬۰۰۰» and
 * "20,000" are "20000", as Input::wholeOf() reads them), every other text trimmed (`trimmed`) — a settings group's.
 */
export function asNumbers(values: object): unknown {
  return trimmed(Object.fromEntries(Object.entries(values).map(([name, value]: [string, unknown]) => [name, typeof value === 'string' ? (wholeOf(value) ?? value) : value])))
}

/** A list read as a set (each item once, in no order), for a field the server keeps as one: a customer's groups. */
export function asSet(items: readonly (string | number)[]): Record<string, true> {
  return Object.fromEntries(items.map((item) => [item, true]))
}

/** A plain record — not a list, nor an object of its own class (a picked file), which is compared by identity. */
function isRecord(value: unknown): value is Record<string, unknown> {
  if (typeof value !== 'object' || value === null) return false
  const prototype: unknown = Object.getPrototypeOf(value)
  return prototype === Object.prototype || prototype === null
}

/** What says nothing in a form: an empty text, null, undefined, or a missing key. */
const nothing = (value: unknown): boolean => value === '' || value === null || value === undefined

/** A number written in Persian, Arabic or Latin digits (a decimal part allowed), as Latin digits; null for other text. */
function numberText(text: string): string | null {
  const latin = toLatinDigits(text).replace('٫', '.')
  return /^\d+(?:\.\d+)?$/.test(latin) ? latin : null
}

/**
 * Whether two readings of a form say the same to the server: like sameData, but every kind of "nothing" is equal, and
 * a number equals the text that writes it in any digits (30, "30", «۳۰»; «۰۹۱۲» is not «912»).
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
 * The form's state: the values on screen, where they started (what revert goes back to and dirty compares with), and
 * the server's last refusal with the values it refused.
 */
interface Draft<T> {
  values: T
  baseline: T
  errors: FieldErrors
  /** The form-level error, with the failure it came from (so a server-requested wait can count down). */
  refusal: { message: string; failure: unknown } | null
  /** The values a submit's refusal was about; null for an error the screen set itself (setErrors, setFormError). */
  refused: T | null
}

/** A form with nothing refused. */
function fresh<T>(values: T, baseline: T = values): Draft<T> {
  return { values, baseline, errors: {}, refusal: null, refused: null }
}

/** The draft with `changes` typed in; the errors of the changed fields go, since the admin is fixing them. */
function typed<T extends object>(current: Draft<T>, changes: Partial<T>): Draft<T> {
  const values = { ...current.values, ...changes }
  const fixed = Object.keys(changes).filter((key) => key in current.errors)
  if (fixed.length === 0) return { ...current, values }
  const errors = { ...current.errors }
  for (const key of fixed) delete errors[key]
  return { ...current, values, errors }
}

/**
 * One form talking to the API: the editable values, a 422's field errors, a form-level error for anything else, and
 * `submit()`, which runs the request with all of that wired in — so a screen only describes its fields and its request.
 * `dirty` compares the values with where the form started, as the server reads them (`reads`): typing a change back
 * undoes it.
 */
export function useForm<T extends object>(initial: T, options: { follow: true; reads?: (values: T) => unknown }): Form<T>
export function useForm<T extends object>(initial: T | (() => T), options?: { reads?: (values: T) => unknown }): Form<T>
export function useForm<T extends object>(initial: T | (() => T), { follow = false, reads = trimmed }: FormOptions<T> = {}): Form<T> {
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Draft<T>>(() => fresh(typeof initial === 'function' ? initial() : initial))
  const { errors, refusal } = draft
  const [busy, setBusy] = useState(false)
  const same = (a: T, b: T) => sameValues(reads(a), reads(b))

  // Adjusted during render (React's way to follow a prop): the server's copy moved — start again from it; or the values
  // are back at the baseline and no longer what was refused — the refusal was about values no longer on screen.
  const [source, setSource] = useState<unknown>(() => (follow ? initial : null))
  if (follow && !sameData(source, initial)) {
    setSource(initial)
    setDraft(fresh(initial as T))
  } else if (draft.refused !== null && same(draft.values, draft.baseline) && !same(draft.values, draft.refused)) {
    setDraft(fresh(draft.values, draft.baseline))
  }

  /** Field errors of the screen's own (a probe's refusal), about the values on screen. */
  const setErrors = useCallback((next: FieldErrors) => setDraft((current) => ({ ...current, errors: next, refused: null })), [])

  /** A form-level error of the screen's own, with no failure behind it. */
  const setFormError = useCallback((message: string | null) => setDraft((current) => ({ ...current, refusal: message === null ? null : { message, failure: null }, refused: null })), [])

  /** Change one value; its error goes. */
  const set = useCallback(<K extends keyof T>(key: K, value: T[K]) => setDraft((current) => typed(current, { [key]: value } as unknown as Partial<T>)), [])

  /** Change several values at once (a driver's own fields with the driver); their errors go. */
  const patch = useCallback((changes: Partial<T>) => setDraft((current) => typed(current, changes)), [])

  /** Start again from `next`: on screen, and what revert() goes back to. */
  const reset = useCallback((next: T) => setDraft(fresh(next)), [])

  /** Drop every unsaved change. */
  const revert = useCallback(() => setDraft((current) => fresh(current.baseline)), [])

  const error = useCallback((field: string) => errors[field]?.[0], [errors])

  /**
   * Run a request as a quiet mutation of the panels' cache (the form shows its own failure); `invalidates` is refetched
   * once it succeeds. Success makes what was sent the new baseline. A 422 becomes field errors — a refusal under a name
   * the form has no field for (the connection, the subject's state) becomes the form error —; any other failure the form
   * error. Resolves to the result, or undefined when it failed.
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
          // `servers.0.server_id` belongs to the `servers` field; a 422 that names no field is the form's error.
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

  /** A <form>'s onSubmit: prevents the browser's own submit and runs `run`. */
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
  /** The failure behind the form error, when a submit's: FormError counts a server-requested wait down from it. */
  failure: unknown
  setFormError: (message: string | null) => void
  busy: boolean
  /** The values differ from where the form started, as the server reads them (`reads`). */
  dirty: boolean
  submit: <R>(request: () => Promise<R>, options?: SubmitOptions) => Promise<R | undefined>
  handleSubmit: (run: () => unknown) => (event: FormEvent) => void
}
