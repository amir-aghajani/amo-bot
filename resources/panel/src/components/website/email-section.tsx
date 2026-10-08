import { CircleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { CaptchaCard } from '@/components/website/captcha-card'
import { useWebsiteGroup } from '@/components/website/use-website-group'
import type { Website, WebsiteRequest } from '@/lib/api-types'

interface EmailSectionProps {
  website: Website
  /** Where this panel sets the shop's email up (the owner's «تنظیمات پنل › ایمیل»); none in an agent's — the owner does. */
  mailSettings?: string
}

/**
 * «ورود با ایمیل»: customers sign up on the website with their email and a password — a code to the address, the account
 * made once it is typed back, so it takes the shop's email —, and the captcha that guards every way in with a password,
 * and any form of the site's own (CaptchaCard).
 */
export function EmailSection({ website, mailSettings }: EmailSectionProps) {
  return (
    <>
      <EmailSignupCard website={website} mailSettings={mailSettings} />
      <CaptchaCard website={website} />
    </>
  )
}

/** The sign-up card's draft: its switch as the request takes it. */
type SignupDraft = Required<Pick<WebsiteRequest, 'email_signup'>>

function signupDraftOf(website: Website): SignupDraft {
  return { email_signup: website.email.enabled }
}

/**
 * Signing up with an email: the switch — held while no email goes out and it is kept off, since the code would reach
 * nobody; kept on, it may still be switched off —, and a word that the accounts made already keep their way in whatever
 * it says.
 */
function EmailSignupCard({ website, mailSettings }: EmailSectionProps) {
  const group = useWebsiteGroup(website, signupDraftOf, (values) => values)
  const { values, set, error } = group
  const { email } = website

  return (
    <SectionCard
      form={group}
      title="ثبت‌نام با ایمیل"
      description="مشتری‌ای که از قبل با ایمیل حساب دارد، هر وضعیتی که این گزینه داشته باشد، با ایمیل و رمز عبورش وارد می‌شود و رمز فراموش‌شده‌اش را بازیابی می‌کند؛ این گزینه فقط ثبت‌نام تازه را باز یا بسته می‌کند."
    >
      {!email.mail_ready && <MailNotReady kept={email.enabled} mailSettings={mailSettings} />}
      <SwitchRow
        label="ثبت‌نام با ایمیل و رمز عبور"
        hint="یک کد به آدرسی که مشتری وارد می‌کند فرستاده می‌شود و حسابش وقتی ساخته می‌شود که همان کد را در وب‌سایت وارد کند."
        checked={values.email_signup}
        onCheckedChange={(checked) => set('email_signup', checked)}
        held={!email.mail_ready && !email.enabled}
        error={error('email_signup')}
      />
    </SectionCard>
  )
}

/** No email goes out: sign-up's code would reach nobody. Where it is set up is the panel's to say — the owner's links its email settings (`mailSettings`), an agent's says the owner does. */
function MailNotReady({ kept, mailSettings }: { kept: boolean; mailSettings?: string }) {
  return (
    <Callout tone="warning" icon={CircleAlert}>
      ارسال ایمیل راه نیفتاده است و کد ثبت‌نام بدون آن به مشتری نمی‌رسد؛ تا راه نیفتد، {kept ? 'کسی نمی‌تواند با ایمیل ثبت‌نام کند.' : 'این گزینه روشن نمی‌شود.'}{' '}
      {mailSettings === undefined ? (
        'ارسال ایمیل را مالک فروشگاه راه می‌اندازد.'
      ) : (
        <>
          ارسال ایمیل را در{' '}
          <TextLink inline to={mailSettings}>
            تنظیمات پنل › ایمیل
          </TextLink>{' '}
          راه بیندازید.
        </>
      )}
    </Callout>
  )
}
