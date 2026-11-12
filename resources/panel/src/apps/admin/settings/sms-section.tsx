import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CircleCheck, RefreshCw } from 'lucide-react'
import { useConfigGroup } from '@/apps/admin/settings/use-config-group'
import { Callout } from '@/components/callout'
import { driverDraft, driverPayload, type DriverDraft } from '@/components/driver-form/draft'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { DriverPicker } from '@/components/driver-form/driver-picker'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { SectionCard } from '@/components/section-card'
import { useSectionShown } from '@/components/sectioned-page'
import { SendIcon } from '@/components/send-icon'
import { SwitchRow } from '@/components/switch-row'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { ConfigSmsRequest, ConfigSmsSettings, DriverDescription, SmsCreditResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { trimmed, useForm } from '@/lib/use-form'

/** No SMS at all — no driver —: the first choice, and what it means. */
const NONE = {
  value: 'none',
  label: 'خاموش',
  hint: 'پیامکی فرستاده نمی‌شود: ورود با شماره موبایل در وب‌سایت‌ها روشن نمی‌شود.',
}

/** The SMS driver `driver` names; undefined for none. */
function driverOf(settings: ConfigSmsSettings, driver: string): DriverDescription | undefined {
  return settings.drivers.find((candidate) => candidate.key === driver)
}

/** A driver's form as config.php holds it: what the screen starts from, and what another driver picked brings. */
function formOf(settings: ConfigSmsSettings, driver: string): DriverDraft {
  const described = driverOf(settings, driver)
  return { ...(described === undefined ? {} : driverDraft(described, settings.values[described.key])), driver }
}

/** The group's PUT: none, or the chosen driver's fields (a secret only when typed) and the agents' switch. Other drivers' settings are not sent. */
function requestOf(settings: ConfigSmsSettings, values: DriverDraft): ConfigSmsRequest {
  const driver = driverOf(settings, String(values.driver))
  if (driver === undefined) return { driver: 'none' }
  // The fields are the driver's own form's — the API's closed request of that driver (DriverFormsTest holds them together).
  return { ...driverPayload(driver, values), driver: driver.key, agents: values.agents === true } as ConfigSmsRequest
}

/**
 * «پیامک»: how the websites' sign-in codes go out by SMS — none or an SMS driver drawn from its description (IPPanel:
 * its key, one of its lines, the approved pattern and its variable) — and whether agents' websites may send them too,
 * on the owner's account. A kept key is bound to the API's address: move it and the key must be typed again.
 */
export function SmsSection({ settings, disabled }: { settings: ConfigSmsSettings; disabled: boolean }) {
  const group = useConfigGroup<DriverDraft>(
    { ...formOf(settings, settings.driver), agents: settings.agents },
    {
      save: (values) => api.put('/settings/config/sms', requestOf(settings, values)),
      saved: 'تنظیمات پیامک ذخیره شد',
      // Whether codes go out is what a website's sign-in by phone number waits for; the credit is the saved key's.
      invalidates: [queryKeys.website, queryKeys.smsCredit],
      // What is unsaved is what the save would send: another way picked and the one saved picked again is no change.
      reads: (values) => trimmed(requestOf(settings, values)),
    },
  )
  const { values, set, patch, error } = group
  const driver = driverOf(settings, String(values.driver))

  return (
    <SectionCard
      form={group}
      title="ارسال پیامک"
      description="کد ورود با شماره موبایل در وب‌سایت‌ها با این تنظیمات فرستاده می‌شود. متن پیامک همان متن الگویی است که در پنل سامانه پیامک تایید شده؛ فروشگاه فقط کد را می‌فرستد."
      disabled={disabled}
    >
      <DriverPicker
        id="sms_driver"
        label="روش ارسال"
        drivers={settings.drivers}
        value={String(values.driver)}
        onChange={(picked) => patch({ ...formOf(settings, picked), agents: values.agents === true })}
        error={error('driver')}
        none={NONE}
      />

      {driver && (
        <>
          <DriverFields driver={driver} stored={settings.values[driver.key]} draft={values} set={set} error={error} idPrefix="sms" />
          <SwitchRow
            label="سایت نماینده‌ها هم بتوانند کد ورود پیامکی بفرستند"
            hint="هزینه هر پیامک، از وب‌سایت هر فروشگاهی که باشد، از اعتبار همین حساب پیامک شما کم می‌شود."
            checked={values.agents === true}
            onCheckedChange={(checked) => set('agents', checked)}
            error={error('agents')}
          />
        </>
      )}
    </SectionCard>
  )
}

/**
 * «اعتبار حساب»: the saved account's credit, asked of its provider — which checks the key too —, read again on demand;
 * only while the section is on screen (every section of «تنظیمات پنل» is mounted, and each read is a call to the provider).
 */
function CreditLine() {
  const shown = useSectionShown()
  const credit = useQuery({ queryKey: queryKeys.smsCredit, queryFn: () => api.get<SmsCreditResponse>('/settings/config/sms/credit'), staleTime: 60_000, enabled: shown })
  // A driver that tells no credit has no line.
  if (credit.data?.credit === null) return null

  return (
    <div className="flex items-center justify-between gap-2">
      <p aria-live="polite" className={credit.error ? 'text-footnote text-danger' : 'text-footnote text-muted-foreground'}>
        {credit.error ? (
          messageOf(credit.error)
        ) : credit.data?.credit ? (
          <>
            اعتبار حساب:{' '}
            <span className="font-medium text-foreground tabular-nums">
              {formatNumber(credit.data.credit.amount)} {credit.data.credit.unit}
            </span>
          </>
        ) : (
          'اعتبار حساب: در حال خواندن…'
        )}
      </p>
      <IconButton aria-label="خواندن دوباره اعتبار" icon={RefreshCw} busy={credit.isFetching} onClick={() => void credit.refetch()} />
    </div>
  )
}

/**
 * «ارسال پیامک آزمایشی»: a test code by the saved settings to a typed number — the code shown, to match the SMS against
 * —, failures on the card in the provider's words; and, while a driver is saved, the account's credit.
 */
export function SmsTestCard({ driver }: { driver: string }) {
  const form = useForm({ to: '' })
  const [sent, setSent] = useState<string | null>(null)
  const send = form.handleSubmit(async () => {
    setSent(null)
    const answer = await form.submit(() => api.post('/settings/config/sms/test', form.values), { invalidates: [queryKeys.smsCredit] })
    if (answer !== undefined) setSent(answer.code)
  })

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">ارسال پیامک آزمایشی</CardTitle>
          <CardDescription>یک کد آزمایشی با تنظیمات ذخیره‌شده و همان الگوی کد ورود فرستاده می‌شود و هزینه‌اش از اعتبار حساب کم می‌شود؛ تغییرات بالا را اول ذخیره کنید.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        {driver !== NONE.value && <CreditLine />}
        <form noValidate className="grid gap-4" onSubmit={send}>
          <Field id="sms_test_to" label="شماره موبایل گیرنده" error={form.error('to')}>
            {(control) => (
              <div className="flex items-start gap-2">
                <Input
                  {...control}
                  dir="ltr"
                  inputMode="tel"
                  autoComplete="tel"
                  spellCheck={false}
                  value={form.values.to}
                  onChange={(e) => {
                    form.set('to', e.target.value)
                    setSent(null)
                  }}
                  placeholder="09121234567"
                  className="min-w-0 flex-1"
                />
                <Button type="submit" variant="secondary" icon={SendIcon} busy={form.busy}>
                  ارسال
                </Button>
              </div>
            )}
          </Field>
          {sent !== null && (
            <Callout tone="success" icon={CircleCheck} role="status">
              کد آزمایشی <bdi dir="ltr">{sent}</bdi> فرستاده شد؛ همین کد باید با پیامک روی گوشی برسد.
            </Callout>
          )}
          <FormError message={form.formError} failure={form.failure} />
        </form>
      </CardContent>
    </Card>
  )
}
