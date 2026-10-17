import { useId, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Copy, Gift, Plus, Search, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { ConfirmModal } from '@/components/confirm-modal'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { GiftCodesSettingsCard } from '@/components/gift-codes/gift-codes-settings-card'
import { MakeCodesModal } from '@/components/gift-codes/make-codes-modal'
import { Dash, ListView, PhoneFacts, RowMenu } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { SearchBox } from '@/components/search-box'
import { RemoveConfirm } from '@/components/sortable-list'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserLabel, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { GiftCodeBatch, GiftCodeBatchesResponse, GiftCodeRow, GiftCodesResponse, GiftCodeStatus, GiftCodesUnusedResponse } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'
import { idLabel } from '@/lib/direction'
import { messageOf } from '@/lib/failure'
import { formatDate, formatMoney, formatNumber, timeAgo } from '@/lib/format'
import { botSettingsQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { GIFT_CODE_KIND, GIFT_CODE_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'

const STATES: GiftCodeStatus[] = ['unused', 'redeemed', 'expired']

/** What deleting codes changes beside the list: the batches' counts. */
const CHANGES = [queryKeys.giftCodeBatches]

/** The program in a paragraph, behind the header's ⓘ. */
const PROGRAM =
  'هر کد هدیه یک بار استفاده می‌شود: مبلغی به کیف پول مشتری اضافه می‌کند یا یک سرویس رایگان از یک پلن به او می‌دهد. مشتری کد را در ربات از دکمه «کد هدیه» یا در وب‌سایت فروشگاه می‌فرستد؛ بزرگ و کوچکی حروف و خط تیره و فاصله مهم نیست. کسی که پشت سر هم کد اشتباه بفرستد مدتی باید صبر کند، پس کدها حدس زده نمی‌شوند.'

/**
 * «کدهای هدیه» (both panels): the redeem switch (a bot-settings group) and every code, newest first, filtered by status
 * and batch. Codes are made in batches; a batch's unused codes can be copied or deleted, and so can one unused code.
 */
export function GiftCodesPage() {
  const tabs = useId()
  const enabled = useQuery(botSettingsQuery).data?.settings.gift_codes_enabled
  const batches = useQuery({ queryKey: queryKeys.giftCodeBatches, queryFn: () => api.get<GiftCodeBatchesResponse>('/gift-codes/batches') })
  const list = usePagedList({
    queryKey: queryKeys.giftCodes,
    read: (query) => api.get<GiftCodesResponse>('/gift-codes', query),
    list: 'codes',
    statuses: ['', ...STATES],
    params: ['batch'],
    address: '/gift-codes',
  })
  const [making, setMaking] = useState(false)
  const [deleting, setDeleting] = useState<GiftCodeRow | null>(null)
  const [discarding, setDiscarding] = useState<GiftCodeBatch | null>(null)
  const named = (batches.data?.batches ?? []).filter((batch): batch is GiftCodeBatch & { batch: string } => batch.batch !== null)
  const batch = named.find((row) => row.batch === list.params.batch) ?? null

  // Quiet: RemoveConfirm says a refusal in its dialog.
  const remove = useMutation({
    mutationFn: (code: GiftCodeRow) => api.delete(`/gift-codes/${code.id}`),
    meta: { quiet: true, invalidates: CHANGES },
    onSuccess: (_, code) => list.remove(code.id),
  })
  const discard = useMutation({
    mutationFn: (label: string) => api.post('/gift-codes/discard', { batch: label }),
    meta: { quiet: true, invalidates: [queryKeys.giftCodes, queryKeys.giftCodeBatches] },
    onSuccess: ({ deleted }) => {
      setDiscarding(null)
      toast.success(`${formatNumber(deleted)} کد استفاده‌نشده حذف شد`)
    },
  })
  const copyUnused = useMutation({
    mutationFn: (label: string) => api.get<GiftCodesUnusedResponse>('/gift-codes/unused', new URLSearchParams({ batch: label })),
    onSuccess: ({ codes }) => {
      if (codes.length === 0) toast.info('این سری کد استفاده‌نشده‌ای ندارد')
      else void copyText(codes.join('\n'), `${formatNumber(codes.length)} کد کپی شد`)
    },
  })

  return (
    <Page>
      <PageHeader
        title="کدهای هدیه"
        description="کدهایی که هر کدام یک بار، مبلغی به کیف پول مشتری اضافه می‌کند یا یک سرویس رایگان به او می‌دهد."
        info={PROGRAM}
        actions={
          <Button icon={Plus} onClick={() => setMaking(true)}>
            ساخت کدها
          </Button>
        }
      />

      <WithBotSettings what="تنظیمات کد هدیه">{(settings) => <GiftCodesSettingsCard settings={settings} />}</WithBotSettings>
      {enabled && <MenuButtonNotice action="gift" label="کد هدیه" screen="فرستادن کد هدیه در ربات" />}

      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(GIFT_CODE_STATUS, STATES)} onChange={list.setFilter} aria-label="وضعیت کد" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={list.typed} onChange={list.setTyped} placeholder="کد یا مشتری‌ای که استفاده کرد" aria-label="جستجوی کد هدیه" />
          {/* A batch the address names (a link, the back button) is said and can be undone, even once it is gone. */}
          {(named.length > 0 || (list.params.batch ?? '') !== '') && (
            <FilterSelect
              label="سری"
              value={list.params.batch ?? ''}
              options={[{ value: '', label: 'همه' }, ...named.map((row) => ({ value: row.batch, label: row.batch }))]}
              onChange={(value) => list.setParam('batch', value)}
            />
          )}
        </div>

        {batch && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-fill px-4 py-3">
            <p className="text-body text-muted-foreground">
              سری «{batch.batch}»: {formatNumber(batch.total)} کد — {formatNumber(batch.unused)} استفاده‌نشده، {formatNumber(batch.redeemed)} استفاده‌شده، {formatNumber(batch.expired)} منقضی‌شده
            </p>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" icon={Copy} busy={copyUnused.isPending} onClick={() => copyUnused.mutate(batch.batch)}>
                کپی کدهای استفاده‌نشده
              </Button>
              {batch.unused + batch.expired > 0 && (
                <Button variant="danger" size="sm" icon={Trash2} onClick={() => setDiscarding(batch)}>
                  حذف کدهای استفاده‌نشده
                </Button>
              )}
            </div>
          </div>
        )}

        <ListView
          list={list}
          noun="کدهای هدیه"
          unit="کد"
          skeletonRows={6}
          empty={
            <EmptyState
              framed
              icon={Gift}
              title="هنوز کد هدیه‌ای نساخته‌اید"
              description="یک سری کد بسازید — مثلا برای جشنواره یا هدیه به مشتری‌های وفادار — و کدها را کپی کنید."
              action={
                <Button variant="secondary" icon={Plus} onClick={() => setMaking(true)}>
                  ساخت کدها
                </Button>
              }
            />
          }
          noMatch={<EmptyState framed icon={Search} title="کدی پیدا نشد" description={list.narrowed ? 'با این جستجو یا فیلتر کدی نیست.' : 'کدی در این وضعیت نیست.'} />}
        >
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>کد</TableHead>
                <TableHead>ارزش</TableHead>
                <TableHead className="hidden md:table-cell">سری</TableHead>
                <TableHead className="hidden md:table-cell">وضعیت</TableHead>
                <TableHead className="hidden md:table-cell">استفاده</TableHead>
                <TableHead className="w-10">
                  <span className="sr-only">عملیات</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.rows.map((code) => (
                <TableRow key={code.id}>
                  <TableCell>
                    <code dir="ltr" className="font-medium tracking-wide">
                      {code.code}
                    </code>
                    <PhoneFacts>
                      <CodeState code={code} />
                      {code.redeemed_at !== null && <Taken code={code} />}
                    </PhoneFacts>
                  </TableCell>
                  <TableCell>
                    <Worth code={code} />
                  </TableCell>
                  <TableCell className="hidden md:table-cell">{code.batch ? <bdi>{code.batch}</bdi> : <Dash />}</TableCell>
                  <TableCell className="hidden md:table-cell">
                    <CodeState code={code} />
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <Taken code={code} />
                  </TableCell>
                  <TableCell>
                    <RowMenu label={`کد ${code.code}`}>
                      <DropdownMenuItem onSelect={() => void copyText(code.code, 'کد کپی شد')}>
                        <Copy aria-hidden />
                        کپی کد
                      </DropdownMenuItem>
                      {code.status !== 'redeemed' && (
                        <>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(code)}>
                            <Trash2 aria-hidden />
                            حذف
                          </DropdownMenuItem>
                        </>
                      )}
                    </RowMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </div>

      <MakeCodesModal open={making} onClose={() => setMaking(false)} />

      <RemoveConfirm
        row={deleting}
        remove={remove}
        title="حذف کد هدیه"
        description={(code) => `کد ${code.code}`}
        details={() => 'این کد دیگر پذیرفته نمی‌شود؛ اگر آن را به کسی داده‌اید، دیگر کار نمی‌کند.'}
        removed="کد حذف شد"
        onClose={() => setDeleting(null)}
      />

      <ConfirmModal
        open={discarding !== null}
        onClose={() => {
          setDiscarding(null)
          discard.reset()
        }}
        title="حذف کدهای استفاده‌نشده"
        description={discarding ? `سری «${discarding.batch}»` : undefined}
        confirmLabel="حذف"
        destructive
        pending={discard.isPending}
        error={discard.error ? messageOf(discard.error) : null}
        onConfirm={() => discarding?.batch && discard.mutate(discarding.batch)}
      >
        {discarding &&
          `${formatNumber(discarding.unused + discarding.expired)} کدی که کسی استفاده نکرده حذف می‌شود و دیگر پذیرفته نمی‌شود. ${discarding.redeemed > 0 ? `${formatNumber(discarding.redeemed)} کد استفاده‌شده برای سابقه می‌ماند.` : ''}`}
      </ConfirmModal>
    </Page>
  )
}

/** What a code is worth: Toman for the wallet, or a plan's service. */
function Worth({ code }: { code: GiftCodeRow }) {
  return (
    <div className="grid gap-0.5">
      <span className="font-medium">{code.kind === 'wallet' ? formatMoney(code.amount ?? 0) : code.plan ? <bdi>{code.plan.name}</bdi> : 'پلن حذف شده'}</span>
      <span className="text-footnote text-muted-foreground">{GIFT_CODE_KIND[code.kind]}</span>
    </div>
  )
}

/** A code's state — and, unused, the day it stops working. */
function CodeState({ code }: { code: GiftCodeRow }) {
  return (
    <div className="grid justify-items-start gap-1">
      <StatusBadge status={GIFT_CODE_STATUS[code.status]} />{' '}
      {code.expires_at && code.status === 'unused' && <span className="text-footnote text-muted-foreground">تا {formatDate(code.expires_at, { dateStyle: 'medium' })}</span>}
    </div>
  )
}

/** Who took the code and when — and the order a plan's service was given by. */
function Taken({ code }: { code: GiftCodeRow }) {
  if (code.redeemed_at === null) return <Dash />

  return (
    <div className="grid gap-0.5">
      {code.redeemed_by ? (
        <TextLink to={userPage(code.redeemed_by.id)}>
          <UserLabel user={code.redeemed_by} />
        </TextLink>
      ) : (
        <span className="text-muted-foreground">حساب حذف‌شده</span>
      )}
      <span className="text-footnote text-muted-foreground">
        <time dateTime={code.redeemed_at} title={formatDate(code.redeemed_at)}>
          {timeAgo(code.redeemed_at)}
        </time>
        {code.order && (
          <>
            {' · '}
            <TextLink to={searchLink('/orders', code.order.id)}>سفارش {idLabel(code.order.id)}</TextLink>
          </>
        )}
      </span>
    </div>
  )
}
