import { SearchX } from 'lucide-react'
import { useNavigate } from 'react-router'
import { Button } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Empty } from '@/apps/app/ui/states'

/** An address of the app that leads nowhere: back home. */
export default function NotFoundScreen() {
  const navigate = useNavigate()

  return (
    <Screen back="/">
      <Empty icon={SearchX} title="این صفحه پیدا نشد" action={<Button onClick={() => navigate('/', { replace: true })}>بازگشت به خانه</Button>} />
    </Screen>
  )
}
