import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type {
  BotChannelsResponse,
  BotSettingsResponse,
  BotTextsResponse,
  CustomerGroupsResponse,
  KeyboardsResponse,
  PaymentDriversResponse,
  PaymentMethodsResponse,
  PlanCategoriesResponse,
  PlanOptions,
  PlansResponse,
  SystemResponse,
  UpdateResponse,
  UserDetailResponse,
} from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/*
 * The shop's reads that more than one screen makes — a list page and the form or the filter that offers the same rows,
 * the notice that checks the keyboard the keyboards page edits — each defined once, so every screen shares one cache
 * entry and one way to ask. A read only one screen makes stays beside it.
 */

export const plansQuery = queryOptions({ queryKey: queryKeys.plans, queryFn: () => api.get<PlansResponse>('/plans') })

export const planCategoriesQuery = queryOptions({ queryKey: queryKeys.planCategories, queryFn: () => api.get<PlanCategoriesResponse>('/plans/categories') })

/** The servers and inbounds the plan form builds entries from, the categories it files a plan under. */
export const planOptionsQuery = queryOptions({ queryKey: queryKeys.planOptions, queryFn: () => api.get<PlanOptions>('/plans/options'), staleTime: 60_000 })

export const paymentMethodsQuery = queryOptions({ queryKey: queryKeys.paymentMethods, queryFn: () => api.get<PaymentMethodsResponse>('/payment-methods') })

/** The gateway drivers the API knows: the add-method picker, the edit form. They change only with an upgrade. */
export const paymentDriversQuery = queryOptions({
  queryKey: queryKeys.paymentDrivers,
  queryFn: () => api.get<PaymentDriversResponse>('/payment-methods/drivers').then((data) => data.drivers),
  staleTime: 5 * 60_000,
})

export const customerGroupsQuery = queryOptions({ queryKey: queryKeys.customerGroups, queryFn: () => api.get<CustomerGroupsResponse>('/customer-groups') })

/** One customer as their page shows them: the page itself, and the pill of a list narrowed to them (its name). */
export const customerQuery = (id: number) => queryOptions({ queryKey: queryKeys.user(id), queryFn: () => api.get<UserDetailResponse>(`/users/${id}`) })

export const keyboardsQuery = queryOptions({ queryKey: queryKeys.keyboards, queryFn: () => api.get<KeyboardsResponse>('/keyboards') })

/** Every text the bot sends: the bot texts page, and the keyboards page's preview (the greeting its menu goes with). */
export const botTextsQuery = queryOptions({ queryKey: queryKeys.botTexts, queryFn: () => api.get<BotTextsResponse>('/bot/texts') })

export const botSettingsQuery = queryOptions({ queryKey: queryKeys.botSettings, queryFn: () => api.get<BotSettingsResponse>('/bot/settings') })

export const botChannelsQuery = queryOptions({ queryKey: queryKeys.botChannels, queryFn: () => api.get<BotChannelsResponse>('/bot/channels') })

/** The machinery behind the shop (the owner's): the dashboard's system card, and the bots' webhook on the settings. */
export const systemQuery = queryOptions({ queryKey: queryKeys.system, queryFn: () => api.get<SystemResponse>('/system') })

/** AmoBot's own update (the owner's): its settings section, and the system card's word that a new version is out. */
export const updateQuery = queryOptions({ queryKey: queryKeys.update, queryFn: () => api.get<UpdateResponse>('/system/update').then((data) => data.update) })
