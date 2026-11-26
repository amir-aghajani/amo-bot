import { Info, TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { TextLink } from '@/components/text-link'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { useMainShop } from '@/lib/auth'

/**
 * «مدیران ربات» (both panels, each shop its own): what the shop's admins — its customers whose role is admin — do from
 * the bot's private chat («⚙️ مدیریت»): the whole management, with the owner's access in the main bot (every section,
 * any shop, the panel's settings, backups, the update) and with the agent's in an agent's bot, decisions under their own
 * name and never about their own account. Nothing to set: who is an admin is the users' role, given and taken on the
 * users list; the bot asks it on each message, so a change holds from the admin's next one.
 */
export function BotAdminsSection() {
  const mainShop = useMainShop()

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">مدیریت فروشگاه از ربات</CardTitle>
          <CardDescription>
            مدیران ربات، یعنی کاربرانی که نقش مدیر دارند، در چت خصوصی ربات با دکمه «⚙️ مدیریت» یا <bdi dir="ltr">/admin</bdi> کل فروشگاه را مدیریت می‌کنند، نه فقط کارهای روزانه را. هر کاری که انجام
            می‌دهند به نام خودشان ثبت می‌شود و هیچ مدیری درباره حساب خودش تصمیم نمی‌گیرد: پرداخت، کیف پول، سرویس و سفارش خودش با مدیر دیگری است و به خودش تخفیف اختصاصی یا گروه «همکار» نمی‌دهد.
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        {mainShop ? (
          <div className="grid gap-1.5 text-body">
            <p>در ربات اصلی، مدیر ربات همان دسترسی مالک را دارد:</p>
            <ul className="grid list-disc gap-1.5 ps-4">
              <li>همه بخش‌های این پنل، در هر فروشگاهی، از جمله فروشگاه نماینده‌ها</li>
              <li>تنظیمات پنل</li>
              <li>پشتیبان‌گیری و بازگردانی، و به‌روزرسانی</li>
            </ul>
            <p>
              جز ورود به پنل، حساب‌های تلگرام مالک، آدرس سایت، توکن و نام کاربری ربات اصلی، آدرس Bot API، رمز Webhook، دیتابیس، تنظیمات Session پنل‌ها، پشتیبان‌های ساخته‌شده پیش از نسخه 0.11.0 و
              برگرداندن به نسخه قبلی که با خود مالک می‌ماند.
            </p>
          </div>
        ) : (
          <p className="text-body">
            در ربات این فروشگاه، مدیر ربات همان دسترسی نماینده را دارد: همه بخش‌هایی که نماینده در پنل خودش دارد، فقط برای همین فروشگاه. با پایان نمایندگی، مدیریت از ربات برای نماینده و مدیرانش بسته
            می‌شود.
          </p>
        )}

        <Callout tone="info" icon={Info}>
          مدیران ربات را در{' '}
          <TextLink inline to="/users?role=admin">
            لیست کاربران
          </TextLink>{' '}
          ببینید و از منوی هر کاربر، مدیر ربات کنید یا مدیریت را بردارید. با برداشتن نقش مدیر، از پیام بعدی‌اش مدیریت فروشگاه در ربات برایش بسته می‌شود.
        </Callout>
        <Callout tone="warning" icon={TriangleAlert}>
          نقش مدیر را فقط به کسانی بدهید که در همه چیز به آن‌ها اعتماد دارید:{' '}
          {mainShop
            ? 'مدیر ربات می‌تواند تنظیمات و توکن ربات را عوض کند، فروشگاه را از پشتیبان بازگردانی کند و دیگران را مدیر کند.'
            : 'مدیر ربات هر کاری را که نماینده از ربات می‌کند انجام می‌دهد، از تنظیمات ربات تا کیف پول و سرویس مشتری‌ها.'}{' '}
          محدودیتش فقط درباره حساب خودش است و دو مدیر این کارها را برای هم انجام می‌دهند.
        </Callout>
      </CardContent>
    </Card>
  )
}
