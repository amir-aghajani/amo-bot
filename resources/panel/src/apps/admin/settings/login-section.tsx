import { toast } from 'sonner'
import { useOwner } from '@/apps/admin/owner'
import { Field } from '@/components/field'
import { SecretInput } from '@/components/secret-input'
import { SectionCard } from '@/components/section-card'
import { Input } from '@/components/ui/input'
import { useSession } from '@/lib/auth'
import { useForm } from '@/lib/use-form'

/**
 * The owner's own login — the username and password config.php keeps —, «تنظیمات پنل» › «ورود به پنل»: the current
 * password proves it is them, and a new password left blank keeps the one they have. Saved, every other browser signed
 * in to the panel is signed out; this one stays, and the form starts again from the login as it is now.
 */
export function LoginSection({ disabled }: { disabled: boolean }) {
  const session = useSession()
  const { changeCredentials } = useOwner()
  const form = useForm(
    { username: session.name, current_password: '', password: '', password_confirmation: '' },
    {
      follow: true,
      // What would change is the login: the current password only proves it is them — one a password manager filled in
      // is nothing unsaved —, and a password is read as typed (Input::password: no trimming).
      reads: ({ username, password, password_confirmation }) => ({ username: username.trim(), password, password_confirmation }),
    },
  )
  const { values, set, error } = form

  const save = async (): Promise<boolean> => {
    const renamed = values.username.trim() !== session.name
    if ((await form.submit(() => changeCredentials(values))) === undefined) return false
    toast.success(renamed && values.password !== '' ? 'نام کاربری و رمز عبور عوض شد' : renamed ? 'نام کاربری عوض شد' : 'رمز عبور عوض شد')
    // The passwords typed go with the save.
    form.reset({ username: values.username.trim(), current_password: '', password: '', password_confirmation: '' })
    return true
  }

  return (
    <SectionCard
      form={{ ...form, save }}
      title="نام کاربری و رمز عبور"
      description="با ذخیره، مرورگرها و دستگاه‌های دیگری که وارد پنل شده‌اند خارج می‌شوند؛ این مرورگر وارد می‌ماند."
      disabled={disabled}
    >
      <Field id="login_username" label="نام کاربری" error={error('username')} hint="حروف انگلیسی، عدد، نقطه، _، @ یا -">
        <Input dir="ltr" value={values.username} onChange={(e) => set('username', e.target.value)} autoComplete="username" autoCapitalize="none" spellCheck={false} />
      </Field>
      <Field id="login_current_password" label="رمز عبور فعلی" error={error('current_password')}>
        <SecretInput value={values.current_password} onChange={(e) => set('current_password', e.target.value)} autoComplete="current-password" />
      </Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="login_password" label="رمز عبور جدید" error={error('password')} hint="دست‌کم 8 کاراکتر؛ خالی بماند، رمز عبور عوض نمی‌شود.">
          <SecretInput value={values.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" />
        </Field>
        <Field id="login_password_confirmation" label="تکرار رمز عبور جدید" error={error('password_confirmation')}>
          <SecretInput value={values.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} autoComplete="new-password" />
        </Field>
      </div>
    </SectionCard>
  )
}
