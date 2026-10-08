import { ErrorState } from '@/components/error-state'
import { Page } from '@/components/page'
import { useTitleSubject } from '@/lib/document-title'

/** The not-found page's name: the tab's, and the phone topbar's at an address no page of the panel has. */
export const NOT_FOUND_TITLE = 'صفحه پیدا نشد'

/**
 * An address no page of the panel has (an old link, a mistyped one, a page only the other panel has): said in the shell,
 * whose sidebar still leads anywhere, with the way back and to the dashboard.
 */
export function NotFoundPage() {
  useTitleSubject(NOT_FOUND_TITLE)

  return (
    <Page>
      <ErrorState variant="page" kind="not-found" title="این صفحه پیدا نشد" description="آدرسی که باز کردید در پنل نیست؛ شاید لینکش قدیمی است یا درست نوشته نشده." />
    </Page>
  )
}
