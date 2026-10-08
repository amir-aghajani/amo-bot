import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FormActions } from '@/components/form-footer'
import { EditorModal, useEditor } from '@/components/row-editor'
import { useForm } from '@/lib/use-form'
import { providers } from '@/test/render'

/*
 * A list page's form in its dialog (components/row-editor): opened on a row, it edits what is saved and offers the way
 * back to it; opened on none, it makes a new row — nothing saved to go back to, no revert —, whatever the form hands its
 * actions: the dialog says which, the form does not.
 */

interface Group {
  id: number
  name: string
}

/** A group's form, as every list's is: its revert handed to its actions, new row or not. */
function GroupForm({ group, onCancel }: { group: Group | null; onCancel: () => void }) {
  const { values, set, dirty, revert } = useForm({ name: group?.name ?? '' })

  return (
    <form noValidate>
      <input aria-label="نام گروه" value={values.name} onChange={(event) => set('name', event.target.value)} />
      <FormActions submitLabel={group ? 'ذخیره تغییرات' : 'افزودن گروه'} onCancel={onCancel} dirty={dirty} onRevert={revert} />
    </form>
  )
}

/** The groups' list: a row's edit, a new row, and their dialog. */
function GroupsPage() {
  const editor = useEditor<Group>()

  return (
    <>
      <button type="button" onClick={() => editor.edit({ id: 1, name: 'VIP' })}>
        ویرایش VIP
      </button>
      <button type="button" onClick={editor.create}>
        گروه تازه
      </button>
      <EditorModal editor={editor} title={(group) => (group ? `ویرایش «${group.name}»` : 'گروه تازه')}>
        {(group) => <GroupForm group={group} onCancel={editor.close} />}
      </EditorModal>
    </>
  )
}

describe('a list’s form in its dialog', () => {
  it('opened on a row, offers the way back to what is saved; on none, a new row’s, offers none', () => {
    render(<GroupsPage />, { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('button', { name: 'ویرایش VIP' }))
    fireEvent.change(screen.getByLabelText('نام گروه'), { target: { value: 'VIP طلایی' } })
    fireEvent.click(screen.getByRole('button', { name: 'بازگردانی تغییرات' }))
    expect((screen.getByLabelText('نام گروه') as HTMLInputElement).value).toBe('VIP')

    // Back where it started, nothing asks: the dialog closes at once.
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    fireEvent.click(screen.getByRole('button', { name: 'گروه تازه' }))
    fireEvent.change(screen.getByLabelText('نام گروه'), { target: { value: 'همکاران' } })
    expect(screen.getByRole('button', { name: 'افزودن گروه' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'بازگردانی تغییرات' })).toBeNull()
  })
})
