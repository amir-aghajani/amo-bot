import { GIFTS_SECTION } from '@/apps/admin/nav'
import { MassGrantsCard } from '@/components/broadcasts/mass-grants-card'
import { BroadcastsPage, type BroadcastsSection } from '@/pages/broadcasts'

/** «هدیه همگانی»: the owner's — the gift reaches every bot's services. */
const GIFTS: BroadcastsSection = {
  section: GIFTS_SECTION,
  description: 'روز و حجم اضافه برای سرویس‌های فعال همه سرورها یکجا — یا فقط نماینده‌ها، یا یک سرور — با پیامی که هر مشتری در ربات می‌گیرد.',
  content: <MassGrantsCard />,
}

/** The owner's «ارسال همگانی»: the shop's page, with the mass gift beside the messages. */
export function OwnerBroadcastsPage() {
  return <BroadcastsPage more={[GIFTS]} />
}
