import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Mail, MailCheck } from 'lucide-react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { keys, queryClient } from '@/apps/app/lib/query'
import { withRecentSignIn } from '@/apps/app/lib/recent'
import { useAccount } from '@/apps/app/lib/session'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { MergeOffer } from '@/apps/app/screens/account/parts/merge-offer'
import { result } from '@/apps/app/telegram/haptics'
import { confirmBox } from '@/apps/app/telegram/webapp'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Field, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Empty, ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import { Countdown, IconTile } from '@/apps/app/ui/visuals'
import type { StoreAccountResponse, StoreCustomer, StoreMergeOfferResponse } from '@/lib/api-types'
import { digitsOnly, formatNumber } from '@/lib/format'

/*
 * «افزودن ایمیل»: an email and its password as another way into the account — to sign in on the shop's website. The
 * address is proven first: a six-digit code goes to it (`POST /me/identities/email`), typed back here (`…/verify`). An
 * address another account of the shop has answers a merge offer instead (parts/merge-offer), which the customer may take
 * (`POST /me/merge`) or leave. Each step asks a recent sign-in, which lib/recent proves without leaving the app.
 */

/** The shortest password the shop takes (its sign-up rule). */
const SHORTEST = 8

/** The reason Telegram's fingerprint prompt gives, when the step needs a recent sign-in. */
const REASON = 'افزودن ایمیل به حساب'

type Step =
  | { at: 'address' }
  /** A code went to `email`; it lasts until `until`. */
  | { at: 'code'; email: string; password: string; until: string }
  | { at: 'merge'; email: string; offer: StoreMergeOfferResponse['merge'] }

/** The account as the shop now has it: its customer replaced, everything else read again. */
function accountIs(customer: StoreCustomer): void {
  queryClient.setQueryData<StoreAccountResponse>(keys.me, (account) => (account === undefined ? account : { ...account, customer }))
  void queryClient.invalidateQueries({ queryKey: keys.me })
}

/** A refusal shown under its field stays there; any other is a toast. */
function refused(error: unknown, fields: string[]): void {
  result('error')
  if (fields.every((field) => fieldProblem(error, field) === undefined)) toast.error(problemOf(error))
}

export default function EmailScreen() {
  const navigate = useNavigate()
  const account = useAccount()
  const [step, setStep] = useState<Step>({ at: 'address' })
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')

  const send = useMutation({
    mutationFn: (body: { email: string; password: string }) => withRecentSignIn(REASON, () => store.post('/me/identities/email', body)),
    meta: { quiet: true },
    onSuccess: ({ expires_in: seconds }, body) => {
      result('success')
      setCode('')
      setStep({ at: 'code', ...body, until: new Date(Date.now() + seconds * 1000).toISOString() })
    },
    onError: (error) => refused(error, ['email', 'password']),
  })

  const verify = useMutation({
    mutationFn: (body: { email: string; code: string }) => withRecentSignIn(REASON, () => store.post('/me/identities/email/verify', body)),
    meta: { quiet: true },
    onSuccess: (answer, body) => {
      if ('merge' in answer) {
        result('warning')
        setStep({ at: 'merge', email: body.email, offer: answer.merge })
        return
      }
      accountIs(answer.customer)
      told('ایمیل به حساب شما اضافه شد.')
      navigate('/account', { replace: true })
    },
    onError: (error) => refused(error, ['code']),
  })

  const merge = useMutation({
    mutationFn: (token: string) => withRecentSignIn('یکی کردن دو حساب', () => store.post('/me/merge', { token })),
    onSuccess: ({ customer }) => {
      accountIs(customer)
      // Everything of the other account is this one's now: every read is asked again.
      void queryClient.invalidateQueries()
      told('دو حساب یکی شد.')
      navigate('/account', { replace: true })
    },
  })

  if (account.isError) {
    return (
      <Screen back title="افزودن ایمیل">
        <ErrorView error={account.error} retry={() => void account.refetch()} />
      </Screen>
    )
  }
  if (account.data === undefined) {
    return (
      <Screen back title="افزودن ایمیل">
        <LoadingBlocks count={2} />
      </Screen>
    )
  }
  const linked = account.data.customer.email
  if (linked !== null && step.at === 'address') {
    return (
      <Screen back title="ایمیل">
        <Empty icon={MailCheck} title="ایمیل حساب شما" text={<span className="ltr">{linked}</span>} action={<Button onClick={() => navigate('/account', { replace: true })}>بازگشت به حساب</Button>} />
      </Screen>
    )
  }

  if (step.at === 'merge') {
    const accept = async () => {
      if (await confirmBox('دو حساب یکی شوند؟ این کار برگشت ندارد.')) merge.mutate(step.offer.token)
    }

    return (
      <Screen back="/account" title="یکی کردن حساب‌ها" bottom="action">
        <MergeOffer offer={step.offer} email={step.email} />
        <Button variant="plain" block onClick={() => navigate('/account', { replace: true })}>
          انصراف
        </Button>
        <MainAction text="یکی کردن دو حساب" busy={merge.isPending} onClick={() => void accept()} />
      </Screen>
    )
  }

  if (step.at === 'code') {
    const typed = digitsOnly(code)
    const codeProblem = verify.isError ? fieldProblem(verify.error, 'code') : undefined
    const submit = () => {
      if (typed.length === 6 && !verify.isPending) verify.mutate({ email: step.email, code: typed })
    }

    return (
      <Screen back title="کد تایید ایمیل" bottom="action">
        <div className="flex flex-col items-center gap-3 px-4 pt-2 text-center">
          <IconTile icon={MailCheck} size="2xl" look="soft" round />
          <p className="text-hint text-footnote">
            {`کد ${formatNumber(6)} رقمی به `}
            <span className="ltr text-text">{step.email}</span>
            {' فرستاده شد. اگر در صندوق ورودی نیست، پوشه هرزنامه را ببینید.'}
          </p>
        </div>
        <Field label="کد تایید" error={codeProblem}>
          {(id) => (
            <TextInput
              id={id}
              ltr
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              className="text-center text-heading font-semibold"
              value={code}
              invalid={codeProblem !== undefined}
              onChange={(event) => {
                verify.reset()
                setCode(event.target.value)
              }}
              onKeyDown={(event) => {
                if (event.key === 'Enter') submit()
              }}
            />
          )}
        </Field>
        <p className="text-hint text-center text-caption">
          اعتبار کد: <Countdown to={step.until} done="تمام شد؛ کد تازه بخواهید" />
        </p>
        <div className="flex justify-center gap-2">
          <Button variant="secondary" size="sm" busy={send.isPending} onClick={() => send.mutate({ email: step.email, password: step.password })}>
            ارسال دوباره کد
          </Button>
          <Button variant="plain" size="sm" onClick={() => setStep({ at: 'address' })}>
            تغییر ایمیل
          </Button>
        </div>
        <MainAction text="تایید ایمیل" enabled={typed.length === 6} busy={verify.isPending} onClick={submit} />
      </Screen>
    )
  }

  const emailProblem = send.isError ? fieldProblem(send.error, 'email') : undefined
  const passwordProblem = send.isError ? fieldProblem(send.error, 'password') : undefined
  const ready = email.trim() !== '' && password.length >= SHORTEST
  const submit = () => {
    if (ready && !send.isPending) send.mutate({ email: email.trim(), password })
  }

  return (
    <Screen back title="افزودن ایمیل" bottom="action">
      <div className="flex flex-col items-center gap-3 px-4 pt-2 text-center">
        <IconTile icon={Mail} size="2xl" look="soft" round />
        <p className="text-hint text-footnote">با ایمیل و رمزی که اینجا انتخاب می‌کنید، از وب‌سایت فروشگاه هم وارد همین حساب می‌شوید. اول یک کد به ایمیل شما فرستاده می‌شود.</p>
      </div>
      <Field label="ایمیل" error={emailProblem}>
        {(id) => (
          <TextInput
            id={id}
            ltr
            type="email"
            inputMode="email"
            autoComplete="email"
            placeholder="name@example.com"
            value={email}
            invalid={emailProblem !== undefined}
            onChange={(event) => {
              send.reset()
              setEmail(event.target.value)
            }}
          />
        )}
      </Field>
      <Field label="رمز عبور" error={passwordProblem} hint={`دست‌کم ${formatNumber(SHORTEST)} نویسه؛ برای ورود با این ایمیل`}>
        {(id) => (
          <TextInput
            id={id}
            ltr
            type="password"
            autoComplete="new-password"
            value={password}
            invalid={passwordProblem !== undefined}
            onChange={(event) => {
              send.reset()
              setPassword(event.target.value)
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter') submit()
            }}
          />
        )}
      </Field>
      <MainAction text="ارسال کد" enabled={ready} busy={send.isPending} onClick={submit} />
    </Screen>
  )
}
