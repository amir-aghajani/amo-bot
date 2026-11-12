import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Fingerprint, ScanFace } from 'lucide-react'
import { toast } from 'sonner'
import { keys, queryClient } from '@/apps/app/lib/query'
import { withRecentSignIn } from '@/apps/app/lib/recent'
import { store } from '@/apps/app/lib/store'
import { biometricName, biometrics, keepToken, requestAccess } from '@/apps/app/telegram/biometric'
import { result } from '@/apps/app/telegram/haptics'
import type { BiometricManager } from '@/apps/app/telegram/webapp'
import { Button } from '@/apps/app/ui/button'
import { Section, SwitchRow } from '@/apps/app/ui/section'
import { Notice } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import type { StoreAccountResponse } from '@/lib/api-types'

/*
 * «تایید با اثر انگشت» (or «تشخیص چهره»): offered only on a device whose Telegram has it. On, the shop gives this session
 * a secret (`PUT /me/biometric`, which asks a recent sign-in itself) and Telegram keeps it behind the fingerprint or face;
 * from then on a change that asks a recent sign-in is proven by the finger (lib/recent) instead of opening the app again.
 * Telegram's permission is asked first, so a refusal leaves no secret behind; once refused, only Telegram's own settings
 * give it back. Off, the shop forgets the secret and Telegram lets go of it.
 */

/** What switching it on came to. */
type Outcome = 'on' | 'refused' | 'unkept'

/** This session's secret as the account now has it, before `GET /me` is read again. */
function sessionHolds(biometric: boolean): void {
  queryClient.setQueryData<StoreAccountResponse>(keys.me, (account) => (account === undefined ? account : { ...account, session: { ...account.session, biometric } }))
  void queryClient.invalidateQueries({ queryKey: keys.me, exact: true })
}

export function BiometricSection({ held }: { held: boolean }) {
  const [device, setDevice] = useState<BiometricManager | null>(null)
  /** Telegram keeps a secret on this device (its own flag, read again after each change). */
  const [kept, setKept] = useState(false)
  const [refused, setRefused] = useState(false)

  useEffect(() => {
    let live = true
    void biometrics().then((found) => {
      if (!live) return
      setDevice(found)
      setKept(found?.isBiometricTokenSaved ?? false)
    })

    return () => {
      live = false
    }
  }, [])

  const switchOn = useMutation({
    mutationFn: async (manager: BiometricManager): Promise<Outcome> => {
      const reason = `فعال‌سازی تایید با ${biometricName(manager)}`
      if (!(await requestAccess(manager, reason))) return 'refused'
      const { token } = await withRecentSignIn(reason, () => store.put('/me/biometric'))
      if (await keepToken(manager, token)) return 'on'
      // Telegram did not keep it: a secret no device holds is no use to anyone.
      await store.delete('/me/biometric').catch(() => undefined)
      return 'unkept'
    },
    onSuccess: (outcome, manager) => {
      const name = biometricName(manager)
      setKept(manager.isBiometricTokenSaved)
      setRefused(outcome === 'refused')
      if (outcome === 'on') {
        sessionHolds(true)
        told(`تایید با ${name} روشن شد.`)
        return
      }
      result('warning')
      if (outcome === 'unkept') {
        sessionHolds(false)
        toast.error(`${name} روی این دستگاه ذخیره نشد؛ دوباره تلاش کنید.`)
      }
    },
  })

  const switchOff = useMutation({
    mutationFn: async (manager: BiometricManager) => {
      await store.delete('/me/biometric')
      // The shop forgot it: off, whether or not Telegram lets go of its copy (with its permission taken back, it may not).
      await keepToken(manager, '').catch(() => false)
    },
    onSuccess: (_, manager) => {
      setKept(manager.isBiometricTokenSaved)
      sessionHolds(false)
      told(`تایید با ${biometricName(manager)} خاموش شد.`)
    },
  })

  if (device === null) return null
  const name = biometricName(device)
  const Icon = device.biometricType === 'face' ? ScanFace : Fingerprint
  const on = held && kept
  const busy = switchOn.isPending || switchOff.isPending

  return (
    <Section title="تایید هویت" footer={`روشن باشد، برای انتقال سرویس یا موجودی و تغییر روش‌های ورود، به جای بستن و باز کردن دوباره برنامه، ${name} شما کافی است.`}>
      <SwitchRow
        icon={Icon}
        tint="#ff2d55"
        title={`تایید با ${name}`}
        subtitle={on ? 'روشن روی همین دستگاه' : 'خاموش'}
        on={on}
        busy={busy}
        onChange={(next) => (next ? switchOn.mutate(device) : switchOff.mutate(device))}
      />
      {refused && (
        <div className="flex flex-col gap-3 px-4 pt-3 pb-4">
          <Notice tone="warning">{`تلگرام اجازه استفاده از ${name} را ندارد؛ آن را در تنظیمات تلگرام بدهید و دوباره روشن کنید.`}</Notice>
          <Button variant="secondary" size="sm" onClick={() => device.openSettings()}>
            {`تنظیمات ${name}`}
          </Button>
        </div>
      )}
    </Section>
  )
}
