import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { LoginSection } from '@/apps/admin/settings/login-section'
import { useSession } from '@/lib/auth'
import { OWNER, signedIn, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The owner's own login, «تنظیمات پنل» › «ورود به پنل» (apps/admin/settings/login-section): what the card sends, and —
 * once the server took it — the session renamed in place, a word of what changed and the card started again from the
 * login as it is now, the passwords typed gone; a refusal stays on the card, under the field it is about, or over the
 * save when it is about none (the throttle's wait). While config.php is not the server's to write, the save is held.
 */

const URL = '/api/admin/auth/credentials'
const PASSWORDS = ['رمز عبور فعلی', 'رمز عبور جدید', 'تکرار رمز عبور جدید']

/** Who the panel says is signed in. */
function SignedIn() {
  return <p data-testid="name">{useSession().name}</p>
}

/** The card, in a signed-in panel. */
async function show({ disabled = false }: { disabled?: boolean } = {}) {
  render(
    <>
      <SignedIn />
      <LoginSection disabled={disabled} />
    </>,
    { wrapper: signedIn().wrapper },
  )
  await screen.findByLabelText('رمز عبور فعلی')
}

/** The card's fields by their labels. */
function type(fields: Record<string, string>) {
  for (const [label, value] of Object.entries(fields)) fireEvent.change(screen.getByLabelText(label), { target: { value } })
}

const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
const valueOf = (label: string) => (screen.getByLabelText(label) as HTMLInputElement).value

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('changing the owner’s login', () => {
  it('sends the card, renames the session in place and starts again from the new login', async () => {
    server().on('PUT', URL, json({ session: { ...OWNER, name: 'boss' } }))
    await show()

    type({ 'نام کاربری': 'boss', 'رمز عبور فعلی': 'the old one', 'رمز عبور جدید': 'a new passphrase', 'تکرار رمز عبور جدید': 'a new passphrase' })
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('نام کاربری و رمز عبور عوض شد'))
    expect(server().sent('PUT', URL)[0]?.body).toEqual({ username: 'boss', current_password: 'the old one', password: 'a new passphrase', password_confirmation: 'a new passphrase' })
    expect(screen.getByTestId('name').textContent).toBe('boss')
    expect(valueOf('نام کاربری')).toBe('boss')
    for (const label of PASSWORDS) expect(valueOf(label)).toBe('')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
  })

  it('leaves a blank new password blank: the username alone changes', async () => {
    server().on('PUT', URL, json({ session: { ...OWNER, name: 'boss' } }))
    await show()

    type({ 'نام کاربری': 'boss', 'رمز عبور فعلی': 'the old one' })
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('نام کاربری عوض شد'))
    expect(server().sent('PUT', URL)[0]?.body).toEqual({ username: 'boss', current_password: 'the old one', password: '', password_confirmation: '' })
  })

  it('changes the password alone, and keeps none of what was typed', async () => {
    server().on('PUT', URL, json({ session: OWNER }))
    await show()

    type({ 'رمز عبور فعلی': 'the old one', 'رمز عبور جدید': 'a new passphrase', 'تکرار رمز عبور جدید': 'a new passphrase' })
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('رمز عبور عوض شد'))
    expect(valueOf('نام کاربری')).toBe('root')
    for (const label of PASSWORDS) expect(valueOf(label)).toBe('')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
  })

  it('keeps the card as typed, the API’s words under the field they are about', async () => {
    server().on('PUT', URL, refusal(422, 'اطلاعات واردشده معتبر نیست.', { current_password: ['رمز عبور فعلی درست نیست.'] }))
    await show()

    type({ 'رمز عبور فعلی': 'a guess', 'رمز عبور جدید': 'a new passphrase', 'تکرار رمز عبور جدید': 'a new passphrase' })
    save()

    expect(await screen.findByText('رمز عبور فعلی درست نیست.')).toBeTruthy()
    expect(screen.getByLabelText('رمز عبور فعلی').getAttribute('aria-invalid')).toBe('true')
    expect(valueOf('رمز عبور جدید')).toBe('a new passphrase')
    expect(screen.getByTestId('name').textContent).toBe('root')
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('says the throttle’s wait over the save', async () => {
    server().on('PUT', URL, refusal(429, 'تلاش‌های ناموفق زیاد بود؛ ۱۵ دقیقه دیگر دوباره امتحان کنید.'))
    await show()

    type({ 'رمز عبور فعلی': 'a guess', 'رمز عبور جدید': 'a new passphrase', 'تکرار رمز عبور جدید': 'a new passphrase' })
    save()

    expect((await screen.findByRole('alert')).textContent).toBe('تلاش‌های ناموفق زیاد بود؛ ۱۵ دقیقه دیگر دوباره امتحان کنید.')
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('has nothing unsaved for the current password alone — one a password manager filled in changes no login', async () => {
    await show()

    type({ 'رمز عبور فعلی': 'filled in by the browser' })

    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')
    type({ 'نام کاربری': 'boss' })
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
  })

  it('holds its save while config.php cannot be written', async () => {
    await show({ disabled: true })

    type({ 'رمز عبور فعلی': 'the old one', 'رمز عبور جدید': 'a new passphrase', 'تکرار رمز عبور جدید': 'a new passphrase' })
    save()

    expect((screen.getByRole('button', { name: 'ذخیره' }) as HTMLButtonElement).disabled).toBe(true)
    expect(server().sent('PUT', URL)).toEqual([])
  })
})
