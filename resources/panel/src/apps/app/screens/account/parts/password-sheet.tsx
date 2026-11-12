import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { keys, queryClient } from '@/apps/app/lib/query'
import { withRecentSignIn } from '@/apps/app/lib/recent'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { result } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Field, TextInput } from '@/apps/app/ui/fields'
import { Sheet } from '@/apps/app/ui/sheet'
import { told } from '@/apps/app/ui/toaster'
import type { StoreAccountResponse, StoreCustomer } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'

/*
 * The password of the account's email, set or changed (`PUT /me/password`, after a recent sign-in): the current one
 * while it has one, then the new. Every other device of the account is signed out by it; this one stays.
 */

/** The shortest password the shop takes (its sign-up rule). */
const SHORTEST = 8

interface PasswordSheetProps {
  open: boolean
  onClose: () => void
  customer: StoreCustomer
}

export function PasswordSheet({ open, onClose, customer }: PasswordSheetProps) {
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const changing = customer.has_password

  const save = useMutation({
    mutationFn: () => withRecentSignIn('تغییر رمز عبور', () => (changing ? store.put('/me/password', { current_password: current, password }) : store.put('/me/password', { password }))),
    meta: { quiet: true },
    onSuccess: ({ customer: now }) => {
      queryClient.setQueryData<StoreAccountResponse>(keys.me, (account) => (account === undefined ? account : { ...account, customer: now }))
      void queryClient.invalidateQueries({ queryKey: keys.sessions })
      told(changing ? 'رمز عبور عوض شد؛ دستگاه‌های دیگر از حساب خارج شدند.' : 'رمز عبور ذخیره شد.')
      setCurrent('')
      setPassword('')
      onClose()
    },
    onError: (error) => {
      result('error')
      if (fieldProblem(error, 'current_password') === undefined && fieldProblem(error, 'password') === undefined) toast.error(problemOf(error))
    },
  })

  const currentProblem = save.isError ? fieldProblem(save.error, 'current_password') : undefined
  const passwordProblem = save.isError ? fieldProblem(save.error, 'password') : undefined
  const ready = password.length >= SHORTEST && (!changing || current !== '')

  return (
    <Sheet open={open} onClose={onClose} title={changing ? 'تغییر رمز عبور' : 'تعیین رمز عبور'}>
      <form
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          if (ready && !save.isPending) save.mutate()
        }}
      >
        <p className="text-hint text-footnote">
          با ایمیل <span className="ltr text-text">{customer.email}</span> و این رمز از وب‌سایت فروشگاه وارد حساب خود می‌شوید.
        </p>
        {changing && (
          <Field label="رمز عبور فعلی" error={currentProblem}>
            {(id) => (
              <TextInput
                id={id}
                ltr
                type="password"
                autoComplete="current-password"
                value={current}
                invalid={currentProblem !== undefined}
                onChange={(event) => {
                  save.reset()
                  setCurrent(event.target.value)
                }}
              />
            )}
          </Field>
        )}
        <Field label="رمز عبور تازه" error={passwordProblem} hint={`دست‌کم ${formatNumber(SHORTEST)} نویسه`}>
          {(id) => (
            <TextInput
              id={id}
              ltr
              type="password"
              autoComplete="new-password"
              value={password}
              invalid={passwordProblem !== undefined}
              onChange={(event) => {
                save.reset()
                setPassword(event.target.value)
              }}
            />
          )}
        </Field>
        <Button type="submit" block busy={save.isPending} disabled={!ready}>
          {changing ? 'تغییر رمز' : 'ذخیره رمز'}
        </Button>
      </form>
    </Sheet>
  )
}
