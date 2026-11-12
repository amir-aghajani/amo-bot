import { describe, expect, it } from 'vitest'
import { pagePolicy } from '../../../../../vite.config'

/*
 * The built pages' Content-Security-Policy (vite.config.ts's pagePolicy): the mini app's lets the customer's Telegram
 * picture load from t.me (its account card); the panels' loads pictures from the site alone.
 */

const PAGE = '<html><head><meta charset="utf-8" /><script>document.write("")</script></head></html>'

/** The policy's `directive`, as written. */
const directive = (policy: string, name: string) => policy.split('; ').find((part) => part.startsWith(`${name} `))

describe('the page policy', () => {
  it('lets the mini app show the Telegram picture from t.me', () => {
    expect(directive(pagePolicy(true, PAGE), 'img-src')).toBe("img-src 'self' data: blob: https://t.me")
  })

  it('keeps the panels’ pictures to the site', () => {
    expect(directive(pagePolicy(false, PAGE), 'img-src')).toBe("img-src 'self' data: blob:")
  })

  it('allows the page’s inline script by its hash, and nothing else from elsewhere', () => {
    const policy = pagePolicy(true, PAGE)

    expect(directive(policy, 'script-src')).toMatch(/^script-src 'self' 'sha256-[A-Za-z0-9+/]+=*'$/)
    expect(directive(policy, 'default-src')).toBe("default-src 'self'")
    expect(directive(policy, 'connect-src')).toBe("connect-src 'self'")
  })
})
