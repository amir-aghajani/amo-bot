import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Gift } from 'lucide-react'
import { useNavigate } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { result } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Field, TextInput } from '@/apps/app/ui/fields'
import { Sheet } from '@/apps/app/ui/sheet'
import { told } from '@/apps/app/ui/toaster'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreAccountResponse } from '@/lib/api-types'

/*
 * «کد هدیه»: the code as the customer has it — any case, dashes and spaces, Persian digits; the shop reads it —, and what
 * it gave in the shop's words. A wallet's code shows on the balance at once; a plan's service opens once delivered.
 */

export function GiftCodeSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate()
  const [code, setCode] = useState('')
  const redeem = useMutation({
    mutationFn: (typed: string) => store.post('/gift-codes/redeem', { code: typed }),
    meta: { quiet: true },
    onSuccess: ({ gift }) => {
      told(gift.words)
      const balance = gift.balance
      if (balance !== null) {
        queryClient.setQueryData<StoreAccountResponse>(keys.me, (account) => (account === undefined ? account : { ...account, customer: { ...account.customer, balance } }))
      }
      void queryClient.invalidateQueries({ queryKey: keys.wallet })
      setCode('')
      onClose()
      if (gift.kind === 'plan') {
        void queryClient.invalidateQueries({ queryKey: keys.subscriptions })
        void queryClient.invalidateQueries({ queryKey: keys.orders })
        if (gift.subscription !== null) navigate(`/services/${gift.subscription.id}`)
      }
    },
    onError: () => result('error'),
  })
  const problem = redeem.isError ? (fieldProblem(redeem.error, 'code') ?? problemOf(redeem.error)) : undefined
  const submit = () => {
    if (code.trim() !== '' && !redeem.isPending) redeem.mutate(code)
  }
  const close = () => {
    redeem.reset()
    onClose()
  }

  return (
    <Sheet open={open} onClose={close} title="کد هدیه">
      <form
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        <div className="flex items-center gap-3">
          <IconTile icon={Gift} size="xl" look="soft" />
          <p className="text-hint min-w-0 flex-1 text-footnote">کد هدیه‌ای را که گرفته‌اید وارد کنید؛ شارژ کیف پول یا سرویس آن همین حالا به حساب شما می‌آید.</p>
        </div>
        <Field label="کد هدیه" error={problem}>
          {(id) => (
            <TextInput
              id={id}
              ltr
              value={code}
              onChange={(event) => {
                setCode(event.target.value)
                if (redeem.isError) redeem.reset()
              }}
              placeholder="NOWRUZ-7K3M9QXAPT"
              autoComplete="off"
              autoCapitalize="characters"
              spellCheck={false}
              invalid={problem !== undefined}
              className="uppercase placeholder:normal-case"
            />
          )}
        </Field>
        <Button type="submit" block busy={redeem.isPending} disabled={code.trim() === ''}>
          ثبت کد
        </Button>
      </form>
    </Sheet>
  )
}
