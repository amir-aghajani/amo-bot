import { useEffect } from 'react'
import { Info } from 'lucide-react'
import { Navigate, useLocation } from 'react-router'
import { useOwner } from '@/apps/admin/owner'
import { MissingShop } from '@/components/app-status'
import { Callout } from '@/components/callout'
import { useRetryWait } from '@/components/error-state'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { PublicPanel, PublicScreen } from '@/components/public-screen'
import { SecretInput } from '@/components/secret-input'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { shopMissing } from '@/lib/api'
import { useAppName } from '@/lib/app-info'
import { afterSignIn, useAuth, type LoginArrival } from '@/lib/auth'
import { useDocumentTitle } from '@/lib/document-title'
import { preloadPage } from '@/lib/prefetch'
import { useForm } from '@/lib/use-form'

/**
 * The owner's sign-in with the login config.php keeps. A lost login is set again from «رمز را فراموش کرده‌اید؟»
 * (pages/recover, with a key read off the host's files). Agents sign in to their own panel from the main bot.
 */
export function LoginPage() {
  const { session } = useAuth()
  const { login } = useOwner()
  const location = useLocation()
  const arrival = location.state as LoginArrival | null
  const { values, set, error, formError, failure, busy, submit, handleSubmit } = useForm({ username: '', password: '' })
  // After too many failed tries the server's wait counts down, and the submit is held as long.
  const wait = useRetryWait(failure)
  const appName = useAppName()
  useDocumentTitle('ورود')
  const destination = afterSignIn(arrival?.from)
  useEffect(() => preloadPage(destination), [destination])

  // Signed in (by this form, or already): this redirect is the only way out — a navigation of the form's own would race
  // it as the session lands.
  if (session) {
    return <Navigate to={destination} replace />
  }
  // The right login at the address of a shop that is not there: no session was opened.
  if (shopMissing(failure)) {
    return <MissingShop failure={failure} />
  }

  const signIn = handleSubmit(() => submit(() => login(values.username, values.password)))

  return (
    <PublicScreen title={`ورود به ${appName}`} description="برای ورود به پنل مدیریت، نام کاربری و رمز عبور را وارد کنید.">
      {arrival?.expired && (
        <Callout tone="info" icon={Info} className="mb-4">
          زمان ورود شما تمام شد؛ دوباره وارد شوید تا به همان صفحه برگردید.
        </Callout>
      )}

      <PublicPanel>
        <form onSubmit={signIn} className="grid gap-5" noValidate>
          <Field id="username" label="نام کاربری" error={error('username')}>
            <Input
              type="text"
              dir="ltr"
              autoComplete="username"
              autoCapitalize="none"
              spellCheck={false}
              autoFocus
              value={values.username}
              onChange={(e) => set('username', e.target.value)}
              className="h-9"
            />
          </Field>

          <Field id="password" label="رمز عبور" error={error('password')}>
            <SecretInput autoComplete="current-password" value={values.password} onChange={(e) => set('password', e.target.value)} className="h-9" />
          </Field>

          <FormError message={formError} failure={failure} />

          <Button type="submit" size="lg" className="w-full" busy={busy} aria-disabled={wait > 0}>
            {busy ? 'در حال ورود…' : 'ورود'}
          </Button>
        </form>
      </PublicPanel>

      <div className="mt-6 grid gap-1.5 text-center text-footnote text-muted-foreground">
        <p>
          <TextLink to="/recover">رمز را فراموش کرده‌اید؟</TextLink>
        </p>
        <p>نماینده‌ها از ربات، با لینک «ورود به پنل»، به پنل نمایندگی خودشان وارد می‌شوند.</p>
      </div>
    </PublicScreen>
  )
}
