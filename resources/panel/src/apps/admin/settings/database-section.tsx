import { CircleCheck, DatabaseZap } from 'lucide-react'
import { databaseDraft, databaseDriver, databaseRequest } from '@/apps/admin/settings/database-form'
import { useConfigGroup } from '@/apps/admin/settings/use-config-group'
import { Callout } from '@/components/callout'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { DriverPicker } from '@/components/driver-form/driver-picker'
import { SectionCard } from '@/components/section-card'
import { Button } from '@/components/ui/button'
import { api } from '@/lib/api'
import type { DatabaseSettings } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatNumber } from '@/lib/format'
import { trimmed } from '@/lib/use-form'
import { useProbe } from '@/lib/use-probe'

/** The database config.php names: its driver's form (another driver to switch to when more than one is offered), tested before saving. */
export function DatabaseSection({ settings, disabled }: { settings: DatabaseSettings; disabled: boolean }) {
  const group = useConfigGroup(databaseDraft(settings), {
    save: (values) => api.put('/settings/config/database', databaseRequest(settings, values)),
    saved: 'اتصال دیتابیس ذخیره شد',
    // What is unsaved is what the save would send: another driver picked and the one saved picked again is no change.
    reads: (values) => trimmed(databaseRequest(settings, values)),
  })
  const { values, set, patch, error, setFormError, busy } = group
  const driver = databaseDriver(settings, String(values.driver))
  // A refused test names the connection, or one field; either way the form's own error line is where it reads.
  const test = useProbe(() => api.post('/settings/config/database/test', databaseRequest(settings, values)), {
    onFailure: (failure) => setFormError(failure.errors.connection?.[0] ?? Object.values(failure.errors)[0]?.[0] ?? messageOf(failure)),
  })

  // Another driver brings its own form (what the server has for it, else its defaults).
  const changeDriver = (key: string) => {
    test.clear()
    patch(databaseDraft(settings, key))
  }

  const check = () => {
    setFormError(null)
    void test.run()
  }

  return (
    <SectionCard
      form={group}
      title="دیتابیس"
      description="قبل از ذخیره، اتصال با مقادیر جدید تست می‌شود و اگر برقرار نشود چیزی تغییر نمی‌کند."
      disabled={disabled}
      actions={
        <Button variant="secondary" size="sm" icon={DatabaseZap} busy={test.busy} disabled={busy} onClick={check}>
          تست اتصال
        </Button>
      }
    >
      <DriverPicker id="db_driver" label="نوع دیتابیس" drivers={settings.drivers} value={driver.key} onChange={changeDriver} error={error('driver')} />
      <DriverFields
        driver={driver}
        stored={driver.key === settings.driver ? settings.values : undefined}
        draft={values}
        set={(name, value) => {
          test.clear()
          set(name, value)
        }}
        error={error}
        idPrefix="db"
      />

      {test.result && (
        <Callout tone="success" icon={CircleCheck}>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="font-medium">اتصال برقرار شد:</span>
            <bdi dir="ltr" className="text-footnote text-muted-foreground">
              {test.result.database.version}
            </bdi>
            <span className="text-muted-foreground">· {formatNumber(test.result.database.tables)} جدول</span>
          </span>
        </Callout>
      )}
    </SectionCard>
  )
}
