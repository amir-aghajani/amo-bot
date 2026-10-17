import { SectionedPage } from '@/components/sectioned-page'
import { USER_SECTIONS } from '@/components/shell/nav'
import { GroupsList } from '@/components/users/groups-list'
import { UsersList } from '@/components/users/users-list'

const HEADERS = {
  users: {
    title: 'کاربران',
    description: (
      <>
        هر کسی که به ربات <bdi dir="ltr">/start</bdi> زده؛ با موجودی کیف پول، سرویس‌ها، وضعیت حساب و گروه‌هایی که شما ساخته‌اید.
      </>
    ),
  },
  groups: { title: 'گروه‌ها', description: 'مشتری‌ها را برای خودتان دسته کنید — VIP، همکاران، یک کمپین —؛ پیام همگانی را می‌شود فقط برای یک گروه فرستاد.' },
}

/** «کاربران» (both panels), in two sections: the customers list and the customer groups. */
export function UsersPage() {
  return (
    <SectionedPage sections={USER_SECTIONS} header={(current) => HEADERS[current.value]}>
      {{ users: <UsersList />, groups: <GroupsList /> }}
    </SectionedPage>
  )
}
