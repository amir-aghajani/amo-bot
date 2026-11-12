/*
 * The mini app's address in its tests, in place of apps/app/config (which reads the page's): a test file mocks it with
 * `vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))`, so every request goes to the Store API under
 * KEY, which the API description takes.
 */

const KEY = '0123456789abcdef01234567'

export const storeKey: string | null = KEY

export const storeBase = `/api/store/v1/${KEY}`

export const appFolder = '/app'

export const routerBasename = ''

export const appBuild = 'test'
