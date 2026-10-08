import type { ErrorResponse, PanelWrite } from '@/lib/api-types'
import { appConfig } from '@/lib/config'

/** What a refusal says under each field it is about (a 422's `errors`). */
export type FieldErrors = NonNullable<ErrorResponse['errors']>

/** What a failed request leaves besides its status and words: what lib/failure tells the admin by, and shows in its details. */
export interface FailureFacts {
  /** The request's id, as the server named it (`X-Request-Id`, the answer's `request_id`): the log's lines of it carry it. */
  requestId?: string | null
  /** A 429's wait, in seconds (`Retry-After`). */
  retryAfter?: number | null
  /** The request — «GET /api/admin/servers» —, without its query (a search may hold a customer's name). */
  endpoint?: string | null
  /** The answer was not the API's own (a host's or a proxy's page, nothing at all): `message` is a technical line about it. */
  foreign?: boolean
  /** No answer came because the panel stopped waiting, not because the network failed. */
  timedOut?: boolean
  /** No answer came while the browser knew itself offline. */
  offline?: boolean
  /** With APP_DEBUG, what the server threw: «RuntimeException: disk on fire». */
  debug?: string | null
}

export class ApiError extends Error {
  /** When it happened: a failure on screen keeps the moment it came, however often it is drawn. */
  readonly at = new Date()

  constructor(
    /** The answer's HTTP status; 0 when no answer came (the network, the timeout). */
    public readonly status: number,
    /** The API's own words — unless `facts.foreign`. A screen words a failure through lib/failure, never this alone. */
    message: string,
    public readonly errors: FieldErrors = {},
    public readonly facts: FailureFacts = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

/** No answer came at all — the network, or the server not answering in time: worth asking again. */
export function isTransportError(error: unknown): boolean {
  return error instanceof ApiError && error.status === 0
}

/** The server refused because the subject moved on (a 422 on `status`: paid, cancelled or decided meanwhile). */
export function stateChanged(error: unknown): boolean {
  return error instanceof ApiError && error.field('status') !== undefined
}

/** The shop the request named is not there (a 404 on `shop`): the owner's tab is at the address of no shop. */
export function shopMissing(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404 && error.field('shop') !== undefined
}

/**
 * The longest the panel waits for an answer. Every request PHP serves ends within its own execution limit, and the
 * slowest ask a panel or Telegram (OUTGOING_HTTP_TIMEOUT) — so only a connection that stalled ever reaches this.
 */
const TIMEOUT_MS = 90_000

type Listener = () => void
const unauthorizedListeners = new Set<Listener>()

/** Subscribe to 401 responses (the auth provider uses this to drop the session). */
export function onUnauthorized(listener: Listener): () => void {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

interface RequestOptions {
  /** A sign-in (the owner's password, an agent's link): its 401 refuses the attempt alone — a session open meanwhile stays. */
  signIn?: boolean
}

/** Whether a body carries a file — an upload: only multipart carries bytes, JSON has none. */
function holdsFile(body: unknown): body is Record<string, unknown> {
  return typeof body === 'object' && body !== null && Object.values(body).some((value) => value instanceof Blob)
}

/** A body with a file, as a browser's form uploads it: a part per field. */
function formOf(body: Record<string, unknown>): FormData {
  const form = new FormData()
  for (const [name, value] of Object.entries(body)) {
    if (value !== undefined) form.append(name, value instanceof Blob ? value : String(value))
  }
  return form
}

/**
 * What a request reads of a success: its JSON (the API's answers), its bytes (a file the panel handles itself — a premium
 * emoji's animation), or nothing (whether a file is there at all).
 */
type Reading = 'json' | 'blob' | 'none'

async function request<T>(url: string, method: string, headers: Record<string, string>, body?: unknown, { signIn = false }: RequestOptions = {}, reading: Reading = 'json'): Promise<T> {
  // A body with a file goes as multipart (the browser writes the boundary header); any other as JSON.
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
        // CSRF guard for the session API: browsers cannot send custom headers cross-origin.
        'X-Requested-With': 'XMLHttpRequest',
        ...(typeof content === 'string' ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
      body: content,
      signal: controller.signal,
    })
    // A failure is the API's JSON whatever was asked for; a success, what was asked for.
    if (!response.ok || reading === 'json') text = response.status === 204 ? '' : await response.text()
    else if (reading === 'blob') bytes = await response.blob()
    else await response.body?.cancel()
  } catch {
    // No answer: the browser's own words ("Failed to fetch") say nothing to the admin — lib/failure words what happened.
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
    // Every failure of PHP's — a controller's, a middleware's, the error handler's, the front controller's — has the one
    // shape {message, errors?, request_id}; anything else came from between the panel and PHP (a host's, a proxy's page).
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

  // A success that is not JSON came from something between the panel and PHP (a host's or a proxy's page).
  if (!readable) {
    throw new ApiError(response.status, `HTTP ${response.status} that is not JSON`, {}, { endpoint, requestId, foreign: true })
  }

  return payload as T
}

/** A `Retry-After`, in seconds — as a number of them, or as the moment to try again. */
function retryAfterOf(response: Response): number | null {
  const value = response.headers.get('Retry-After')
  if (value === null || value.trim() === '') return null
  const seconds = Number(value)
  const until = Number.isFinite(seconds) ? seconds * 1000 : Date.parse(value) - Date.now()

  return Number.isNaN(until) ? null : Math.max(0, Math.ceil(until / 1000))
}

/** A write of an API as lib/api-types lists its operations (PanelWrite, InstallWrite). */
interface Write {
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** Its path under the API's address, a parameter's place the type of its value: `/plans/${number}`. */
  path: string
  /** What it takes: `undefined` for none. */
  body: unknown
  /** What it answers: `void` for nothing. */
  answer: unknown
}

type Method = Write['method']

/** The paths an API takes `method` at. */
type PathOf<W extends Write, M extends Method> = Extract<W, { method: M }>['path']

/** The write a path is: the operation whose path it fits — every one's, for a path that stands for several (`/orders/${number}/${action}`). */
type WriteAt<W extends Write, M extends Method, P extends string> = W extends { method: M; path: infer Pattern } ? (P extends Pattern ? W : never) : never

type BodyOf<W extends Write, M extends Method, P extends string> = WriteAt<W, M, P>['body']
type AnswerOf<W extends Write, M extends Method, P extends string> = WriteAt<W, M, P>['answer']

/** The keys a body may have: any of its schema's — every member's of a oneOf. */
type KeysOf<T> = T extends unknown ? keyof T : never

/**
 * A body holding nothing its write does not read: the description's objects are closed, so a field the server does not
 * take is a mistake — a compile error here, whether the body is written out or a form's values handed over whole.
 */
type Exact<B, Body> = B & { [K in Exclude<keyof B, KeysOf<Body>>]: never }

/**
 * What a write takes after its path: nothing when it takes no body; else its body — optional where the path stands for
 * several writes and one of them takes none — and the request's options.
 */
type BodyArgs<Body, B> = [Body] extends [undefined] ? [] : undefined extends Body ? [body?: Exact<B, NonNullable<Body>>, options?: RequestOptions] : [body: Exact<B, Body>, options?: RequestOptions]

/**
 * An API from `base`, its writes typed by its operations (`W`: lib/api-types' PanelWrite, InstallWrite): a write's path
 * picks the body it takes — a field the operation does not read, one it needs left out, a body to a write that takes
 * none: each a compile error — and its answer. A body with a file goes as multipart (an upload), any other as JSON. A
 * read names its answer; a file's address is probed for being there (a picture the browser could not draw), or read
 * as its bytes. `headers` go with every request (asked each time — the installer's key can change).
 */
export function apiClient<W extends Write = never>(base: string, headers: () => Record<string, string> = () => ({})) {
  const write =
    <M extends Exclude<Method, 'DELETE'>>(method: M) =>
    <P extends PathOf<W, M>, B extends BodyOf<W, M, P>>(path: P, ...args: BodyArgs<BodyOf<W, M, P>, B>) =>
      request<AnswerOf<W, M, P>>(`${base}${path}`, method, headers(), ...args)

  return {
    /** A read; `query` (a list's search, filters and page), when it holds anything, goes after a `?`. */
    get: <T>(path: string, query?: URLSearchParams) => request<T>(withQuery(`${base}${path}`, query), 'GET', headers()),
    /** Whether the file at `path` is there: resolved when the address answers with it, else the failure the API words. */
    probe: (path: string) => request<void>(`${base}${path}`, 'GET', headers(), undefined, {}, 'none'),
    /** The bytes of the file at `path`, else the failure the API words. */
    blob: (path: string) => request<Blob>(`${base}${path}`, 'GET', headers(), undefined, {}, 'blob'),
    post: write('POST'),
    put: write('PUT'),
    patch: write('PATCH'),
    delete: <P extends PathOf<W, 'DELETE'>>(path: P) => request<AnswerOf<W, 'DELETE', P>>(`${base}${path}`, 'DELETE', headers()),
  }
}

/** What every request of the owner's panel carries: the shop its tab shows (lib/config). An agent's panel names none. */
const shopHeaders: Record<string, string> = appConfig.shop === null ? {} : { 'X-Shop': String(appConfig.shop) }

/**
 * This panel's API — /api/admin for the owner's, /api/agent for an agent's —, session-authenticated, every request in
 * the shop the tab shows.
 */
export const api = apiClient<PanelWrite>(appConfig.apiBase, () => shopHeaders)

/**
 * The address of a file of this panel's API that the browser asks for itself — an <img>, a <video>, a download link —,
 * none of which carries a header: the shop the tab shows goes in its query (`shop`), beside the address's own.
 */
export function mediaUrl(path: string, query: Record<string, string> = {}): string {
  return withQuery(`${appConfig.apiBase}${path}`, new URLSearchParams({ ...query, ...(appConfig.shop === null ? {} : { shop: String(appConfig.shop) }) }))
}

/**
 * An address and, when it holds anything, its query after a `?` — read off the query's text: `URLSearchParams.size` is
 * younger than browsers the build still serves (Safari 16.4, Chrome 111), where it would drop the query unseen.
 */
function withQuery(address: string, query: URLSearchParams | undefined): string {
  const search = query?.toString() ?? ''

  return search === '' ? address : `${address}?${search}`
}

/** The rest of the API, from its root: /app (the shop's name, whether it is installed) — reads only. The installer has its own (its key). */
export const rootApi = apiClient(appConfig.apiRoot)
