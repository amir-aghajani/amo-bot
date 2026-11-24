import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AGENT_ROUTES } from '@/apps/agent/app'
import type { BotTextsResponse, CustomEmojisResponse, Session } from '@/lib/api-types'
import { BotTextsPage } from '@/pages/bot-texts'
import { signedIn } from '@/test/render'
import { pageAt } from '@/test/routes'
import { premiumTextsResponse } from '@/test/rows'
import { json, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * An agent's «متن‌های ربات» (pages/bot-texts, the owner's page): «ایموجی پرمیوم متن‌ها» on it too, read and checked through
 * their panel's API — their bot's shop, naming none —, the check's message sent by their bot to them.
 */

const AGENT: Session = { name: '@reza_shop_bot', shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active', selling_suspended: null } }

describe('an agent’s bot texts', () => {
  it('are a page of their panel', () => {
    expect(pageAt(AGENT_ROUTES, '/bot-texts')).toBe('bot-texts')
  })

  it('have the premium emoji card, read and checked through their panel’s API', async () => {
    server()
      .on('GET', '/api/agent/auth/me', json({ session: AGENT }))
      .on('GET', '/api/agent/bot/texts', json({ groups: [] } satisfies BotTextsResponse))
      .on('GET', '/api/agent/bot/premium-texts', json(premiumTextsResponse({ enabled: false, can_enable: false, status: null })))
      .on('GET', '/api/agent/bot/custom-emojis', json({ emojis: [], status: null } satisfies CustomEmojisResponse))
      .on('POST', '/api/agent/bot/premium-texts/check', json(premiumTextsResponse({ enabled: false })))
    render(<BotTextsPage />, { wrapper: signedIn({ session: AGENT, at: '/bot-texts' }).wrapper })

    expect(await screen.findByText('ایموجی پرمیوم متن‌ها')).toBeTruthy()
    expect((await screen.findByRole('switch', { name: 'ایموجی پرمیوم متن‌ها روشن باشد' })).getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(screen.getByRole('button', { name: 'بررسی دوباره' }))

    expect(await screen.findByText('ربات می‌تواند ایموجی پرمیوم بفرستد')).toBeTruthy()
    expect(server().sent('POST', '/api/agent/bot/premium-texts/check')[0]?.headers['x-shop']).toBeUndefined()
  })
})
