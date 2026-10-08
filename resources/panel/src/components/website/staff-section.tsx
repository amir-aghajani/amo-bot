import { useId } from 'react'
import { Info, TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { useWebsiteGroup } from '@/components/website/use-website-group'
import type { StaffGrant, Website, WebsiteRequest } from '@/lib/api-types'

/** The card's draft: its two switches and the grants, as the request takes them. */
type Draft = Required<Pick<WebsiteRequest, 'staff_enabled' | 'staff_strong_sign_in' | 'staff_grants'>>

function draftOf({ staff }: Website): Draft {
  return { staff_enabled: staff.enabled, staff_strong_sign_in: staff.strong_sign_in, staff_grants: staff.grants }
}

/** What each grant lets the shop's admins do on the website, in the grants' own order (the API's). */
const GRANTS: { value: StaffGrant; label: string; hint: string }[] = [
  { value: 'catalog', label: 'پلن‌ها و دسته‌بندی‌ها', hint: 'ساختن، ویرایش، مرتب کردن، روشن و خاموش کردن و حذف پلن‌ها و دسته‌بندی‌ها.' },
  { value: 'wallet', label: 'کیف پول مشتری', hint: 'افزایش و کم کردن موجودی کیف پول مشتری.' },
  { value: 'refunds', label: 'بازپرداخت', hint: 'بازپرداخت پرداخت مشتری، همان «بازپرداخت» صفحه پرداخت‌ها.' },
  { value: 'extend', label: 'افزایش زمان و حجم سرویس', hint: 'دادن روز و حجم بیشتر به سرویس یک مشتری.' },
  { value: 'delete', label: 'حذف سرویس', hint: 'حذف همیشگی سرویس مشتری از سرورش و از فروشگاه.' },
  { value: 'account_security', label: 'امنیت حساب مشتری', hint: 'خاموش کردن ورود دو مرحله‌ای حساب وب‌سایت مشتری و خارج کردنش از همه دستگاه‌ها.' },
]

/** The grants with one of them switched: kept in the grants' own order, as the server keeps them, so a draft put back reads as unchanged. */
function switched(grants: StaffGrant[], grant: StaffGrant, on: boolean): StaffGrant[] {
  return GRANTS.map(({ value }) => value).filter((value) => (value === grant ? on : grants.includes(value)))
}

/**
 * «مدیران سایت»: the shop's admins — its bot's admins, the customers given the role on the users list — work the shop
 * from its website with their own accounts there (the Store API's /admin/*): the switch that lets them in, whether they
 * must have signed in strongly, and what the shop lets them do beyond its daily work, a switch a grant. Who they are is
 * the users list's, narrowed to the role — and the card says plainly what the guards on them are: each admin is kept
 * from their own account alone, so two admins can do for each other what each is refused for themselves.
 */
export function StaffSection({ website }: { website: Website }) {
  const group = useWebsiteGroup(website, draftOf, (values) => values)
  const { values, set, error } = group
  const ids = useId()
  const grantsError = error('staff_grants')

  return (
    <SectionCard
      form={group}
      title="مدیریت فروشگاه از وب‌سایت"
      description="مدیران ربات با حساب وب‌سایت خودشان کارهای روزانه فروشگاه را در وب‌سایت انجام می‌دهند: رسیدها و پرداخت‌ها، سفارش‌ها، سرویس‌ها، تیکت‌ها، مشتری‌ها و ارسال‌های همگانی. روش‌های پرداخت، ربات، گروه گزارش‌ها، وب‌سایت و اینکه چه کسی مدیر است فقط از پنل تغییر می‌کند. ورود هر مدیر تا ۱۲ ساعت برای کار مدیریتی معتبر است و بعد از آن باید ورودش را دوباره تایید کند؛ تایید پرداخت هم، چون سرویس تحویل می‌دهد، ورود ۱۵ دقیقه گذشته را می‌خواهد."
    >
      <Callout tone="info" icon={Info}>
        مدیران ربات همان کاربرانی هستند که نقش مدیر دارند؛ آن‌ها را در{' '}
        <TextLink inline to="/users?role=admin">
          لیست کاربران
        </TextLink>{' '}
        ببینید و از منوی هر کاربر، مدیر ربات کنید یا مدیریت را بردارید.
      </Callout>
      <Callout tone="warning" icon={TriangleAlert}>
        هر مدیر فقط درباره حساب خودش محدود است: پرداخت، کیف پول، سرویس و نظر خودش با مدیر دیگری است و حساب مدیران و نماینده‌ها فقط از پنل تغییر می‌کند. ولی دو مدیر این کارها را برای هم انجام می‌دهند؛
        پس نقش مدیر را فقط به کسانی بدهید که به آن‌ها اعتماد دارید.
      </Callout>

      <SwitchRow
        label="کار مدیران از وب‌سایت"
        hint="خاموش باشد، وب‌سایت هیچ کار مدیریتی را نمی‌پذیرد."
        checked={values.staff_enabled}
        onCheckedChange={(checked) => set('staff_enabled', checked)}
        error={error('staff_enabled')}
      />
      <SwitchRow
        label="ورود امن مدیران"
        hint="کار مدیریتی فقط از ورودی پذیرفته می‌شود که با تلگرام، گوگل یا رمز عبور همراه کد ورود دو مرحله‌ای بوده است؛ خاموش باشد، رمز عبور تنها هم کافی است."
        checked={values.staff_strong_sign_in}
        onCheckedChange={(checked) => set('staff_strong_sign_in', checked)}
        error={error('staff_strong_sign_in')}
      />

      <div
        role="group"
        aria-labelledby={`${ids}-title`}
        aria-describedby={`${ids}-about${grantsError ? ` ${ids}-error` : ''}`}
        data-invalid={grantsError ? '' : undefined}
        tabIndex={grantsError ? -1 : undefined}
        className="grid gap-3 rounded-md outline-none focus-visible:focus-ring"
      >
        <div className="grid gap-1">
          <span id={`${ids}-title`} className="text-body font-medium">
            کارهای بیشتر
          </span>
          <p id={`${ids}-about`} className="text-footnote leading-relaxed text-muted-foreground">
            این کارها فقط وقتی از وب‌سایت انجام می‌شود که روشنشان کنید، و مدیر برای هر کدام باید در ۱۵ دقیقه گذشته وارد شده یا ورودش را دوباره تایید کرده باشد.
          </p>
        </div>
        {GRANTS.map((grant) => (
          <SwitchRow
            key={grant.value}
            label={grant.label}
            hint={grant.hint}
            checked={values.staff_grants.includes(grant.value)}
            onCheckedChange={(checked) => set('staff_grants', switched(values.staff_grants, grant.value, checked))}
          />
        ))}
        {grantsError && (
          <p id={`${ids}-error`} className="text-footnote text-danger">
            {grantsError}
          </p>
        )}
      </div>
    </SectionCard>
  )
}
