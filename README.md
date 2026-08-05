# GitHub Memories Banner

A drop-in PHP script that renders a dynamic SVG "memories" banner for any
GitHub user or organization, like the GitHub contributions calendar but
looking **N years back to this exact day**. Self-host it, set one env var,
and embed the image anywhere that accepts a URL:

```md
![My Memories](https://your-domain.com/github-memories/?username=octocat&details=1)
```

On GitHub you can also use an HTML `<img>` tag, which lets you control the
displayed size:

```html
<img src="https://your-domain.com/github-memories/?username=octocat&details=1" width="450" alt="My Memories">
```

Works in GitHub profile READMEs, issues, PRs, Notion, blogs, forum signatures,
email signatures — anything that renders remote SVGs.

> **Heads-up (GitHub):** GitHub never hotlinks your server directly — it
> proxies every image through its **Camo** cache
> (`camo.githubusercontent.com`), which has a short fetch timeout. If your
> banner ever appears as a *bare link* instead of an image, Camo timed out
> fetching a cold render. This project caches renders server-side to keep cold
> fetches fast; see [Troubleshooting](#troubleshooting).

---

## Live demo

A live deployment is running at:

![Demo](https://alexomar.com/github-memories/?username=ikarius6&type=user&years=3&theme=light&title=My%20Memories&showUsername=1&showDate=1&details=1)

Try changing the query string to see the banner react in real time:

| Try this | URL |
| --- | --- |
| Dark theme, no breakdown | `?username=octocat&theme=dark` |
| 5 years back | `?username=octocat&years=5` |
| Org mode | `?type=org&username=github` |
| Custom title, hide username | `?username=octocat&title=My%20Memories&showUsername=0` |
| Custom title, keep the date | `?username=octocat&title=My%20Memories&showDate=1` |
| Full breakdown, 8 repos/year | `?username=octocat&details=1&repoLimit=8` |

---

## Why "memories"?

GitHub shows you what you committed *today*. This banner shows you what you
committed **on this exact day, 1 / 2 / 3 ... years ago** — a small dose of
nostalgia every time you (or visitors) glance at your profile.

---

## Setup

### 1. Get a GitHub Personal Access Token (PAT)

The script needs a PAT with **read-only public access** to call the GitHub
API. It is never exposed to end users — it lives only in your server's
environment.

1. Sign in at <https://github.com> and open
   **Settings → Developer settings → Personal access tokens → Fine-grained tokens**.
2. Click **Generate new token**.
3. Configure it:
   - **Token name:** `github-memories-banner`
   - **Expiration:** whatever you like — 90 days is fine; rotate when expired.
   - **Resource owner:** the user or org the banner will query.
   - **Repository access:**
     - For a **public-only** banner (recommended): pick
       **Public repositories (read-only)**.
     - If you want private contributions counted in your own total, pick
       **All repositories** and the matching read access. Private repo
       names are **never displayed** by the banner — see
       [Privacy](#privacy-private-repos-are-never-leaked).
   - **Permissions → Repository permissions:**
     - `Commits` → **Read-only**
     - `Metadata`→ **Read-only** (auto-selected)
4. Click **Generate token** and copy the value immediately (GitHub won't
   show it again).

> Classic PATs (the older `repo`/`public_repo` scopes) also work, but
> fine-grained tokens are the safer choice.

### 2. Deploy the PHP script

Copy `index.php` to any PHP-enabled host (Apache, nginx + PHP-FPM, etc.).
The script outputs `image/svg+xml`, so no HTML wrapping is needed — a
directory containing just `index.php` is enough.

Example folder layout:

```
/var/www/html/github-memories/
└── index.php
```

### 3. Set the `GITHUB_TOKEN` environment variable

Hard-coding the token in the file is **never** recommended — anyone reading
the source would leak it. Set it as an env var instead.

**nginx + PHP-FPM** — in your `server { ... }` block:

```nginx
location ~ \.php$ {
    fastcgi_pass   unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_param  GITHUB_TOKEN "ghp_xxxxxxxxxxxxxxxxxxxx";
    fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include        fastcgi_params;
}
```

(You can also use a `.env` file loaded via your process manager; see
`systemd` example below.)

**Apache** — in the `<Directory>` or `<VirtualHost>` block:

```apache
SetEnv GITHUB_TOKEN "ghp_xxxxxxxxxxxxxxxxxxxx"
```

**systemd service** (for any PHP server you manage via systemd):

```ini
# /etc/systemd/system/github-memories.service
[Service]
Environment="GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxx"
ExecStart=/usr/bin/php -S 127.0.0.1:8080 -t /var/www/html/github-memories
```

`.htaccess` / shared hosting — if you can't set real env vars, create a
`.env.php` next to `index.php`:

```php
<?php
// .env.php
putenv('GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxx');
```

…and add this at the **top of `index.php`, before the first `header()`
call**:

```php
require __DIR__ . '/.env.php';
```

Then ensure `.env.php` is never web-served (Apache: add
`<Files ".env.php"> Require all denied </Files>`).

### 4. Embed it

Use the script URL as an image source anywhere:

```md
![My GitHub Memories](https://your-domain.com/github-memories/?username=octocat&details=1)
```

On GitHub, an HTML `<img>` tag works too and lets you set the display width:

```html
<img src="https://your-domain.com/github-memories/?username=octocat&details=1" width="450" alt="My GitHub Memories">
```

That's it. The banner is cached **server-side for 4 hours** (in the system
temp dir, keyed by parameters + the day) and also sends a 4-hour
`Cache-Control` header. The server-side cache is what keeps GitHub's Camo
proxy from timing out on a cold fetch — see
[Troubleshooting](#troubleshooting) if the image shows up as a link.

The on-disk cache is self-limiting: entries past their TTL are pruned and a
hard file cap (`CACHE_MAX_FILES`, default 500, oldest evicted first) keeps a
flood of unique URLs — e.g. random `?title=` values — from filling the disk.

### 5. (Optional) Cache at the web-server level

The PHP disk cache already makes cold renders fast. If you want Apache to
serve repeat requests **without invoking PHP at all**, add a `.htaccess` in
the banner's directory.

First, reinforce the caching headers (works entirely in `.htaccess`, requires
`mod_headers` + `mod_expires`):

```apache
# .htaccess — /var/www/html/github-memories/
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/svg+xml "access plus 4 hours"
</IfModule>

<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=14400"
    # Vary on the query string so different banners aren't served the wrong cache.
    Header append Vary "Accept-Encoding"
</IfModule>
```

For a true **server-side response cache** (Apache stores the rendered SVG and
skips PHP on a hit), use `mod_cache` + `mod_cache_disk`. Note that `CacheRoot`
and `CacheEnable` are **not** allowed in `.htaccess` — they must live in your
`httpd.conf` / vhost:

```apache
# httpd.conf or your <VirtualHost> — NOT .htaccess
LoadModule cache_module        modules/mod_cache.so
LoadModule cache_disk_module   modules/mod_cache_disk.so

CacheRoot   /var/cache/apache2/github-memories
CacheEnable disk /github-memories/
CacheDirLevels 2
CacheDirLength 1
# Honor the Cache-Control: max-age the script emits, and cache query-string URLs.
CacheIgnoreQueryString Off
CacheQuickHandler on
```

Because the script sends `Cache-Control: public, max-age=14400`, `mod_cache`
will cache each distinct banner URL (query string included) for 4 hours and
serve it straight from disk, so neither PHP nor the GitHub API is hit on a
cache hit. Keep the Apache TTL aligned with `CACHE_TTL` in `index.php`.

> nginx equivalent: use `fastcgi_cache` with a key that includes
> `$request_uri` and `fastcgi_cache_valid 200 4h;`.

---

## Parameters

All parameters are optional. Defaults are shown in **bold**.

| Parameter | Default | Allowed | Description |
| --- | --- | --- | --- |
| `username` | `octocat` | sanitized text | Target GitHub user or org login. |
| `type` | **`user`** | `user`, `org` | `user` queries GitHub's GraphQL contributions calendar. `org` falls back to REST repo-commits (no contributionsCalendar exists for orgs). |
| `years` | **`3`** | `1`–`10` | How many years back to sample (1 = just last year, 5 = five years back, etc.). |
| `theme` | **`dark`** | `dark`, `light` | Color palette. |
| `title` | _(auto)_ | any text (max 100 chars) | Custom title. When omitted, the banner uses `On This Day (Mon D) - {username}'s Memories`. |
| `showUsername` | **`1`** | `0`, `1` | When `0`, the username is omitted from the title (useful with a custom `title`). |
| `showDate` | _(auto)_ | `0`, `1` | Prepend the `On This Day (Mon D)` date to the title. Defaults to `1` for the auto title and `0` when a custom `title` is set — pass it explicitly to override (e.g. keep the date alongside a custom title). |
| `details` | **`0`** | `0`, `1` | When `1`, render a per-repo commit breakdown with clickable links to GitHub's commits view filtered by author + that day. |
| `repoLimit` | **`5`** | `1`–`20` | Max repos shown per year when `details=1`. Repos are sorted by commit count desc. |

### Example URLs

```
# Simplest banner, all defaults
?username=octocat

# Light theme, 5 years, with per-repo breakdown
?username=octocat&years=5&theme=light&details=1&repoLimit=8

# Org mode — count commits across the org's public repos
?type=org&username=github

# Custom title, username hidden
?username=octocat&title=My%20Memories&showUsername=0

# Custom title but keep the "On This Day (Mon D)" prefix
?username=octocat&title=My%20Memories&showDate=1
```

---

## Privacy: private repos are never leaked

GitHub's profile contribution graph **counts private contributions** toward
your total. To match that behavior, the banner's `total` number includes
private contributions — but **private repository names and links are never
displayed**.

When `details=1`:

- Only **public** repos appear in the per-repo breakdown.
- Private repos are still counted in the year total.
- A muted note is shown, e.g.
  `+ 2 private repos hidden (5 commits counted)`, so the math adds up for
  viewers without leaking any repo names.

Org mode only queries **public** repos by design, so private contributions
don't exist there.

---

## Rate limits

- GitHub GraphQL (user mode): one request per sampled year (so `years=3`
  = 3 requests per render). With the 4-hour response cache, a typical
  personal usage is tiny.
- GitHub REST (org mode): one request per repo per year, so orgs with many
  active repos will consume more of your rate limit. Keep `years` low for
  orgs (3 is plenty).

The 4-hour `Cache-Control` header means returning visitors reuse their
browser cache, costing you zero API calls.

---

## Embedding tip: rate-limit friendly defaults

If you're embedding the banner on a high-traffic page (popular README,
blog post), consider:

- `years=3` (default) — already minimal.
- Omit `details=1` unless you really want it — `details` issues no extra
  API calls in user mode (GraphQL returns the per-repo breakdown in the
  same query), but in **org mode** it does issue one REST request per repo.
- Keep the default 4-hour cache; don't lower it.

---

## Troubleshooting

**On GitHub the banner shows as a plain link (e.g. just the word "Demo"), not an image**
This is a GitHub **Camo** timeout, not a markdown problem — `![alt](url)` is
correct. GitHub proxies the image through `camo.githubusercontent.com`, which
gives up if your server is slow to respond, and a broken proxied image falls
back to its `alt` text (which GitHub wraps in a link). Causes and fixes:
- **Cold render is too slow.** Each fresh render makes one GitHub API call per
  sampled year. This project caches renders server-side (system temp dir) so
  subsequent Camo fetches return in well under a second — make sure that temp
  dir is writable by the PHP process.
- **Warm it up.** Once Camo successfully caches a render it keeps serving it;
  open the raw banner URL in a browser (or `curl` it) once, then hard-refresh
  the GitHub page.
- **Keep `years` low** (the default `3` is fine) so cold renders stay fast.

**Banner shows `_GitHub Memories - Error: GITHUB_TOKEN env var is not set_`**
The `GITHUB_TOKEN` environment variable isn't visible to the PHP process.
Re-check your web server / systemd env configuration and restart the
service.

**Banner renders but counts are all `0`**
- The token's resource owner may not match `username` (fine-grained tokens
  are scoped to a specific user/org).
- The token may lack read access to the target repos.
- The token may be expired.

**`details=1` shows fewer repos than expected**
Private repos are intentionally hidden — see
[Privacy](#privacy-private-repos-are-never-leaked). Raise `repoLimit` if
you want more public repos listed.

**Org counts look low**
Org mode only counts **public** repos (per GitHub's own behavior). Add
the same fine-grained token's resource owner to allow access to private
org repos if desired, though those still won't be displayed by name.

---

## License

MIT — do whatever you want. PRs welcome.