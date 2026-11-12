import { storeBase } from '@/apps/app/config'
import { inPreview } from '@/apps/app/telegram/webapp'
import { apiClient, ApiError } from '@/lib/api'
import type { StoreWrite } from '@/lib/api-types'
import { isStaleBuildError } from '@/lib/stale-build'

/*
 * The mini app's only way to the shop: the Store API under the shop's store key, typed by the API description
 * (StoreWrite), with the customer's bearer token on every request once they are signed in (lib/session keeps it). Every
 * failure is an ApiError; its message is already in the customer's words.
 */

let bearer: string | null = null

/** The token the requests carry from now on (null: none — before the sign-in). */
export function setBearer(token: string | null): void {
  bearer = token
}

/** The Store API, as the signed-in customer. */
export const store = apiClient<StoreWrite>(storeBase, (): Record<string, string> => (bearer === null ? {} : { Authorization: `Bearer ${bearer}` }))

/** A picture the API answers to the customer alone (a service's QR card, a ticket's picture), as an address an <img> shows. */
export async function pictureUrl(path: string): Promise<string> {
  return URL.createObjectURL(await store.blob(path))
}

/** The customer's session ended (or never was): a 401 the API answers with `WWW-Authenticate: Bearer`. */
export function signedOut(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401 && /^Bearer\b/i.test(error.facts.challenge ?? '')
}

/**
 * What the request needs first is a recent sign-in: the 403 whose `WWW-Authenticate` asks for one. Never in a panel's
 * preview, which is no sign-in and can prove none: its refusal stands, in the shop's words.
 */
export function needsRecentSignIn(error: unknown): boolean {
  return !inPreview() && error instanceof ApiError && error.status === 403 && /insufficient_user_authentication/.test(error.facts.challenge ?? '')
}

/** A new key for an ordering request (`Idempotency-Key`): the same key again is answered by the order it made. */
export function requestKey(): string {
  return crypto.randomUUID()
}

const OFFLINE = 'اینترنت وصل نیست؛ اتصال را بررسی کنید و دوباره تلاش کنید.'

/**
 * What to tell the customer of a failure: the API's own words for a refusal, a word on the connection when no answer came,
 * and the request's id («کد پیگیری») beside a server failure so support can find it.
 */
export function problemOf(error: unknown): string {
  // lib/recent's RecentSignInNeeded is worded for the customer already.
  if (error instanceof Error && error.name === 'RecentSignInNeeded') return error.message
  // A screen's file that did not load, its one reload for a new build already behind it (main.tsx): the network, or an
  // update still being put on the host.
  if (isStaleBuildError(error)) return navigator.onLine === false ? OFFLINE : 'این صفحه باز نشد؛ اگر فروشگاه همین الان به‌روز شده، چند دقیقه بعد دوباره امتحان کنید.'
  if (!(error instanceof ApiError)) return 'خطایی پیش آمد؛ دوباره تلاش کنید.'
  if (error.status === 0) return error.facts.offline ? OFFLINE : 'پاسخی از فروشگاه نرسید؛ کمی بعد دوباره تلاش کنید.'
  if (error.facts.foreign) return 'پاسخ فروشگاه خوانده نشد؛ کمی بعد دوباره تلاش کنید.'
  const id = error.facts.requestId
  return error.status >= 500 && id ? `${error.message} (کد پیگیری: ${id})` : error.message
}

/** The first refusal under a request field, for the input it is about. */
export function fieldProblem(error: unknown, field: string): string | undefined {
  return error instanceof ApiError ? error.field(field) : undefined
}
