#!/usr/bin/env python3
"""Independent Python stdlib check of the official v1 BLAKE2b wire vectors."""
from hashlib import blake2b
from struct import pack

expected = (
    'c189ec6e1935c0751cfc85a0f075405cae507d7925bb19687caf56cbb54e0ecf',
    '8a62648e9195cb10ea900c0a24d2a166c1e982347a2dc6833c3e756bf5715d36',
)
derived = []
for direction, vector in enumerate(expected, 1):
    transcript = b'rdkx-spl' + bytes([direction]) + pack('<II', 1, 1) + bytes([0x22])*32 + bytes([0x33])*32
    value = blake2b(transcript, key=bytes([0x11])*32, digest_size=32).hexdigest()
    assert value == vector, (direction, value)
    derived.append(value)
assert derived[0] != derived[1]
print('PASS: official v1 directional key vectors (independent hashlib implementation)')
