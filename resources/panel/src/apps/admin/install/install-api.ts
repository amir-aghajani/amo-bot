import { apiClient, ApiError } from '@/lib/api'
import type { InstallStatus, InstallWrite } from '@/lib/api-types'
import { appConfig } from '@/lib/config'
import { forgetStored, readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'

/*
 * The installer's API (/api/install), open only until the shop is installed — and only to whoever holds the key the
 * first request wrote to storage/install-key.txt on the host: every request carries it, `X-Install-Key`. The owner reads
 * the file in the host's file manager and types it once; the tab keeps it until the installation ends.
 */

export const installApi = apiClient<InstallWrite>(`${appConfig.apiRoot}/install`, () => ({ 'X-Install-Key': readStored(STORAGE_KEYS.installKey, 'session') ?? '' }))

export const readStatus = () => installApi.get<InstallStatus>('')

export const keepKey = (key: string) => writeStored(STORAGE_KEYS.installKey, key.trim(), 'session')

/** Whether a key was typed in this tab (a refusal is then about that one). */
export const hasKey = () => (readStored(STORAGE_KEYS.installKey, 'session') ?? '') !== ''

/** The installation ended: the key is gone from the host too. */
export const forgetKey = () => forgetStored(STORAGE_KEYS.installKey, 'session')

/** What the server says about the key when a request came without it, or with the wrong one; undefined for any other failure. */
export function keyRefusal(error: unknown): string | undefined {
  return error instanceof ApiError && error.status === 403 ? error.field('key') : undefined
}
