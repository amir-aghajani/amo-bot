import { FACTS } from '@/components/customer/profile-cards'
import { Fact, FactList } from '@/components/fact-list'
import { TextLink } from '@/components/text-link'
import { Card, CardContent, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import type { LoyaltyStanding } from '@/lib/api-types'
import { formatMoney, formatPercent } from '@/lib/format'

/** The customer's loyalty tier (its discount and cashback), what they spent, and what is left to the next tier; only while the shop has tiers. */
export function LoyaltyCard({ loyalty }: { loyalty: LoyaltyStanding }) {
  const { tier, spent, next } = loyalty
  const perks =
    tier &&
    [tier.discount_percent > 0 && `${formatPercent(tier.discount_percent)} تخفیف`, tier.cashback_percent > 0 && `${formatPercent(tier.cashback_percent)} کش‌بک اضافه`].filter(Boolean).join('، ')

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>سطح وفاداری</CardTitle>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <FactList className={FACTS}>
          <Fact label="سطح">
            {tier ? (
              <span>
                <TextLink to="/loyalty">{tier.name}</TextLink>
                {perks && <span className="text-muted-foreground"> · {perks}</span>}
              </span>
            ) : (
              <span className="text-muted-foreground">هنوز به اولین سطح نرسیده است</span>
            )}
          </Fact>
          <Fact label="جمع خریدها">
            <span className="tabular">{formatMoney(spent)}</span>
          </Fact>
          <Fact label="سطح بعدی">
            {next ? (
              <span>
                {next.name} <span className="text-muted-foreground tabular">({formatMoney(next.missing)} مانده)</span>
              </span>
            ) : (
              <span className="text-muted-foreground">بالاترین سطح</span>
            )}
          </Fact>
        </FactList>
      </CardContent>
    </Card>
  )
}
