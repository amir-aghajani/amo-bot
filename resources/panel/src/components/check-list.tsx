import { useId } from 'react'
import { FieldGroup } from '@/components/field'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { messageOf } from '@/lib/failure'
import { cn } from '@/lib/utils'

interface CheckOption<V> {
  value: V
  label: string
  /** A word beside its name: a plan off sale, a server switched off. */
  hint?: string
}

interface CommonProps<V> {
  label: string
  options: CheckOption<V>[]
  hint: string
  error?: string
  /** What it says in place of options still being read («در حال خواندن سرورها…»). */
  pending?: string
  /** Why its options could not be read, said in their place. */
  failure?: string
}

/**
 * `pending` and `failure` for options a query reads, the list named by `what` («سرورها»): «در حال خواندن سرورها…» while
 * it reads, «لیست سرورها خوانده نشد: …» once it failed.
 */
export function optionsRead(query: { isPending: boolean; isError: boolean; error: unknown }, what: string): Pick<CommonProps<never>, 'pending' | 'failure'> {
  return {
    pending: query.isPending ? `در حال خواندن ${what}…` : undefined,
    failure: query.isError ? `لیست ${what} خوانده نشد: ${messageOf(query.error)}` : undefined,
  }
}

/**
 * With `all`, a first box stands for every option, now and to come («همه پلن‌ها») — `value` null — and the options show
 * only once it is unticked.
 */
type CheckListProps<V> = CommonProps<V> & ({ all: string; value: V[] | null; onChange: (value: V[] | null) => void } | { all?: undefined; value: V[]; onChange: (value: V[]) => void })

/** A group of tick boxes that is one field of a form. The values ticked come back in the options' order. */
export function CheckList<V extends string | number>(props: CheckListProps<V>) {
  const { label, options, hint, error, pending, failure } = props
  const id = useId()
  const ticked = props.value ?? []
  const toggle = (option: V, on: boolean) => {
    const next = options.map((candidate) => candidate.value).filter((candidate) => (candidate === option ? on : ticked.includes(candidate)))
    // Either shape of `onChange` takes a list.
    ;(props.onChange as (value: V[]) => void)(next)
  }

  return (
    <FieldGroup label={label} hint={hint} error={error} className="gap-2">
      {props.all !== undefined && (
        <div className="flex items-center gap-2">
          <Checkbox id={`${id}-all`} checked={props.value === null} onCheckedChange={(checked) => props.onChange(checked === true ? null : [])} />
          <Label htmlFor={`${id}-all`} className="font-normal">
            {props.all}
          </Label>
        </div>
      )}
      {props.value !== null && (
        <div className={cn('flex flex-wrap gap-x-5 gap-y-2', props.all !== undefined && 'max-h-48 overflow-y-auto ps-6')}>
          {options.length === 0 &&
            (failure ? <span className="text-footnote text-danger">{failure}</span> : <span className="text-footnote text-muted-foreground">{pending ?? 'چیزی برای انتخاب نیست.'}</span>)}
          {options.map((option) => (
            <div key={option.value} className="flex items-center gap-2">
              <Checkbox id={`${id}-${option.value}`} checked={ticked.includes(option.value)} onCheckedChange={(checked) => toggle(option.value, checked === true)} />
              <Label htmlFor={`${id}-${option.value}`} className="font-normal">
                {option.label}
                {option.hint && <span className="ms-1.5 text-footnote text-faint">({option.hint})</span>}
              </Label>
            </div>
          ))}
        </div>
      )}
    </FieldGroup>
  )
}
