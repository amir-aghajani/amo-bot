import { describe, expect, it } from 'vitest'
import { MINI_APP_FRAME_ANCESTORS, MINI_APP_HTACCESS, pagePolicy } from '../../../../../../vite.config'

/*
 * The frame between a panel and the mini app's preview (vite.config.ts): a panel's built page may frame the site's own
 * pages — the mini app, beside it — and nothing else; the mini app's folder lets the site frame it, besides Telegram's
 * web client; the mini app frames nothing.
 */

const PAGE = '<html><head><meta charset="utf-8" /><script>document.write("")</script></head></html>'

/** The policy's `directive`, as written. */
const directive = (policy: string, name: string) => policy.split('; ').find((part) => part.startsWith(`${name} `))

describe('the frame policies', () => {
  it('let a panel frame the site’s own pages, and nothing else', () => {
    expect(directive(pagePolicy(false, PAGE), 'frame-src')).toBe("frame-src 'self'")
  })

  it('let the mini app frame nothing', () => {
    expect(directive(pagePolicy(true, PAGE), 'frame-src')).toBe("frame-src 'none'")
  })

  it('let the site and Telegram’s web client frame the mini app, and nobody else', () => {
    expect(MINI_APP_FRAME_ANCESTORS).toBe("frame-ancestors 'self' https://web.telegram.org")
    expect(MINI_APP_HTACCESS).toContain('Header unset X-Frame-Options')
    expect(MINI_APP_HTACCESS).toContain(`Header set Content-Security-Policy "${MINI_APP_FRAME_ANCESTORS}"`)
  })
})

/*
 * The mini app's folder says its own pages (its .htaccess, beside the frame rule): a server that does not carry
 * public/.htaccess's rules into a folder with an .htaccess of its own still answers app/{store key}/… with the app.
 */
describe('the mini app’s folder', () => {
  it('serves its files, a missing build file as a 404, and its page for any other address', () => {
    const rewriting = MINI_APP_HTACCESS.slice(MINI_APP_HTACCESS.indexOf('<IfModule mod_rewrite.c>'))

    expect(rewriting.split('\n').map((line) => line.trim())).toEqual([
      '<IfModule mod_rewrite.c>',
      'RewriteEngine On',
      'RewriteCond %{REQUEST_FILENAME} !-f',
      'RewriteRule ^assets/ - [R=404,L]',
      'RewriteCond %{REQUEST_FILENAME} !-f',
      'RewriteRule ^ index.html [L]',
      '</IfModule>',
      '',
    ])
  })
})
