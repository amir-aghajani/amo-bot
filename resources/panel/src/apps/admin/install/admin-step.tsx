import { CircleCheck } from 'lucide-react'
import { installApi } from '@/apps/admin/install/install-api'
import type { StepProps } from '@/apps/admin/install/steps'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { PublicPanel } from '@/components/public-screen'
import { SecretInput } from '@/components/secret-input'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useForm } from '@/lib/use-form'

/** The panel's login: the username and password written (hashed) to config.php. */
export function AdminStep({ status, onDone }: StepProps) {
  const { values, set, error, formError, busy, submit, handleSubmit } = useForm({ username: status.admin.username || 'admin', password: '', password_confirmation: '' })

  const save = handleSubmit(async () => {
    const fresh = await submit(() => installApi.post('/admin', values))
    if (fresh) onDone(fresh, 'site')
  })

  return (
    <PublicPanel title="ورود پنل" description="نام کاربری و رمزی که با آن وارد همین پنل می‌شوید. رمز به صورت hash در فایل config.php نوشته می‌شود.">
      {status.admin.configured && (
        <Callout tone="success" icon={CircleCheck}>
          ورود پنل تعیین شده است (نام کاربری <bdi dir="ltr">{status.admin.username}</bdi>
          ). برای عوض کردنش فرم را پر کنید.
        </Callout>
      )}
      <form onSubmit={save} noValidate className="grid gap-4">
        <Field id="admin_username" label="نام کاربری" error={error('username')} hint="حروف انگلیسی، عدد، نقطه، _، @ یا -">
          <Input dir="ltr" value={values.username} onChange={(e) => set('username', e.target.value)} autoComplete="username" autoCapitalize="none" spellCheck={false} />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id="admin_password" label="رمز عبور" error={error('password')} hint="دست‌کم 8 کاراکتر">
            <SecretInput value={values.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" />
          </Field>
          <Field id="admin_password_confirmation" label="تکرار رمز عبور" error={error('password_confirmation')}>
            <SecretInput value={values.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} autoComplete="new-password" />
          </Field>
        </div>
        <FormError message={formError} />
        <div className="flex flex-wrap items-center gap-2">
          <Button type="submit" className="w-fit" busy={busy}>
            ذخیره
          </Button>
          {status.admin.configured && (
            <Button variant="secondary" onClick={() => onDone(status, 'site')} disabled={busy}>
              ادامه بدون تغییر
            </Button>
          )}
        </div>
      </form>
    </PublicPanel>
  )
}
