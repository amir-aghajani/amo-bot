/*
 * Every react-query key of the panels, flat and in one place: a page invalidates exactly the keys it
 * names, and no key is a prefix of another — so nothing needs `exact` to avoid sweeping a neighbour.
 * A paged list (`usePagedList`) appends its own {search, status, page} object under its key. The keys
 * of one row (`server(id)`, `payment(id)`, …) have an `every…` prefix beside them, for the live updates,
 * which reload whatever row of a kind is on screen.
 */
export const queryKeys = {
  /** The shop's name and whether it is installed (lib/app-info) — the one key a new session keeps (lib/auth). */
  app: ['app'] as const,
  installStatus: ['install-status'] as const,
  /** Every shop the owner may open (the owner's shop picker). */
  shops: ['shops'] as const,
  /** The change feed's numbers (lib/use-live-updates). */
  changes: ['changes'] as const,
  /** How many wait on a human — the sidebar's numbers (components/shell/queue-badge). */
  queues: ['queues'] as const,
  dashboard: (range: number) => ['dashboard', range] as const,
  /** Every range of the dashboard at once — the one prefix, for a change that moves its counts (a review). */
  dashboards: ['dashboard'] as const,
  /** The machinery behind the shop (the owner's dashboard): the bot, the scheduler. */
  system: ['system'] as const,
  /** AmoBot's own update (the owner's): the newest release, and the update under way. */
  update: ['update'] as const,
  plans: ['plans'] as const,
  planCategories: ['plan-categories'] as const,
  planOptions: ['plan-options'] as const,
  servers: ['servers'] as const,
  /** The servers the subscriptions screen narrows by and moves to, as its panel reads them (components/subscriptions/server-choices). */
  serverChoices: ['server-choices'] as const,
  server: (id: number) => ['server', id] as const,
  everyServer: ['server'] as const,
  serverGrants: (id: number) => ['server-grants', id] as const,
  everyServerGrants: ['server-grants'] as const,
  massGrants: ['mass-grants'] as const,
  serverDrivers: ['server-drivers'] as const,
  paymentMethods: ['payment-methods'] as const,
  paymentDrivers: ['payment-drivers'] as const,
  orders: ['orders'] as const,
  order: (id: number) => ['order', id] as const,
  everyOrder: ['order'] as const,
  payments: ['payments'] as const,
  payment: (id: number) => ['payment', id] as const,
  everyPayment: ['payment'] as const,
  subscriptions: ['subscriptions'] as const,
  subscription: (id: number) => ['subscription', id] as const,
  everySubscription: ['subscription'] as const,
  users: ['users'] as const,
  /** One customer as their page shows them (lib/queries' `customerQuery`). */
  user: (id: number) => ['user', id] as const,
  everyUser: ['user'] as const,
  customerGroups: ['customer-groups'] as const,
  userWallet: (id: number) => ['user-wallet', id] as const,
  everyUserWallet: ['user-wallet'] as const,
  referrals: ['referrals'] as const,
  referrers: ['referrers'] as const,
  invitees: ['invitees'] as const,
  referralCommissions: ['referral-commissions'] as const,
  agency: ['agency'] as const,
  agencySettings: ['agency-settings'] as const,
  agencyLevels: ['agency-levels'] as const,
  agencyRequests: ['agency-requests'] as const,
  agents: ['agents'] as const,
  /** An agent's traffic, line by line (the agents page's agent modal). */
  agentTraffic: (id: number) => ['agent-traffic', id] as const,
  everyAgentTraffic: ['agent-traffic'] as const,
  /** «حساب نمایندگی» (the agent's panel): their bot and its traffic, their level and wallet, and the traffic's lines. */
  account: ['account'] as const,
  accountTraffic: ['account-traffic'] as const,
  botSettings: ['bot-settings'] as const,
  botChannels: ['bot-channels'] as const,
  reportGroup: ['report-group'] as const,
  keyboards: ['keyboards'] as const,
  botTexts: ['bot-texts'] as const,
  customEmojis: ['custom-emojis'] as const,
  broadcasts: ['broadcasts'] as const,
  /** The support tickets (pages/tickets — and a customer's page, narrowed to them). */
  tickets: ['tickets'] as const,
  /** One ticket with its conversation (pages/ticket). */
  ticket: (id: number) => ['ticket', id] as const,
  everyTicket: ['ticket'] as const,
  /** The customers' reviews of the shop (pages/reviews). */
  reviews: ['reviews'] as const,
  /**
   * Whether a picture of the API's the browser did not draw — a receipt, a ticket message's — is there to download, or
   * why it is not (components/api-picture): by its address.
   */
  pictureCheck: (path: string) => ['picture-check', path] as const,
  /** The shop's website (its settings' page): every save and a new key answer it whole, put back in place of the read. */
  website: ['website'] as const,
  configSettings: ['config-settings'] as const,
}
