import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import type { ReportGroupResponse } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** How often the screen asks whether a group was handed to the bot, while a connect link is out. */
const POLL_MS = 3000

/** The admins' report group as the server has it — asked again every few seconds while a connect link waits to be used. */
export function useReportGroup() {
  return useQuery({
    queryKey: queryKeys.reportGroup,
    queryFn: () => api.get<ReportGroupResponse>('/bot/report-group'),
    select: (data) => data.group,
    refetchInterval: (query) => (query.state.data?.group.link ? POLL_MS : false),
  })
}

/**
 * What the report group card does — a connect link, a check, a test message, disconnecting —, each putting the group the
 * server answers with into the card's copy. A refusal is the panel's toast in the server's words (a 422 on `link`: no
 * link can be made yet).
 */
export function useReportGroupActions() {
  const queryClient = useQueryClient()
  const apply = (result: ReportGroupResponse) => queryClient.setQueryData<ReportGroupResponse>(queryKeys.reportGroup, { group: result.group })

  const link = useMutation({ mutationFn: () => api.post('/bot/report-group/link'), onSuccess: apply })

  const check = useMutation({
    mutationFn: () => api.post('/bot/report-group/check'),
    onSuccess: (result) => {
      apply(result)
      if (result.group.problem) toast.error('گروه بررسی شد؛ مشکل هنوز هست')
      else toast.success('گروه درست است و ربات دسترسی‌هایش را دارد')
    },
  })

  const test = useMutation({
    mutationFn: () => api.post('/bot/report-group/test'),
    onSuccess: (result) => {
      apply(result)
      if (result.queued === 0) toast.error('همه بخش‌های گزارش خاموش است؛ پیامی فرستاده نشد')
      else if (result.sent === result.queued) toast.success(`پیام تست به ${formatNumber(result.sent)} تاپیک فرستاده شد`)
      else toast.warning(`${formatNumber(result.queued - result.sent)} پیام تست در صف ارسال ماند`)
    },
  })

  const disconnect = useMutation({
    mutationFn: () => api.delete('/bot/report-group'),
    onSuccess: (result) => {
      apply(result)
      toast.success('اتصال گروه گزارش‌ها قطع شد')
    },
  })

  return { link, check, test, disconnect, busy: link.isPending || check.isPending || test.isPending || disconnect.isPending }
}
