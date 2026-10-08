import { useEffect } from 'react'
import { Info } from 'lucide-react'
import { Navigate, useLocation } from 'react-router'
import { useOwner } from '@/apps/admin/owner'
import { MissingShop } from '@/components/app-status'
import { Callout } from '@/components/callout'
import { useRetryWait } from '@/components/error-state'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { PublicScreen } from '@/components/public-screen'
import { SecretInput } from '@/components/secret-input'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { shopMissing } from '@/lib/api'
import { useAppName } from '@/lib/app-info'
import { useAuth } from '@/lib/auth'
import { useDocumentTitle } from '@/lib/document-title'
import { preloadPage } from '@/lib/prefetch'
import { useForm } from '@/lib/use-form'

/** What the guard that sent the owner here left in the history entry: the page they were on, whether their session ended. */
type Arrival = { from?: string; expired?: boolean } | null

/**
 * The owner's sign-in: the login config.php keeps — which the page, open to anyone, names to nobody: a lost one is set
 * again from «رمز را فراموش کرده‌اید؟» (pages/recover — a key off the host's files, no shell needed). Agents sign in to
 * their own panel, from the main bot.
 */
export function LoginPage() {
  const { session } = useAuth()
  const { login } = useOwner()
  const location = useLocation()
  const arrival = location.state as Arrival
  const { values, set, error, formError, failure, busy, submit, handleSubmit } = useForm({ username: '', password: '' })
  // Too many failed tries: the server's wait counts down, and the form waits as long (another try would be refused).
  const wait = useRetryWait(failure)
  const appName = useAppName()
  useDocumentTitle('ورود')
  // Where signing in leads: back where the guard came from, never to this page — its page loading while the form waits.
  const destination = arrival?.from && arrival.from !== '/login' ? arrival.from : '/'
  useEffect(() => preloadPage(destination), [destination])

  // Signed in — by this form, or already —, the panel goes to `destination`. This is the only way out: a navigation of
  // the form's own would race the one drawn here as the session lands.
  if (session) {
    return <Navigate to={destination} replace />
  }
  // The right login, at the address of a shop that is not there: no session opened, and the screen says where to go.
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

      <form onSubmit={signIn} className="grid gap-5 rounded-2xl border border-border bg-card p-6 shadow-card" noValidate>
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

      <div className="mt-6 grid gap-1.5 text-center text-footnote text-muted-foreground">
        <p>
          <TextLink to="/recover">رمز را فراموش کرده‌اید؟</TextLink>
        </p>
        <p>نماینده‌ها از ربات، با لینک «ورود به پنل»، به پنل نمایندگی خودشان وارد می‌شوند.</p>
      </div>
    </PublicScreen>
  )
}
