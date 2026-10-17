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
import { useReadFailure } from '@/components/page'
import { PublicPanel, PublicScreen } from '@/components/public-screen'
import type { InstallStatus } from '@/lib/api-types'
import { useAppName } from '@/lib/app-info'
import { useDocumentTitle } from '@/lib/document-title'
import { queryKeys } from '@/lib/query-keys'

/**
 * The web installer (the only page of a shop not yet installed): the install key read off the host, requirements,
 * database and tables, the owner's login, the site (and the bot's token), then finish — all over /api/install, which
 * disappears afterwards. It opens on the first step still needed; a done step can be revisited.
 */
export function InstallPage() {
  const queryClient = useQueryClient()
  const appName = useAppName()
  useDocumentTitle('نصب')
  const status = useQuery({ queryKey: queryKeys.installStatus, queryFn: readStatus })
  // Kept while it is asked again: a key refused stays the key's step, a failure its panel, the retry spinning.
  const failure = useReadFailure(status)
  const [picked, setPicked] = useState<Step | null>(null)
  const step = picked ?? (status.data ? firstOpen(status.data) : null)
  const refusal = keyRefusal(failure.error)

  const done = (fresh: InstallStatus, next: Step) => {
    queryClient.setQueryData(queryKeys.installStatus, fresh)
    setPicked(next)
  }

  return (
    <PublicScreen title={`نصب ${appName}`} description="چند قدم تا راه افتادن فروشگاه" wide>
      {refusal !== undefined ? (
        <KeyStep refusal={refusal} tried={hasKey()} checking={status.isFetching} recheck={() => void status.refetch()} />
      ) : failure.error ? (
        <PublicPanel>
          <ErrorState what="وضعیت نصب" error={failure.error} onRetry={() => void status.refetch()} retrying={failure.retrying} />
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
