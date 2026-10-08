import { useQuery } from '@tanstack/react-query'
import { ErrorState } from '@/components/error-state'
import { SectionedPage } from '@/components/sectioned-page'
import { WEBSITE_SETTINGS_SECTIONS } from '@/components/shell/nav'
import { Skeleton } from '@/components/ui/skeleton'
import { ConnectionSection } from '@/components/website/connection-section'
import { EmailSection } from '@/components/website/email-section'
import { GoogleLoginSection } from '@/components/website/google-login-section'
import { ReviewsSection } from '@/components/website/reviews-section'
import { StaffSection } from '@/components/website/staff-section'
import { TelegramLoginSection } from '@/components/website/telegram-login-section'
import { websiteQuery } from '@/components/website/website-query'

/**
 * The shop's website — the main bot's, or an agent's own —, which talks to the shop through the Store API. A section per
 * subject, picked from the settings' sidebar (a select on a phone): the connection — the API on or off, the site's
 * address and the other origins its pages call from, the API's own address and its key —, then the ways its customers
 * sign in: Telegram, Google, an email with a password and the captcha that guards it; the customers' reviews it shows
 * and takes (ReviewsSection); and last the shop's admins, who work the shop from the website with what it lets them do
 * (StaffSection). One read feeds them all, every card's save puts the website it answers back in its place, and the
 * other sections stay mounted (hidden) so an unsaved draft survives switching.
 *
 * `mailSettings`: where the panel sets the shop's email up (the owner's «تنظیمات پنل › ایمیل»), which email sign-up
 * waits for; an agent's panel has none — the owner sets it up.
 */
export function WebsiteSettingsPage({ mailSettings }: { mailSettings?: string }) {
  const { data, error, refetch } = useQuery(websiteQuery)

  return (
    <SectionedPage
      sections={WEBSITE_SETTINGS_SECTIONS}
      width="narrow"
      header={() => ({ title: 'تنظیمات وب‌سایت', description: 'وب‌سایت فروشگاه از راه API به فروشگاه وصل می‌شود و مشتری در آن با تلگرام، گوگل یا ایمیل وارد حسابش می‌شود.' })}
      above={() => (
        <>
          {error && <ErrorState what="تنظیمات وب‌سایت" error={error} onRetry={() => void refetch()} />}
          {!data && !error && <Skeleton className="h-64 w-full rounded-xl" />}
        </>
      )}
    >
      {data
        ? {
            connection: <ConnectionSection website={data.website} />,
            telegram: <TelegramLoginSection website={data.website} />,
            google: <GoogleLoginSection website={data.website} />,
            email: <EmailSection website={data.website} mailSettings={mailSettings} />,
            reviews: <ReviewsSection website={data.website} />,
            staff: <StaffSection website={data.website} />,
          }
        : EMPTY}
    </SectionedPage>
  )
}

const EMPTY = { connection: null, telegram: null, google: null, email: null, reviews: null, staff: null }
