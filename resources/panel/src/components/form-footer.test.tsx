import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { Disclosure } from '@/components/disclosure'
import { Field } from '@/components/field'
import { Creating, FormActions, SaveFooter } from '@/components/form-footer'
import { LeaveQuestion } from '@/components/leave-question'
import { Modal } from '@/components/modal'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import { useForm } from '@/lib/use-form'
import { providers, until } from '@/test/render'
import { refusal, server } from '@/test/server'

/*
 * The form footers (components/form-footer): save and revert held rather than disabled, a revert only on an edit, no
 * unsaved question after a change is typed back, and a 422 moving the focus to the first invalid field (opening its
 * fold) with a count of what is left.
 */

/** A group's name in its dialog: an edit of the group — or, inside Creating, as EditorModal opens it on no row, a new one's. */
function GroupDialog({ onClose, onSubmit }: { onClose: () => void; onSubmit: () => void }) {
  const { values, set, dirty, revert, handleSubmit } = useForm({ name: 'VIP' })

  return (
    <Modal open onClose={onClose} title="گروه">
      <form onSubmit={handleSubmit(onSubmit)} noValidate>
        <Field id="group_name" label="نام گروه">
          <Input value={values.name} onChange={(event) => set('name', event.target.value)} />
        </Field>
        <FormActions submitLabel="ذخیره" onCancel={onClose} dirty={dirty} onRevert={revert} />
      </form>
    </Modal>
  )
}

const held = (name: string) => screen.getByRole('button', { name }).getAttribute('aria-disabled') === 'true'

/** A long form: the fields far above its footer — the password folded away under «تنظیمات پیشرفته» when `folded`. */
function ServerForm({ folded = false }: { folded?: boolean }) {
  const { values, set, error, formError, busy, dirty, revert, submit, handleSubmit } = useForm({
    driver: '3x-ui' as const,
    name: 'آلمان',
    base_url: 'https://panel.example.com',
    auth_mode: 'password' as const,
    username: '',
    password: '',
    verify_tls: true,
    timeout: 30,
    proxy_enabled: false,
    is_active: true,
  })

  const password = (
    <Field id="password" label="رمز عبور" error={error('password')}>
      <Input value={values.password} onChange={(event) => set('password', event.target.value)} />
    </Field>
  )

  return (
    <form onSubmit={handleSubmit(() => submit(() => api.put('/servers/1', values)))} noValidate>
      <Field id="name" label="نام" error={error('name')}>
        <Input value={values.name} onChange={(event) => set('name', event.target.value)} />
      </Field>
      <Field id="username" label="نام کاربری" error={error('username')}>
        <Input value={values.username} onChange={(event) => set('username', event.target.value)} />
      </Field>
      {folded ? <Disclosure label="تنظیمات پیشرفته">{password}</Disclosure> : password}
      <FormActions error={formError} submitLabel="ذخیره تغییرات" busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}

function footer(props: { dirty: boolean; saving?: boolean; onSave: () => void; onRevert: () => void }) {
  return <SaveFooter saving={props.saving ?? false} dirty={props.dirty} onSave={props.onSave} onRevert={props.onRevert} />
}

/** The server form with a change to save — its name —, its save pressed: an edit with nothing changed has nothing to save. */
function saveRenamed() {
  fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'هلند' } })
  const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })
  save.focus()
  fireEvent.click(save)
}

describe('a card’s footer', () => {
  it('keeps the focus on «ذخیره» through the save and once there is nothing left to save', () => {
    const onSave = vi.fn()
    const onRevert = vi.fn()
    const { rerender } = render(footer({ dirty: true, onSave, onRevert }))
    const save = screen.getByRole('button', { name: 'ذخیره' })

    save.focus()
    fireEvent.click(save)
    expect(onSave).toHaveBeenCalledTimes(1)

    rerender(footer({ dirty: true, saving: true, onSave, onRevert }))
    rerender(footer({ dirty: false, onSave, onRevert }))

    expect(document.activeElement).toBe(save)
    expect(save.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(save)
    fireEvent.click(screen.getByRole('button', { name: 'بازگردانی تغییرات' }))
    expect(onSave).toHaveBeenCalledTimes(1)
    expect(onRevert).not.toHaveBeenCalled()
  })
})

describe('a dialog’s form', () => {
  it('as an edit, holds its revert and its save while nothing changed — changed back too — and closing then asks nothing', () => {
    const onClose = vi.fn()
    const onSubmit = vi.fn()
    render(
      <>
        <LeaveQuestion />
        <GroupDialog onClose={onClose} onSubmit={onSubmit} />
      </>,
      { wrapper: providers().wrapper },
    )
    expect([held('بازگردانی تغییرات'), held('ذخیره')]).toEqual([true, true])

    fireEvent.change(screen.getByLabelText('نام گروه'), { target: { value: 'VIP طلایی' } })
    expect([held('بازگردانی تغییرات'), held('ذخیره')]).toEqual([false, false])

    fireEvent.change(screen.getByLabelText('نام گروه'), { target: { value: 'VIP ' } })
    expect([held('بازگردانی تغییرات'), held('ذخیره')]).toEqual([true, true])
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
    expect(onSubmit).not.toHaveBeenCalled()

    fireEvent(screen.getByRole('dialog', { name: 'گروه' }), new Event('cancel', { cancelable: true }))
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    expect(onClose).toHaveBeenCalledTimes(2)
    expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull()
  })

  it('as a new one’s, offers no revert — there is nothing saved to go back to — and its submit goes as it opened', () => {
    const onSubmit = vi.fn()
    render(
      <Creating value>
        <GroupDialog onClose={vi.fn()} onSubmit={onSubmit} />
      </Creating>,
      { wrapper: providers().wrapper },
    )

    expect(screen.queryByRole('button', { name: 'بازگردانی تغییرات' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })
})

describe('a dialog’s form that cannot go yet', () => {
  it('holds its submit alone: «انصراف» and the revert stay free', () => {
    const onCancel = vi.fn()
    const onRevert = vi.fn()
    const { rerender } = render(<FormActions submitLabel="افزودن به سرویس‌ها" disabled onCancel={onCancel} onRevert={onRevert} />)

    expect((screen.getByRole('button', { name: 'افزودن به سرویس‌ها' }) as HTMLButtonElement).disabled).toBe(true)
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    expect(onCancel).toHaveBeenCalledTimes(1)

    rerender(<FormActions submitLabel="افزودن به سرویس‌ها" disabled dirty onCancel={onCancel} onRevert={onRevert} />)
    fireEvent.click(screen.getByRole('button', { name: 'بازگردانی تغییرات' }))
    expect(onRevert).toHaveBeenCalledTimes(1)
  })
})

describe('a refused save', () => {
  it('puts the focus on the first field to fix and says how many are left, following each fix', async () => {
    server().on('PUT', '/api/admin/servers/1', refusal(422, 'Refused.', { username: ['نام کاربری را وارد کنید.'], password: ['رمز عبور را وارد کنید.'] }))
    render(<ServerForm />, { wrapper: providers().wrapper })

    saveRenamed()

    await until(() => expect(screen.getByText('۲ فیلد نیاز به اصلاح دارد')).toBeTruthy())
    expect(document.activeElement).toBe(screen.getByLabelText('نام کاربری'))

    fireEvent.change(screen.getByLabelText('نام کاربری'), { target: { value: 'admin' } })
    await until(() => expect(screen.getByText('یک فیلد نیاز به اصلاح دارد')).toBeTruthy())

    fireEvent.change(screen.getByLabelText('رمز عبور'), { target: { value: 'secret-1' } })
    await until(() => expect(screen.queryByText(/نیاز به اصلاح دارد/)).toBeNull())
  })

  it('opens the fold that holds the field to fix, and puts the focus on it there', async () => {
    server().on('PUT', '/api/admin/servers/1', refusal(422, 'Refused.', { password: ['رمز عبور را وارد کنید.'] }))
    render(<ServerForm folded />, { wrapper: providers().wrapper })
    const fold = screen.getByRole('button', { name: 'تنظیمات پیشرفته' })
    expect(fold.getAttribute('aria-expanded')).toBe('false')

    saveRenamed()

    await until(() => expect(document.activeElement).toBe(screen.getByLabelText('رمز عبور')))
    expect(fold.getAttribute('aria-expanded')).toBe('true')
  })

  it('opens a fold whose field is refused, the focus on a field to fix above it', async () => {
    server().on('PUT', '/api/admin/servers/1', refusal(422, 'Refused.', { username: ['نام کاربری را وارد کنید.'], password: ['رمز عبور را وارد کنید.'] }))
    render(<ServerForm folded />, { wrapper: providers().wrapper })

    saveRenamed()

    await until(() => expect(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }).getAttribute('aria-expanded')).toBe('true'))
    expect(document.activeElement).toBe(screen.getByLabelText('نام کاربری'))
  })

  it('counts a field of a section kept hidden, but gives the focus to the first one on screen', async () => {
    server().on('PUT', '/api/admin/servers/1', refusal(422, 'Refused.', { name: ['نام تکراری است.'], password: ['رمز عبور را وارد کنید.'] }))
    render(<ServerForm />, { wrapper: providers().wrapper })
    saveRenamed()
    screen.getByLabelText('نام').closest('div')?.setAttribute('hidden', '')

    await until(() => expect(screen.getByText('۲ فیلد نیاز به اصلاح دارد')).toBeTruthy())
    expect(document.activeElement).toBe(screen.getByLabelText('رمز عبور'))
  })
})
