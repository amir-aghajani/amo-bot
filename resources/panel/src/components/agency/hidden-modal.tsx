import { useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { toast } from 'sonner'
import { CheckList } from '@/components/check-list'
import { ErrorState } from '@/components/error-state'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { useReadFailure } from '@/components/page'
import { Skeleton } from '@/components/ui/skeleton'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { AgentHidden, AgentHiddenResponse, AgentRow } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { useForm } from '@/lib/use-form'

/**
 * «سرورها و پلن‌های پنهان»: the servers of the installation and the agent's own plans, a tick each to keep it from their
 * shop. A hidden server sells none of their plans, gives no free test and takes no service they move to it — what they
 * have there runs on and renews; a hidden plan is not sold, and they cannot show it again.
 */
export function HiddenModal({ agent, onClose, onSaved }: { agent: AgentRow | null; onClose: () => void; onSaved: (agent: AgentRow) => void }) {
  return (
    <Modal open={agent !== null} onClose={onClose} size="md" title={agent ? `سرورها و پلن‌های پنهان ${userLabel(agent.user)}` : 'سرورها و پلن‌های پنهان'}>
      {agent && <HiddenRead key={agent.id} agent={agent} onClose={onClose} onSaved={onSaved} />}
    </Modal>
  )
}

/** The servers and plans, read afresh each time the dialog opens. */
function HiddenRead({ agent, onClose, onSaved }: { agent: AgentRow; onClose: () => void; onSaved: (agent: AgentRow) => void }) {
  const read = useQuery({ queryKey: queryKeys.agentHidden(agent.id), queryFn: () => api.get<AgentHiddenResponse>(`/agency/agents/${agent.id}/hidden`) })
  const failure = useReadFailure(read)
  const failed = failure.error && <ErrorState what="سرورها و پلن‌ها" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />

  // A later read that fails is said over the form, which keeps what was ticked.
  if (!read.data) return failed || <Skeleton className="h-48 w-full rounded-xl" />

  return (
    <>
      {failed}
      <HiddenForm agent={agent} hidden={read.data.hidden} onClose={onClose} onSaved={onSaved} />
    </>
  )
}

function HiddenForm({ agent, hidden, onClose, onSaved }: { agent: AgentRow; hidden: AgentHidden; onClose: () => void; onSaved: (agent: AgentRow) => void }) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm({
    server_ids: hidden.servers.filter((server) => server.hidden).map((server) => server.id),
    plan_ids: hidden.plans.filter((plan) => plan.hidden).map((plan) => plan.id),
  })

  const save = handleSubmit(async () => {
    const data = await submit(() => api.put(`/agency/agents/${agent.id}/hidden`, values), { invalidates: [queryKeys.agentHidden(agent.id)] })
    if (data) {
      toast.success('سرورها و پلن‌های پنهان ذخیره شد')
      onSaved(data.agent)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <CheckList
        label="سرورهای پنهان"
        hint="پلن‌های نماینده روی این سرورها فروخته نمی‌شود، اکانت تست در آن‌ها داده نمی‌شود و نماینده سرویسی به آن‌ها منتقل نمی‌کند. سرویس‌هایی که نماینده همین حالا روی آن‌ها دارد مثل قبل کار می‌کنند و تمدید می‌شوند."
        options={hidden.servers.map((server) => ({ value: server.id, label: server.name, hint: server.is_active ? undefined : 'غیرفعال' }))}
        value={values.server_ids}
        onChange={(ids) => set('server_ids', ids)}
        error={error('server_ids')}
      />
      <CheckList
        label="پلن‌های پنهان"
        hint="این پلن‌های نماینده فروخته نمی‌شوند؛ او آن‌ها را «پنهان‌شده توسط پشتیبانی» می‌بیند و نمی‌تواند دوباره نمایششان دهد. سرویس‌هایی که با آن‌ها فروخته شده تمدید می‌شوند."
        options={hidden.plans.map((plan) => ({ value: plan.id, label: plan.name, hint: plan.is_active ? undefined : 'خاموش' }))}
        value={values.plan_ids}
        onChange={(ids) => set('plan_ids', ids)}
        error={error('plan_ids')}
      />
      <FormActions error={formError} onCancel={onClose} submitLabel="ذخیره" busy={busy} dirty={dirty} onRevert={revert} icon={Check} />
    </form>
  )
}
