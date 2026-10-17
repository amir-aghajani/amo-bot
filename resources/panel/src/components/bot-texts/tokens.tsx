/** A `%name%` the bot fills in as it sends a text — the name captured. */
export const VARIABLE = /%([A-Za-z_][A-Za-z0-9_]*)%/g

/**
 * What runs left to right inside a Persian sentence about a text: a `%variable%`, a command («/menu»), a handle
 * («@BotFather») or a start link's parameter («?start=usertest») — not a path's or an address's part.
 */
const TOKEN = /(%[A-Za-z_][A-Za-z0-9_]*%|(?<![\w./:?=-])[/@?][A-Za-z][\w=-]*)/g

/**
 * Text with its tokens (`%variables%`, commands, handles) set apart left to right, so one inside Persian reads whole
 * instead of losing its «%», «/» or «@» to the other side («menu/»).
 */
export function WithTokens({ text }: { text: string }) {
  return (
    <>
      {/* split() puts each captured token between the runs of text around it: every odd part is a token. */}
      {text.split(TOKEN).map((part, i) =>
        i % 2 === 1 ? (
          <bdi key={i} dir="ltr">
            {part}
          </bdi>
        ) : (
          part
        ),
      )}
    </>
  )
}
