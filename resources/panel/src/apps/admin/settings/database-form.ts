import { driverDraft, driverPayload, type DriverDraft } from '@/components/driver-form/draft'
import type { DatabaseRequest, DatabaseSettings, DriverDescription } from '@/lib/api-types'

/*
 * The database part of a form — the installer's step, the settings' section —: a driver picked among those on offer and
 * its form (components/driver-form), sent as the driver's own request.
 */

/** The driver `key` names among those on offer — else the one config.php names, else the first: the API always offers one. */
export function databaseDriver(settings: DatabaseSettings, key: string): DriverDescription {
  const driver = settings.drivers.find((option) => option.key === key) ?? settings.drivers.find((option) => option.key === settings.driver) ?? settings.drivers[0]
  if (driver === undefined) throw new Error('The API offers no database driver.')
  return driver
}

/** A database form's draft: the driver picked (`driver`) and its form — what config.php holds for the one it names, the defaults for another. */
export function databaseDraft(settings: DatabaseSettings, key: string = settings.driver): DriverDraft {
  const driver = databaseDriver(settings, key)
  return { ...driverDraft(driver, driver.key === settings.driver ? settings.values : undefined), driver: driver.key }
}

/**
 * What a database form sends: the driver and its form's fields — the API's closed request of that driver, whose fields
 * are its form's (tests/Unit/Drivers/DriverFormsTest holds the two together).
 */
export function databaseRequest(settings: DatabaseSettings, draft: DriverDraft): DatabaseRequest {
  const driver = databaseDriver(settings, String(draft.driver))
  return { ...driverPayload(driver, draft), driver: driver.key } as DatabaseRequest
}
