# Maintenance fork status

This fork is development software. Its PHP fixes are tested independently of
RustDesk remote-control compatibility. No customer rollout or production
server replacement was performed during development.

## Corrected in the address-book service

- HTTPS is required by default. A reverse proxy must be explicitly trusted by
  exact socket-peer IP; spoofed forwarding headers from other peers are ignored.
- Dynamic bodies have a 1 MiB limit, including chunked JSON and admin forms.
  Unsupported multipart uploads are refused. Also set web-server/PHP limits.
- Failed and pending login attempts are reserved atomically in SQLite, with
  independent limits per canonical username and per client IP. Successful
  verification releases its reservation. A crash fails closed until expiry.
- Protocol imports bound peer/tag counts and field lengths before replacing
  data. Limits can reject oversized existing books; export a protected backup
  and check size before upgrading.
- On Unix, SQLite and application-generated database backups are owner-only.
  Existing files must belong to the PHP service user. This does **not** encrypt
  the database. Use private directories and encrypted backups/volumes; legacy
  peer hashes are credential-equivalent. On Windows configure service-user ACLs.
- Schema migration 3 adds independent login indexes and accommodates older
  databases without an audit table. Run `php scripts/migrate.php` before use.

See [configuration](CONFIGURATION.md) for environment variables and proxy setup.

## Validation

The following tests are part of CI and can be run locally on supported PHP:

```sh
sh tests/php73_static_guard.sh
php tests/security_controls.php
php tests/address_book_limits.php
bash tests/curl_api_smoke.sh
bash tests/curl_security.sh
```

The complete PHP suites passed locally on PHP 8.5.5 and in an isolated
PHP 8.4.27 container on 2026-11-09. No installed RustDesk component was replaced.

Runtime checks include two independent throttle scopes, 16 concurrent SQLite
workers, reservation expiry, legacy schema migration, database/backup modes,
trusted-proxy parsing, HTTP rejection, chunked/declared request limits and
existing login/save/logout behavior. The static PHP 7.3 guard is not a real
PHP 7.3 compatibility test; use a currently supported PHP branch in deployment.

## Remote-control blocker remains separate

A real official macOS RustDesk 1.5.1 client can log into upstream Rust-Book and
show an account's centrally stored peers. With official OSS-hbbs 1.1.16, the
signed-in connection attempt fails before endpoint authentication with
`Failed to secure tcp: deadline has elapsed`. Logging out reaches the normal
endpoint password prompt. API authentication and peer lists alone therefore
cannot be used as remote-control acceptance criteria.

The official client activates an extra Secure-TCP exchange when an account
token is present; this OSS server release waits for client data first. The
address-book API cannot correct a handshake performed on another TCP service.
Removing the server public key is not a supported workaround.

[Experimental paired patches](../experimental/secure-tcp-v1/README.md) are kept
separate from the MIT PHP application and carry their own AGPL license. They
adapt the server to the official client's v1 direction-key exchange and make
all identified client token paths require authenticated encryption. A server
patch alone does not remove the lax handshake behavior of an unmodified client.
The Linux server debug build and all eight hbbs library tests passed. The
client has not been compiled or tested against fake servers or an actual
paired desktop session. No custom binary is bundled or silently installed. See their validation log
for build and runtime evidence; pending checks remain explicit.

Before any customer rollout: compile and verify both patched components,
exercise negative downgrade/tampering tests, and test signed-in desktop/input,
relay fallback, multiple actual clients, restart persistence and unattended
endpoint reboot. This fork is not a paid RustDesk Pro replacement: address-book
login does not centrally grant or revoke the endpoint's remote-access password.
