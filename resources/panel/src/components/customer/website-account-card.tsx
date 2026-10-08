import { useId, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { LogOut, ShieldOff } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { Fact, FactList } from '@/components/fact-list'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { UserIdentity, userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { CustomerAccount, CustomerMerge, UserDetailResponse, UserRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatDate, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/* A customer's account on the shop's website, on their page: how it signs in, the devices signed in, the accounts merged into it. */

/** A fact's column, a little narrower than a dialog's: the card sits in the page's side column. */
const FACTS = 'grid-cols-[minmax(5.5rem,auto)_1fr] gap-x-4 gap-y-2.5'

/** What support does to the account: turn two-factor sign-in off, sign it out of every device. */
type Operation = 'two-factor' | 'sessions'

interface WebsiteAccountCardProps {
  customer: UserRow
  account: CustomerAccount
}

/**
 * «ورود به وب‌سایت» — the ways the customer signs in on the shop's website (their Telegram account, a Google account, an
 * email with or without a password, the second step), the devices signed in, and the accounts merged into theirs. Support
 * turns two-factor sign-in off for one who lost the phone it was on, and signs them out of every device — each after a
 * second look; the account the server answers (or no device left) put in place on the page.
 */
export function WebsiteAccountCard({ customer, account }: WebsiteAccountCardProps) {
  const queryClient = useQueryClient()
  const mergesTitle = useId()
  const [asking, setAsking] = useState<Operation | null>(null)
  const settle = (next: CustomerAccount) => queryClient.setQueryData<UserDetailResponse>(queryKeys.user(customer.id), (current) => current && { ...current, account: next })

  const turnOff = useMutation({
    mutationFn: () => api.post(`/users/${customer.id}/two-factor/disable`),
    meta: { quiet: true },
    onSuccess: (answer) => {
      settle(answer.account)
      setAsking(null)
      toast.success(`ورود دو مرحله‌ای ${userLabel(customer)} خاموش شد`)
    },
  })
  const signOut = useMutation({
    mutationFn: () => api.post(`/users/${customer.id}/sessions/end`),
    meta: { quiet: true },
    onSuccess: () => {
      settle({ ...account, sessions: 0 })
      setAsking(null)
      toast.success(`${userLabel(customer)} از همه دستگاه‌ها خارج شد`)
    },
  })
  const running = asking === 'two-factor' ? turnOff : signOut
  const ask = (operation: Operation) => {
    turnOff.reset()
    signOut.reset()
    setAsking(operation)
  }

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>ورود به وب‌سایت</CardTitle>
          <CardDescription>راه‌های ورود مشتری به وب‌سایت فروشگاه و دستگاه‌هایی که با آن وارد شده است.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-5">
        <FactList className={FACTS}>
          {/* Which Telegram account, the page's header says. */}
          <Fact label="تلگرام">{customer.telegram_id === null ? <Missing>وصل نیست</Missing> : 'وصل است'}</Fact>
          <Fact label="گوگل">{account.google ? 'وصل است' : <Missing>وصل نیست</Missing>}</Fact>
          <Fact label="ایمیل">
            {account.email === null ? (
              <Missing>ایمیلی ندارد</Missing>
            ) : (
              <>
                <bdi dir="ltr">{account.email}</bdi>
                <span className="block text-footnote text-muted-foreground">{account.has_password ? 'با رمز عبور' : 'بدون رمز عبور'}</span>
              </>
            )}
          </Fact>
          <Fact label="ورود دو مرحله‌ای">
            {account.two_factor ? (
              <span className="flex flex-wrap items-center gap-2">
                <Badge variant="success">روشن</Badge>
                <Button variant="danger-outline" size="sm" icon={ShieldOff} onClick={() => ask('two-factor')}>
                  خاموش کردن ورود دو مرحله‌ای
                </Button>
              </span>
            ) : (
              <Missing>خاموش</Missing>
            )}
          </Fact>
          <Fact label="دستگاه‌ها">
            {account.sessions > 0 ? (
              <span className="flex flex-wrap items-center gap-2">
                <span className="tabular">{formatNumber(account.sessions)} دستگاه</span>
                <Button variant="danger-outline" size="sm" icon={LogOut} onClick={() => ask('sessions')}>
                  خروج از همه دستگاه‌ها
                </Button>
              </span>
            ) : (
              <Missing>در هیچ دستگاهی وارد نیست</Missing>
            )}
          </Fact>
        </FactList>

        {account.merges.length > 0 && (
          <section className="grid gap-2" aria-labelledby={mergesTitle}>
            <h3 id={mergesTitle} className="text-footnote font-medium text-muted-foreground">
              حساب‌های ادغام‌شده
            </h3>
            <ul className="grid gap-3">
              {account.merges.map((merge) => (
                <MergedAccount key={merge.merged_user_id} merge={merge} />
              ))}
            </ul>
          </section>
        )}
      </CardContent>

      <ConfirmModal
        open={asking !== null}
        onClose={() => setAsking(null)}
        destructive
        pending={running.isPending}
        error={running.error ? messageOf(running.error) : null}
        onConfirm={() => running.mutate()}
        {...(asking === 'sessions'
          ? {
              title: 'خروج از همه دستگاه‌ها',
              description: `${userLabel(customer)} از همه دستگاه‌هایی که با آن وارد وب‌سایت شده است خارج می‌شود.`,
              confirmLabel: 'خروج از همه',
              children: 'برای ادامه باید دوباره وارد وب‌سایت شود؛ ربات تلگرام و سرویس‌هایش دست نمی‌خورد.',
            }
          : {
              title: 'خاموش کردن ورود دو مرحله‌ای',
              description: `${userLabel(customer)} از این پس فقط با ایمیل و رمز عبور وارد وب‌سایت می‌شود.`,
              confirmLabel: 'خاموش کن',
              children:
                'برای مشتری‌ای که گوشی برنامه احراز هویتش را از دست داده است. به او خبر داده می‌شود — در ربات تلگرام و با ایمیل، هر کدام که دارد — و در اعلان‌های وب‌سایتش هم می‌ماند. هر وقت بخواهد می‌تواند دوباره آن را روشن کند.',
            })}
      />
    </Card>
  )
}

/** A slot the account has nothing in, in secondary ink. */
function Missing({ children }: { children: string }) {
  return <span className="text-muted-foreground">{children}</span>
}

/**
 * An account merged into this one: who it was — its name, its handle or email —, its former number and when the customer
 * merged it (from their website: the one way two accounts are made one).
 */
function MergedAccount({ merge }: { merge: CustomerMerge }) {
  const { merged } = merge

  return (
    <li className="grid gap-1">
      <UserIdentity user={{ id: merge.merged_user_id, name: merged.name, username: merged.username, telegram_id: merged.telegram_id, email: merged.email }} />
      <span className="text-footnote text-muted-foreground">
        حساب{' '}
        <bdi dir="ltr" className="text-foreground">
          #{merge.merged_user_id}
        </bdi>
        {merged.google && ' · با گوگل'} · به درخواست خود مشتری · {formatDate(merge.created_at, { dateStyle: 'medium' })}
      </span>
    </li>
  )
}
