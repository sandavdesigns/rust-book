<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/AddressBookValidationException.php';
require_once __DIR__ . '/../src/RustDeskProtocol.php';

function validBook()
{
    return array(
        'tags' => array('Home'),
        'peers' => array(
            array(
                'id' => 'peer-1',
                'username' => 'user',
                'hostname' => 'host',
                'platform' => 'linux',
                'alias' => 'Desktop',
                'note' => 'Desk note',
                'tags' => array('Home'),
                'hash' => 'hash'
            )
        ),
        'tag_colors' => '{"Home":4283215696}'
    );
}

function assertAccepted($book, $label)
{
    try {
        RustDeskProtocol::normalizeLegacyAddressBook($book);
    } catch (AddressBookValidationException $exception) {
        fwrite(STDERR, $label . " was rejected\n");
        exit(1);
    }
}

function assertRejected($book, $label)
{
    try {
        RustDeskProtocol::normalizeLegacyAddressBook($book);
    } catch (AddressBookValidationException $exception) {
        return;
    }

    fwrite(STDERR, $label . " was accepted\n");
    exit(1);
}

function assertTrue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$atBounds = validBook();
$atBounds['tags'] = array_fill(0, RustDeskProtocol::MAX_TAGS, str_repeat('t', RustDeskProtocol::MAX_TAG_LENGTH));
foreach ($atBounds['tags'] as $index => $unused) {
    $atBounds['tags'][$index] = str_repeat('t', RustDeskProtocol::MAX_TAG_LENGTH - strlen((string) $index)) . $index;
}
$atBounds['peers'][0]['id'] = str_repeat('i', RustDeskProtocol::MAX_ID_LENGTH);
$atBounds['peers'][0]['username'] = str_repeat('u', RustDeskProtocol::MAX_PEER_FIELD_LENGTH);
$atBounds['peers'][0]['hostname'] = str_repeat('h', RustDeskProtocol::MAX_PEER_FIELD_LENGTH);
$atBounds['peers'][0]['platform'] = str_repeat('p', RustDeskProtocol::MAX_PLATFORM_LENGTH);
$atBounds['peers'][0]['alias'] = str_repeat('a', RustDeskProtocol::MAX_PEER_FIELD_LENGTH);
$atBounds['peers'][0]['note'] = str_repeat('n', RustDeskProtocol::MAX_PEER_NOTE_LENGTH);
$atBounds['peers'][0]['hash'] = str_repeat('x', RustDeskProtocol::MAX_PEER_HASH_LENGTH);
$atBounds['peers'][0]['tags'] = array_slice($atBounds['tags'], 0, RustDeskProtocol::MAX_TAGS_PER_PEER);
$atBounds['tag_colors'] = '{}';
assertAccepted($atBounds, 'values at configured bounds');

$tooManyPeers = validBook();
$tooManyPeers['peers'] = array_fill(0, RustDeskProtocol::MAX_PEERS + 1, $tooManyPeers['peers'][0]);
assertRejected($tooManyPeers, 'too many peers');

$tooManyTags = validBook();
$tooManyTags['tags'] = array_fill(0, RustDeskProtocol::MAX_TAGS + 1, 'tag');
assertRejected($tooManyTags, 'too many tags');

$tooManyPeerTags = validBook();
$tooManyPeerTags['peers'][0]['tags'] = array_fill(0, RustDeskProtocol::MAX_TAGS_PER_PEER + 1, 'tag');
assertRejected($tooManyPeerTags, 'too many peer tags');

$tooLongId = validBook();
$tooLongId['peers'][0]['id'] = str_repeat('i', RustDeskProtocol::MAX_ID_LENGTH + 1);
assertRejected($tooLongId, 'oversized peer id');

$oversizedPeerFields = array(
    'username' => RustDeskProtocol::MAX_PEER_FIELD_LENGTH,
    'hostname' => RustDeskProtocol::MAX_PEER_FIELD_LENGTH,
    'platform' => RustDeskProtocol::MAX_PLATFORM_LENGTH,
    'alias' => RustDeskProtocol::MAX_PEER_FIELD_LENGTH,
    'hash' => RustDeskProtocol::MAX_PEER_HASH_LENGTH
);
foreach ($oversizedPeerFields as $field => $limit) {
    $tooLongField = validBook();
    $tooLongField['peers'][0][$field] = str_repeat('x', $limit + 1);
    assertRejected($tooLongField, 'oversized peer ' . $field);
}

$tooLongNote = validBook();
$tooLongNote['peers'][0]['note'] = str_repeat('n', RustDeskProtocol::MAX_PEER_NOTE_LENGTH + 1);
assertRejected($tooLongNote, 'oversized peer note');

$unicodeNoteAtLimit = validBook();
$unicodeNoteAtLimit['peers'][0]['note'] = str_repeat('ä', RustDeskProtocol::MAX_PEER_NOTE_LENGTH);
assertAccepted($unicodeNoteAtLimit, '300 Unicode note characters');

$unicodeNoteTooLong = validBook();
$unicodeNoteTooLong['peers'][0]['note'] = str_repeat('ä', RustDeskProtocol::MAX_PEER_NOTE_LENGTH + 1);
assertRejected($unicodeNoteTooLong, '301 Unicode note characters');

$invalidUtf8Note = validBook();
$invalidUtf8Note['peers'][0]['note'] = "\xC3\x28";
assertRejected($invalidUtf8Note, 'invalid UTF-8 note');

$tooLongTag = validBook();
$tooLongTag['tags'][0] = str_repeat('t', RustDeskProtocol::MAX_TAG_LENGTH + 1);
assertRejected($tooLongTag, 'oversized tag');

$duplicatePeerId = validBook();
$duplicatePeerId['peers'][] = $duplicatePeerId['peers'][0];
assertRejected($duplicatePeerId, 'duplicate peer id');

$duplicateTag = validBook();
$duplicateTag['tags'][] = 'Home';
assertRejected($duplicateTag, 'duplicate tag');

$duplicatePeerTag = validBook();
$duplicatePeerTag['peers'][0]['tags'][] = 'Home';
$normalizedDuplicatePeerTag = RustDeskProtocol::normalizeLegacyAddressBook($duplicatePeerTag);
assertTrue(count($normalizedDuplicatePeerTag['peers'][0]['tags']) === 1, 'duplicate peer tag must be deduplicated');

$unknownColorTag = validBook();
$unknownColorTag['tag_colors'] = '{"Missing":1}';
$normalizedUnknownColorTag = RustDeskProtocol::normalizeLegacyAddressBook($unknownColorTag);
assertTrue($normalizedUnknownColorTag['tag_colors_map'] === array(), 'color for an unknown tag must be ignored');

$invalidBook = validBook();
$invalidBook['peers'][0]['id'] = str_repeat('i', RustDeskProtocol::MAX_ID_LENGTH + 1);
$beforeInvalidNormalization = serialize($invalidBook);
assertRejected($invalidBook, 'invalid book for mutation check');
if (serialize($invalidBook) !== $beforeInvalidNormalization) {
    fwrite(STDERR, "normalization mutated rejected input\n");
    exit(1);
}

echo "Address book protocol limits passed.\n";
