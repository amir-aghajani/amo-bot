import { describe, expect, it, vi } from 'vitest'
import { AGENT_ROUTES } from '@/apps/agent/app'
import { AGENT_NAV } from '@/apps/agent/nav'
import { navOfShop } from '@/components/shell/nav'
import { navAddresses, pageAt } from '@/test/routes'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * An agent's routes (apps/agent/app): every address their menu leads to is a page of their panel's, and none of the
 * owner's own pages — the servers, the agency, the main shop's custom builder, the mass gift — is one: those addresses
 * are the not-found page.
 */

describe('an agent’s menu', () => {
  it('leads to pages their panel has — its pages, their sections and the settings’, none to the not-found page', () => {
    // As the shell draws it: an agent's shop, without the main shop's own pages.
    const addresses = navAddresses(navOfShop(AGENT_NAV, false))

    expect(addresses).toContain('/account')
    for (const address of addresses) expect(pageAt(AGENT_ROUTES, address), address).not.toBe('*')
  })
})

describe('the owner’s own pages', () => {
  it('are none of an agent’s', () => {
    for (const address of ['/servers', '/servers/4', '/agents', '/agents/list', '/custom-services', '/broadcasts/gifts', '/install', '/recover']) {
      expect(pageAt(AGENT_ROUTES, address), address).toBe('*')
    }
  })
})
