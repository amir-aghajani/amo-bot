import { Check } from 'lucide-react'
import { toast } from 'sonner'
import { AgencyTermsFields, termsBody, type AgencyTerms } from '@/components/agency/agency-terms-fields'
import { AgentBotName } from '@/components/agency/agent-bot-name'
import { TrafficLedger } from '@/components/agency/traffic-ledger'
import { Balance } from '@/components/balance'
import { BalanceAdjust } from '@/components/balance-adjust'
import { Fact, FactList } from '@/components/fact-list'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { AgentBot, AgentRow } from '@/lib/api-types'
import { amountDraft, formatBytes, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { EMAILED, NOT_DELIVERED } from '@/lib/statuses'
import { useForm } from '@/lib/use-form'

/** An agent: their bot and what it sold, what they owe, and their level and credit to change — the agent is told. */
export function AgentModal({ agent, onClose, onSaved }: { agent: AgentRow | null; onClose: () => void; onSaved: (agent: AgentRow) => void }) {
  return (
    <Modal open={agent !== null} onClose={onClose} size="md" title={agent ? `نماینده ${userLabel(agent.user)}` : 'نماینده'}>
      {agent && <AgentForm key={agent.id} agent={agent} onClose={onClose} onSaved={onSaved} />}
    </Modal>
  )
}

function AgentForm({ agent, onClose, onSaved }: { agent: AgentRow; onClose: () => void; onSaved: (agent: AgentRow) => void }) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm<AgencyTerms>({
    level_id: String(agent.level.id),
    credit_limit: amountDraft(agent.credit_limit),
  })

  // Another level moves the agent between the levels' counts.
  const save = handleSubmit(async () => {
    const data = await submit(() => api.put(`/agency/agents/${agent.id}`, termsBody(values)), { invalidates: [queryKeys.agencyLevels] })
    if (data) {
      // Saved as they were: nothing changed, so nobody was told.
      if (data.delivery === null) toast.success('سطح و اعتبار تغییری نکرد')
      else if (data.delivery === 'told') toast.success('نمایندگی به‌روز شد و به نماینده خبر داده شد')
      else if (data.delivery === 'emailed') toast.success(`نمایندگی به‌روز شد و خبرش به نماینده ${EMAILED}`)
      else toast.warning('نمایندگی به‌روز شد، ولی به نماینده خبر داده نشد', { description: NOT_DELIVERED[data.delivery]('نماینده', agent.user.telegram_id !== null) })
      onSaved(data.agent)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <FactList>
        <Fact label="ربات">{agent.bot ? <AgentBotName bot={agent.bot} /> : 'هنوز ندارد'}</Fact>
        {agent.bot && <Fact label="حجم باقی‌مانده">{formatBytes(agent.bot.traffic_balance)}</Fact>}
        <Fact label="مشتری‌های ربات">{formatNumber(agent.counts.customers)}</Fact>
        <Fact label="سرویس‌های فروخته‌شده">
          {formatNumber(agent.counts.sold)} · {formatNumber(agent.counts.active)} فعال
        </Fact>
        <Fact label="موجودی کیف پول">
          <Balance balance={agent.balance} />
        </Fact>
      </FactList>
      <AgencyTermsFields values={values} set={set} error={error} />
      <FormActions error={formError} onCancel={onClose} submitLabel="ذخیره تغییرات" busy={busy} dirty={dirty} onRevert={revert} icon={Check} />
    </form>
  )
}

/**
 * An agent's traffic from the agents page: what their bot may still sell, more or less of it by hand (a gift, a
 * correction — with the note on their ledger), and every line of it.
 */
export function TrafficModal({ agent, onClose, onSaved }: { agent: AgentRow | null; onClose: () => void; onSaved: (agent: AgentRow) => void }) {
  return (
    <Modal open={agent !== null} onClose={onClose} size="md" title={agent ? `حجم ${userLabel(agent.user)}` : 'حجم نماینده'}>
      {agent?.bot && <TrafficBody key={agent.id} agent={agent} bot={agent.bot} onClose={onClose} onSaved={onSaved} />}
    </Modal>
  )
}

function TrafficBody({ agent, bot, onClose, onSaved }: { agent: AgentRow; bot: AgentBot; onClose: () => void; onSaved: (agent: AgentRow) => void }) {
  return (
    <div className="grid gap-5">
      <BalanceAdjust
        balanceLabel="حجم باقی‌مانده ربات"
        balance={formatBytes(bot.traffic_balance)}
        fields={{ amount: 'gb', note: 'note' }}
        amountLabel="حجم (گیگابایت)"
        amountPlaceholder="50"
        noteHint="نماینده این متن را در گردش حجمش می‌بیند."
        notePlaceholders={{ add: 'مثلا: هدیه شروع کار', remove: 'مثلا: اصلاح اشتباه' }}
        submitLabels={{ add: 'افزایش حجم', remove: 'کاهش حجم' }}
        // The API takes the change signed: a negative gb takes away. The answer is the agent; the ledger under it is read again.
        send={(direction, gb, note) => api.post(`/agency/agents/${agent.id}/traffic`, { gb: direction === 'remove' ? `-${gb}` : gb, note })}
        invalidates={[queryKeys.agentTraffic(agent.id)]}
        onDone={(result, direction) => {
          onSaved(result.agent)
          toast.success(`حجم ${direction === 'add' ? 'اضافه' : 'کم'} شد · باقی‌مانده: ${formatBytes(result.agent.bot?.traffic_balance ?? 0)}`)
        }}
        onCancel={onClose}
      />

      <section className="grid gap-2.5">
        <h3 className="text-body font-medium">گردش حجم</h3>
        <TrafficLedger queryKey={queryKeys.agentTraffic(agent.id)} url={`/agency/agents/${agent.id}/traffic`} />
      </section>
    </div>
  )
}
