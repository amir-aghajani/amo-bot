import fs from 'node:fs'
import path from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/*
 * The bridge to a VPN app (resources/panel/app/open.html, opened by screens/services/parts/connect): anyone can link to
 * it, so it opens no app by itself. It shows the host of the subscription link it would hand over and opens the app from
 * a tap on its button alone; an address of another scheme, or with no plain http(s) link in it, opens nothing.
 */

const page = fs.readFileSync(path.join(import.meta.dirname, '../../../app/open.html'), 'utf8')
const markup = /<main>[\s\S]*<\/main>/.exec(page)![0]
const script = /<script>([\s\S]*?)<\/script>/.exec(page)![1]!

/** The page opened at `#to=…&name=…`, its script run. */
function open(to: string, name = '') {
  window.history.replaceState(null, '', `/app/open.html#${new URLSearchParams({ to, name }).toString()}`)
  document.body.innerHTML = markup
  // The page's own inline script, run as the browser runs it.
  new Function(script)()
}

const title = () => document.getElementById('title')!.textContent
const button = () => document.getElementById('open') as HTMLAnchorElement
const host = () => (document.getElementById('from')!.hidden ? null : document.getElementById('host')!.textContent)

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('the bridge page', () => {
  it('shows whose subscription it is and opens the app only from its button', () => {
    const to = 'happ://add/https://sub.example.com:2096/sub/abc'
    open(to, 'Happ')

    expect(title()).toBe('افزودن سرویس به Happ')
    expect(host()).toBe('sub.example.com:2096')
    expect(button().hidden).toBe(false)
    expect(button().getAttribute('href')).toBe(to)
    expect(button().textContent).toBe('باز کردن Happ')
    // Nothing is opened by itself, now or later.
    expect(vi.getTimerCount()).toBe(0)
    vi.runAllTimers()
    expect(window.location.pathname).toBe('/app/open.html')
  })

  it('reads v2rayNG’s link off its url parameter, and the others’ after their command', () => {
    open(`v2rayng://install-sub?url=${encodeURIComponent('https://panel.example.net/sub/abc')}#${encodeURIComponent('سرویس من')}`, 'v2rayNG')
    expect(host()).toBe('panel.example.net')

    open('hiddify://import/https://panel.example.org/sub/abc#name', 'Hiddify')
    expect(host()).toBe('panel.example.org')

    open('streisand://import/http://10.0.0.1:8080/sub', 'Streisand')
    expect(host()).toBe('10.0.0.1:8080')
  })

  it('opens nothing for another scheme', () => {
    open('javascript:alert(1)', 'Happ')

    expect(title()).toBe('این لینک برنامه‌ای را باز نمی‌کند.')
    expect(button().hidden).toBe(true)
    expect(host()).toBeNull()
  })

  it.each([
    ['no web link in it', 'happ://add/ftp://files.example.com/sub'],
    ['no link at all', 'hiddify://import/'],
    ['a name before its host', 'happ://add/https://shop.example.com@evil.example/sub'],
    ['a backslash that reads two ways', 'happ://add/https://shop.example.com\\@evil.example/sub'],
    ['v2rayNG with no url parameter', 'v2rayng://install-sub/https://panel.example.net/sub'],
  ])('opens nothing for an address with %s', (_case, to) => {
    open(to, 'Happ')

    expect(title()).toBe('این لینک برنامه‌ای را باز نمی‌کند.')
    expect(button().hidden).toBe(true)
    expect(host()).toBeNull()
  })

  it('writes the app’s name as text', () => {
    open('happ://add/https://sub.example.com/sub', '<img src=x onerror=alert(1)>')

    expect(document.querySelector('img')).toBeNull()
    expect(title()).toContain('<img')
  })
})
