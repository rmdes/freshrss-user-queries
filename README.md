# freshrss-user-queries

A [FreshRSS](https://freshrss.org) extension that exposes your **saved user queries** over an
authenticated API: list all of them, and run any of them by name.

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
  {"name": "Tech-fr", "description": "", "get": "c_12", "get_name": "techfr",
   "search": "", "state": 3, "shared_rss": true, "shared_opml": false}
]}
```

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
state and order. Errors are JSON `{"error": "..."}` with `401` (bad or missing auth), `404`
(unknown query) or `400` (bad search).

## Compatibility

Tested on FreshRSS **1.30.1**. It also runs on newer versions that add per-query sort:
`getSort()` is applied only when it exists.

## Security

- Only the authenticated user's own queries are visible. The token check mirrors
  `greader.php`'s, and accounts without an API password are rejected.
- Unlike share URLs, nothing here works without logging in.

## License

MIT
