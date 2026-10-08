import type { ComponentProps } from 'react'
import { Button } from '@/components/ui/button'

/** A square, borderless control for a single icon (the `quiet` button): the label is required — the icon alone says nothing to assistive tech. */
export function IconButton(props: Omit<ComponentProps<typeof Button>, 'variant' | 'size'> & { 'aria-label': string }) {
  return <Button variant="quiet" size="icon" {...props} />
}
