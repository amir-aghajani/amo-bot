import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import { signedIn } from '@/test/render'
import { subscriptionRow } from '@/test/rows'

/*
 * A row's detail dialog when the row was deleted meanwhile, elsewhere (components/detail-modal): it keeps what it last
 * showed, says the row no longer exists, and offers nothing more to do to it — every operation withdrawn.
 */

const SERVICE = subscriptionRow()

/** A service's dialog — a detail dialog — in a signed-in panel, its row deleted meanwhile or not. */
function show(gone: boolean, subscription = SERVICE) {
  const props = { onClose: vi.fn(), onChanged: vi.fn(), onDeleted: vi.fn(), onStale: vi.fn(), onMove: vi.fn() }
  render(<SubscriptionModal subscription={subscription} gone={gone} {...props} />, { wrapper: signedIn().wrapper })
}

describe('the way to the customer', () => {
  it('is a chat in Telegram — none for a customer who has no Telegram account', async () => {
    show(false)
    expect((await screen.findByRole('link', { name: 'گفتگو در تلگرام' })).getAttribute('href')).toBe('https://t.me/amir')
  })

  it('is no chat for a customer who signed up on the website', async () => {
    show(false, subscriptionRow({ user: { id: 11, name: 'سارا', username: null, telegram_id: null, email: 'sara@example.com' } }))

    expect(await screen.findByText('sara@example.com')).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'گفتگو در تلگرام' })).toBeNull()
  })
})

describe('a row deleted meanwhile', () => {
  it('stays on screen as it was, says it is gone, and offers no operation', async () => {
    show(true)

    expect(await screen.findByText(/این مورد دیگر وجود ندارد/)).toBeTruthy()
    expect(screen.getByText(SERVICE.link ?? '')).toBeTruthy()
    expect(screen.queryByRole('region', { name: 'عملیات' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'حذف سرویس' })).toBeNull()
  })

  it('is not a row that is there: its operations are offered', async () => {
    show(false)

    expect(await screen.findByRole('button', { name: 'حذف سرویس' })).toBeTruthy()
    expect(screen.queryByText(/این مورد دیگر وجود ندارد/)).toBeNull()
  })
})
