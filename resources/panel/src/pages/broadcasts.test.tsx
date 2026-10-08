import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { BroadcastsResponse } from '@/lib/api-types'
import { BroadcastsPage, type BroadcastsSection } from '@/pages/broadcasts'
import { signedIn } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * «ارسال همگانی» (pages/broadcasts): a page of the messages alone — an agent's — is titled by its own name, as its menu,
 * its topbar and its tab call it; one with more sections beside the messages — the owner's — by the section on screen.
 */

const GIFTS: BroadcastsSection = { section: { value: 'gifts', title: 'هدیه همگانی', to: '/broadcasts/gifts' }, description: 'هدیه به همه سرویس‌ها.', content: <p>هدیه</p> }

/** The page at /broadcasts, the sections `more` beside its messages. */
function open(more?: BroadcastsSection[]) {
  server().on('GET', '/api/admin/broadcasts', json({ broadcasts: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } } satisfies BroadcastsResponse))
  render(<BroadcastsPage more={more} />, { wrapper: signedIn({ at: '/broadcasts' }).wrapper })
}

describe('the broadcasts page', () => {
  it('of the messages alone is titled by its own name', async () => {
    open()

    expect((await screen.findByRole('heading', { level: 1 })).textContent).toBe('ارسال همگانی')
  })

  it('with sections beside the messages is titled by the section on screen', async () => {
    open([GIFTS])

    expect((await screen.findByRole('heading', { level: 1 })).textContent).toBe('پیام همگانی')
  })
})
