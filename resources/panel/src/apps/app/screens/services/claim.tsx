import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link2 } from 'lucide-react'
import { useNavigate } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { result } from '@/apps/app/telegram/haptics'
import { MainAction } from '@/apps/app/ui/button'
import { Field, TextArea } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Empty, ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'

/*
 * «افزودن سرویس با لینک», as the bot takes it: a service support made on a server by hand, added to the customer's
 * services by its subscription link or one of its configs pasted here (`POST /subscriptions/claim`) — then its own page.
 * Every refusal (no link in the text, no server has it, another customer's) is the shop's words under the field.
 */

/** The most the shop reads of the text. */
const LONGEST = 2000

export default function ClaimScreen() {
  const navigate = useNavigate()
  const shopRead = useShop()
  const shop = shopRead.data
  const [link, setLink] = useState('')
  const claim = useMutation({
    mutationFn: () => store.post('/subscriptions/claim', { link: link.trim() }),
    meta: { quiet: true },
    onSuccess: (answer) => {
      const id = answer.subscription.id
      queryClient.setQueryData(keys.subscription(id), answer)
      // Every list of the services (home's, the list, a ticket's picker) is read again with it.
      void queryClient.invalidateQueries({ queryKey: keys.subscriptions, predicate: (query) => query.queryKey[1] !== id })
      told('سرویس به سرویس‌های شما اضافه شد.')
      navigate(`/services/${id}`, { replace: true })
    },
    onError: () => result('error'),
  })
  const problem = claim.isError ? (fieldProblem(claim.error, 'link') ?? problemOf(claim.error)) : undefined

  return (
    <Screen back title="افزودن سرویس با لینک" bottom="action">
      {shop === undefined ? (
        shopRead.isError ? (
          <ErrorView error={shopRead.error} retry={() => void shopRead.refetch()} />
        ) : (
          <LoadingBlocks count={2} />
        )
      ) : !shop.shop.service_claims ? (
        <Empty icon={Link2} title="افزودن سرویس با لینک فعلا فعال نیست" />
      ) : (
        <>
          <p className="text-hint px-4 text-footnote">اگر پشتیبانی برایتان سرویسی ساخته، لینک اشتراک یا یکی از کانفیگ‌هایش را اینجا بگذارید تا به سرویس‌های شما اضافه شود و بتوانید تمدیدش کنید.</p>
          <Field label="لینک یا کانفیگ سرویس" error={problem}>
            {(id) => (
              <TextArea
                id={id}
                dir="ltr"
                className="ltr text-left text-footnote"
                rows={5}
                maxLength={LONGEST}
                autoCapitalize="off"
                autoComplete="off"
                spellCheck={false}
                placeholder="https://…/sub/…  یا  vless://…"
                value={link}
                invalid={problem !== undefined}
                onChange={(event) => {
                  setLink(event.target.value)
                  claim.reset()
                }}
              />
            )}
          </Field>
          <MainAction text="افزودن سرویس" enabled={link.trim() !== ''} busy={claim.isPending} onClick={() => claim.mutate()} />
        </>
      )}
    </Screen>
  )
}
