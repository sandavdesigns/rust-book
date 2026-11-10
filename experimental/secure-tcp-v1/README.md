# Experimental hbbs v1 secure TCP companion patch

Status: isolated source candidate, **not a production recommendation**. Production services, configuration, server identities and installed clients were unchanged; separate lab components were built and exercised. This component is separate from the address-book API application. It repairs the missing rendezvous exchange for modern clients; it does not add account authorization to OSS hbbs. The safe candidate is the **paired server v1 patch plus strict client patch**, subject to the remaining validation gates. An unmodified official 1.5.1 client is not approved for this candidate.

## Exact source boundary

- Server: `rustdesk/rustdesk-server` tag `1.1.16`, commit `73523b31cfd25d77dee862e6fc9f5e1fb5e485ef`.
- Original server submodule: `rustdesk/hbb_common` commit `83419b6549636ee39dacef7776c473f5802e08d6`.
- Protocol/KDF reference: official client `1.5.1`, commit `23e5bb471a040d62f0a6ad52591fb30784265b73`, submodule `fae47e024dd83cef6aaecde0aa7d11731df11649`.
- Server changes: `src/lib.rs`, `src/rendezvous_server.rs`, new `src/secure_tcp.rs`, and two added `KeyExchange` fields plus `KxParams` in the pinned hbb_common schema. No dependency update, no hbbr change, no UDP/NAT-helper/WebSocket change.
- Separate client change: `client-1.5.1-strict.patch` against `src/client.rs` at the client commit above; input/output SHA-256 hashes in `client-source-manifest.json`.

The HTTP extension cannot supply an exchange on hbbs's TCP socket. An API-only change that clears the token or omits the configured server key would avoid the trigger by weakening the intended protection. It is not the repair implemented here.

## Design and security assessment

1. Only the main raw TCP rendezvous listener offers an ephemeral X25519 key signed by the existing Ed25519 server identity. The signed high bit and domain-prefixed signed `KxParams` bind the advertised version to that ephemeral key, matching client 1.5.1.
2. Only version 1 is accepted for an exchange. A zero-version, malformed, truncated, or unopenable exchange closes the socket. There is no fallback from an attempted exchange to plaintext. Older clients requiring version 0 are deliberately unsupported.
3. The client chooses a random secretbox key, sealed with its ephemeral key to the server's ephemeral key. Official v1 keyed BLAKE2b derivation produces distinct keys per direction and binds both public keys and versions. Nonce counters start at one, are direction-specific, and fail before wrapping. Authentication failure closes the receive loop. Zero/one-byte unauthenticated frames are rejected.
4. Cipher state lives in the original `Framed` codec, which `split()` shares. Moving the sink into `tcp_punch` or another task does not duplicate counters or lose encryption. Neither codec nor cipher is cloneable. Coalesced incoming frames stay buffered in the same codec when it is upgraded.
5. Ephemeral secrets are scoped to the exchange; sodiumoxide secret-key types erase their own storage on drop. Derived keys remain only for the socket lifetime. The patch does not persist or log secrets, alter `id_ed25519`, or generate a replacement long-term identity. Memory-erasure guarantees beyond these types, allocator copies, dumps and swap are not claimed.
6. This authenticates the server to a client with the correct configured public key. It does **not** authenticate an API user to hbbs. OSS hbbs does not validate the address-book access token; device/session authorization and client-to-client encryption remain separate. End-to-end session crypto is untouched.
7. The server preserves a tokenless plaintext first request after sending its offer. In pinned official 1.5.1, `src/client.rs:997` and `:1835` read replies through `get_next_nonkeyexchange_msg`; `src/common.rs:2181–2201` explicitly skips one KeyExchange frame. This is source evidence for compatibility, not a completed client runtime test or a guarantee for older clients. The raw TCP codec rejects nonempty tokens in plaintext PunchHoleRequest/RequestRelay/HealthCheck on every frame. A 1 MiB incoming frame limit bounds allocation; handshake I/O has five-second deadlines. This is not full connection-rate/DoS hardening.

### Important remaining client limitation

Client 1.5.1 `secure_tcp` accepts some non-KeyExchange first responses as success without encryption (`key_exchange` returns `Ok(false)` and the caller discards the boolean). An active network attacker can replace an offer with such a message before the token is sent. The server can reject the later plaintext token but cannot undo its disclosure on the wire. Therefore this server-only patch cannot promise complete active-attacker protection for the official legacy-token path. Signed version parameters address stripping of v1 parameters from a genuine offer; they do not repair that separate client fallback.

The separately supplied `client-1.5.1-strict.patch` uses the already present `secure_tcp_required(&mut Stream, &str) -> ResultType<()>` on all three token-bearing rendezvous paths in `src/client.rs`: initial punch, relay request, and health check. Token/switch-code requests no longer skip the handshake merely because the configured public key is empty. On raw TCP, a missing/invalid key, unexpected first message, parse failure or EOF now returns an error before sending secrets. The existing WebRTC-required path remains strict. There is no new API or dependency, and the normal tokenless path remains unchanged. This patch changes source only; no installed client binary has been replaced.

This client patch relies on the paired v1 server. The existing strict helper still accepts a genuine v0 exchange from a different server and skips its own crypto when the application is configured for WebSocket; this work does not certify v0 servers, unprotected ws://, arbitrary client transports, or all RustDesk crypto paths. Use a verified TLS-protected WSS configuration if choosing that separate transport.

Before considering a deployment, build the client using the pinned upstream platform/Flutter workflow and test against fake servers sending EOF, garbage, a non-KeyExchange protobuf, invalid signatures and stripped version parameters: no token/switch code may leave the socket. Test valid paired v1, wrong/missing server keys, direct and forced-relay sessions, and tokenless regression. The actual-client-code negative matrix and signed-in forced-relay GUI test have since passed in the isolated lab; see VALIDATION.md for coverage and remaining gates, including direct-path and customer endpoint acceptance.

## Alternatives reviewed

- The public `admiraldaro/rustdesk-server-secure-tcp-patch` and open upstream proposals [#706](https://github.com/rustdesk/rustdesk-server/pull/706), [#745](https://github.com/rustdesk/rustdesk-server/pull/745) demonstrate the missing exchange. As inspected on 2026-11-09, they enable v0; they were not merged maintainer releases. Automated reviewer confidence and third-party production reports were not treated as validation.
- The pinned upstream v0 `Encrypt` uses one key for both directions with the same counter-derived nonce sequence. The official modern hbb_common regression test itself shows equal first ciphertexts for equal plaintext in opposite directions. Activating this scheme was rejected: differing plaintexts under reused keystream can disclose their XOR, and nonce reuse also harms authentication assumptions.
- Updating the entire submodule would pull unrelated API changes. This candidate copies only the official v1 transcript/KDF semantics into a small server module and adds the required protobuf schema fields.
- WSS is a possible separate transport approach but requires testing the actual client and server WebSocket behavior; it is not implemented here.

## Build and verification

For an isolated Linux build:

```sh
# From the repository root:
cd experimental/secure-tcp-v1
sh fetch-and-apply.sh
docker build -f Dockerfile.test -t rustdesk-v1-test .
docker run --rm rustdesk-v1-test
```

The fetch script creates `upstream/source/` with complete pinned server/submodule sources. The image build runs `cargo test --locked --lib secure_tcp::tests -- --nocapture`, then `cargo build --locked --bin hbbs`. Running the image executes the complete library suite. No ports or production volumes are used. The toolchain version is pinned, but the base image digest is pinned; the Debian package repository snapshot is not: this is a reproducible source recipe, not a claim of bit-for-bit build reproducibility. Cargo uses the unmodified upstream lockfile.

To prepare source independently, run `fetch-and-apply.sh` in a fresh directory. It fetches only the two pinned commits and applies `server-v1.patch` and `hbb-proto-v1.patch`. Review downloaded sources and record archive checksums for redistribution. `check_vectors.py` independently verifies official KDF vectors using Python's hashlib.

Executed: the final Linux debug build and all eight hbbs library tests passed (seven new Secure-TCP tests and the existing database test). Full Mac/Linux GUI compilation and paired signed-in relay desktop/input testing also passed in the separate lab; see VALIDATION.md for exact runs and limitations.

Implemented Rust checks: official KDF vectors and distinct direction ciphertexts; bidirectional decryption; replay, reflection and tampering failure; short-frame rejection; nonce exhaustion; plaintext token/oversize rejection (all three token message types, empty-token positive cases, and real TCP handshake rejection); TCP coalesced handshake plus two encrypted requests; independent moved-sink responses with preserved counters; v0/malformed exchange rejection; tokenless first-message preservation. These tests do not replace a real RustDesk 1.5.1 end-to-end connection test.

Executed validation is recorded in `VALIDATION.md`. Keep incomplete checks visibly incomplete; do not infer success from source review.

## Distribution and AGPL separation

This companion modifies the AGPLv3 RustDesk server; distribute it under AGPLv3 with the upstream license and notices. It is not part of the MIT address-book application's implementation. Keep these patches/source and their license in a clearly marked separate directory or repository. A directory boundary is documentation, not a legal exception for combined derivative works.

For a distributed binary or network-served modified version, provide the corresponding server and modified submodule source, these patches, build scripts, applicable notices and required dependency source under the relevant licenses. Users of the modified network service must receive a prominent, free source-access offer. A patch alone or a link to unmodified upstream source is not the complete corresponding source for the built binary. See the [GNU AGPL FAQ](https://www.gnu.org/licenses/gpl-faq.html#AGPLv3CorrespondingSource) and bundled `LICENSE`, especially sections 1, 6 and 13. No production source-offer mechanism is installed by this experiment.

Public artifacts must contain only synthetic test data and upstream references; never copy production domains, addresses, tokens, private keys, database files or private server configuration into this directory's release package.
