import { useQuery } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WebsiteAccountCard } from '@/components/customer/website-account-card'
import type { CustomerAccount, CustomerAccountResponse, UserDetailResponse, UserRow } from '@/lib/api-types'
import { customerQuery } from '@/lib/queries'
import { providers, until } from '@/test/render'
import { customerAccount, userRow } from '@/test/rows'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * «ورود به وب‌سایت» on a customer's page (components/customer/website-account-card): how they sign in on the shop's
 * website — their Telegram account, Google, an email with or without a password, two-factor sign-in —, the devices
 * signed in and the accounts merged into theirs; and what support does there, each after a second look: two-factor
 * sign-in turned off, every device signed out — the account the server answers put in place on the page, nothing read
 * again for it.
 */

const SARA = userRow({ name: 'سارا', username: null, telegram_id: null, email: 'sara@example.com' })

const SIGNED_IN = customerAccount({
  email: 'sara@example.com',
  google: true,
  has_password: true,
  two_factor: true,
  sessions: 2,
  merges: [
    { merged_user_id: 31, merged: { telegram_id: 5151, username: 'ali', email: null, google: false, name: 'علی' }, created_at: '2026-10-02T10:00:00Z' },
    { merged_user_id: 24, merged: { telegram_id: null, username: null, email: 'old@example.com', google: true, name: null }, created_at: '2026-09-20T10:00:00Z' },
  ],
})

/** The customer's page, as far as the card goes: the page's own read of them, the card drawn from it. */
function Page() {
  const { data } = useQuery(customerQuery(10))
  return data ? <WebsiteAccountCard customer={data.user} account={data.account} /> : null
}

/** The card on the customer's page, the server answering the page's read with `account`. */
function open(account: CustomerAccount = SIGNED_IN, customer: UserRow = SARA) {
  const detail: UserDetailResponse = { user: customer, referral: { referrer: null, referrals: 0, earned: '0.00' }, agency: null, account }
  server().on('GET', '/api/admin/users/10', json(detail))
  render(<Page />, { wrapper: providers().wrapper })
}

/** The fact under its label. */
const fact = (label: string) => screen.getByText(label, { selector: 'dt' }).nextElementSibling as HTMLElement

describe('the website account', () => {
  it('says how the customer signs in there, and the accounts merged into theirs', async () => {
    open()

    await screen.findByRole('heading', { level: 2, name: 'ورود به وب‌سایت' })
    expect(fact('تلگرام').textContent).toBe('وصل نیست')
    expect(fact('گوگل').textContent).toBe('وصل است')
    expect(within(fact('ایمیل')).getByText('sara@example.com').getAttribute('dir')).toBe('ltr')
    expect(within(fact('ایمیل')).getByText('با رمز عبور')).toBeTruthy()
    expect(within(fact('ورود دو مرحله‌ای')).getByText('روشن')).toBeTruthy()
    expect(within(fact('دستگاه‌ها')).getByText('۲ دستگاه')).toBeTruthy()

    const [newer, older] = within(screen.getByRole('region', { name: 'حساب‌های ادغام‌شده' })).getAllByRole('listitem')
    if (!newer || !older) throw new Error('Two merged accounts are listed.')
    expect(newer.textContent).toContain('علی')
    expect(within(newer).getByText('#31')).toBeTruthy()
    expect(older.textContent).toContain('old@example.com')
    expect(older.textContent).toContain('با گوگل')
    // Two accounts are made one by the customer alone, from their website.
    for (const merged of [newer, older]) expect(merged.textContent).toContain('به درخواست خود مشتری')
  })

  it('offers nothing to do while there is nothing to do: no second step, no device signed in', async () => {
    open(customerAccount(), userRow())

    await screen.findByRole('heading', { level: 2, name: 'ورود به وب‌سایت' })
    expect(fact('تلگرام').textContent).toBe('وصل است')
    expect(fact('ایمیل').textContent).toBe('ایمیلی ندارد')
    expect(fact('ورود دو مرحله‌ای').textContent).toBe('خاموش')
    expect(fact('دستگاه‌ها').textContent).toBe('در هیچ دستگاهی وارد نیست')
    expect(screen.queryByRole('button')).toBeNull()
    expect(screen.queryByRole('region', { name: 'حساب‌های ادغام‌شده' })).toBeNull()
  })

  it('turns two-factor sign-in off after a second look, the account answered put in place', async () => {
    open()
    server().on('POST', '/api/admin/users/10/two-factor/disable', json({ account: { ...SIGNED_IN, two_factor: false } } satisfies CustomerAccountResponse))

    fireEvent.click(await screen.findByRole('button', { name: 'خاموش کردن ورود دو مرحله‌ای' }))
    const dialog = await screen.findByRole('dialog', { name: 'خاموش کردن ورود دو مرحله‌ای' })
    // The customer is told on every door they have — Telegram, their email — and their website keeps it.
    expect(dialog.textContent).toContain('در ربات تلگرام و با ایمیل، هر کدام که دارد')
    expect(dialog.textContent).toContain('در اعلان‌های وب‌سایتش هم می‌ماند')
    expect(server().sent('POST', '/api/admin/users/10/two-factor/disable')).toEqual([])
    fireEvent.click(within(dialog).getByRole('button', { name: 'خاموش کن' }))

    await until(() => expect(fact('ورود دو مرحله‌ای').textContent).toBe('خاموش'))
    expect(
      server()
        .sent('POST', '/api/admin/users/10/two-factor/disable')
        .map((request) => request.body),
    ).toEqual([undefined])
    expect(server().sent('GET', '/api/admin/users/10')).toHaveLength(1)
  })

  it('signs the customer out of every device after a second look', async () => {
    open()
    server().on('POST', '/api/admin/users/10/sessions/end', noContent)

    fireEvent.click(await screen.findByRole('button', { name: 'خروج از همه دستگاه‌ها' }))
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'خروج از همه دستگاه‌ها' })).getByRole('button', { name: 'خروج از همه' }))

    await until(() => expect(fact('دستگاه‌ها').textContent).toBe('در هیچ دستگاهی وارد نیست'))
    expect(server().sent('POST', '/api/admin/users/10/sessions/end')).toHaveLength(1)
    expect(server().sent('GET', '/api/admin/users/10')).toHaveLength(1)
  })

  it('says a refusal in the dialog, which stays', async () => {
    const off = 'ورود دو مرحله‌ای این حساب روشن نیست.'
    open()
    server().on('POST', '/api/admin/users/10/two-factor/disable', refusal(422, off))

    fireEvent.click(await screen.findByRole('button', { name: 'خاموش کردن ورود دو مرحله‌ای' }))
    const dialog = await screen.findByRole('dialog', { name: 'خاموش کردن ورود دو مرحله‌ای' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'خاموش کن' }))

    await until(() => expect(within(dialog).getByText(off)).toBeTruthy())
    expect(within(fact('ورود دو مرحله‌ای')).getByText('روشن')).toBeTruthy()
  })
})
