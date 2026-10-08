import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { LoaderCircle } from 'lucide-react'
import { AdminStep } from '@/apps/admin/install/admin-step'
import { DatabaseStep } from '@/apps/admin/install/database-step'
import { FinishStep } from '@/apps/admin/install/finish-step'
import { hasKey, keyRefusal, readStatus } from '@/apps/admin/install/install-api'
import { KeyStep } from '@/apps/admin/install/key-step'
import { RequirementsStep } from '@/apps/admin/install/requirements-step'
import { SiteStep } from '@/apps/admin/install/site-step'
import { StepBar } from '@/apps/admin/install/step-bar'
import { firstOpen, type Step } from '@/apps/admin/install/steps'
import { ErrorState } from '@/components/error-state'
import { PublicPanel, PublicScreen } from '@/components/public-screen'
import type { InstallStatus } from '@/lib/api-types'
import { useAppName } from '@/lib/app-info'
import { useDocumentTitle } from '@/lib/document-title'
import { queryKeys } from '@/lib/query-keys'

/**
 * The web installer, for a shop that is not installed yet (the panel opens it instead of everything else): first the
 * installer's key — whoever installs must be able to read a file on the host —, then the server's requirements, the
 * database and its tables, the panel's login, the shop's name and address with the bot's token if it is at hand, then
 * the end of the installation — every step over /api/install, which is gone afterwards. It opens on the first step the
 * installation still needs; a done step can be gone back to.
 */
export function InstallPage() {
  const queryClient = useQueryClient()
  const appName = useAppName()
  useDocumentTitle('نصب')
  const status = useQuery({ queryKey: queryKeys.installStatus, queryFn: readStatus })
  const [picked, setPicked] = useState<Step | null>(null)
  const step = picked ?? (status.data ? firstOpen(status.data) : null)
  const refusal = keyRefusal(status.error)

  const done = (fresh: InstallStatus, next: Step) => {
    queryClient.setQueryData(queryKeys.installStatus, fresh)
    setPicked(next)
  }

  return (
    <PublicScreen title={`نصب ${appName}`} description="چند قدم تا راه افتادن فروشگاه" wide>
      {refusal !== undefined ? (
        <KeyStep refusal={refusal} tried={hasKey()} checking={status.isFetching} recheck={() => void status.refetch()} />
      ) : status.error ? (
        <PublicPanel>
          <ErrorState what="وضعیت نصب" error={status.error} onRetry={() => void status.refetch()} retrying={status.isFetching} />
        </PublicPanel>
      ) : !status.data || step === null ? (
        <PublicPanel>
          <p className="flex items-center gap-2 text-body text-muted-foreground" role="status">
            <LoaderCircle className="size-4 animate-spin" aria-hidden />
            در حال بررسی سرور…
          </p>
        </PublicPanel>
      ) : (
        <>
          <StepBar current={step} status={status.data} onPick={setPicked} />
          {step === 'requirements' && <RequirementsStep status={status.data} onDone={done} recheck={() => void status.refetch()} rechecking={status.isFetching} />}
          {step === 'database' && <DatabaseStep status={status.data} onDone={done} />}
          {step === 'admin' && <AdminStep status={status.data} onDone={done} />}
          {step === 'site' && <SiteStep status={status.data} onDone={done} />}
          {step === 'finish' && <FinishStep status={status.data} />}
        </>
      )}
    </PublicScreen>
  )
}
