import { KeyRound } from 'lucide-react'
import { keepKey } from '@/apps/admin/install/install-api'
import { Field } from '@/components/field'
import { PublicPanel } from '@/components/public-screen'
import { SecretInput } from '@/components/secret-input'
import { Button } from '@/components/ui/button'
import { useForm } from '@/lib/use-form'

/**
 * The install key step: the text of storage/install-key.txt, written on the first visit, so only the host's owner can
 * install. A wrong key (`tried`) shows as the field's error; `recheck` asks the server about the typed one.
 */
export function KeyStep({ refusal, tried, checking, recheck }: { refusal: string; tried: boolean; checking: boolean; recheck: () => void }) {
  const { values, set, handleSubmit } = useForm({ key: '' })

  const enter = handleSubmit(() => {
    keepKey(values.key)
    recheck()
  })

  return (
    <PublicPanel
      title="کلید نصب"
      description="برای این‌که فقط صاحب هاست بتواند فروشگاه را نصب کند، نصب با یک کلید باز می‌شود: متن فایل storage/install-key.txt روی هاست. آن را از File Manager هاست باز کنید و این‌جا بنویسید."
    >
      <form onSubmit={enter} noValidate className="grid gap-4">
        <Field id="install_key" label="کلید نصب" error={tried && !checking ? refusal : undefined}>
          <SecretInput value={values.key} onChange={(e) => set('key', e.target.value)} autoFocus />
        </Field>
        <Button type="submit" className="w-fit" icon={KeyRound} busy={checking} disabled={values.key.trim() === ''}>
          ادامه
        </Button>
      </form>
    </PublicPanel>
  )
}
