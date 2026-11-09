# Validation

2026-11-09:

- PASS: immutable common.rs source hash verified by `extract.py`.
- PASS: nine extracted function bodies retain the exact source text; individual line boundaries and hashes recorded.
- PASS: full vendored hbb_common tree byte-compared to the previously downloaded immutable official commit.
- PASS: Rust 1.90 compilation and both default tests executed in the isolated container; 2 passed, 0 failed, 2 integration tests intentionally ignored in this invocation. The negative matrix covers 24 cases and records zero outgoing client bytes.
- PASS: both integration tests explicitly executed against a separate real patched hbbs process on the internal lab network; 2 passed, 0 failed. Verified encrypted ID_NOT_EXIST response without cleartext synthetic token, plus tokenless compatibility. No desktop session was exercised.
- Full GUI client and remote-control session: outside this harness, pending separately.

No successful runtime or GUI result is inferred from source inspection alone.

Resolved Cargo.lock SHA-256: `8be18d1e4e6d8223b177f284a0d1062f6c1c1e05ebc424d2a6dfcdb59e0501c7`.
