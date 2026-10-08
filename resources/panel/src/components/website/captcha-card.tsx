import { Info } from 'lucide-react'
import { Callout } from '@/components/callout'
import { driverDraft, driverPayload, type DriverDraft } from '@/components/driver-form/draft'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { DriverPicker } from '@/components/driver-form/driver-picker'
import { SectionCard } from '@/components/section-card'
import { useWebsiteGroup } from '@/components/website/use-website-group'
import type { DriverDescription, Website, WebsiteCaptchaRequest } from '@/lib/api-types'
import { sameData } from '@/lib/utils'

type Captcha = Website['captcha']

/** No captcha: the first choice, and what it means. */
const NONE = {
  value: 'none',
  label: 'خاموش',
  hint: 'فرم‌های وب‌سایت تایید امنیتی نمی‌خواهند؛ ثبت‌نام، ورود با رمز عبور، بازیابی رمز و ثبت نظر فقط با محدودیت تعداد درخواست‌ها در برابر ربات‌ها نگه داشته می‌شوند.',
}

/** The card's draft: the captcha's driver — or `none` — and its form's fields as typed, under one key, as the server refuses them (`captcha.<field>`). */
interface CaptchaDraft {
  captcha: DriverDraft
}

/** The captcha driver `key` names; undefined for none. */
function driverOf(captcha: Captcha, key: string): DriverDescription | undefined {
  return captcha.drivers.find((driver) => driver.key === key)
}

/** A driver's form as the website keeps it: what the card starts from, and what another driver picked brings. */
function formOf(captcha: Captcha, key: string): DriverDraft {
  const driver = driverOf(captcha, key)
  return { ...(driver === undefined ? {} : driverDraft(driver, captcha.values[driver.key])), driver: key }
}

/** The captcha as the PATCH sends it: none, or a driver and its form's fields — a secret only when typed. */
function requestOf(captcha: Captcha, values: DriverDraft): WebsiteCaptchaRequest {
  const driver = driverOf(captcha, String(values.driver))
  if (driver === undefined) return { driver: 'none' }
  // The fields are the driver's own form's — the API's closed request of that driver.
  return { ...driverPayload(driver, values), driver: driver.key } as WebsiteCaptchaRequest
}

/**
 * The captcha the website's forms ask — a sign-up, a sign-in with a password, a password reset, a review, and any form of
 * the site's own its backend asks the shop about —: none, or a driver picked from those the server describes, its form drawn
 * from its description (components/driver-form): Cloudflare Turnstile's keys, ALTCHA's none. A secret kept goes with
 * its widget's site key: changed, the field says to type it again, as the server asks. The server refuses a field under
 * `captcha.<field>`: typing it lets its refusal go, another driver picked every one of them.
 */
export function CaptchaCard({ website }: { website: Website }) {
  const group = useWebsiteGroup<CaptchaDraft>(
    website,
    (saved) => ({ captcha: formOf(saved.captcha, saved.captcha.driver) }),
    (values) => ({ captcha: requestOf(website.captcha, values.captcha) }),
  )
  const { values, set, errors, setErrors, error } = group
  const captcha = values.captcha
  const driver = driverOf(website.captcha, String(captcha.driver))

  /**
   * The draft changed: what the admin fixed (`fixed`, by the refusal's field) no longer said — the form lets a field's
   * refusal go by the field's own name, and these are said under its fields' (`captcha.<field>`) —, and every word of the
   * refusal once the draft is back where it started — what it sends, as the form measures it —, as the form does: it was
   * about what is no longer on screen.
   */
  const change = (next: DriverDraft, fixed: (field: string) => boolean) => {
    const back = sameData(requestOf(website.captcha, next), requestOf(website.captcha, formOf(website.captcha, website.captcha.driver)))
    const left = Object.entries(errors).filter(([field]) => !back && !fixed(field))
    if (left.length < Object.keys(errors).length) setErrors(Object.fromEntries(left))
    set('captcha', next)
  }

  return (
    <SectionCard form={group} title="تایید امنیتی" description="فرم‌های وب‌سایت را در برابر درخواست‌های خودکار ربات‌ها نگه می‌دارد.">
      <DriverPicker
        id="website_captcha_driver"
        label="نوع تایید امنیتی"
        drivers={website.captcha.drivers}
        value={String(captcha.driver)}
        onChange={(key) => change(formOf(website.captcha, key), (field) => field.startsWith('captcha.'))}
        error={error('captcha.driver')}
        none={NONE}
      />
      {driver && (
        <DriverFields
          driver={driver}
          stored={website.captcha.values[driver.key]}
          draft={captcha}
          set={(name, value) => change({ ...captcha, [name]: value }, (field) => field === `captcha.${name}`)}
          error={(name) => error(`captcha.${name}`)}
          idPrefix="website_captcha"
        />
      )}
      {driver && (
        <Callout tone="info" icon={Info}>
          ثبت‌نام، ورود با رمز عبور، بازیابی رمز عبور و ثبت نظر از وب‌سایت، توکن ویجت را هم می‌خواهند (فیلد <code dir="ltr">captcha</code>)، هر کدام برای action خودش: <bdi dir="ltr">sign_up</bdi>،{' '}
          <bdi dir="ltr">sign_in</bdi>، <bdi dir="ltr">password_reset</bdi> و <bdi dir="ltr">review</bdi>. فرم‌های دیگر وب‌سایت، مثل فرم تماس با ما، را سرور وب‌سایت با{' '}
          <bdi dir="ltr">POST /captcha/verify</bdi> از فروشگاه می‌پرسد؛ کلید مخفی فقط نزد فروشگاه است.
        </Callout>
      )}
    </SectionCard>
  )
}
