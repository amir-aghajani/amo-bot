import type { FormEvent, ReactNode } from 'react'
import { FormError, SaveFooter } from '@/components/form-footer'
import { Card, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import type { SettingsGroup } from '@/lib/use-settings-group'

interface SectionCardProps {
  /** The group's form (useSettingsGroup): the card saves it, reverts it and says what failed — a wait the server asked for counting down. */
  form: Pick<SettingsGroup<object>, 'save' | 'busy' | 'dirty' | 'revert' | 'formError' | 'failure'>
  title: string
  description: ReactNode
  /** Lock the save for another reason (config.php cannot be written). */
  disabled?: boolean
  /** Actions at the start of the footer (a test button). */
  actions?: ReactNode
  children: ReactNode
}

/** One settings group: a card that is also its form, the title and description on top, the save pinned to its foot. */
export function SectionCard({ form, title, description, disabled, actions, children }: SectionCardProps) {
  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    void form.save()
  }

  return (
    <Card>
      <form onSubmit={onSubmit} noValidate>
        <CardHeader className="pb-4">
          <CardHeading>
            <CardTitle className="text-heading">{title}</CardTitle>
            <CardDescription>{description}</CardDescription>
          </CardHeading>
        </CardHeader>
        <div className="grid gap-5 px-4 pb-5">
          {children}
          <FormError message={form.formError} failure={form.failure} />
        </div>
        <SaveFooter saving={form.busy} dirty={form.dirty} onRevert={form.revert} disabled={disabled} start={actions} />
      </form>
    </Card>
  )
}
