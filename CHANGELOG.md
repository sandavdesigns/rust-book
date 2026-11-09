# Changelog

## 0.1.1-dev — maintenance fork

- Require HTTPS by default, including loopback; explicitly trust TLS proxies.
- Bound JSON and admin request bodies, including chunked requests.
- Reserve login attempts atomically, with independent source-IP/account limits.
- Bound address-book item counts and string lengths before replacement.
- Create Unix database and backup files with owner-only permissions.
- Add regression tests and legacy schema upgrade coverage.
- Document the remaining OSS Secure-TCP blocker and separate experimental
  paired client/server patches. No production compatibility claim.


All notable public changes to Rust-Book are documented here.

## 0.1.0 - Initial Public Release

Initial public release of the lightweight RustDesk-compatible account and legacy address-book API.

Included:

- RustDesk password login, current-user, logout, and bearer-token authentication.
- Legacy single-address-book compatibility through `POST /api/ab/personal`, `GET /api/ab`, `POST /api/ab`, and `POST /api/ab/get`.
- SQLite storage for users, token hashes, login attempts, settings, tags, and address-book peers.
- Browser admin panel for users, settings, tags, and peers.
- PHP 7.3-compatible application source.
- Curl-based smoke tests, documentation, deployment examples, and CI workflow.
