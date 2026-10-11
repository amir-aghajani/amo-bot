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
 * «تنظیمات وب‌سایت» (both panels): the shop's website, which talks to the shop through the Store API — its connection,
 * the ways customers sign in, reviews and the shop's admins. One read feeds every card; each save answers the whole
 * website, which replaces the read.
 *
 * `mailSettings`: where the panel sets up the shop's email (the owner's), which email sign-up needs; an agent has none.
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
