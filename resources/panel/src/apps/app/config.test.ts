import { describe, expect, it, vi } from 'vitest'

/*
 * Which shop the mini app is and where its API lives (apps/app/config), read off the page's address: the shop's key
 * after the app's folder — wherever the shop is installed —, the Store API beside the app, the router under both. An
 * address with a slash too many at its start ("//app/…") is read as the one it means: on this host, never another.
 */

const KEY = '0123456789abcdef01234567'

/** The app's address read afresh, the page at `path` on this host. */
async function at(path: string) {
  window.history.replaceState(null, '', `${window.location.origin}${path}`)
  vi.resetModules()

  return import('@/apps/app/config')
}

describe('the mini app’s address', () => {
  it('names the shop by its key, the Store API beside the app and the router under both', async () => {
    const config = await at(`/app/${KEY}/services/9`)

    expect(config.storeKey).toBe(KEY)
    expect(config.storeBase).toBe(`/api/store/v1/${KEY}`)
    expect(config.routerBasename).toBe(`/app/${KEY}`)
    expect(config.appFolder).toBe('/app')
  })

  it('holds under a sub-folder install', async () => {
    const config = await at(`/shop/app/${KEY}/`)

    expect(config.storeBase).toBe(`/shop/api/store/v1/${KEY}`)
    expect(config.routerBasename).toBe(`/shop/app/${KEY}`)
  })

  it('keeps the API and the router on this host for an address with a slash too many, and puts the page at the one it means', async () => {
    const config = await at(`//app/${KEY}/services?from=bot#tgWebAppVersion=8.0`)

    expect(config.storeBase).toBe(`/api/store/v1/${KEY}`)
    expect(new URL(config.storeBase, window.location.href).host).toBe(window.location.host)
    expect(config.routerBasename).toBe(`/app/${KEY}`)
    expect(config.appFolder).toBe('/app')
    expect(window.location.pathname).toBe(`/app/${KEY}/services`)
    expect(window.location.search).toBe('?from=bot')
    expect(window.location.hash).toBe('#tgWebAppVersion=8.0')
  })
})
