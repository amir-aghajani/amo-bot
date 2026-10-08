import { Send } from 'lucide-react'
import { toast } from 'sonner'
import { useConfigGroup } from '@/apps/admin/settings/use-config-group'
import { driverDraft, driverPayload, type DriverDraft } from '@/components/driver-form/draft'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { DriverPicker } from '@/components/driver-form/driver-picker'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { SectionCard } from '@/components/section-card'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { ConfigMailRequest, ConfigMailSettings, DriverDescription } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { trimmed, useForm } from '@/lib/use-form'

/** No email at all — no driver —: the first choice, and what it means. */
const NONE = {
  value: 'none',
  label: 'خاموش',
  hint: 'ایمیلی فرستاده نمی‌شود: ثبت‌نام با ایمیل در وب‌سایت‌ها روشن نمی‌شود، کد بازیابی رمز عبور به مشتری نمی‌رسد و مشتری‌هایی که تلگرام ندارند یا ربات را بسته‌اند اعلان‌های فروشگاه را با ایمیل نمی‌گیرند.',
}

/** The mail driver `transport` names; undefined for none. */
function driverOf(settings: ConfigMailSettings, transport: string): DriverDescription | undefined {
  return settings.drivers.find((driver) => driver.key === transport)
}

/** A driver's form as config.php holds it: what the screen starts from, and what another driver picked brings. */
function formOf(settings: ConfigMailSettings, transport: string): DriverDraft {
  const driver = driverOf(settings, transport)
  return { ...(driver === undefined ? {} : driverDraft(driver, settings.values[driver.key])), transport }
}

/**
 * The group's PUT: none, or a driver's own request — its form's fields shown, a secret only when typed — with who the
 * emails come from. What the other drivers keep is not sent: it stays as it is.
 */
function requestOf(settings: ConfigMailSettings, values: DriverDraft): ConfigMailRequest {
  const driver = driverOf(settings, String(values.transport))
  if (driver === undefined) return { transport: 'none' }
  // The fields are the driver's own form's — the API's closed request of that driver (DriverFormsTest holds them together).
  return { ...driverPayload(driver, values), transport: driver.key, from_address: String(values.from_address), from_name: String(values.from_name) } as ConfigMailRequest
}

/**
 * The shop's email — what a website's sign-up codes and password resets go out by, every shop's, and the shop's notices
 * to a customer Telegram cannot reach (no Telegram account, or the bot turned away): how — none, or a mail driver, its
 * form drawn from its description (components/driver-form) —, and who the emails come from while any goes out. A
 * password kept goes with the server, the encryption and the account it was given for: moved, the field says to type it
 * again, as the server asks.
 */
export function MailSection({ settings, disabled }: { settings: ConfigMailSettings; disabled: boolean }) {
  const group = useConfigGroup<DriverDraft>(
    { ...formOf(settings, settings.transport), from_address: settings.from_address, from_name: settings.from_name },
    {
      save: (values) => api.put('/settings/config/mail', requestOf(settings, values)),
      saved: 'تنظیمات ایمیل ذخیره شد',
      // Whether email goes out is what the website's email sign-up waits for (its `email.mail_ready`).
      invalidates: [queryKeys.website],
      // What is unsaved is what the save would send: another way picked and the one saved picked again is no change.
      reads: (values) => trimmed(requestOf(settings, values)),
    },
  )
  const { values, set, patch, error } = group
  const driver = driverOf(settings, String(values.transport))
  const sender = driver?.traits.sender

  return (
    <SectionCard
      form={group}
      title="ارسال ایمیل"
      description="کدهای ثبت‌نام و بازیابی رمز عبور وب‌سایت‌ها، وب‌سایت فروشگاه اصلی و نماینده‌ها، و اعلان‌های فروشگاه به مشتری‌هایی که تلگرام ندارند یا ربات را بسته‌اند، با این تنظیمات فرستاده می‌شوند."
      disabled={disabled}
    >
      <DriverPicker
        id="mail_transport"
        label="روش ارسال"
        drivers={settings.drivers}
        value={String(values.transport)}
        onChange={(transport) => patch(formOf(settings, transport))}
        error={error('transport')}
        none={NONE}
      />

      {driver && (
        <>
          <DriverFields driver={driver} stored={settings.values[driver.key]} draft={values} set={set} error={error} idPrefix="mail" />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="mail_from_address" label="ایمیل فرستنده" error={error('from_address')} hint={typeof sender === 'string' ? sender : undefined}>
              <Input
                dir="ltr"
                inputMode="email"
                autoComplete="off"
                spellCheck={false}
                value={String(values.from_address)}
                onChange={(e) => set('from_address', e.target.value)}
                placeholder="no-reply@example.com"
              />
            </Field>
            <Field id="mail_from_name" label="نام فرستنده" optional error={error('from_name')} hint="خالی بماند، نام هر فروشگاه: نام برنامه برای فروشگاه اصلی و نام ربات برای فروشگاه هر نماینده.">
              <Input value={String(values.from_name)} onChange={(e) => set('from_name', e.target.value)} />
            </Field>
          </div>
        </>
      )}
    </SectionCard>
  )
}

/**
 * A short email by the mail settings as saved, to an address the owner types — to see the shop's email go out. A refusal
 * of the address is said under it; any other failure — no email going out yet, the mail server's own refusal — on the
 * card's line, in its words.
 */
export function MailTestCard() {
  const form = useForm({ to: '' })
  const send = form.handleSubmit(async () => {
    const sent = await form.submit(() => api.post('/settings/config/mail/test', form.values))
    if (sent !== undefined) toast.success('ایمیل تست فرستاده شد؛ صندوق ورودی و پوشه Spam را نگاه کنید')
  })

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">ارسال ایمیل تست</CardTitle>
          <CardDescription>یک ایمیل کوتاه با تنظیمات ذخیره‌شده فرستاده می‌شود؛ تغییرات بالا را اول ذخیره کنید.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <form noValidate className="grid gap-4" onSubmit={send}>
          <Field id="mail_test_to" label="ایمیل گیرنده" error={form.error('to')}>
            {(control) => (
              <div className="flex items-start gap-2">
                <Input
                  {...control}
                  dir="ltr"
                  inputMode="email"
                  autoComplete="email"
                  spellCheck={false}
                  value={form.values.to}
                  onChange={(e) => form.set('to', e.target.value)}
                  placeholder="you@example.com"
                  className="min-w-0 flex-1"
                />
                <Button type="submit" variant="secondary" icon={Send} busy={form.busy}>
                  ارسال
                </Button>
              </div>
            )}
          </Field>
          <FormError message={form.formError} failure={form.failure} />
        </form>
      </CardContent>
    </Card>
  )
}
