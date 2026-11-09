# Executed validation and remaining limits

2026-11-09, isolated development test only.

## Source and protocol

- Official server 1.1.16 and client 1.5.1 inputs are pinned to immutable commits/submodules in the source manifests.
- Both companion patches apply cleanly to their respective pinned source trees; changed files were byte-compared against the reviewed candidate.
- Independent Python BLAKE2b direction-key vectors match the pinned client implementation.
- Patched hbbs: eight library tests passed, including directional encryption, replay/downgrade checks and protection of all three token-bearing message types. Debug hbbs built successfully and was used only in the separate lab. No release-build or bit-for-bit reproducibility claim.
- Actual-client-code harness: nine verbatim pinned client functions plus unchanged hbb_common transport; two default tests passed. The negative matrix covers eight failures for three token types, observing zero outgoing client bytes. A regression witness exercises the original lax behavior.
- Both real-hbbs integration tests explicitly passed: encrypted ID_NOT_EXIST response without cleartext synthetic token, and tokenless-client compatibility. This harness is distinct from a complete GUI application.

## Complete GUI clients

- Full macOS arm64 app built in run 42703740829; full Linux x64 portable bundle built in run 42704656003. Both workflow runs succeeded and downloaded ZIP digests matched GitHub metadata.
- The first Mac archive required local development resealing because build.py copied its service after Xcode signing. The explicitly approved development-only library-validation exception is documented in BUILD-CLIENT.md. It is not an Apple Developer ID-signed or notarized customer release. OS Gatekeeper/SIP were unchanged.
- Mac -> own Linux device: real video, independently observed digit entry and deletion, mouse delivery, disconnect and reconnect passed via the isolated hbbs/hbbr relay, first signed out and then signed in.
- Signed-in connection launched from the actual address-book card succeeded; this exercises the original secure-TCP failure path with the complete GUI client.
- Login, logout and login again passed against the isolated account API. Only two own-device entries were used. Their IDs and aliases persisted after re-login.
- The Linux machine's normal Wayland session lacks a required RemoteDesktop portal capability. The complete GUI test therefore used a separate nested X11 test window and portable, signature-verified official twm; no desktop/session/service replacement. Those temporary processes and terminals were stopped after testing.
- Unresponsive remote security/settings controls were checked against upstream's deliberate remote-click protections; those protections were not disabled.

## Note roundtrip correction

The GUI test found that notes were dropped both by the legacy API and by the client's legacy serializer/changeNote implementation. Server schema migration 4 adds a bounded UTF-8 note column additively and preserves old-client writes that omit notes. Per-user isolation, existing-v3 migration, idempotence, 300/301 Unicode-character bounds and invalid UTF-8 were tested. The client companion patch adds opt-in legacy note serialization and implements its missing update operation. Final rebuilt-GUI note roundtrip is pending and must not be inferred from these protocol tests.

Server-side note, protocol-limit and security-control suites passed in the pinned PHP 8.4.27 container, including sixteen concurrent rate-limit workers. A static PHP-7.3 compatibility guard passed locally; that is not a PHP-7.3 runtime claim.

## Boundaries

No customer machine was enrolled. Production RustDesk containers, client server settings, DNS, WAN/NAT/firewall and other existing services were unchanged. Lab GUI coverage is local-network relay coverage; public-WAN behavior, Windows service/reboot persistence, normal Linux Wayland unattended access and incoming Mac permissions remain separate acceptance items. No production rollout or customer-ready distribution is claimed.
