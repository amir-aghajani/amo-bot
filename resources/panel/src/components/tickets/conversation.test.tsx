import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Conversation } from '@/components/tickets/conversation'
import { providers } from '@/test/render'
import { ticketDetail, ticketMessage } from '@/test/rows'

/*
 * A ticket's conversation (components/tickets/conversation): a message is read in its own direction, Latin among
 * Persian too, and a long run with no space in it — a link, a pasted config — breaks inside its message rather than
 * stretching it past its column.
 */

const LINK = `vless://${'a1b2c3d4'.repeat(40)}@example.com:443`

describe('a message', () => {
  it('breaks a long run with nowhere else to break, and reads each paragraph in its own direction', () => {
    render(<Conversation ticket={ticketDetail({ messages: [ticketMessage({ body: `این لینک کار نمی‌کند:\n${LINK}` })] })} />, { wrapper: providers().wrapper })

    const body = within(screen.getByRole('article', { name: 'پیام مشتری' })).getByText(/این لینک کار نمی‌کند/)
    expect(body.className).toContain('wrap-anywhere')
    expect(body.className).not.toContain('wrap-break-word')
    expect(body.getAttribute('dir')).toBe('auto')
    expect(body.className).toContain('[unicode-bidi:plaintext]')
  })

  it('by one of the shop’s admins says who by their name, else as the server keeps them', () => {
    const answers = [
      ticketMessage({ id: 40, author: 'support', reviewer: 'user#12', reviewer_name: 'سارا احمدی', channel: 'staff' }),
      ticketMessage({ id: 41, author: 'support', reviewer: 'tg:7001', reviewer_name: null, channel: 'group' }),
      ticketMessage({ id: 42, author: 'support', reviewer: 'پشتیبانی', reviewer_name: null, channel: 'panel' }),
    ]
    render(<Conversation ticket={ticketDetail({ messages: answers })} />, { wrapper: providers().wrapper })

    const [named, kept, support] = screen.getAllByRole('article', { name: 'پاسخ پشتیبانی' }).map((article) => article.querySelector('header span')?.textContent)
    expect([named, kept, support]).toEqual(['پشتیبانی · سارا احمدی', 'پشتیبانی · شناسه تلگرام 7001', 'پشتیبانی'])
  })
})
