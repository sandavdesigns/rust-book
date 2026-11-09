<?php
declare(strict_types=1);

final class RustDeskProtocol
{
    const MAX_PEERS = 10000;
    const MAX_TAGS = 1000;
    const MAX_TAGS_PER_PEER = 128;
    const MAX_ID_LENGTH = 128;
    const MAX_TAG_LENGTH = 128;
    const MAX_PEER_FIELD_LENGTH = 512;
    // RustDesk's client limits peer notes to 300 Unicode characters.
    const MAX_PEER_NOTE_LENGTH = 300;
    const MAX_PEER_NOTE_BYTES = 1200;
    const MAX_PLATFORM_LENGTH = 128;
    const MAX_PEER_HASH_LENGTH = 4096;
    const MAX_TAG_COLOR_LENGTH = 32;
    const MAX_TAG_COLORS_JSON_LENGTH = 262144;

    public static function normalizeLegacyAddressBook($book)
    {
        if (!is_array($book) || !isset($book['tags']) || !isset($book['peers'])) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $tags = self::normalizeStringList($book['tags'], 'tags', self::MAX_TAGS, self::MAX_TAG_LENGTH);
        self::assertUniqueStrings($tags, 'tags');

        if (!self::isListArray($book['peers'])) {
            throw new AddressBookValidationException('Invalid address book data');
        }
        self::assertListLimit($book['peers'], self::MAX_PEERS);

        $tagColors = array();
        if (isset($book['tag_colors'])) {
            if (!is_string($book['tag_colors'])) {
                throw new AddressBookValidationException('Invalid address book data');
            }
            if (strlen($book['tag_colors']) > self::MAX_TAG_COLORS_JSON_LENGTH) {
                throw new AddressBookValidationException('Invalid address book data');
            }
            $tagColors = self::decodeTagColors($book['tag_colors']);
        }

        $tagIndex = array();
        foreach ($tags as $tag) {
            $tagIndex[$tag] = true;
        }

        $peers = array();
        $ids = array();
        foreach ($book['peers'] as $peer) {
            $normalized = self::normalizeLegacyPeer($peer);
            if (isset($ids[$normalized['id']])) {
                throw new AddressBookValidationException('Invalid address book data');
            }
            $ids[$normalized['id']] = true;

            foreach ($normalized['tags'] as $tag) {
                if (!isset($tagIndex[$tag])) {
                    if (count($tags) >= self::MAX_TAGS) {
                        throw new AddressBookValidationException('Invalid address book data');
                    }
                    $tags[] = $tag;
                    $tagIndex[$tag] = true;
                }
            }

            $peers[] = $normalized;
        }

        $filteredColors = array();
        foreach ($tagColors as $tag => $color) {
            if (isset($tagIndex[$tag])) {
                $filteredColors[$tag] = $color;
            }
        }

        return array(
            'tags' => $tags,
            'peers' => $peers,
            'tag_colors_map' => $filteredColors
        );
    }

    public static function legacyBook($tags, $peers, $tagColors)
    {
        return array(
            'tags' => array_values($tags),
            'peers' => array_values($peers),
            'tag_colors' => self::encodeTagColors($tagColors, $tags)
        );
    }

    public static function jsonDecodeObject($json)
    {
        $decoded = json_decode((string) $json, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        return $decoded;
    }

    private static function normalizeLegacyPeer($peer)
    {
        if (!is_array($peer) || !isset($peer['id'])) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $id = self::boundedStringValue($peer['id'], self::MAX_ID_LENGTH);
        if (trim($id) === '') {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $peerTags = isset($peer['tags'])
            ? self::normalizeStringList($peer['tags'], 'peers.tags', self::MAX_TAGS_PER_PEER, self::MAX_TAG_LENGTH)
            : array();
        $peerTags = self::uniqueList($peerTags);

        return array(
            'id' => $id,
            'username' => isset($peer['username']) ? self::boundedStringValue($peer['username'], self::MAX_PEER_FIELD_LENGTH) : '',
            'hostname' => isset($peer['hostname']) ? self::boundedStringValue($peer['hostname'], self::MAX_PEER_FIELD_LENGTH) : '',
            'platform' => isset($peer['platform']) ? self::boundedStringValue($peer['platform'], self::MAX_PLATFORM_LENGTH) : '',
            'alias' => isset($peer['alias']) ? self::boundedStringValue($peer['alias'], self::MAX_PEER_FIELD_LENGTH) : '',
            // Keep presence separate from value so old clients that do not know
            // this field do not erase a note written by a newer client.
            'note' => isset($peer['note']) ? self::boundedUnicodeStringValue($peer['note'], self::MAX_PEER_NOTE_LENGTH, self::MAX_PEER_NOTE_BYTES) : '',
            'note_provided' => array_key_exists('note', $peer),
            'tags' => $peerTags,
            'hash' => isset($peer['hash']) ? self::boundedStringValue($peer['hash'], self::MAX_PEER_HASH_LENGTH) : ''
        );
    }

    private static function normalizeStringList($value, $field, $maxItems, $maxItemLength)
    {
        if (!self::isListArray($value)) {
            throw new AddressBookValidationException('Invalid address book data');
        }
        self::assertListLimit($value, $maxItems);

        $items = array();
        foreach ($value as $item) {
            $items[] = self::boundedStringValue($item, $maxItemLength);
        }

        return $items;
    }

    private static function stringValue($value)
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw new AddressBookValidationException('Invalid address book data');
    }

    private static function boundedStringValue($value, $maxLength)
    {
        $string = self::stringValue($value);
        if (strlen($string) > $maxLength) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        return $string;
    }

    private static function boundedUnicodeStringValue($value, $maxCharacters, $maxBytes)
    {
        $string = self::stringValue($value);
        if (strlen($string) > $maxBytes) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $matches = array();
        $count = preg_match_all('/./us', $string, $matches);
        if ($count === false || $count > $maxCharacters) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        return $string;
    }

    private static function assertListLimit($items, $maxItems)
    {
        if (count($items) > $maxItems) {
            throw new AddressBookValidationException('Invalid address book data');
        }
    }

    private static function assertUniqueStrings($items, $field)
    {
        $seen = array();
        foreach ($items as $item) {
            if (isset($seen[$item])) {
                throw new AddressBookValidationException('Invalid address book data');
            }
            $seen[$item] = true;
        }
    }

    private static function uniqueList($items)
    {
        $seen = array();
        $unique = array();
        foreach ($items as $item) {
            if (!isset($seen[$item])) {
                $unique[] = $item;
                $seen[$item] = true;
            }
        }

        return $unique;
    }

    private static function isListArray($value)
    {
        if (!is_array($value)) {
            return false;
        }

        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }

    private static function decodeTagColors($json)
    {
        $decoded = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $colors = array();
        foreach ($decoded as $tag => $color) {
            if (!is_string($tag) || strlen($tag) > self::MAX_TAG_LENGTH) {
                throw new AddressBookValidationException('Invalid address book data');
            }

            if (is_int($color)) {
                $colors[$tag] = (string) $color;
            } elseif (is_string($color) && preg_match('/^-?\d+$/', $color)) {
                if (strlen($color) > self::MAX_TAG_COLOR_LENGTH) {
                    throw new AddressBookValidationException('Invalid address book data');
                }
                $colors[$tag] = $color;
            } elseif (is_float($color) && floor($color) === $color && $color >= -2147483648 && $color <= 2147483647) {
                $colors[$tag] = sprintf('%.0f', $color);
            } else {
                throw new AddressBookValidationException('Invalid address book data');
            }
        }

        if (count($colors) > self::MAX_TAGS) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        return $colors;
    }

    private static function encodeTagColors($colors, $tags)
    {
        $parts = array();
        foreach ($tags as $tag) {
            if (!array_key_exists($tag, $colors) || $colors[$tag] === null || $colors[$tag] === '') {
                continue;
            }

            $encodedTag = json_encode((string) $tag, JSON_UNESCAPED_SLASHES);
            if ($encodedTag === false) {
                throw new RuntimeException('Could not encode tag color key.');
            }
            $parts[] = $encodedTag . ':' . (string) $colors[$tag];
        }

        return '{' . implode(',', $parts) . '}';
    }
}
