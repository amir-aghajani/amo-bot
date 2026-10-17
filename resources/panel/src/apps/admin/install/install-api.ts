import { apiClient, ApiError } from '@/lib/api'
import type { InstallStatus, InstallWrite } from '@/lib/api-types'
import { appConfig } from '@/lib/config'
import { forgetStored, readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'

/*
 * The installer's API (/api/install), open only until the shop is installed — and only to whoever holds the key the
 * first request wrote to storage/install-key.txt on the host: every request carries it, `X-Install-Key`. The owner reads
 * the file in the host's file manager and types it once; the tab keeps it until the installation ends — in its session
 * storage, so a reload keeps it too, and in memory, so a browser that refuses storage still installs.
 */

/** The key as typed in this page; null until it is (then the tab's storage may still hold one from before a reload). */
let typed: string | null = null

const installKey = () => typed ?? readStored(STORAGE_KEYS.installKey, 'session') ?? ''

export const installApi = apiClient<InstallWrite>(`${appConfig.apiRoot}/install`, () => ({ 'X-Install-Key': installKey() }))

export const readStatus = () => installApi.get<InstallStatus>('')

export function keepKey(key: string): void {
  typed = key.trim()
  writeStored(STORAGE_KEYS.installKey, typed, 'session')
}

/** Whether a key was typed in this tab (a refusal is then about that one). */
export const hasKey = () => installKey() !== ''

/** The installation ended: the key is gone from the host too. */
export function forgetKey(): void {
  typed = null
  forgetStored(STORAGE_KEYS.installKey, 'session')
}

/** What the server says about the key when a request came without it, or with the wrong one; undefined for any other failure. */
export function keyRefusal(error: unknown): string | undefined {
  return error instanceof ApiError && error.status === 403 ? error.field('key') : undefined
}
