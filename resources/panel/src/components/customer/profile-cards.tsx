import { useState } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import { Tags } from 'lucide-react'
import { AgentBotName } from '@/components/agency/agent-bot-name'
import { customerList } from '@/components/customer-filter'
import { Fact, FactList } from '@/components/fact-list'
import { Dash } from '@/components/list-view'
import { TextLink } from '@/components/text-link'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { UserIdentity, userPage } from '@/components/user-identity'
import { GroupsModal } from '@/components/users/groups-modal'
import { WalletPanel } from '@/components/users/wallet-modal'
import type { AgentRow, CustomerReferral, UserRow } from '@/lib/api-types'
import { formatBytes, formatMoney, formatNumber, formatPricePerGb } from '@/lib/format'

/* The side of a customer's page: their wallet, what the referral program knows of them, their groups, an agent's agency. */

/** A fact's column, a little narrower than a dialog's: the cards sit in the page's side column. */
const FACTS = 'grid-cols-[minmax(5.5rem,auto)_1fr] gap-x-4 gap-y-2.5'

interface WalletCardProps {
  customer: UserRow
  credit: string | null
  /** Where else the balance shows, read again after a change (WalletPanel's). */
  invalidates: readonly QueryKey[]
  onChanged: (customer: UserRow) => void
}

/** The wallet: the balance, a credit or debit by hand, the ledger — and an agent's credit, how far below zero it may go. */
export function WalletCard({ customer, credit, invalidates, onChanged }: WalletCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>کیف پول</CardTitle>
          <CardDescription>
            {credit !== null && Number(credit) > 0
              ? `اعتبار نمایندگی ${formatMoney(credit)}: موجودی تا این اندازه زیر صفر می‌رود.`
              : 'افزایش یا کاهش دستی، با توضیحی که مشتری در تاریخچه کیف پولش می‌بیند.'}
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <WalletPanel user={customer} invalidates={invalidates} onChanged={onChanged} />
      </CardContent>
    </Card>
  )
}

/** Whose link brought the customer, and the ones their own link brought — the invitees' list narrowed to them. */
export function ReferralCard({ customer, referral }: { customer: UserRow; referral: CustomerReferral }) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>زیرمجموعه‌گیری</CardTitle>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <FactList className={FACTS}>
          <Fact label="معرف">
            {referral.referrer ? <UserIdentity user={referral.referrer} to={userPage(referral.referrer.id)} /> : <span className="text-muted-foreground">با لینک کسی نیامده است</span>}
          </Fact>
          <Fact label="زیرمجموعه‌ها">
            {referral.referrals > 0 ? (
              <TextLink to={customerList('/referrals/invitees', customer.id, 'referrer')} className="tabular">
                {formatNumber(referral.referrals)} نفر
              </TextLink>
            ) : (
              <span className="text-muted-foreground">هنوز کسی را نیاورده است</span>
            )}
          </Fact>
          <Fact label="پورسانت">{Number(referral.earned) > 0 ? <span className="tabular">{formatMoney(referral.earned)}</span> : <Dash />}</Fact>
        </FactList>
      </CardContent>
    </Card>
  )
}

/** The admin's groups the customer is in, changed as the users table changes them (GroupsModal, and its `invalidates`). */
export function GroupsCard({ customer, invalidates, onSaved }: { customer: UserRow; invalidates: readonly QueryKey[]; onSaved: (customer: UserRow) => void }) {
  const [editing, setEditing] = useState(false)

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>گروه‌ها</CardTitle>
        </CardHeading>
        <CardAction>
          <Button variant="secondary" size="sm" icon={Tags} onClick={() => setEditing(true)}>
            ویرایش
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent>
        {customer.groups.length === 0 ? (
          <p className="text-body text-muted-foreground">در هیچ گروهی نیست.</p>
        ) : (
          <ul className="flex flex-wrap gap-1.5" aria-label="گروه‌های مشتری">
            {customer.groups.map((group) => (
              <li key={group.id}>
                <Badge variant="outline" className="font-normal">
                  {group.name}
                </Badge>
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      <GroupsModal
        user={editing ? customer : null}
        invalidates={invalidates}
        onClose={() => setEditing(false)}
        onSaved={(saved) => {
          onSaved(saved)
          setEditing(false)
        }}
      />
    </Card>
  )
}

/** An agent's agency, as the agents list shows it: their level, their bot and its traffic, what it sold. */
export function AgencyCard({ agent, agentPage }: { agent: AgentRow; agentPage?: (agent: AgentRow) => string }) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>نمایندگی</CardTitle>
          <CardDescription>این مشتری نماینده است و ربات فروش خودش را دارد.</CardDescription>
        </CardHeading>
        {agentPage && (
          <CardAction>
            <TextLink to={agentPage(agent)} className="text-footnote">
              مدیریت نماینده
            </TextLink>
          </CardAction>
        )}
      </CardHeader>
      <CardContent>
        <FactList className={FACTS}>
          <Fact label="سطح">
            {agent.level.name} <span className="text-footnote text-muted-foreground">{formatPricePerGb(agent.level.price_per_gb)}</span>
          </Fact>
          <Fact label="ربات">{agent.bot ? <AgentBotName bot={agent.bot} /> : <Dash />}</Fact>
          {agent.bot && <Fact label="حجم ربات">{formatBytes(agent.bot.traffic_balance)}</Fact>}
          <Fact label="فروش ربات">
            {formatNumber(agent.counts.sold)} سرویس
            <span className="block text-footnote text-muted-foreground">
              {formatNumber(agent.counts.active)} فعال · {formatNumber(agent.counts.customers)} مشتری
            </span>
          </Fact>
        </FactList>
      </CardContent>
    </Card>
  )
}
