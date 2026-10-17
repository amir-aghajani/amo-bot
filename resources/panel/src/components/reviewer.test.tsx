import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Reviewer } from '@/components/reviewer'

/*
 * Who decided something (components/reviewer): a login or a handle as it is, left to right; an admin the server keeps by
 * a number — `user#12`, `agent#3`, `tg:<id>` — in words, the number apart; «پشتیبانی» as the word it is.
 */

const said = (name: string) => render(<Reviewer name={name} />).container

describe('a reviewer', () => {
  it('kept by a number is said in words, never as the raw identifier', () => {
    expect(said('user#12').textContent).toBe('کاربر #12')
    expect(said('agent#3').textContent).toBe('نماینده #3')
    expect(said('tg:7123456789').textContent).toBe('شناسه تلگرام 7123456789')
    expect(said('user#12').querySelector('bdi[dir="ltr"]')?.textContent).toBe('#12')
  })

  it('with a handle or a login is shown as it is, left to right; support as its word', () => {
    expect(said('@amir_admin').querySelector('bdi[dir="ltr"]')?.textContent).toBe('@amir_admin')
    expect(said('owner').querySelector('bdi[dir="ltr"]')?.textContent).toBe('owner')
    expect(said('پشتیبانی').querySelector('bdi:not([dir])')?.textContent).toBe('پشتیبانی')
  })

  it('whose name the server read is said by it, in its own direction', () => {
    const named = render(<Reviewer name="tg:7123456789" person="Sara Ahmadi" />).container
    expect(named.textContent).toBe('Sara Ahmadi')
    expect(named.querySelector('bdi:not([dir])')?.textContent).toBe('Sara Ahmadi')
    expect(render(<Reviewer name="user#12" person={null} />).container.textContent).toBe('کاربر #12')
  })
})
