import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CircleCheck, KeyRound } from 'lucide-react'
import { Navigate } from 'react-router'
import { toast } from 'sonner'
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
import type { RecoveryKeyResponse } from '@/lib/api-types'
import { useAuth } from '@/lib/auth'
import { useDocumentTitle } from '@/lib/document-title'
import { formatDate } from '@/lib/format'
import { useForm } from '@/lib/use-form'

/**
 * The owner's way back in without a shell — a password forgotten, or no login set at all —, which the sign-in page's
 * «رمز را فراموش کرده‌اید؟» leads to: the panel writes a one-time key to a file of the host's (storage/recovery-key.txt),
 * the owner reads it in their host's File Manager and sets a new username and password with it. Done, they are signed
 * in under it, and every other session ends.
 */
export function RecoverPage() {
  const { session } = useAuth()
  const { recoveryKey, recover } = useOwner()
  const [written, setWritten] = useState<RecoveryKeyResponse | null>(null)
  const write = useMutation({ mutationFn: recoveryKey, onSuccess: (key) => setWritten(key) })
  const { values, set, error, formError, failure, busy, submit, handleSubmit } = useForm({ key: '', username: '', password: '', password_confirmation: '' })
  // Too many wrong keys: the server's wait counts down, and the form waits as long.
  const wait = useRetryWait(failure)
  useDocumentTitle('بازیابی ورود')

  // Signed in — by this form, or already —, the panel opens.
  if (session) {
    return <Navigate to="/" replace />
  }
  // A new login, set at the address of a shop that is not there: nothing written, and the screen says where to go.
  if (shopMissing(failure)) {
    return <MissingShop failure={failure} />
  }

  const save = handleSubmit(async () => {
    if (await submit(() => recover(values))) toast.success('نام کاربری و رمز تازه ذخیره شد')
  })

  return (
    <PublicScreen wide title="بازیابی ورود" description="نام کاربری یا رمز پنل را فراموش کرده‌اید؟ با یک کلید از فایل‌های هاست، ورود تازه بگذارید.">
      <div className="grid gap-4">
        <PublicPanel
          title="کلید بازیابی"
          description="برای این‌که فقط صاحب هاست بتواند ورود پنل را عوض کند، یک کلید یک‌بارمصرف در فایل‌های هاست نوشته می‌شود. آن را بسازید، فایلش را در File Manager هاست (مثلا cPanel) باز کنید و متنش را در فرم پایین بنویسید."
        >
          {written && (
            <Callout tone="success" icon={CircleCheck} role="status">
              کلید در فایل <bdi dir="ltr">{written.file}</bdi> نوشته شد و تا ساعت {formatDate(written.expires_at, { timeStyle: 'short' })} کار می‌کند.
            </Callout>
          )}
          {/* Asked again while the key still works, the same key and its hour; once over, a new one. */}
          <Button variant={written ? 'secondary' : 'default'} className="w-fit" icon={KeyRound} busy={write.isPending} onClick={() => write.mutate()}>
            ساختن کلید
          </Button>
        </PublicPanel>

        <PublicPanel title="ورود تازه" description="متن کلید را از فایل کپی کنید و نام کاربری و رمزی را که از این به بعد با آن وارد می‌شوید بنویسید.">
          <form onSubmit={save} noValidate className="grid gap-4">
            <Field id="recovery_key" label="کلید بازیابی" error={error('key')}>
              <SecretInput value={values.key} onChange={(e) => set('key', e.target.value)} autoComplete="off" />
            </Field>
            <Field id="recovery_username" label="نام کاربری" error={error('username')} hint="حروف انگلیسی، عدد، نقطه، _، @ یا -">
              <Input dir="ltr" value={values.username} onChange={(e) => set('username', e.target.value)} autoComplete="username" autoCapitalize="none" spellCheck={false} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field id="recovery_password" label="رمز عبور تازه" error={error('password')} hint="دست‌کم 8 کاراکتر">
                <SecretInput value={values.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" />
              </Field>
              <Field id="recovery_password_confirmation" label="تکرار رمز عبور" error={error('password_confirmation')}>
                <SecretInput value={values.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} autoComplete="new-password" />
              </Field>
            </div>
            <FormError message={formError} failure={failure} />
            <Button type="submit" className="w-fit" busy={busy} aria-disabled={wait > 0}>
              ذخیره و ورود
            </Button>
          </form>
        </PublicPanel>
      </div>

      <p className="mt-6 text-center text-footnote text-muted-foreground">
        <TextLink to="/login">بازگشت به صفحه ورود</TextLink>
      </p>
    </PublicScreen>
  )
}
