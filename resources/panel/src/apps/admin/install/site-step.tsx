import { installApi } from '@/apps/admin/install/install-api'
import type { StepProps } from '@/apps/admin/install/steps'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { PublicPanel } from '@/components/public-screen'
import { SecretInput } from '@/components/secret-input'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useAppName } from '@/lib/app-info'
import { appConfig } from '@/lib/config'
import { useForm } from '@/lib/use-form'

/** Where the browser reached the shop: what its address most likely is, its sub-folder in it. */
const siteAddress = () => `${window.location.origin}${appConfig.basePath}`

/** The shop's name and public address, and the bot's token when it is at hand (checked with Telegram first). */
export function SiteStep({ status, onDone }: StepProps) {
  const appName = useAppName()
  const { values, set, error, formError, busy, submit, handleSubmit } = useForm({
    name: status.site.name || appName,
    url: status.site.url.startsWith('http://localhost') || status.site.url === '' ? siteAddress() : status.site.url,
    token: '',
  })

  const save = handleSubmit(async () => {
    const fresh = await submit(() => installApi.post('/site', values))
    if (fresh) onDone(fresh, 'finish')
  })

  return (
    <PublicPanel
      title="سایت و ربات"
      description="نام فروشگاه و آدرسی که سایت با آن باز می‌شود (Webhook ربات و آدرس Cron از آن ساخته می‌شوند). توکن ربات را اگر الان دارید وارد کنید؛ بعدا هم از «تنظیمات» می‌شود."
    >
      <form onSubmit={save} noValidate className="grid gap-4">
        <Field id="site_name" label="نام فروشگاه" error={error('name')}>
          <Input value={values.name} onChange={(e) => set('name', e.target.value)} />
        </Field>
        <Field id="site_url" label="آدرس سایت" error={error('url')} hint="با https://، با زیرپوشه اگر در زیرپوشه نصب شده، و بدون / در انتها">
          <Input dir="ltr" value={values.url} onChange={(e) => set('url', e.target.value)} spellCheck={false} />
        </Field>
        <Field
          id="site_token"
          label="توکن ربات"
          error={error('token')}
          optional
          hint={
            status.telegram.configured ? (
              <>
                توکن ربات <bdi dir="ltr">@{status.telegram.username || '…'}</bdi> ذخیره شده است؛ برای عوض کردنش توکن تازه را بنویسید.
              </>
            ) : (
              <>
                از <bdi dir="ltr">@BotFather</bdi>، شبیه <bdi dir="ltr">123456789:AAF…</bdi>
              </>
            )
          }
        >
          <SecretInput value={values.token} onChange={(e) => set('token', e.target.value)} />
        </Field>
        <FormError message={formError} />
        <Button type="submit" className="w-fit" busy={busy}>
          ذخیره و ادامه
        </Button>
      </form>
    </PublicPanel>
  )
}
