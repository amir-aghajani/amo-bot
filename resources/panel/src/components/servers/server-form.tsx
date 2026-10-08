import { useEffect, useRef } from 'react'
import { Info, PlugZap } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { driverDraft, driverPayload, type DriverDraft } from '@/components/driver-form/draft'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { FormActions } from '@/components/form-footer'
import { ProbeResult } from '@/components/servers/probe-result'
import { Button } from '@/components/ui/button'
import { api } from '@/lib/api'
import type { DriverDescription, Probe, ServerRequest, ServerRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'
import { trimmed, useForm } from '@/lib/use-form'
import { useProbe } from '@/lib/use-probe'

interface ServerFormProps {
  /** The connector, as GET /servers/drivers describes it: a server's form on it. */
  driver: DriverDescription
  /** Present when editing: secrets stay blank and mean "keep". */
  server?: ServerRow
  /** Saved — and, for a new server, what its panel answered as it was added (null for an edit, which asks nothing). */
  onSaved: (server: ServerRow, probe: Probe | null) => void
  /** Absent on the server's page, where the form is a card rather than a dialog. */
  onCancel?: () => void
}

/**
 * Add or edit a server: a new one's connector notes, then its form drawn from the connector's description alone
 * (components/driver-form) — the server's name, the connector's connection, and under «تنظیمات پیشرفته» the
 * connection's options with the server's capacity, notes and switch —, "test connection" and its outcome, then the
 * actions. A save and a test send the connector and the fields shown: a new server's to the list, a saved one's to its
 * own address, and a test of a saved server under its id, so the secrets left blank are its stored ones.
 */
export function ServerForm({ driver, server, onSaved, onCancel }: ServerFormProps) {
  const editing = server !== undefined
  const stored = server?.form ?? undefined
  // What is unsaved is what a save would send: a field out of sight is not sent, a secret left blank keeps the one kept.
  const form = useForm<DriverDraft>(() => driverDraft(driver, stored), { reads: (draft) => trimmed(driverPayload(driver, draft)) })
  const { values, error, formError, busy, dirty, submit, handleSubmit } = form
  // The fields are the connector's form — the API's closed request of that connector (DriverFormsTest holds them together).
  const request = () => ({ ...driverPayload(driver, values), driver: driver.key }) as ServerRequest
  // A refused probe is about the inputs: its field messages land where a refused save's would.
  const probe = useProbe(() => api.post('/servers/test', server ? { ...request(), id: server.id } : request()), {
    onFailure: (failure) => {
      form.setErrors(failure.errors)
      form.setFormError(failure.status === 422 ? null : messageOf(failure))
    },
  })
  const locked = busy || probe.busy

  // Opened in the picker, the form takes the focus at its first field: the card that opened it went with the picker.
  const formRef = useRef<HTMLFormElement>(null)
  useEffect(() => {
    if (!editing) formRef.current?.querySelector<HTMLElement>('input, textarea')?.focus()
  }, [editing])

  // Any edit invalidates the last probe result: it described other input.
  const set = (name: string, value: string | boolean) => {
    form.set(name, value)
    probe.clear()
  }
  const revert = () => {
    form.revert()
    probe.clear()
  }

  const test = () => {
    form.setErrors({})
    form.setFormError(null)
    void probe.run()
  }

  // A new server's panel is asked as it is added — the answer comes back with it; an edit asks nothing.
  const save = handleSubmit(async () => {
    const data = await submit(
      (): Promise<{ server: ServerRow; probe: Probe | null }> =>
        server ? api.put(`/servers/${server.id}`, request()).then(({ server: saved }) => ({ server: saved, probe: null })) : api.post('/servers', request()),
      // The servers list shows it — new, or with its new name and state.
      { invalidates: [queryKeys.servers] },
    )
    if (data) {
      toast.success(server ? 'تنظیمات سرور ذخیره شد' : 'سرور اضافه شد')
      onSaved(data.server, data.probe)
    }
  })

  return (
    <form ref={formRef} onSubmit={save} noValidate className="grid gap-5">
      {!editing && driver.notes.length > 0 && (
        <Callout tone="info" icon={Info} className="text-footnote leading-relaxed">
          <ul className="grid gap-1">
            {driver.notes.map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        </Callout>
      )}

      <DriverFields driver={driver} stored={stored} draft={values} set={set} error={error} idPrefix="server" />

      {probe.result && <ProbeResult probe={probe.result.probe} />}

      <FormActions
        error={formError}
        onCancel={onCancel}
        submitLabel={editing ? 'ذخیره تغییرات' : 'افزودن سرور'}
        busy={busy}
        disabled={probe.busy}
        dirty={dirty}
        onRevert={revert}
        start={
          <Button variant="secondary" icon={PlugZap} busy={probe.busy} onClick={test} disabled={locked}>
            تست اتصال
          </Button>
        }
      />
    </form>
  )
}
