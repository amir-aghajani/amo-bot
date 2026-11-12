import { useState } from 'react'
import { KeyRound, Mail } from 'lucide-react'
import { useAccount } from '@/apps/app/lib/session'
import { BiometricSection } from '@/apps/app/screens/account/parts/biometric'
import { PasswordSheet } from '@/apps/app/screens/account/parts/password-sheet'
import { SessionsSection } from '@/apps/app/screens/account/parts/sessions'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { ErrorView, LoadingBlocks } from '@/apps/app/ui/states'

/*
 * «امنیت»: the fingerprint or face that proves it is the customer (on a device whose Telegram has it), the password of
 * the account's email (once it has one; else the way to add the email), and the devices signed in to the account.
 */

export default function SecurityScreen() {
  const account = useAccount()
  const [password, setPassword] = useState(false)

  if (account.isError) {
    return (
      <Screen back title="امنیت">
        <ErrorView error={account.error} retry={() => void account.refetch()} />
      </Screen>
    )
  }
  if (account.data === undefined) {
    return (
      <Screen back title="امنیت">
        <LoadingBlocks count={3} />
      </Screen>
    )
  }

  const { customer, session } = account.data

  return (
    <Screen back title="امنیت" subtitle="ورود و دستگاه‌های حساب شما">
      <BiometricSection held={session.biometric} />

      <Section title="رمز عبور" footer={customer.email === null ? 'رمز عبور برای ورود با ایمیل است؛ اول یک ایمیل به حساب اضافه کنید.' : 'تغییر رمز، دستگاه‌های دیگر را از حساب خارج می‌کند.'}>
        {customer.email === null ? (
          <Row icon={Mail} tint="#ff9500" title="افزودن ایمیل" to="/account/email" />
        ) : (
          <Row
            icon={KeyRound}
            tint="#5856d6"
            title={customer.has_password ? 'تغییر رمز عبور' : 'تعیین رمز عبور'}
            subtitle={customer.has_password ? 'رمز ورود با ایمیل' : 'این حساب هنوز رمز عبور ندارد'}
            onClick={() => setPassword(true)}
          />
        )}
      </Section>

      <SessionsSection />

      {customer.email !== null && <PasswordSheet open={password} onClose={() => setPassword(false)} customer={customer} />}
    </Screen>
  )
}
