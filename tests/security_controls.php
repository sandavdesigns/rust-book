<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (isset($argv[1]) && $argv[1] === '--worker') {
    $config = AppConfig::load(dirname(__DIR__));
    $db = new Database($config);
    $limiter = new RateLimiter($db, $config, new Settings($db));
    echo $limiter->reserveAttempt('parallel', '198.51.100.9') === null ? 'blocked' : 'reserved';
    exit;
}

$directory = sys_get_temp_dir() . '/rust-book-security-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    putenv('RUSTDESK_API_DATABASE_PATH=' . $directory . '/test.sqlite3');
    putenv('RUSTDESK_API_LOGIN_MAX_FAILURES=5');
    putenv('RUSTDESK_API_TRUSTED_PROXIES=127.0.0.1,192.0.2.1');
    putenv('RUSTDESK_API_REQUIRE_HTTPS=1');
    $config = AppConfig::load(dirname(__DIR__));
    check($config->get('require_https') === true, 'HTTPS enabled');
    $_SERVER = array('REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.1');
    check(!HttpSecurity::isHttps($config), 'spoofed proto must be ignored');
    check(HttpSecurity::remoteIp($config) === '198.51.100.1', 'spoofed client IP must be ignored');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    check(HttpSecurity::isHttps($config), 'explicit trusted proxy may attest HTTPS');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.66,198.51.100.42,192.0.2.1';
    check(HttpSecurity::remoteIp($config) === '198.51.100.42', 'nearest untrusted client wins');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-IP';
    check(HttpSecurity::remoteIp($config) === '127.0.0.1', 'invalid forwarding header fails closed');
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https,http';
    check(!HttpSecurity::isHttps($config), 'ambiguous proto must be rejected');
    $_SERVER['HTTPS'] = 'on';
    check(HttpSecurity::isHttps($config), 'native TLS accepted');

    $db = new Database($config);
    $migrations = new Migrations($db, $config);
    $migrations->migrate();
    check($migrations->migrate() === array(), 'migration idempotent');
    check($migrations->currentVersion() === 3, 'schema upgraded');
    $db->pdo()->exec('DELETE FROM schema_migrations WHERE version = 3');
    $db->pdo()->exec('DROP TABLE login_attempts');
    check($migrations->migrate() === array(3), 'legacy v2 database gains the login table');
    check($db->tableExists('login_attempts'), 'legacy audit table created');
    if (DIRECTORY_SEPARATOR === '/') {
        clearstatcache();
        check((fileperms($db->path()) & 0777) === 0600, 'database is owner-only');
        $backup = $db->backup('permissions-test');
        clearstatcache();
        check((fileperms($backup) & 0777) === 0600, 'backup is owner-only');
    }
    $limiter = new RateLimiter($db, $config, new Settings($db));
    for ($i = 0; $i < 5; $i++) {
        check($limiter->reserveAttempt('spray-' . $i, '198.51.100.1') !== null, 'initial IP attempts allowed');
    }
    check($limiter->reserveAttempt('new-name', '198.51.100.1') === null, 'username spraying blocked');
    for ($i = 0; $i < 5; $i++) {
        check($limiter->reserveAttempt('target', '203.0.113.' . $i) !== null, 'initial account attempts allowed');
    }
    check($limiter->reserveAttempt('TARGET', '203.0.113.99') === null, 'rotating IPs blocked per canonical account');
    $id = $limiter->reserveAttempt('successful', '192.0.2.9');
    $limiter->finishAttempt($id, true, 'success');
    for ($i = 0; $i < 5; $i++) {
        check($limiter->reserveAttempt('successful', '192.0.2.9') !== null, 'success does not consume failure limit');
    }
    check($limiter->reserveAttempt('successful', '192.0.2.9') === null, 'pending reservations consume limit');
    $db->pdo()->exec("UPDATE login_attempts SET attempted_at = '2000-01-01T00:00:00Z'");
    check($limiter->reserveAttempt('target', '203.0.113.99') !== null, 'window expiry releases account');

    $workers = array();
    for ($i = 0; $i < 16; $i++) {
        $pipes = array();
        $process = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker',
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        check(is_resource($process), 'worker started');
        fclose($pipes[0]);
        $workers[] = array($process, $pipes);
    }
    $reserved = 0;
    foreach ($workers as $worker) {
        $output = stream_get_contents($worker[1][1]);
        $error = stream_get_contents($worker[1][2]);
        fclose($worker[1][1]); fclose($worker[1][2]);
        check(proc_close($worker[0]) === 0, 'worker error: ' . $error);
        check($output === 'reserved' || $output === 'blocked', 'known worker result');
        if ($output === 'reserved') { $reserved++; }
    }
    check($reserved === 5, 'atomic reservation must admit exactly five concurrent attempts');
    echo "Security controls passed: proxy trust, TLS detection, migration, account/IP throttling, expiry, 16 concurrent workers.\n";
} finally {
    unset($limiter, $migrations, $db);
    foreach (glob($directory . '/*') as $path) { unlink($path); }
    rmdir($directory);
}
