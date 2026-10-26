import { useQuery } from '@tanstack/react-query'
import { ShieldAlert } from 'lucide-react'
import { PageStrip } from '@/components/page-strip'
import { useShellReads } from '@/components/shell/shell-context'
import { systemQuery } from '@/lib/queries'

/**
 * The owner's strip over every page while the shop runs on a PHP release with its branch's known security holes open
 * (SystemStatus `php_safe`): which release to move to, in the host's settings — and that no update is installed until
 * then (the update refuses it). Read after the page's own reads.
 */
export function PhpBanner() {
  const { data } = useQuery({ ...systemQuery, enabled: useShellReads() })
  const system = data?.system
  if (!system || system.php_safe) return null

  return (
    <PageStrip tone="danger" icon={ShieldAlert} role="status">
      <span>
        PHP این هاست (<bdi dir="ltr">{system.php}</bdi>) حفره امنیتی جدی دارد. آن را از تنظیمات هاست (مثلا «Select PHP Version» در cPanel) به PHP <bdi dir="ltr">{system.php_required}</bdi> یا بالاتر
        ببرید؛ تا آن وقت به‌روزرسانی فروشگاه نصب نمی‌شود.
      </span>
    </PageStrip>
  )
}
