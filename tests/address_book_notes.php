<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function noteCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function noteColumnExists(PDO $pdo)
{
    foreach ($pdo->query('PRAGMA table_info(address_book_entries)')->fetchAll() as $column) {
        if ((string) $column['name'] === 'note') {
            return true;
        }
    }
    return false;
}

function noteBook($peerId, $note)
{
    return array(
        'tags' => array(),
        'peers' => array(array(
            'id' => $peerId,
            'username' => 'peer-user',
            'hostname' => 'peer-host',
            'platform' => 'linux',
            'alias' => 'Peer alias',
            'note' => $note,
            'tags' => array(),
            'hash' => ''
        )),
        'tag_colors' => '{}'
    );
}

$directory = sys_get_temp_dir() . '/rust-book-notes-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $legacyPath = $directory . '/legacy-v3.sqlite3';
    $legacyPdo = new PDO('sqlite:' . $legacyPath);
    $legacyPdo->exec('CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
    $legacyPdo->exec("INSERT INTO schema_migrations(version, applied_at) VALUES(1, '2000-01-01T00:00:00Z'), (2, '2000-01-01T00:00:00Z'), (3, '2000-01-01T00:00:00Z')");
    $legacyPdo->exec('CREATE TABLE address_book_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, rustdesk_id TEXT NOT NULL, username TEXT NOT NULL DEFAULT "", hostname TEXT NOT NULL DEFAULT "", platform TEXT NOT NULL DEFAULT "", alias TEXT NOT NULL DEFAULT "", peer_hash TEXT NOT NULL DEFAULT "", sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    unset($legacyPdo);

    putenv('RUSTDESK_API_DATABASE_PATH=' . $legacyPath);
    $legacyConfig = AppConfig::load(dirname(__DIR__));
    $legacyDb = new Database($legacyConfig);
    $legacyMigrations = new Migrations($legacyDb, $legacyConfig);
    noteCheck($legacyMigrations->migrate() === array(4), 'v3 database must receive note migration');
    noteCheck(noteColumnExists($legacyDb->pdo()), 'note column must be added to v3 database');
    noteCheck($legacyMigrations->migrate() === array(), 'note migration must be idempotent');

    $databasePath = $directory . '/test.sqlite3';
    putenv('RUSTDESK_API_DATABASE_PATH=' . $databasePath);
    $config = AppConfig::load(dirname(__DIR__));
    $db = new Database($config);
    $migrations = new Migrations($db, $config);
    noteCheck($migrations->migrate() === array(1, 2, 3, 4), 'new database must receive all migrations');
    noteCheck($migrations->currentVersion() === 4, 'note schema version must be current');

    $now = $db->now();
    $insertUser = $db->pdo()->prepare('INSERT INTO users(username, username_canonical, display_name, password_hash, is_admin, enabled, created_at, updated_at, password_changed_at) VALUES(:username, :canonical, :display_name, :password_hash, 0, 1, :now, :now, :now)');
    $insertUser->execute(array(':username' => 'alice', ':canonical' => 'alice', ':display_name' => 'Alice', ':password_hash' => 'not-used', ':now' => $now));
    $aliceId = (int) $db->pdo()->lastInsertId();
    $insertUser->execute(array(':username' => 'bob', ':canonical' => 'bob', ':display_name' => 'Bob', ':password_hash' => 'not-used', ':now' => $now));
    $bobId = (int) $db->pdo()->lastInsertId();

    $books = new AddressBookRepository($db);
    $unicodeNote = str_repeat('ä', RustDeskProtocol::MAX_PEER_NOTE_LENGTH);
    $books->replaceForUser($aliceId, noteBook('peer-alice', $unicodeNote));
    $books->replaceForUser($bobId, noteBook('peer-bob', 'Bob private note'));
    $aliceBook = $books->getForUser($aliceId);
    $bobBook = $books->getForUser($bobId);
    noteCheck($aliceBook['peers'][0]['note'] === $unicodeNote, 'Unicode note must survive legacy PUT/GET roundtrip');
    noteCheck($bobBook['peers'][0]['id'] === 'peer-bob', 'user must only receive own peer');
    noteCheck($bobBook['peers'][0]['note'] === 'Bob private note', 'user must not receive another user note');
    noteCheck(strpos(json_encode($aliceBook), 'password') === false, 'legacy book must not store or return passwords');

    $oldClientBook = noteBook('peer-alice', 'ignored');
    unset($oldClientBook['peers'][0]['note']);
    $books->replaceForUser($aliceId, $oldClientBook);
    noteCheck($books->getForUser($aliceId)['peers'][0]['note'] === $unicodeNote, 'old client update must preserve stored note');

    $tooLong = noteBook('peer-alice', str_repeat('ä', RustDeskProtocol::MAX_PEER_NOTE_LENGTH + 1));
    try {
        $books->replaceForUser($aliceId, $tooLong);
        throw new RuntimeException('oversized note was accepted');
    } catch (AddressBookValidationException $exception) {
    }

    echo "Address book note tests passed: v3 migration, idempotence, Unicode roundtrip, isolation and bounds.\n";
} finally {
    putenv('RUSTDESK_API_DATABASE_PATH');
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
