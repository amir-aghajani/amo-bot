import { StatusBadge } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import type { AgentBot } from '@/lib/api-types'
import { agentBotStatus } from '@/lib/statuses'

/** An agent's bot by its @username (a link to it in Telegram), with how it stands. */
export function AgentBotName({ bot }: { bot: AgentBot }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      {bot.username ? (
        <TextLink href={`https://t.me/${bot.username}`}>
          <bdi dir="ltr">@{bot.username}</bdi>
        </TextLink>
      ) : (
        <span className="text-muted-foreground">بدون توکن</span>
      )}{' '}
      <StatusBadge status={agentBotStatus(bot)} />
    </span>
  )
}
