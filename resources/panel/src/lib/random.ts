/** Cryptographically random hex string of `bytes` bytes (2×bytes characters), for secrets the admin generates in-browser. */
export function randomToken(bytes: number): string {
  const buffer = new Uint8Array(bytes)
  crypto.getRandomValues(buffer)
  return Array.from(buffer, (b) => b.toString(16).padStart(2, '0')).join('')
}
