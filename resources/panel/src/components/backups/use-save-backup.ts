import { useMutation } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { toastFailure } from '@/lib/failure'
import { saveFile } from '@/lib/save-file'

/**
 * How long a backup's request waits for its answer: a large file made and downloaded, uploaded to be checked, or
 * restored, over a slow connection, takes minutes — far more than the panel's usual wait (lib/api).
 */
export const BACKUP_TIMEOUT_MS = 15 * 60_000

/** A backup file to save: where the API serves it, and the name it is saved as should the server give none. */
interface BackupFile {
  path: string
  fallback: string
}

/**
 * Saves a backup file the API serves (one made now, a state kept from before a restore), fetched with the panel's
 * session, by the name the server gives it; a failure is a toast. `saving(path)` is whether that file is being fetched.
 */
export function useSaveBackup() {
  const save = useMutation({
    mutationFn: async ({ path, fallback }: BackupFile) => {
      const file = await api.download(path, { timeoutMs: BACKUP_TIMEOUT_MS })
      saveFile(file.bytes, file.name ?? fallback)
    },
    meta: { quiet: true },
    onError: (error) => toastFailure(error, 'فایل پشتیبان دریافت نشد'),
  })

  return {
    save: (file: BackupFile) => save.mutate(file),
    busy: save.isPending,
    saving: (path: string) => save.isPending && save.variables?.path === path,
  }
}

/** A kept state's address: its name as BackupsStatus.kept lists it. */
export function keptPath(name: string): string {
  return `/backups/kept/${encodeURIComponent(name)}`
}
