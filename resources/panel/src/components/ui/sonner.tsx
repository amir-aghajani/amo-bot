import { CircleAlert, CircleCheck, Info, LoaderCircle, TriangleAlert } from 'lucide-react'
import { createPortal } from 'react-dom'
import { Toaster as Sonner, type ToasterProps } from 'sonner'
import { useTopModal } from '@/components/portal-container'
import { useTheme } from '@/lib/theme'

/**
 * Toasts as the Console's: a floating card on the popover surface, the outcome shown by a coloured icon. Local change to
 * re-apply after a shadcn reinstall: while a modal is open they are drawn inside the top one, above its backdrop, so
 * they can be read and pressed; a toast stays up as they move (sonner's store is global, and a newly mounted toaster
 * replays what is still showing).
 */
const Toaster = (props: ToasterProps) => {
  const { theme } = useTheme()
  const layer = useTopModal()

  return createPortal(
    <Sonner
      theme={theme}
      dir="rtl"
      className="toaster group"
      icons={{
        success: <CircleCheck className="size-4 text-success" aria-hidden />,
        error: <CircleAlert className="size-4 text-danger" aria-hidden />,
        warning: <TriangleAlert className="size-4 text-warning" aria-hidden />,
        info: <Info className="size-4 text-info" aria-hidden />,
        loading: <LoaderCircle className="size-4 animate-spin text-muted-foreground" aria-hidden />,
      }}
      toastOptions={{
        classNames: {
          toast: 'group/toast !gap-2.5 !rounded-xl !border !border-border !bg-popover !px-3.5 !py-3 !font-sans !text-popover-foreground !shadow-popover',
          title: '!text-body !font-medium',
          description: '!text-footnote !text-muted-foreground',
          actionButton: '!rounded-md !bg-primary !text-primary-foreground',
          cancelButton: '!rounded-md !bg-fill !text-foreground',
          closeButton: '!border-border !bg-popover !text-muted-foreground',
        },
      }}
      {...props}
    />,
    layer ?? document.body,
  )
}

export { Toaster }
