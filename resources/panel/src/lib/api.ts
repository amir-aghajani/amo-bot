import type { ErrorResponse, PanelWrite } from '@/lib/api-types'
import { appConfig } from '@/lib/config'

/*
 * The panels' only way to the server: JSON requests to the panel's own API, typed by the API description
 * (lib/api-types). Every failure becomes an `ApiError`, which lib/failure turns into words.
 */

/** A 422's refusals, by field. */
export type FieldErrors = NonNullable<ErrorResponse['errors']>

/** What a failed request leaves besides its status and message: what lib/failure classifies it by and lists in its details. */
export interface FailureFacts {
  /** The server's id of the request (`request_id`, else `X-Request-Id`): its log lines carry it. */
  requestId?: string | null
  /** A 429's wait, in seconds (`Retry-After`). */
  retryAfter?: number | null
  /** «GET /api/admin/servers» — without the query, which may hold a customer's name. */
  endpoint?: string | null
  /** The answer was not the API's (a host's or a proxy's page, or none at all): `message` is a technical line. */
  foreign?: boolean
  /** No answer: the panel stopped waiting (TIMEOUT_MS). */
  timedOut?: boolean
  /** No answer while the browser said it was offline. */
  offline?: boolean
  /** With APP_DEBUG, what the server threw: «RuntimeException: disk on fire». */
  debug?: string | null
}

export class ApiError extends Error {
  /** When it happened: a failure on screen keeps its moment however often it is drawn. */
  readonly at = new Date()

  constructor(
    /** The HTTP status; 0 when no answer came (the network, the timeout). */
    public readonly status: number,
    /** The API's message — unless `facts.foreign`. Screens word failures through lib/failure, never this alone. */
    message: string,
    public readonly errors: FieldErrors = {},
    public readonly facts: FailureFacts = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** The first refusal under a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

/** No answer came at all (the network, or the server not answering in time): worth asking again. */
export function isTransportError(error: unknown): boolean {
  return error instanceof ApiError && error.status === 0
}

/** The server refused because the subject moved on: a 422 on `status` (paid, cancelled or decided meanwhile). */
export function stateChanged(error: unknown): boolean {
  return error instanceof ApiError && error.field('status') !== undefined
}

/** The shop the request named does not exist: a 404 on `shop` (the owner's tab is at a wrong `/s/<id>` address). */
export function shopMissing(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404 && error.field('shop') !== undefined
}

/** The longest the panel waits for an answer. PHP ends every request well within this; only a stalled connection reaches it. */
const TIMEOUT_MS = 90_000

type Listener = () => void
const unauthorizedListeners = new Set<Listener>()

/** Subscribe to 401 answers (lib/auth ends the session on one). */
export function onUnauthorized(listener: Listener): () => void {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

interface RequestOptions {
  /** A sign-in (the owner's password, an agent's link): its 401 refuses the attempt only, and leaves an open session alone. */
  signIn?: boolean
  /**
   * The change's `Idempotency-Key` (support's order by hand, a wallet's or an agent's traffic set right, a ticket's
   * answer): the same key again is answered by what it did, never done a second time.
   */
  idempotencyKey?: string
}

/** Whether a body carries a file (an upload): it then goes as multipart. */
function holdsFile(body: unknown): body is Record<string, unknown> {
  return typeof body === 'object' && body !== null && Object.values(body).some((value) => value instanceof Blob)
}

/** A body with a file as a multipart form, a part per field. */
function formOf(body: Record<string, unknown>): FormData {
  const form = new FormData()
  for (const [name, value] of Object.entries(body)) {
    if (value !== undefined) form.append(name, value instanceof Blob ? value : String(value))
  }
  return form
}

/** What a request reads of a success: JSON, the bytes (a file the panel handles itself), or nothing (is the file there?). */
type Reading = 'json' | 'blob' | 'none'

async function request<T>(
  url: string,
  method: string,
  headers: Record<string, string>,
  body?: unknown,
  { signIn = false, idempotencyKey }: RequestOptions = {},
  reading: Reading = 'json',
): Promise<T> {
  const content = body === undefined ? undefined : holdsFile(body) ? formOf(body) : JSON.stringify(body)
  const endpoint = `${method} ${new URL(url, document.baseURI).pathname}`
  const controller = new AbortController()
  let timedOut = false
  const timer = setTimeout(() => {
    timedOut = true
    controller.abort()
  }, TIMEOUT_MS)

  let response: Response
  let text = ''
  let bytes: Blob | null = null
  try {
    response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        // The session API's CSRF guard: a browser cannot send a custom header cross-site.
        'X-Requested-With': 'XMLHttpRequest',
        ...(typeof content === 'string' ? { 'Content-Type': 'application/json' } : {}),
        ...(idempotencyKey === undefined ? {} : { 'Idempotency-Key': idempotencyKey }),
        ...headers,
      },
      body: content,
      signal: controller.signal,
    })
    // A failure is JSON whatever was asked for; a success is what was asked for.
    if (!response.ok || reading === 'json') text = response.status === 204 ? '' : await response.text()
    else if (reading === 'blob') bytes = await response.blob()
    else await response.body?.cancel()
  } catch {
    // The browser's own words ("Failed to fetch") mean nothing to the admin; lib/failure words what happened.
    const offline = navigator.onLine === false
    throw new ApiError(0, timedOut ? `No answer within ${TIMEOUT_MS / 1000} seconds` : 'No answer: the request did not reach the server', {}, { endpoint, timedOut, offline, foreign: true })
  } finally {
    clearTimeout(timer)
  }

  if (response.status === 204 || (response.ok && reading === 'none')) {
    return undefined as T
  }
  if (response.ok && reading === 'blob') {
    return bytes as T
  }

  let payload: unknown = null
  let readable = true
  try {
    payload = text ? JSON.parse(text) : null
  } catch {
    readable = false
  }
  const requestId = response.headers.get('X-Request-Id')

  if (!response.ok) {
    // Every failure PHP answers has the shape {message, errors?, request_id}; anything else came from between the panel
    // and PHP (a host's or a proxy's page).
    const data = (readable && payload !== null && typeof payload === 'object' ? payload : {}) as Partial<ErrorResponse>
    if (response.status === 401 && !signIn) {
      unauthorizedListeners.forEach((listener) => listener())
    }
    const worded = typeof data.message === 'string'
    throw new ApiError(response.status, worded ? (data.message ?? '') : `HTTP ${response.status} without the API's answer`, data.errors ?? {}, {
      endpoint,
      requestId: data.request_id ?? requestId,
      retryAfter: retryAfterOf(response),
      foreign: !worded,
      debug: data.debug ? `${data.debug.exception}: ${data.debug.detail}` : null,
    })
  }

  // A success that is not JSON came from something between the panel and PHP.
  if (!readable) {
    throw new ApiError(response.status, `HTTP ${response.status} that is not JSON`, {}, { endpoint, requestId, foreign: true })
  }

  return payload as T
}

/** A `Retry-After` in seconds — sent as a number of seconds or as a date. */
function retryAfterOf(response: Response): number | null {
  const value = response.headers.get('Retry-After')
  if (value === null || value.trim() === '') return null
  const seconds = Number(value)
  const until = Number.isFinite(seconds) ? seconds * 1000 : Date.parse(value) - Date.now()

  return Number.isNaN(until) ? null : Math.max(0, Math.ceil(until / 1000))
}

/** One write of an API, as lib/api-types lists them (PanelWrite, InstallWrite). */
interface Write {
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** Its path under the API's base, a parameter as the type of its value: `/plans/${number}`. */
  path: string
  /** What it takes: `undefined` for none. */
  body: unknown
  /** What it answers: `void` for nothing. */
  answer: unknown
}

type Method = Write['method']

/** The paths an API takes `method` at. */
type PathOf<W extends Write, M extends Method> = Extract<W, { method: M }>['path']

/** The write(s) a path matches — several for a path like `/orders/${number}/${action}`. */
type WriteAt<W extends Write, M extends Method, P extends string> = W extends { method: M; path: infer Pattern } ? (P extends Pattern ? W : never) : never

type BodyOf<W extends Write, M extends Method, P extends string> = WriteAt<W, M, P>['body']
type AnswerOf<W extends Write, M extends Method, P extends string> = WriteAt<W, M, P>['answer']

/** Every key a body may have: any member's of a oneOf. */
type KeysOf<T> = T extends unknown ? keyof T : never

/** A body with no field its write does not read: the API's objects are closed, so an extra field is a compile error. */
type Exact<B, Body> = B & { [K in Exclude<keyof B, KeysOf<Body>>]: never }

/**
 * The arguments after a write's path: none when it takes no body; else the body — optional when one of the writes the
 * path matches takes none — and the request's options.
 */
type BodyArgs<Body, B> = [Body] extends [undefined] ? [] : undefined extends Body ? [body?: Exact<B, NonNullable<Body>>, options?: RequestOptions] : [body: Exact<B, Body>, options?: RequestOptions]

/**
 * A client of the API at `base`. Writes are typed by their path (`W`: PanelWrite, InstallWrite): the path picks the body
 * and the answer, so a wrong field, a missing one or a body to a write that takes none is a compile error. A read names
 * its answer type. `headers` is called for every request (the installer's key can change).
 */
export function apiClient<W extends Write = never>(base: string, headers: () => Record<string, string> = () => ({})) {
  const write =
    <M extends Exclude<Method, 'DELETE'>>(method: M) =>
    <P extends PathOf<W, M>, B extends BodyOf<W, M, P>>(path: P, ...args: BodyArgs<BodyOf<W, M, P>, B>) =>
      request<AnswerOf<W, M, P>>(`${base}${path}`, method, headers(), ...args)

  return {
    /** A read; `query` (a list's search, filters, page) goes after a `?` when it holds anything. */
    get: <T>(path: string, query?: URLSearchParams) => request<T>(withQuery(`${base}${path}`, query), 'GET', headers()),
    /** Resolves when the file at `path` is there, else throws the API's failure (why a picture could not be drawn). */
    probe: (path: string) => request<void>(`${base}${path}`, 'GET', headers(), undefined, {}, 'none'),
    /** The bytes of the file at `path`. */
    blob: (path: string) => request<Blob>(`${base}${path}`, 'GET', headers(), undefined, {}, 'blob'),
    post: write('POST'),
    put: write('PUT'),
    patch: write('PATCH'),
    delete: <P extends PathOf<W, 'DELETE'>>(path: P) => request<AnswerOf<W, 'DELETE', P>>(`${base}${path}`, 'DELETE', headers()),
  }
}

/** The owner's panel names the shop its tab shows on every request; an agent's panel names none. */
const shopHeaders: Record<string, string> = appConfig.shop === null ? {} : { 'X-Shop': String(appConfig.shop) }

/** This panel's API (/api/admin or /api/agent), session-authenticated, in the shop the tab shows. */
export const api = apiClient<PanelWrite>(appConfig.apiBase, () => shopHeaders)

/**
 * The address of an API file the browser fetches itself (an <img>, a <video>, a download link), which carries no
 * header: the shop goes in the `shop` query parameter instead.
 */
export function mediaUrl(path: string, query: Record<string, string> = {}): string {
  return withQuery(`${appConfig.apiBase}${path}`, new URLSearchParams({ ...query, ...(appConfig.shop === null ? {} : { shop: String(appConfig.shop) }) }))
}

/** An address with its query after a `?` when it holds anything (read off its text: `URLSearchParams.size` is too new). */
function withQuery(address: string, query: URLSearchParams | undefined): string {
  const search = query?.toString() ?? ''

  return search === '' ? address : `${address}?${search}`
}

/** The API's root, for /api/app (the shop's name, whether it is installed). The installer has its own client (its key). */
export const rootApi = apiClient(appConfig.apiRoot)
