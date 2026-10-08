/** A `%name%` the bot fills in as it sends a text — the name captured. */
export const VARIABLE = /%([A-Za-z_][A-Za-z0-9_]*)%/g

/**
 * Text with its `%variables%` set apart left to right, so a token inside Persian reads whole instead of losing its
 * percent signs to the other side.
 */
export function WithTokens({ text }: { text: string }) {
  return (
    <>
      {/* split() puts each captured name between the runs of text around it: every odd part is a variable's name. */}
      {text.split(VARIABLE).map((part, i) =>
        i % 2 === 1 ? (
          <bdi key={i} dir="ltr">
            %{part}%
          </bdi>
        ) : (
          part
        ),
      )}
    </>
  )
}
