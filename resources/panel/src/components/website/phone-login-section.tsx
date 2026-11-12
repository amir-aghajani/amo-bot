import { CircleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { useWebsiteGroup } from '@/components/website/use-website-group'
import type { Website, WebsiteRequest } from '@/lib/api-types'

interface PhoneLoginSectionProps {
  website: Website
  /** Where this panel sets the installation's SMS up (the owner's «تنظیمات پنل › پیامک»); none in an agent's — the owner does. */
  smsSettings?: string
}

/** The card's draft: its switch as the request takes it. */
type Draft = Required<Pick<WebsiteRequest, 'phone_login'>>

function draftOf(website: Website): Draft {
  return { phone_login: website.phone.enabled }
}

/**
 * «ورود با موبایل»: the customer signs in with a code texted to their mobile. It cannot be switched on while no SMS goes
 * out for the site — none set up (the owner's panel links where), or an agent's site the owner does not text for (they
 * pay for every code) —; kept on meanwhile, it signs nobody in.
 */
export function PhoneLoginSection({ website, smsSettings }: PhoneLoginSectionProps) {
  const group = useWebsiteGroup(website, draftOf, (values) => values)
  const { values, set, error } = group
  const { phone } = website
  const ready = phone.sms === 'ready'

  return (
    <SectionCard
      form={group}
      title="ورود مشتری با شماره موبایل"
      description="مشتری شماره موبایلش را وارد می‌کند و کدی شش رقمی با پیامک می‌گیرد؛ با همان کد وارد حسابش می‌شود، و اگر با این شماره حسابی نداشته باشد حساب تازه‌ای برایش ساخته می‌شود."
    >
      {!ready && <SmsNotReady sms={phone.sms} kept={phone.enabled} smsSettings={smsSettings} />}
      <SwitchRow
        label="ورود با شماره موبایل (پیامک)"
        hint="فقط شماره موبایل ایران پذیرفته می‌شود. مشتری‌ای که شماره‌اش را در ربات فرستاده، با همین شماره به همان حساب ربات وارد می‌شود."
        checked={values.phone_login}
        onCheckedChange={(checked) => set('phone_login', checked)}
        held={!ready && !phone.enabled}
        error={error('phone_login')}
      />
    </SectionCard>
  )
}

/**
 * Why no code goes out: the owner has not set SMS up, or does not text codes for agents' sites — said to the owner (whose
 * panel links its settings, `smsSettings`) as their own doing, to an agent as the owner's. `kept`: the switch stays on
 * meanwhile, and signs nobody in.
 */
function SmsNotReady({ sms, kept, smsSettings }: { sms: Website['phone']['sms']; kept: boolean; smsSettings?: string }) {
  const meanwhile = kept ? 'کسی نمی‌تواند با شماره موبایل وارد شود.' : 'این گزینه روشن نمی‌شود.'

  return (
    <Callout tone="warning" icon={CircleAlert}>
      {sms === 'not_for_agents' && smsSettings !== undefined ? (
        <>
          پیامک را برای سایت نماینده‌ها باز نکرده‌اید؛ تا آن را در «
          <TextLink inline to={smsSettings}>
            تنظیمات پنل › پیامک
          </TextLink>
          » باز نکنید، {meanwhile}
        </>
      ) : sms === 'not_for_agents' ? (
        `مالک فروشگاه پیامک را برای سایت نماینده‌ها باز نکرده است؛ تا باز نکند، ${meanwhile}`
      ) : smsSettings === undefined ? (
        `پیامک را مالک فروشگاه راه می‌اندازد و هنوز راه‌اندازی نشده است؛ تا راه نیفتد، ${meanwhile}`
      ) : (
        <>
          پیامک در «
          <TextLink inline to={smsSettings}>
            تنظیمات پنل › پیامک
          </TextLink>
          » راه‌اندازی نشده است؛ تا راه نیفتد، {meanwhile}
        </>
      )}
    </Callout>
  )
}
