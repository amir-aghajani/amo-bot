import { SectionedPage } from '@/components/sectioned-page'
import { USER_SECTIONS } from '@/components/shell/nav'
import { GroupsList } from '@/components/users/groups-list'
import { UsersList } from '@/components/users/users-list'

const HEADERS = {
  users: { title: 'کاربران', description: 'هر کسی که به ربات /start زده؛ با موجودی کیف پول، سرویس‌ها، وضعیت حساب و گروه‌هایی که شما ساخته‌اید.' },
  groups: { title: 'گروه‌ها', description: 'مشتری‌ها را برای خودتان دسته کنید — VIP، همکاران، یک کمپین —؛ پیام همگانی را می‌شود فقط برای یک گروه فرستاد.' },
}

/**
 * Two sections, listed in the sidebar: everyone who ever talked to the bot — searchable, paged, filterable by status and
 * by one of the admin's groups, each name opening the customer's page (pages/customer), with ban/unban, the bot's admin
 * role, the wallet and the groups each is in — and the groups themselves (a broadcast can go to one). Both stay mounted,
 * so each keeps its search and page.
 */
export function UsersPage() {
  return (
    <SectionedPage sections={USER_SECTIONS} header={(current) => HEADERS[current.value]}>
      {{ users: <UsersList />, groups: <GroupsList /> }}
    </SectionedPage>
  )
}
