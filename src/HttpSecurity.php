<?php
declare(strict_types=1);

final class HttpSecurity
{
    const MAX_BODY_BYTES = 1048576;
    private static $bodyRead = false;
    private static $body;

    public static function isHttps(AppConfig $config)
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== ''
            && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $peer = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return in_array($peer, $config->get('trusted_proxies'), true)
            && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    public static function remoteIp(AppConfig $config)
    {
        $peer = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!in_array($peer, $config->get('trusted_proxies'), true)
            || !isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $peer;
        }
        // Walk from the nearest proxy. Never trust the caller-controlled left edge.
        $chain = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = trim($chain[$i]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $peer;
            }
            if (!in_array($candidate, $config->get('trusted_proxies'), true)) {
                return $candidate;
            }
        }
        return $peer;
    }

    public static function enforce(AppConfig $config)
    {
        if ($config->get('require_https') && !self::isHttps($config)) {
            self::reject(426, 'HTTPS required');
        }
        if (self::isHttps($config)) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        if (isset($_SERVER['CONTENT_LENGTH'])
            && (float) $_SERVER['CONTENT_LENGTH'] > self::MAX_BODY_BYTES) {
            self::reject(413, 'Request body too large');
        }
        // There are no upload endpoints; PHP hides multipart bodies from php://input.
        if (isset($_SERVER['CONTENT_TYPE']) && stripos((string) $_SERVER['CONTENT_TYPE'], 'multipart/form-data') === 0) {
            self::reject(415, 'Multipart requests are not supported');
        }
        self::readBody();
    }

    public static function readBody()
    {
        if (self::$bodyRead) {
            return self::$body;
        }
        self::$bodyRead = true;
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return false;
        }
        $body = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
        fclose($stream);
        if ($body !== false && strlen($body) > self::MAX_BODY_BYTES) {
            self::reject(413, 'Request body too large');
        }
        self::$body = $body;
        return self::$body;
    }

    private static function reject($status, $message)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(array('error' => $message));
        exit;
    }
}
