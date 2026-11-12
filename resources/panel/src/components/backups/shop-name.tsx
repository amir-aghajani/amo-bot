/** A shop a backup is of, in a sentence: its bot's @username, else its title in quotes, else «این فروشگاه». */
export function ShopName({ shop }: { shop: { username: string | null; title?: string | null; name?: string } | null }) {
  if (shop?.username) return <bdi dir="ltr">@{shop.username}</bdi>
  const title = shop?.title ?? shop?.name

  return <>{title ? `«${title}»` : 'این فروشگاه'}</>
}
