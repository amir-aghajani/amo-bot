import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FactList } from '@/components/fact-list'
import { CustomerFacts, distinctUserLabel, TelegramChatLink, UserIdentity, UserLabel, userLabel, userPage } from '@/components/user-identity'
import type { UserRef } from '@/lib/api-types'
import { isolate } from '@/lib/direction'
import { providers } from '@/test/render'

/*
 * A customer is shown by three identifiers, never blended (components/user-identity): the Telegram name, the handle and
 * the numeric id — two fixed lines where there is room, one identifier where only one fits, kept whole in a Persian
 * sentence —, the name the way to their page. One who signed up on the website has no Telegram account: their email
 * stands where the handle and the Telegram id would, and there is no chat to open.
 */

const AMIR: UserRef = { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null }

/** A customer of the website alone: a name and an email, no Telegram account. */
const SARA: UserRef = { id: 11, name: 'سارا', username: null, telegram_id: null, email: 'sara@example.com' }

describe('one line about a customer', () => {
  it('is the name, else the handle, else the Telegram id — each isolated, so a sentence around it keeps its «@»', () => {
    expect(userLabel(AMIR)).toBe(isolate('امیر'))
    expect(userLabel({ ...AMIR, name: null })).toBe(isolate('@amir'))
    expect(userLabel({ ...AMIR, name: null, username: null })).toBe(isolate('123456789'))
  })

  it('is the email of a customer without Telegram, else their number in the shop', () => {
    expect(userLabel(SARA)).toBe(isolate('سارا'))
    expect(userLabel({ ...SARA, name: null })).toBe(isolate('sara@example.com'))
    expect(userLabel({ ...SARA, name: null, email: null })).toBe(isolate('#11'))
    expect(distinctUserLabel(SARA)).toBe(`${isolate('سارا')} (${isolate('sara@example.com')})`)
  })

  it('is the same in markup, in a <bdi> rather than isolates', () => {
    const { container } = render(<UserLabel user={{ ...AMIR, name: null }} />)

    expect(container.innerHTML).toBe('<bdi>@amir</bdi>')
  })
})

describe('a customer told from every other', () => {
  it('is the name with the handle, else with the Telegram id — two «࿐Âmîr™» are two people', () => {
    expect(distinctUserLabel({ ...AMIR, name: '࿐Âmîr™' })).toBe(`${isolate('࿐Âmîr™')} (${isolate('@amir')})`)
    expect(distinctUserLabel({ ...AMIR, name: '࿐Âmîr™', username: null })).toBe(`${isolate('࿐Âmîr™')} (${isolate('123456789')})`)
    expect(distinctUserLabel({ ...AMIR, name: null })).toBe(isolate('@amir'))
  })
})

describe('the way to a customer in Telegram', () => {
  it('opens their public handle, else the link only the apps understand — in one wording, in a new tab', () => {
    const { rerender } = render(<TelegramChatLink user={AMIR} />)
    const link = screen.getByRole('link', { name: 'گفتگو در تلگرام' })
    expect([link.getAttribute('href'), link.getAttribute('target'), link.getAttribute('rel')]).toEqual(['https://t.me/amir', '_blank', 'noreferrer'])

    rerender(<TelegramChatLink user={{ ...AMIR, username: null }} />)
    expect(screen.getByRole('link', { name: 'گفتگو در تلگرام' }).getAttribute('href')).toBe('tg://user?id=123456789')
  })

  it('is none for a customer without a Telegram account', () => {
    const { container } = render(<TelegramChatLink user={SARA} />)

    expect(container.innerHTML).toBe('')
  })
})

describe('a customer in a row', () => {
  it('takes two lines whatever the account has — never the numeric id', () => {
    const { container, rerender } = render(<UserIdentity user={AMIR} />)
    expect(container.textContent).toBe('امیر@amir')

    rerender(<UserIdentity user={{ ...AMIR, name: null, username: null }} />)
    expect(container.textContent).toBe('بدون نامبدون نام کاربری')
    expect(container.textContent).not.toContain('123456789')
  })

  it('puts a website customer’s email where the handle would be — «بدون ایمیل» without one', () => {
    const { container, rerender } = render(<UserIdentity user={SARA} />)
    expect(container.textContent).toBe('ساراsara@example.com')
    expect(container.querySelector('bdi[dir="ltr"]')?.textContent).toBe('sara@example.com')

    rerender(<UserIdentity user={{ ...SARA, email: null }} />)
    expect(container.textContent).toBe('سارابدون ایمیل')
  })

  it('marks a banned account beside the name', () => {
    const { container } = render(<UserIdentity user={AMIR} status="banned" />)

    expect(container.textContent).toContain('مسدود')
  })

  it('makes the name the way to the customer’s page — «بدون نام» standing in for none', () => {
    const { rerender } = render(<UserIdentity user={AMIR} to={userPage(AMIR.id)} />, { wrapper: providers().wrapper })
    expect(screen.getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/10')

    rerender(<UserIdentity user={{ ...AMIR, name: null }} to={userPage(AMIR.id)} />)
    expect(screen.getByRole('link', { name: 'بدون نام' }).getAttribute('href')).toBe('/users/10')
  })
})

/** The customer's facts in a dialog, opened on the screen at `at`. */
function facts(user: UserRef, at = '/orders') {
  return render(
    <FactList>
      <CustomerFacts user={user} />
    </FactList>,
    { wrapper: providers({ at }).wrapper },
  )
}

describe('a customer in a dialog’s facts', () => {
  it('sets the name and the handle apart on one line — a Latin name too, whose isolate runs left to right', () => {
    const { container } = facts({ ...AMIR, name: 'IRCODSTORE Support' })

    expect(container.querySelector('dd')?.textContent).toBe('IRCODSTORE Support @amir')
  })

  it('leads to the customer’s page by the name', () => {
    facts(AMIR)

    expect(screen.getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/10')
  })

  it('leads nowhere on the customer’s own page', () => {
    facts(AMIR, '/users/10')

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByText('امیر')).toBeTruthy()
  })

  it('gives a website customer’s email the line the Telegram id has', () => {
    const { container } = facts(SARA)

    expect([...container.querySelectorAll('dt')].map((term) => term.textContent)).toEqual(['مشتری', 'ایمیل'])
    expect(container.querySelectorAll('dd')[1]?.textContent).toBe('sara@example.com')
  })
})
