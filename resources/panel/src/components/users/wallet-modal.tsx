import { useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { Receipt } from 'lucide-react'
import { toast } from 'sonner'
import { Balance } from '@/components/balance'
import { BalanceAdjust } from '@/components/balance-adjust'
import { EmptyState } from '@/components/empty-state'
import { LedgerList } from '@/components/ledger-list'
import { ListView } from '@/components/list-view'
import { Modal } from '@/components/modal'
import { Reviewer } from '@/components/reviewer'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { UserRow, UserWalletResponse } from '@/lib/api-types'
import { formatAmount, formatBalance, formatDate, formatMoney } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

interface WalletModalProps {
  user: UserRow | null
  onClose: () => void
  /** The row came back with a new balance. */
  onChanged: (user: UserRow) => void
}

/** A customer's wallet from the users table, in a dialog of its own (WalletPanel). */
export function WalletModal({ user, onClose, onChanged }: WalletModalProps) {
  return (
    <Modal open={user !== null} onClose={onClose} size="md" title={user ? `کیف پول ${userLabel(user)}` : 'کیف پول'}>
      {user && <WalletPanel key={user.id} user={user} onCancel={onClose} onChanged={onChanged} />}
    </Modal>
  )
}

interface WalletPanelProps {
  user: UserRow
  /** The row came back with a new balance. */
  onChanged: (user: UserRow) => void
  /** Where else the customer's balance shows that `onChanged` does not reach (the users table, under their page), read again after a change. */
  invalidates?: readonly QueryKey[]
  /** Leave without a change — the dialog's «انصراف»; the card on the customer's page has none. */
  onCancel?: () => void
}

/** A customer's wallet — in its dialog, or on their page: the balance, a manual credit or debit with a note, and the ledger. */
export function WalletPanel({ user, onChanged, invalidates, onCancel }: WalletPanelProps) {
  const queryClient = useQueryClient()
  const read = useQuery({ queryKey: queryKeys.userWallet(user.id), queryFn: () => api.get<UserWalletResponse>(`/users/${user.id}/wallet`) })
  const transactions = read.data?.transactions ?? []

  return (
    <div className="grid gap-5">
      <BalanceAdjust
        balanceLabel="موجودی فعلی"
        balance={<Balance balance={read.data?.user.balance ?? user.balance} />}
        fields={{ amount: 'amount', note: 'description' }}
        amountLabel="مبلغ (تومان)"
        amountPlaceholder="50000"
        whole
        amountHint={(amount) => `= ${formatMoney(amount)}`}
        noteHint="مشتری این متن را در تاریخچه کیف پولش می‌بیند."
        notePlaceholders={{ add: 'مثلا: هدیه، جبران قطعی', remove: 'مثلا: اصلاح اشتباه' }}
        submitLabels={{ add: 'افزایش موجودی', remove: 'کاهش موجودی' }}
        send={(direction, amount, description) => api.post(`/users/${user.id}/wallet`, { type: direction === 'add' ? 'credit' : 'debit', amount, description })}
        invalidates={invalidates}
        onDone={(result, direction) => {
          queryClient.setQueryData<UserWalletResponse>(queryKeys.userWallet(user.id), { user: result.user, transactions: result.transactions })
          onChanged(result.user)
          toast.success(`${direction === 'add' ? 'افزایش' : 'کاهش'} موجودی ثبت شد · موجودی جدید: ${formatBalance(result.user.balance)}`)
        }}
        onCancel={onCancel}
      />

      <section className="grid gap-2.5">
        <h3 className="text-body font-medium">تراکنش‌ها</h3>
        <ListView
          list={{ rows: transactions, isPending: read.isPending, error: read.error, refetch: () => void read.refetch() }}
          noun="تراکنش‌ها"
          skeletonRows={3}
          empty={<EmptyState compact framed icon={Receipt} title="هنوز تراکنشی ندارد" description="شارژها، خریدها و تغییرهای دستی این‌جا ثبت می‌شوند." />}
        >
          <LedgerList
            entries={transactions.map((row) => ({
              id: row.id,
              credit: row.type === 'credit',
              title: row.description ?? '—',
              detail: (
                <>
                  {formatDate(row.created_at)} · مانده {formatBalance(row.balance_after)}
                  {row.reviewer && (
                    <>
                      {' · '}
                      <Reviewer name={row.reviewer} />
                    </>
                  )}
                </>
              ),
              amount: formatAmount(row.amount),
            }))}
          />
        </ListView>
      </section>
    </div>
  )
}
