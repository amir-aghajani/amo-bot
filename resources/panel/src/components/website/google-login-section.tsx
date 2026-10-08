import { Info } from 'lucide-react'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { TextLink } from '@/components/text-link'
import { Input } from '@/components/ui/input'
import { useWebsiteGroup } from '@/components/website/use-website-group'
import type { Website, WebsiteRequest } from '@/lib/api-types'
import { originOf } from '@/lib/utils'

/** The card's draft: Google's client id as the request takes it — blank for none. */
type Draft = Required<Pick<WebsiteRequest, 'google_client_id'>>

function draftOf(website: Website): Draft {
  return { google_client_id: website.google.client_id ?? '' }
}

/**
 * «ورود با گوگل»: customers sign in to the website with their Google account — to the account it signs in to already,
 * else to the one with the address Google vouches for, else to a new one — under the site's OAuth client id in Google
 * Cloud; blank, Google sign-in is off. How to make one — the website's origin among its Authorized JavaScript origins —
 * is said in steps above the field.
 */
export function GoogleLoginSection({ website }: { website: Website }) {
  const group = useWebsiteGroup(website, draftOf, (values) => values)
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="ورود مشتری با گوگل"
      description="مشتری با حساب گوگلش وارد وب‌سایت می‌شود؛ اگر با همان ایمیل در فروشگاه حساب داشته باشد به همان حساب، وگرنه حساب تازه‌ای برایش ساخته می‌شود."
    >
      <OAuthClientSteps url={website.url} />

      <Field
        id="website_google_client_id"
        label="Client ID گوگل"
        error={error('google_client_id')}
        hint={
          <>
            مثل <bdi dir="ltr">1234567890-abc123.apps.googleusercontent.com</bdi>؛ خالی بماند، ورود با گوگل خاموش است.
          </>
        }
      >
        <Input dir="ltr" autoComplete="off" spellCheck={false} value={values.google_client_id} onChange={(e) => set('google_client_id', e.target.value)} />
      </Field>
    </SectionCard>
  )
}

/** What the owner does in Google Cloud: an OAuth client for a web application, the website's origin among its JavaScript origins, its Client ID here. */
function OAuthClientSteps({ url }: { url: string | null }) {
  return (
    <Callout tone="info" icon={Info}>
      <p className="font-medium">راه‌اندازی در Google Cloud</p>
      <ol className="mt-1.5 grid list-[persian] gap-1 ps-5">
        <li>
          در <bdi dir="ltr">Google Cloud Console</bdi> به <bdi dir="ltr">{'APIs & Services › Credentials'}</bdi> بروید.
        </li>
        <li>
          <bdi dir="ltr">{'Create Credentials › OAuth client ID'}</bdi> را بزنید و نوع <bdi dir="ltr">Web application</bdi> را انتخاب کنید.
        </li>
        <li>
          {url === null ? (
            <>
              اول آدرس وب‌سایت را در بخش{' '}
              <TextLink inline to="/website-settings/connection">
                اتصال
              </TextLink>{' '}
              ذخیره کنید، بعد آن را در <bdi dir="ltr">Authorized JavaScript origins</bdi> اضافه کنید
            </>
          ) : (
            <>
              آدرس وب‌سایت، <bdi dir="ltr">{originOf(url)}</bdi>، را در <bdi dir="ltr">Authorized JavaScript origins</bdi> اضافه کنید
            </>
          )}
          ؛ Originهای دیگر وب‌سایت را هم، اگر ورود با گوگل روی آن‌ها هم لازم است.
        </li>
        <li>
          <bdi dir="ltr">Create</bdi> را بزنید و Client ID را که <bdi dir="ltr">Google Cloud</bdi> نشان می‌دهد در فیلد پایین وارد کنید.
        </li>
      </ol>
    </Callout>
  )
}
