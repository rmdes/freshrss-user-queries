# freshrss-user-queries

A [FreshRSS](https://freshrss.org) extension that exposes your **saved user queries** over an
authenticated API: list all of them, run any of them by name, create and edit them, and turn
on sharing to get their public HTML / RSS / OPML / GReader JSON links.

FreshRSS's own APIs can't do this. The Google Reader and Fever APIs know nothing about user
queries, and `/api/query.php` only serves queries you have shared, one token at a time. This
extension adds `/api/misc.php?ext=UserQueries`, authenticated with the same Google Reader API
login your clients already use, and returns **every** saved query, shared or not.

Built for [freshrss-mcp](https://github.com/rmdes/freshrss-mcp), but it is a plain HTTP API usable
by any client.

## Install

1. Put this repo in FreshRSS's `extensions/` directory **as `xExtension-UserQueries`**:
   ```sh
   cd /path/to/FreshRSS/extensions
   git clone https://github.com/rmdes/freshrss-user-queries.git xExtension-UserQueries
   ```
   On [Cloudron](https://cloudron.io), from a local clone:
   ```sh
   cloudron sync push --app <app> ./xExtension-UserQueries /app/data/extensions
   ```
2. As admin, enable **UserQueries** in *Settings → Extensions*. It is a `system` extension because
   `/api/misc.php` only serves system-enabled extensions.
3. API access must be enabled in FreshRSS's authentication settings, and the user needs an API
   password set in their profile (the same requirements as any Google Reader API client).

## API

Authenticate like the Google Reader API: get a token from `ClientLogin`, then send it in the
`Authorization` header.

```sh
BASE=https://rss.example.com/api
TOKEN=$(curl -s -d 'Email=<user>&Passwd=<api password>' "$BASE/greader.php/accounts/ClientLogin" | sed -n 's/^Auth=//p')
AUTH="Authorization: GoogleLogin auth=$TOKEN"
```

### List queries

```sh
curl -H "$AUTH" "$BASE/misc.php?ext=UserQueries"
```

```json
{"queries": [
  {"id": 0, "name": "Tech-fr", "description": "", "get": "c_12", "get_name": "techfr",
   "search": "", "state": 15, "order": "", "shared_rss": true, "shared_opml": false,
   "urls": {"html": "https://rss.example.com/api/query.php?user=me&t=TOKEN&f=html",
            "rss": "https://rss.example.com/api/query.php?user=me&t=TOKEN&f=rss",
            "greader": null, "opml": null}}
]}
```

`urls` holds the public share links, the same ones the FreshRSS UI shows. `html` and `rss` are set
when *sharing by HTML & RSS* is on (`shared_rss`); `greader` and `opml` when *sharing by OPML* is on
(`shared_opml`). FreshRSS only offers `opml` for queries over all feeds, a category or a feed.
A link is `null` when its sharing is off.

### Run a query

```sh
curl -H "$AUTH" "$BASE/misc.php?ext=UserQueries&name=Tech-fr&nb=20&offset=0&search=intitle:linux"
```

| Parameter | Meaning |
|-----------|---------|
| `name` | Query name (exact match; with duplicate names, the first one wins) |
| `nb` | Number of articles (capped by the user's `max_posts_per_rss` setting) |
| `offset` | Articles to skip, for paging (there is no continuation token) |
| `search` | Optional extra [FreshRSS search](https://freshrss.github.io/FreshRSS/en/users/10_filter.html), ANDed with the query's own, so it can only narrow it |

The response is a Google Reader stream (`{"id", "title", "items": [...]}`), the same item format as
`/api/greader.php/reader/api/0/stream/contents`. Item IDs can be used with `edit-tag` to mark
articles read or starred.

The query is applied the same way FreshRSS's `/api/query.php` applies shared queries: its filter,
state and order.

### Create, update and delete queries

Writes are `POST` requests with an `action`, sent as a form or a JSON body. Each returns the saved
query in the list format above, including its share `urls`.

```sh
# A query over the "MediasLibres" folder, shared in every format
curl -H "$AUTH" -H 'Content-Type: application/json' "$BASE/misc.php?ext=UserQueries" \
  -d '{"action": "create", "name": "MediasLibres", "category": "MediasLibres",
       "share_rss": true, "share_opml": true}'

# Turn OPML/GReader sharing off (links stop working; the token is kept for when it is back on)
curl -H "$AUTH" "$BASE/misc.php?ext=UserQueries" -d 'action=update&name=MediasLibres&share_opml=0'

curl -H "$AUTH" "$BASE/misc.php?ext=UserQueries" -d 'action=delete&id=12'
```

| Field | Actions | Meaning |
|-------|---------|---------|
| `action` | all | `create`, `update` or `delete` |
| `id` / `name` | update, delete | Which query: its `id`, or its name if no other query has the same name |
| `name` | create | Name of the new query; must be unique |
| `new_name` | update | Rename (must be unique) |
| `category` | create, update | Folder name; filters the query on that folder |
| `get` | create, update | Raw FreshRSS filter instead of `category`: `a` all (default), `c_<id>` category, `f_<id>` feed, `t_<id>` label, `i` important (favourites: use `state`) |
| `search` | create, update | [FreshRSS search](https://freshrss.github.io/FreshRSS/en/users/10_filter.html) expression |
| `state` | create, update | Integer bitmask, or a list of `read`, `unread`, `favorite`, `not_favorite` |
| `order` | create, update | `DESC` (newest first) or `ASC` |
| `description` | create, update | Free text |
| `share_rss` | create, update | `true`/`false`: sharing by HTML & RSS |
| `share_opml` | create, update | `true`/`false`: sharing by OPML and GReader JSON |

Queries are stored exactly as the UI stores them (normalised through `FreshRSS_UserQuery`, with the
UI's share token), so they show up and stay editable in *Settings → User queries*.

### Errors

JSON `{"error": "..."}` with `401` (bad or missing auth), `404` (unknown query or folder), `400`
(bad parameter or search), `405` (write without `POST`), `409` (name already used, or ambiguous
name: use `id`) or `500` (configuration could not be saved).

## Compatibility

Tested on FreshRSS **1.30.1**. It also runs on newer versions that add per-query sort:
`getSort()` is applied only when it exists.

## Security

- Only the authenticated user's own queries are visible. The token check mirrors
  `greader.php`'s, and accounts without an API password are rejected.
- Nothing here works without logging in. Turning sharing on, though, publishes that query's
  articles to anyone holding its share link, exactly as the UI toggle does.

## License

MIT
