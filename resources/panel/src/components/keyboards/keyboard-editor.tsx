import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Plus, RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { FormError, SaveFooter } from '@/components/form-footer'
import { ButtonModal, type ButtonMoves } from '@/components/keyboards/button-modal'
import { KeyboardPreview } from '@/components/keyboards/keyboard-preview'
import { KeyboardRow } from '@/components/keyboards/keyboard-row'
import { useKeyboardDraft, type Position } from '@/components/keyboards/use-keyboard-draft'
import { useRowDrag } from '@/components/keyboards/use-row-drag'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import type { KeyboardAction, KeyboardLayoutData, KeyboardsResponse, KeyboardType } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

interface KeyboardEditorProps {
  keyboard: KeyboardLayoutData
  actions: KeyboardAction[]
  styles: KeyboardsResponse['styles']
  limits: KeyboardsResponse['limits']
  /** The bot's greeting the keyboard goes with, as the preview shows it (Telegram HTML, samples in); undefined while it loads. */
  welcome: string | undefined
  onSaved: (keyboard: KeyboardLayoutData) => void
}

/** The button dialog: a new button for a row, or the button at a place. */
type Editing = { mode: 'add'; row: number } | { mode: 'edit'; at: Position } | null

const TYPES: PageTab<KeyboardType>[] = [
  { value: 'reply', label: 'کیبورد پایین صفحه' },
  { value: 'inline', label: 'دکمه‌های شیشه‌ای' },
]

const TYPE_HINT: Record<KeyboardType, string> = {
  reply: 'دکمه‌ها زیر جعبه متن می‌مانند و همیشه در دسترس‌اند؛ متن هر دکمه همان پیامی است که مشتری با زدنش می‌فرستد.',
  inline: 'دکمه‌ها زیر پیام خوش‌آمد می‌چسبند؛ صفحه‌های بعدی دکمه «بازگشت» به منو می‌گیرند.',
}

/**
 * One keyboard: its rows of buttons, edited in place — a button dragged anywhere (another spot, another row, a new row
 * under the last) or moved from its dialog, rows reordered (the moved row keeping the focus), either added or removed —
 * with a live preview beside it. A button is added while an action is left to place: each stands on the keyboard once.
 * Saves the whole layout.
 */
export function KeyboardEditor({ keyboard, actions, styles, limits, welcome, onSaved }: KeyboardEditorProps) {
  const draft = useKeyboardDraft(keyboard, limits, onSaved)
  const { form } = draft
  const { type } = form.values
  const rows = draft.buttons
  const drag = useRowDrag(draft.moveButton)
  const taken = rows.flat().map((button) => button.action)
  const exhausted = actions.every((action) => taken.includes(action.key))
  const [editing, setEditing] = useState<Editing>(null)
  const [confirmReset, setConfirmReset] = useState(false)

  const resetToDefault = useMutation({
    mutationFn: () => api.post(`/keyboards/${keyboard.name}/reset`),
    onSuccess: (data) => {
      toast.success('کیبورد به چیدمان پیش‌فرض برگشت')
      setConfirmReset(false)
      onSaved(data.keyboard)
    },
  })

  // The save's refusals: about the keyboard as a whole above the rows, about a row or one of its buttons (`rows.2.1`) under it.
  const topErrors = [...(form.errors.type ?? []), ...(form.errors.rows ?? [])]
  const rowErrors = (r: number) => [
    ...new Set(
      Object.entries(form.errors)
        .filter(([key]) => key.startsWith(`rows.${r}.`) || key === `rows.${r}`)
        .flatMap(([, messages]) => messages),
    ),
  ]

  const full = (r: number) => (rows[r]?.length ?? limits.per_row) >= limits.per_row
  const at = editing?.mode === 'edit' ? editing.at : null

  /**
   * The moves open to the button at `from` — along its row, or to the end of the row above or below; from the last row,
   * down onto a new one when it leaves a button behind — the dialog staying on it.
   */
  const movesOf = (from: Position): ButtonMoves => {
    const length = rows[from.row]?.length ?? 0
    const last = from.row === rows.length - 1
    const go = (to: Position) => () => {
      const landed = draft.moveButton(from, to)
      if (landed) setEditing({ mode: 'edit', at: landed })
    }
    return {
      place: `ردیف ${formatNumber(from.row + 1)}، دکمه ${formatNumber(from.index + 1)} از ${formatNumber(length)}`,
      to: {
        right: from.index > 0 ? go({ row: from.row, index: from.index - 1 }) : undefined,
        left: from.index < length - 1 ? go({ row: from.row, index: from.index + 2 }) : undefined,
        up: from.row > 0 && !full(from.row - 1) ? go({ row: from.row - 1, index: rows[from.row - 1]?.length ?? 0 }) : undefined,
        down: last
          ? length > 1 && rows.length < limits.rows
            ? go({ row: rows.length, index: 0 })
            : undefined
          : !full(from.row + 1)
            ? go({ row: from.row + 1, index: rows[from.row + 1]?.length ?? 0 })
            : undefined,
      },
    }
  }

  return (
    <Card>
      <CardHeader className="border-b border-border pb-4">
        <CardHeading>
          <CardTitle className="text-heading">
            {keyboard.title}
            {keyboard.is_default && !form.dirty && <Badge variant="outline">چیدمان پیش‌فرض</Badge>}
          </CardTitle>
          <CardDescription>
            کیبوردی که با /start به مشتری نشان داده می‌شود. دکمه‌ها را بکشید و هر جا خواستید بیندازید، یا از پنجره هر دکمه جابه‌جایش کنید؛ ردیف اول، بالاترین ردیف است و دکمه اول هر ردیف سمت راست
            می‌نشیند.
          </CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="grid gap-6 pt-5 lg:grid-cols-[1fr_320px]">
        <div className="grid content-start gap-4">
          <div className="grid gap-2">
            <PageTabs as="choice" value={type} onChange={(value) => form.set('type', value)} tabs={TYPES} aria-label="نوع کیبورد" />
            <p className="text-footnote leading-relaxed text-muted-foreground">{TYPE_HINT[type]}</p>
          </div>

          <FormError message={form.formError ?? (topErrors.length > 0 ? topErrors.join(' ') : null)} />

          <ol className="grid gap-3">
            {form.values.rows.map((row, r) => (
              <KeyboardRow
                key={row.id}
                row={row.buttons}
                index={r}
                count={rows.length}
                canAdd={!full(r) && !exhausted}
                problems={rowErrors(r)}
                drag={drag}
                onMove={(delta) => draft.moveRow(r, delta)}
                onRemove={() => draft.removeRow(r)}
                onAdd={() => setEditing({ mode: 'add', row: r })}
                onEdit={(index) => setEditing({ mode: 'edit', at: { row: r, index } })}
              />
            ))}
          </ol>

          {/* While a button is dragged: under the last row, a row of its own (when the keyboard has room for one). */}
          {drag.dragging && rows.length < limits.rows && (
            <div
              {...drag.row(rows.length)}
              className={cn(
                'grid min-h-14 place-items-center rounded-xl border border-dashed p-3 text-footnote transition-colors',
                drag.insertAt(rows.length) === null ? 'border-border-strong text-muted-foreground' : 'border-selected bg-info-soft/40 text-foreground',
              )}
            >
              برای ساختن ردیف تازه اینجا رها کنید
            </div>
          )}

          {exhausted && <p className="text-footnote text-muted-foreground">همه دکمه‌های ربات روی کیبورد هستند؛ هر دکمه یک بار می‌آید، پس برای دکمه تازه اول یکی را بردارید.</p>}

          <Button variant="secondary" icon={Plus} className="w-fit" onClick={draft.addRow} disabled={rows.length >= limits.rows}>
            افزودن ردیف
          </Button>
        </div>

        <div className="lg:sticky lg:top-20 lg:self-start">
          <p className="mb-2 text-footnote font-medium text-muted-foreground">پیش‌نمایش</p>
          <KeyboardPreview type={type} rows={rows} message={welcome} />
        </div>
      </CardContent>

      <SaveFooter
        saving={form.busy}
        dirty={form.dirty}
        onRevert={form.revert}
        onSave={() => void draft.save()}
        start={
          <Button variant="secondary" size="sm" icon={RotateCcw} onClick={() => setConfirmReset(true)} disabled={form.busy || (keyboard.is_default && !form.dirty)}>
            بازگشت به پیش‌فرض
          </Button>
        }
      />

      <ButtonModal
        open={editing !== null}
        onClose={() => setEditing(null)}
        actions={actions}
        styles={styles}
        taken={taken}
        button={at ? rows[at.row]?.[at.index] : undefined}
        labelMax={limits.label}
        moves={at ? movesOf(at) : undefined}
        onSave={(button) => {
          if (editing?.mode === 'add') draft.addButton(editing.row, button)
          else if (at) draft.setButton(at, button)
          setEditing(null)
        }}
        onRemove={
          at
            ? () => {
                draft.removeButton(at)
                setEditing(null)
              }
            : undefined
        }
      />

      <ConfirmModal
        open={confirmReset}
        onClose={() => setConfirmReset(false)}
        title="بازگشت به چیدمان پیش‌فرض"
        description="چیدمان فعلی کنار گذاشته می‌شود و کیبورد به حالت اولیه برمی‌گردد."
        confirmLabel="بازگشت به پیش‌فرض"
        pending={resetToDefault.isPending}
        onConfirm={() => resetToDefault.mutate()}
      >
        این کار برگشت‌پذیر نیست؛ اگر فقط می‌خواهید تغییرات ذخیره‌نشده را کنار بگذارید، «بازگردانی تغییرات» را بزنید.
      </ConfirmModal>
    </Card>
  )
}
