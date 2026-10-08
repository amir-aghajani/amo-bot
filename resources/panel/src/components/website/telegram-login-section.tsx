import { Info } from 'lucide-react'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { SecretField } from '@/components/secret-field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Input } from '@/components/ui/input'
import { keptSecret, useWebsiteGroup } from '@/components/website/use-website-group'
import type { Website, WebsiteRequest } from '@/lib/api-types'

/** The card's draft: the request's Telegram fields — the secret blank until one is typed. */
type Draft = Required<Pick<WebsiteRequest, 'telegram_login' | 'telegram_client_id' | 'telegram_client_secret' | 'clear_telegram_client_secret'>>

function draftOf(website: Website): Draft {
  return { telegram_login: website.telegram.enabled, telegram_client_id: website.telegram.client_id ?? '', telegram_client_secret: '', clear_telegram_client_secret: false }
}

/**
 * «ورود با تلگرام»: customers sign in to the website with their Telegram account — the bot's customer, services and all —
 * under the Client ID @BotFather shows for the site (Bot Settings › Login Widget), which is not necessarily the bot's own
 * id; the Client Secret only the redirect flow needs, kept as a secret is (blank keeps it, a press empties it). What
 * BotFather must be told — the website's address among its Allowed URLs — is said in steps above the fields.
 */
export function TelegramLoginSection({ website }: { website: Website }) {
  const group = useWebsiteGroup(website, draftOf, (values) => ({
    ...values,
    // Left blank, the kept one stays.
    telegram_client_secret: values.telegram_client_secret || undefined,
  }))
  const { values, set, error } = group
  const { telegram } = website

  return (
    <SectionCard form={group} title="ورود مشتری با تلگرام" description="مشتری با حساب تلگرامش وارد وب‌سایت می‌شود و همان حسابی را می‌بیند که در ربات دارد؛ با همان سرویس‌ها و کیف پول.">
      <SwitchRow
        label="ورود با تلگرام"
        hint="خاموش که باشد، وب‌سایت ورود با تلگرام را نمی‌پذیرد. برای روشن کردنش Client ID لازم است."
        checked={values.telegram_login}
        onCheckedChange={(checked) => set('telegram_login', checked)}
        error={error('telegram_login')}
      />

      <LoginWidgetSteps url={website.url} />

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="website_telegram_client_id"
          label="Client ID"
          error={error('telegram_client_id')}
          hint={
            <>
              عددی که <bdi dir="ltr">@BotFather</bdi> در <bdi dir="ltr">Bot Settings › Login Widget</bdi> نشان می‌دهد.
              {telegram.bot_id !== null && (
                <>
                  {' '}
                  شناسه ربات این فروشگاه: <bdi dir="ltr">{telegram.bot_id}</bdi> — Client ID معمولا همین است، ولی عددی را بنویسید که BotFather نشان می‌دهد.
                </>
              )}
            </>
          }
        >
          <Input dir="ltr" inputMode="numeric" autoComplete="off" spellCheck={false} value={values.telegram_client_id} onChange={(e) => set('telegram_client_id', e.target.value)} />
        </Field>
        <SecretField
          id="website_telegram_client_secret"
          label="Client Secret"
          optional
          stored={keptSecret(telegram.has_secret)}
          value={values.telegram_client_secret}
          onChange={(value) => set('telegram_client_secret', value)}
          clear={values.clear_telegram_client_secret}
          onClear={(clear) => set('clear_telegram_client_secret', clear)}
          error={error('telegram_client_secret')}
          hint={
            telegram.has_secret ? 'یک Client Secret ذخیره شده است. فقط روش redirect به آن نیاز دارد؛ روش popup بدون آن کار می‌کند.' : 'فقط روش redirect به آن نیاز دارد؛ روش popup بدون آن کار می‌کند.'
          }
        />
      </div>
    </SectionCard>
  )
}

/** What the owner does in @BotFather: the website's address among the Login Widget's Allowed URLs, and the Client ID it shows. */
function LoginWidgetSteps({ url }: { url: string | null }) {
  return (
    <Callout tone="info" icon={Info}>
      <p className="font-medium">راه‌اندازی در BotFather</p>
      <ol className="mt-1.5 grid list-[persian] gap-1 ps-5">
        <li>
          در تلگرام <bdi dir="ltr">@BotFather</bdi> را باز کنید، ربات فروشگاه را انتخاب کنید و به <bdi dir="ltr">Bot Settings › Login Widget</bdi> بروید.
        </li>
        <li>
          {url === null ? (
            <>
              اول آدرس وب‌سایت را در بخش{' '}
              <TextLink inline to="/website-settings/connection">
                اتصال
              </TextLink>{' '}
              ذخیره کنید، بعد همان را در <bdi dir="ltr">Allowed URLs</bdi> اضافه کنید
            </>
          ) : (
            <>
              آدرس وب‌سایت، <bdi dir="ltr">{url}</bdi>، را در <bdi dir="ltr">Allowed URLs</bdi> اضافه کنید
            </>
          )}
          ؛ اگر وب‌سایت از روش redirect استفاده می‌کند، آدرس صفحه بازگشت را هم.
        </li>
        <li>Client ID را در فیلد پایین وارد کنید؛ برای روش redirect، Client Secret را هم.</li>
      </ol>
    </Callout>
  )
}
