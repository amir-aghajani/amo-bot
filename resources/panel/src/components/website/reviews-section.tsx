import { Info } from 'lucide-react'
import { Callout } from '@/components/callout'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { useWebsiteGroup } from '@/components/website/use-website-group'
import type { Website, WebsiteRequest } from '@/lib/api-types'

/** The card's draft: its switch, as the request takes it. */
type Draft = Required<Pick<WebsiteRequest, 'reviews_enabled'>>

function draftOf(website: Website): Draft {
  return { reviews_enabled: website.reviews.enabled }
}

/**
 * «نظرات»: whether the website shows approved reviews and takes new ones. Guests may review only while the website asks
 * a captcha; otherwise only signed-in customers can, which the card says.
 */
export function ReviewsSection({ website }: { website: Website }) {
  const group = useWebsiteGroup(website, draftOf, (values) => values)
  const { values, set, error } = group

  return (
    <SectionCard form={group} title="نظرات مشتریان در وب‌سایت" description="مشتری‌ها در وب‌سایت درباره فروشگاه نظر می‌نویسند و وب‌سایت نظرهایی را که پشتیبانی تایید کرده نشان می‌دهد.">
      <SwitchRow
        label="نظرات در وب‌سایت"
        hint="روشن باشد، وب‌سایت نظرهای تاییدشده را نشان می‌دهد و نظر تازه می‌گیرد؛ خاموش باشد، هیچ‌کدام."
        checked={values.reviews_enabled}
        onCheckedChange={(checked) => set('reviews_enabled', checked)}
        error={error('reviews_enabled')}
      />
      <Callout tone="info" icon={Info}>
        هر نظر تا وقتی در صفحه{' '}
        <TextLink inline to="/reviews">
          نظرات
        </TextLink>{' '}
        تایید نشود، در وب‌سایت دیده نمی‌شود.{' '}
        {website.captcha.driver === 'none' ? (
          <>
            تایید امنیتی وب‌سایت خاموش است، پس فقط مشتری‌هایی که وارد حسابشان شده‌اند نظر می‌نویسند؛ برای نظر مهمان، تایید امنیتی را در{' '}
            <TextLink inline to="/website-settings/email">
              ورود با ایمیل
            </TextLink>{' '}
            روشن کنید.
          </>
        ) : (
          'مهمان‌ها هم پشت تایید امنیتی وب‌سایت نظر می‌نویسند.'
        )}
      </Callout>
    </SectionCard>
  )
}
