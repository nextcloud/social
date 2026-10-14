# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Turns an Aloha Social Bluesky recovery phrase into the private key it
# stands for, offline and with nothing but Python 3:
#
#   python3 recovery-key.py bip39-english.txt
#
# then type the twelve words. It prints the private key as hex and in the
# multibase form `goat plc sign --plc-signing-key` takes; `goat key inspect`
# shows the did:key of it, which is the one listed on your DID.
# The word list is BIP-39's English list.

import hashlib
import sys

BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'

words = open(sys.argv[1], encoding='utf-8').read().split()
phrase = input('Twelve words: ').lower().split()
if len(words) != 2048 or len(phrase) != 12 or any(word not in words for word in phrase):
    sys.exit('Not twelve words of the list')

bits = ''.join(format(words.index(word), '011b') for word in phrase)
entropy = int(bits[:128], 2).to_bytes(16, 'big')
if int(bits[128:], 2) != hashlib.sha256(entropy).digest()[0] >> 4:
    sys.exit('The words do not check: one of them is wrong')

key = hashlib.sha256(b'social.atproto.recovery.v1' + entropy).digest()
number = int.from_bytes(b'\x81\x26' + key, 'big')
multibase = ''
while number:
    number, digit = divmod(number, 58)
    multibase = BASE58[digit] + multibase

print('hex:       ' + key.hex())
print('multibase: z' + multibase)
