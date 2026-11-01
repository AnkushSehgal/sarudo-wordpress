# Sarudo for WordPress

Connects a WordPress site to a [Sarudo](https://sarudo.com) AI employee. Once connected, Sarudo operates
the site through one authenticated REST endpoint: it publishes posts, manages media and SEO, runs
WooCommerce, maintains plugins and users, and more. Every command it runs is written to an audit log on
your own site.

- Requires WordPress 6.0+ and PHP 7.4+
- License: GPLv2 or later (see [LICENSE](LICENSE))

## Install

1. Put this folder at `wp-content/plugins/sarudo`:

   ```bash
   git clone https://github.com/AnkushSehgal/sarudo-wordpress.git wp-content/plugins/sarudo
   ```

   The packaged zip is also available from [sarudo.com/install/wordpress](https://sarudo.com/install/wordpress)
   (Plugins → Add New → Upload Plugin).
2. Activate **Sarudo** under Plugins.
3. Open **Settings → Sarudo** and copy the connection token. Then click **"I've saved it"** so the plaintext
   is removed from the database; only a bcrypt hash stays.
4. Paste the token into your Sarudo chat when it asks to connect your website.

## The API

Two routes under `/wp-json/sarudo/v1/`, both requiring `Authorization: Bearer <token>`:

| Route | What it does |
|---|---|
| `POST /exec` | Runs one command: `{"command": "posts.create", "args": {...}}` |
| `GET /capabilities` | Lists every registered command, the detected integrations (Yoast, Rank Math, AIOSEO, WooCommerce, Elementor, form and cache plugins) and basic site info |

Send `X-Sarudo-Dry-Run: true` with `/exec` to get a description of what a command would do without doing it.
Every response uses the same envelope:

```json
{ "ok": true, "command": "posts.create", "dry_run": false, "result": { }, "error": null,
  "audit_log_id": 42, "duration_ms": 118 }
```

### Commands

122 commands in these families (`GET /capabilities` returns the exact list for your install):

| Area | Families |
|---|---|
| Content | `posts`, `pages`, `media`, `terms`, `comments`, `revisions` |
| SEO | `seo` (meta via Yoast / Rank Math / AIOSEO, 301 redirects) |
| Design | `menus`, `widgets`, `customizer`, `site_editor`, `themes` |
| Store | `wc` (products, orders and refunds, customers, coupons, sales reports) |
| Administration | `plugins`, `users`, `options`, `settings`, `updates`, `maintenance`, `cron`, `cache`, `backup`, `forms` |
| Low level | `database` (SQL), `files` (under `wp-content/uploads`, or all of `wp-content` when asked) |
| Diagnostics | `site`, `site_health` |

Commands live in [`commands/`](commands); each file registers its family with `Sarudo_Registry::register()`.

## Security

**The connection token grants administrator-level control of the site.** Whoever holds it can install
plugins, manage users and run SQL. Treat it like an administrator password.

- The token has about 288 bits of entropy and is stored as a bcrypt hash (`sarudo_secret_hash`).
- **Rotate** it from Settings → Sarudo; the old token stops working immediately. Deactivating the plugin
  cuts off access too.
- **IP allowlist**: lock the API to your Sarudo instance's address. `X-Forwarded-For` is ignored unless the
  direct peer is listed in the `sarudo_trusted_proxies` option (comma-separated; set it with
  `wp option update sarudo_trusted_proxies "<ips>"`, and only when the site sits behind a proxy or CDN).
- **Rate limit**: 60 requests per minute per token by default, configurable in settings.
- **Confirmation flags**: permanent deletes (posts, pages, media, terms, menus, users, plugins, themes,
  files, WooCommerce products and coupons), refunds, backup restores, changing `siteurl` / `home`, and SQL
  containing `DROP`, `TRUNCATE` or a `DELETE` without `WHERE` are refused unless the call carries an
  explicit `confirm` / `confirm_destructive` flag. The SQL check is a pattern match, not a parser (an
  `UPDATE` without `WHERE` is not gated), and lighter removals (a menu item, a widget, a redirect, a cron
  event, trashing a comment) run without a flag.
- **Audit log**: every call is recorded with its arguments and result, kept 90 days by default and pruned
  daily. Fields named `password`, `token`, `secret`, `api_key`, `authorization` or `bearer` are stored as
  `[REDACTED]`.

### Reporting a vulnerability

Please email **support@sarudo.com** rather than opening a public issue.

## Contributing

This plugin is developed inside Sarudo's main codebase and mirrored here. Issues and pull requests are
welcome; an accepted pull request is ported into the main codebase and arrives here with the next mirror.
