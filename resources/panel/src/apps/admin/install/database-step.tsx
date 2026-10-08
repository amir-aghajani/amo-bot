import { CircleCheck } from 'lucide-react'
import { installApi } from '@/apps/admin/install/install-api'
import type { StepProps } from '@/apps/admin/install/steps'
import { databaseDraft, databaseDriver, databaseRequest } from '@/apps/admin/settings/database-form'
import { Callout } from '@/components/callout'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { DriverPicker } from '@/components/driver-form/driver-picker'
import { FormError } from '@/components/form-footer'
import { PublicPanel } from '@/components/public-screen'
import { Button } from '@/components/ui/button'
import { useForm } from '@/lib/use-form'

/**
 * The database: written to config.php once its driver's probe answers, then the shop's tables made on it. Nothing is
 * kept yet to show: a secret left blank is an empty one here.
 */
export function DatabaseStep({ status, onDone }: StepProps) {
  const { database } = status
  const { values, set, patch, error, formError, busy, submit, handleSubmit } = useForm(() => databaseDraft(database))
  const driver = databaseDriver(database, String(values.driver))

  const connect = handleSubmit(async () => {
    // The tables are made by the next request, which runs on what this one wrote.
    const fresh = await submit(async () => {
      await installApi.post('/database', databaseRequest(database, values))
      return installApi.post('/tables')
    })
    if (fresh) onDone(fresh, 'admin')
  })

  return (
    <PublicPanel title="دیتابیس" description={driver.description}>
      {database.tables && (
        <Callout tone="success" icon={CircleCheck}>
          دیتابیس وصل است و جدول‌ها ساخته شده‌اند.
        </Callout>
      )}
      <form onSubmit={connect} noValidate className="grid gap-4">
        <DriverPicker id="db_driver" label="نوع دیتابیس" drivers={database.drivers} value={driver.key} onChange={(key) => patch(databaseDraft(database, key))} error={error('driver')} />
        <DriverFields driver={driver} draft={values} set={set} error={error} idPrefix="db" />
        <FormError message={formError ?? (database.tables ? null : database.error)} />
        <div className="flex flex-wrap items-center gap-2">
          <Button type="submit" className="w-fit" busy={busy}>
            {busy ? 'در حال اتصال و ساخت جدول‌ها…' : 'اتصال و ساخت جدول‌ها'}
          </Button>
          {database.tables && (
            <Button variant="secondary" onClick={() => onDone(status, 'admin')} disabled={busy}>
              ادامه بدون تغییر
            </Button>
          )}
        </div>
      </form>
    </PublicPanel>
  )
}
