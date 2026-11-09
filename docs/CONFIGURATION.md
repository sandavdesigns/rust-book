---
layout: default
title: Configuration | Rust-Book
description: Configuration reference for Rust-Book, including SQLite, token lifetime, login throttling, and admin sessions.
---

# Configuration

Rust-Book uses one PHP configuration file:

```text
config/config.php
```

Create it from:

```text
config/config.example.php
```

Environment variables can override the same options. Do not introduce a second configuration system unless the application design changes.

## Options

### `require_https`

- Boolean, default `true`.
- Environment: `RUSTDESK_API_REQUIRE_HTTPS` (`1` or `0`).
- HTTP requests are rejected with 426 before authentication. This includes
  loopback, because a local reverse proxy can otherwise expose an HTTP bypass.
- Native HTTPS or HTTPS attested by an explicitly trusted proxy is accepted.
- Set `0` only for an isolated local test server bound to loopback; never for
  public deployment. This option is not editable in the admin panel.

### `trusted_proxies`

- Array of exact IPv4/IPv6 addresses, default empty.
- Environment: `RUSTDESK_API_TRUSTED_PROXIES`, comma-separated IP addresses.
- Only these socket peers may supply `X-Forwarded-Proto: https` and
  `X-Forwarded-For`. The latter is walked right-to-left to the nearest
  untrusted address; a caller-supplied leftmost value cannot bypass throttling.
- The proxy must overwrite forwarding headers; restrict backend reachability
  to the proxy. Native Nginx/PHP-FPM with `fastcgi_param HTTPS on` needs no
  forwarded-proto trust. Invalid or ambiguous proto headers do not enable TLS.
- Example: `array('192.0.2.10')`. Use the actual proxy IP, not a whole LAN.

### Request and address-book limits

All dynamic requests are limited to 1 MiB. JSON bodies are read with a bounded
stream even without Content-Length. Configure the web server and PHP
`post_max_size` as well, because PHP may parse admin forms before application
code runs. Protocol limits bound peer counts, tag counts, and field lengths;
oversize imports fail before replacement rather than silently truncating data.

### `database_path`

- Type: string path
- Required: no
- Default: `data/rustdesk-api.sqlite3`
- Environment: `RUSTDESK_API_DATABASE_PATH`
- Example: `/var/lib/rust-book/rust-book.sqlite3`
- Security impact: high; the database contains password hashes, token hashes, and legacy peer hashes.
- Reload required: restart PHP-FPM, Apache, or the PHP development server.

Keep the database outside `public/`. PHP needs write access to the file and its containing directory.

### `token_lifetime_days`

- Type: integer
- Required: no
- Default: `90`
- Bounds: `1` to `3650`
- Environment: `RUSTDESK_API_TOKEN_LIFETIME_DAYS`
- Example: `30`
- Security impact: shorter lifetimes reduce token exposure; longer lifetimes reduce client logins.
- Reload required: new logins use the new value. If set in the admin Settings page, no PHP reload is needed.

### `sqlite_busy_timeout_ms`

- Type: integer
- Required: no
- Default: `5000`
- Bounds: `100` to `60000`
- Environment: `RUSTDESK_API_SQLITE_BUSY_TIMEOUT_MS`
- Example: `10000`
- Security impact: none directly.
- Reload required: restart PHP.

This controls how long SQLite waits for a locked database before returning an error.

### `login_max_failures`

- Type: integer
- Required: no
- Default: `10`
- Bounds: `0` to `1000`
- Environment: `RUSTDESK_API_LOGIN_MAX_FAILURES`
- Example: `8`
- Security impact: high; setting `0` disables rate limiting.
- The threshold applies independently per canonical account and per source IP.
  Verification reserves a slot atomically before password hashing. Pending
  attempts count until finished successfully or the rolling window expires.
  A locked account can therefore also be denied to its legitimate owner until
  the window expires. A proxy without correct client-IP forwarding aggregates
  users under the proxy IP. Configure additional gateway throttling for larger
  deployments; this is not a distributed denial-of-service defense.
- Reload required: new checks use the new value. If set in the admin Settings page, no PHP reload is needed.

### `login_window_seconds`

- Type: integer
- Required: no
- Default: `600`
- Bounds: `60` to `86400`
- Environment: `RUSTDESK_API_LOGIN_WINDOW_SECONDS`
- Example: `900`
- Security impact: high; longer windows make brute-force throttling stricter.
- Reload required: new checks use the new value. If set in the admin Settings page, no PHP reload is needed.

### `admin_session_idle_seconds`

- Type: integer
- Required: no
- Default: `1800`
- Bounds: `300` to `86400`
- Environment: `RUSTDESK_API_ADMIN_SESSION_IDLE_SECONDS`
- Example: `1800`
- Security impact: shorter values reduce unattended admin-session exposure.
- Reload required: new checks use the new value. If set in the admin Settings page, no PHP reload is needed.

### `admin_session_absolute_seconds`

- Type: integer
- Required: no
- Default: `43200`
- Bounds: `600` to `604800`
- Environment: `RUSTDESK_API_ADMIN_SESSION_ABSOLUTE_SECONDS`
- Example: `43200`
- Security impact: caps total admin-session age.
- Reload required: new checks use the new value. If set in the admin Settings page, no PHP reload is needed.

## Admin Settings Page

The admin panel can edit:

- `token_lifetime_days`
- `login_max_failures`
- `login_window_seconds`
- `admin_session_idle_seconds`
- `admin_session_absolute_seconds`

If an option is explicitly set in `config/config.php` or an environment variable, that explicit value takes precedence over the database setting.
