# Validation log

2026-11-09:

- Downloaded official server tag and resolved immutable commit and submodule SHA via GitHub tree/ref metadata.
- Inspected official client 1.5.1 handshake and pinned hbb_common v1 KDF; compared community v0 patch and open upstream PRs #706/#745.
- PASS: `git apply --check` for both patches against their respective clean pinned source trees.
- PASS: applied server, schema and client patches to fresh copies and byte-compared every changed file with the reviewed candidate source.
- PASS: `python3 check_vectors.py`, independent hashlib BLAKE2b implementation matches both official direction-key vectors.
- PASS: `sh -n fetch-and-apply.sh`.
- PASS: strict client patch `git apply --check` against immutable client source; verified existing `secure_tcp_required` signature and all three token-bearing `src/client.rs` paths. Original and patched SHA-256 hashes recorded in `client-source-manifest.json`.
- Client compile, fake-server downgrade negative tests, and real paired client/server test: pending. Source comparison is not counted as runtime validation.
- Tokenless-client compatibility source review: official 1.5.1 punch and relay response readers use `get_next_nonkeyexchange_msg`, which skips one offered KeyExchange. Actual GUI compatibility remains pending; server unit test covers first-request preservation only.
- PASS: isolated Linux Docker build using Rust 1.90, locked dependencies, one CPU and 1100 MiB RAM; all eight hbbs library tests passed (seven Secure-TCP tests plus the existing database test).
- PASS: `cargo test --locked --lib`: 8 passed, 0 failed, 0 ignored, 0 filtered out.
- PASS: `cargo build --locked --bin hbbs` (debug/dev profile). Test binary SHA-256: `cb8f684ed6d53258da890693a0c4ff51c68c0f12ca44da87bf0719fe78a90c63`. No binary is bundled or installed.
- Release-profile build was stopped in favor of the final debug test build; no release-binary or reproducible-binary claim. Upstream compiler warnings remain.
- Official 1.5.1 GUI end-to-end connection, signed-in and signed-out regression: pending.
- Production deployment: not performed and not authorized by this companion task.

Local native Cargo was unavailable; Apple system git was blocked by an unaccepted Xcode license. Patch validation used the available non-Apple git executable. No licensing/system-toolchain settings were changed.

- Review correction: plaintext token protection now covers HealthCheck (`Hc`) as well as PunchHoleRequest and RequestRelay in codec and handshake. Expanded unit test covers nonempty/empty tokens for all three types; new loopback TCP test rejects all three token-bearing messages. Eight Rust tests are now defined; execution remains owned by the integrating agent.
