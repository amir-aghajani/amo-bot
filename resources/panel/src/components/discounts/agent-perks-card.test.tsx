import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AgentPerksCard } from '@/components/discounts/agent-perks-card'
import type * as Config from '@/lib/config'
import { providers } from '@/test/render'
import { botSettingsRow } from '@/test/rows'

/*
 * «همکارها فقط با قیمت همکاری بخرند»'s card (components/discounts/agent-perks-card): in the main shop it speaks of the
 * agents and the «همکار» groups; in an agent's shop, which has no agents, of the «همکار» groups alone. Either way, the
 * regular price where no agent price applies.
 */

const config = vi.hoisted(() => ({ shop: 1 as number | null }))

vi.mock('@/lib/config', async (original) => {
  const actual = await original<typeof Config>()
  return {
    ...actual,
    appConfig: {
      ...actual.appConfig,
      get shop() {
        return config.shop
      },
    },
  }
})

afterEach(() => {
  config.shop = 1
})

function show() {
  render(<AgentPerksCard settings={botSettingsRow()} />, { wrapper: providers().wrapper })
}

describe('the agent perks card', () => {
  it('in the main shop, names the agents and the «همکار» groups', () => {
    show()

    expect(screen.getByText('همکارها')).toBeTruthy()
    expect(screen.getByText(/^نماینده‌ها و کاربرهای گروه‌های «همکار» قیمت نماینده پلن‌ها را می‌پردازند/)).toBeTruthy()
    expect(screen.getByText(/قیمت معمولی را می‌پردازند/).textContent).toContain('سرویس دلخواه')
  })

  it.each([
    ['an agent’s panel', null],
    ['the owner in an agent’s shop', 7],
  ])('in %s, names the «همکار» groups alone', (_, shop) => {
    config.shop = shop
    show()

    expect(screen.getByText('همکارها')).toBeTruthy()
    expect(screen.queryByText(/نماینده‌ها/)).toBeNull()
    expect(screen.getByText(/^کاربرهای گروه‌های «همکار» قیمت نماینده پلن‌ها را می‌پردازند/)).toBeTruthy()
    expect(screen.getByText(/قیمت معمولی را می‌پردازند/).textContent).not.toContain('سرویس دلخواه')
    expect(screen.getByRole('switch', { name: 'همکارها فقط با قیمت همکاری بخرند' })).toBeTruthy()
  })
})
