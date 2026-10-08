import { contractProblem } from '@/test/contract'

/*
 * The PHP server as the panels' tests play it: every test gets a fresh one in place of `fetch` (src/test/setup.ts), so
 * nothing ever goes out. A test says what a route answers (`on`) — an answer, or a function of the request —, holds a
 * route's requests until it answers them (`hold`), and reads what was sent (`requests`, `sent`). A request to a route
 * the test gave no answer for fails the test, and so does one the API description does not take (src/test/contract: its
 * operation, its parameters, its body) — as the PHP tests hold theirs to it; `unchecked()` lets one through.
 */

/** A request as the server received it. */
export interface SentRequest {
  method: string
  /** The address without the origin and the query: "/api/admin/plans". */
  path: string
  query: URLSearchParams
  /** Header names in lower case. */
  headers: Record<string, string>
  /** A JSON body parsed, a FormData as sent; undefined without one. */
  body: unknown
  credentials: RequestCredentials | undefined
}

/** What the server does with one request: answers it (with headers of its own besides the type), or gives no answer at all. */
export type Answer = { kind: 'response'; status: number; body: string | null; type: string; headers?: Record<string, string> } | { kind: 'offline' } | { kind: 'silent' }

type Handler = Answer | ((request: SentRequest) => Answer | Promise<Answer>)

/** A JSON answer (a 2xx by default). */
export function json(body: unknown, status = 200): Answer {
  return { kind: 'response', status, body: JSON.stringify(body), type: 'application/json' }
}

/** A failure in the API's one shape, `{message, errors?}` (ErrorResponse). */
export function refusal(status: number, message: string, errors?: Record<string, string[]>): Answer {
  return json(errors === undefined ? { message } : { message, errors }, status)
}

/** A page instead of the API's JSON — what a host or a proxy between the panel and PHP answers. */
export function html(body: string, status = 200): Answer {
  return { kind: 'response', status, body, type: 'text/html' }
}

export const noContent: Answer = { kind: 'response', status: 204, body: null, type: 'application/json' }

/** The same answer with these headers too: a request's `X-Request-Id`, a 429's `Retry-After`. */
export function withHeaders(answer: Answer, headers: Record<string, string>): Answer {
  return answer.kind === 'response' ? { ...answer, headers: { ...answer.headers, ...headers } } : answer
}

/** No answer: the network failed (the browser rejects the request). */
export const offline: Answer = { kind: 'offline' }

/** No answer until the request is given up (aborted): a connection that stalled. */
export const silent: Answer = { kind: 'silent' }

/** A route whose requests wait for the test to answer them, in the order they came. */
export interface Held {
  /** How many requests wait now. */
  readonly waiting: number
  answer: (answer: Answer) => void
}

export class FakeServer {
  /** Every request, in the order sent. */
  readonly requests: SentRequest[] = []
  /** The requests no route answered: the test fails with them. */
  readonly unexpected: SentRequest[] = []
  /** What is wrong, by the API description, with the requests sent — and with those let through unchecked that it takes: the test fails with them. */
  readonly drifts: string[] = []
  private readonly routes = new Map<string, Handler>()
  /** The routes whose next request goes in unchecked (`unchecked()`), one mark a request. */
  private readonly loose: string[] = []

  /** What `method path` answers from now on (the last word on a route stands). */
  on(method: string, path: string, handler: Handler): this {
    this.routes.set(`${method} ${path}`, handler)
    return this
  }

  /**
   * Let the next request to `method path` go in as it is, not held to the API description — for a test whose point is a
   * request no screen sends, and the server's refusal of it (as HttpTestCase's unchecked()). One the description does
   * take fails the test, so the call never outlives its reason — and so does a mark no request used.
   */
  unchecked(method: string, path: string): this {
    this.loose.push(`${method} ${path}`)
    return this
  }

  /** Every way the test's requests left the API description, the marks of `unchecked()` no request used among them. */
  contractFailures(): string[] {
    return [...this.drifts, ...this.loose.map((route) => `${route}: marked unchecked(), but no such request was sent`)]
  }

  hold(method: string, path: string): Held {
    const waiting: ((answer: Answer) => void)[] = []
    this.on(method, path, () => new Promise<Answer>((resolve) => waiting.push(resolve)))
    return {
      get waiting() {
        return waiting.length
      },
      answer: (answer) => {
        const next = waiting.shift()
        if (next === undefined) throw new Error(`No request to ${method} ${path} is waiting.`)
        next(answer)
      },
    }
  }

  /** The requests sent to `method path`. */
  sent(method: string, path: string): SentRequest[] {
    return this.requests.filter((request) => request.method === method && request.path === path)
  }

  /** A request held to the API description — or, marked unchecked, held to not matching it. */
  private checkContract(request: SentRequest): void {
    const route = `${request.method} ${request.path}`
    const problem = contractProblem(request)
    const mark = this.loose.indexOf(route)
    if (mark === -1) {
      if (problem !== null) this.drifts.push(problem)
      return
    }
    this.loose.splice(mark, 1)
    if (problem === null) this.drifts.push(`${route}: sent unchecked(), but the API description takes it — send it checked`)
  }

  readonly fetch = async (input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> => {
    const url = new URL(input instanceof Request ? input.url : String(input), window.location.href)
    const request: SentRequest = {
      method: (init.method ?? 'GET').toUpperCase(),
      path: url.pathname,
      query: url.searchParams,
      headers: Object.fromEntries([...new Headers(init.headers).entries()].map(([name, value]) => [name.toLowerCase(), value])),
      body: typeof init.body === 'string' ? JSON.parse(init.body) : (init.body ?? undefined),
      credentials: init.credentials,
    }
    this.requests.push(request)
    this.checkContract(request)

    const handler = this.routes.get(`${request.method} ${request.path}`)
    if (handler === undefined) {
      this.unexpected.push(request)
      throw new TypeError(`The test's server has no answer for ${request.method} ${request.path}.`)
    }

    const answer = await untilAborted(typeof handler === 'function' ? handler(request) : handler, init.signal)
    if (answer.kind === 'offline') throw new TypeError('Failed to fetch')
    if (answer.kind === 'silent') return untilAborted(new Promise<never>(() => {}), init.signal)
    return new Response(answer.body, { status: answer.status, headers: { 'Content-Type': answer.type, ...answer.headers } })
  }
}

/** Settles with `answer`, or rejects as the browser does once the request is aborted (a timeout). */
function untilAborted<T>(answer: T | Promise<T>, signal: AbortSignal | null | undefined): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const abort = () => reject(new DOMException('The operation was aborted.', 'AbortError'))
    if (signal?.aborted) return abort()
    signal?.addEventListener('abort', abort, { once: true })
    Promise.resolve(answer)
      .then(resolve, reject)
      .finally(() => signal?.removeEventListener('abort', abort))
  })
}

let current = new FakeServer()

/** A fresh server for the next test (src/test/setup.ts). */
export function startServer(): FakeServer {
  current = new FakeServer()
  return current
}

/** The test's server. */
export function server(): FakeServer {
  return current
}
