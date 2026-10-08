import { afterEach, describe, expect, it, vi } from 'vitest'
import { pageOf, routePath } from '@/lib/config'
import { STORAGE_KEYS } from '@/lib/storage'
import adminPage from '../../admin/index.html?raw'
import agentPage from '../../agent/index.html?raw'

/*
 * One build serves any install and any deep link: each panel's index.html sets the page's base to the panel's folder
 * before anything loads, and lib/config reads the router's basename and the API's addresses off that base — and, on the
 * owner's panel, the shop the tab shows off its address. These tests run the pages' own inline script at an address,
 * then read lib/config afresh.
 */

type Panel = 'admin' | 'agent'

/** The inline script of a panel's index.html, as the browser runs it before anything else. */
function inlineScript(panel: Panel): string {
  const script = /<script>([\s\S]*?)<\/script>/.exec(panel === 'admin' ? adminPage : agentPage)?.[1]
  if (script === undefined) throw new Error(`${panel}/index.html has no inline script.`)
  return script
}

/** The panel's page opened at `address`: its inline script run, lib/config read as that page reads it. */
async function open(panel: Panel, address: string) {
  document.head.querySelectorAll('base, meta[name="theme-color"]').forEach((element) => element.remove())
  window.history.replaceState(null, '', address)
  const themeColor = document.createElement('meta')
  themeColor.name = 'theme-color'
  document.head.append(themeColor)
  new Function(inlineScript(panel))()
  vi.resetModules()
  return { ...(await import('@/lib/config')), themeColor: themeColor.content }
}

afterEach(() => {
  document.head.querySelectorAll('meta[name="theme-color"]').forEach((element) => element.remove())
  document.documentElement.classList.remove('dark')
  document.documentElement.style.colorScheme = ''
})

describe('a panel’s address', () => {
  it('makes the owner’s panel at the site’s root talk to /api/admin, in the main shop', async () => {
    const { appConfig, routerBasename } = await open('admin', '/admin/')

    expect(document.baseURI).toBe('http://localhost/admin/')
    expect(routerBasename).toBe('/admin')
    expect(appConfig).toEqual({ basePath: '', apiBase: '/api/admin', apiRoot: '/api', shop: 1 })
  })

  it('finds the panel’s folder from a deep link of it, with or without a trailing slash', async () => {
    expect((await open('admin', '/admin/users/groups')).routerBasename).toBe('/admin')
    expect(document.baseURI).toBe('http://localhost/admin/')

    expect((await open('admin', '/admin')).routerBasename).toBe('/admin')
    expect(document.baseURI).toBe('http://localhost/admin/')
  })

  it('keeps a sub-folder install’s prefix for the router and the API', async () => {
    const { appConfig, routerBasename } = await open('admin', '/shop/admin/subscriptions?status=active')

    expect(document.baseURI).toBe('http://localhost/shop/admin/')
    expect(routerBasename).toBe('/shop/admin')
    expect(appConfig).toEqual({ basePath: '/shop', apiBase: '/shop/api/admin', apiRoot: '/shop/api', shop: 1 })
  })

  it('makes an agent’s panel talk to /api/agent, at the root and in a sub-folder — naming no shop: theirs is their bot', async () => {
    const atRoot = await open('agent', '/agent/account')
    expect(atRoot.routerBasename).toBe('/agent')
    expect(atRoot.appConfig).toEqual({ basePath: '', apiBase: '/api/agent', apiRoot: '/api', shop: null })

    const inFolder = await open('agent', '/vpn/shop/agent/login')
    expect(inFolder.routerBasename).toBe('/vpn/shop/agent')
    expect(inFolder.appConfig).toEqual({ basePath: '/vpn/shop', apiBase: '/vpn/shop/api/agent', apiRoot: '/vpn/shop/api', shop: null })

    expect((await open('agent', '/agent/s/7/plans')).appConfig.shop).toBeNull()
  })

  it('never reads a doubled leading slash as another host', async () => {
    const { routerBasename } = await open('admin', 'http://localhost//admin/users')

    expect(document.baseURI).toBe('http://localhost/admin/')
    expect(routerBasename).toBe('/admin')
  })
})

describe('the shop an owner’s tab shows', () => {
  it('is the one its address names: the router under its place, the files beside the panel’s page as for any address', async () => {
    const page = await open('admin', '/shop/admin/s/7/payments?status=awaiting_review')

    expect(document.baseURI).toBe('http://localhost/shop/admin/')
    expect(page.routerBasename).toBe('/shop/admin/s/7')
    expect(page.appConfig.shop).toBe(7)
    expect(page.routePath('/shop/admin/s/7/users/12')).toBe('/users/12')
  })

  it('is the main bot’s at its bare address — the main shop’s own place in it taken off, nothing else of the address', async () => {
    const { appConfig, routerBasename } = await open('admin', '/admin/s/1/orders?status=stuck#top')

    expect([appConfig.shop, routerBasename]).toEqual([1, '/admin'])
    expect(window.location.pathname + window.location.search + window.location.hash).toBe('/admin/orders?status=stuck#top')

    await open('admin', '/admin/s/1')
    expect(window.location.pathname).toBe('/admin/')
  })

  it('is the main bot’s when the address names no shop’s number — a page of the panel’s, then, no page has', async () => {
    for (const address of ['/admin/s/0/orders', '/admin/s/abc/orders', '/admin/s/', '/admin/shops']) {
      const { appConfig, routerBasename } = await open('admin', address)
      expect([appConfig.shop, routerBasename], address).toEqual([1, '/admin'])
    }
  })

  it('opens at its dashboard: the main bot’s at the panel’s own address', async () => {
    const { shopHome } = await open('admin', '/shop/admin/s/7/payments')

    expect(shopHome(7)).toBe('/shop/admin/s/7/')
    expect(shopHome(1)).toBe('/shop/admin/')
  })
})

describe('the theme before the first paint', () => {
  it('is dark unless the admin chose light — the key lib/theme keeps it under', async () => {
    const dark = await open('admin', '/admin/')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.documentElement.style.colorScheme).toBe('dark')
    expect(dark.themeColor).toBe('#151515')

    localStorage.setItem(STORAGE_KEYS.theme, 'light')
    const light = await open('agent', '/agent/')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
    expect(document.documentElement.style.colorScheme).toBe('light')
    expect(light.themeColor).toBe('#fcfcfb')
  })
})

describe('reading a router address', () => {
  it('takes the panel’s folder off a link’s address (the suite runs at /admin/)', () => {
    expect(routePath('/admin/users/groups')).toBe('/users/groups')
    expect(routePath('/admin')).toBe('/')
    expect(routePath('/agent/plans')).toBe('/agent/plans')
  })

  it('names the page an address is on by its first segment: a page’s sections are one page', () => {
    expect(pageOf('/bot-settings/general')).toBe('/bot-settings')
    expect(pageOf('/users/groups')).toBe('/users')
    expect(pageOf('/users')).toBe('/users')
    expect(pageOf('/')).toBe('/')
  })

  it('names one subject’s page — a row of a list by its number — a page of its own', () => {
    expect(pageOf('/users/12')).toBe('/users/12')
    expect(pageOf('/servers/4')).toBe('/servers/4')
    expect(pageOf('/servers/4/grants')).toBe('/servers/4')
  })
})
