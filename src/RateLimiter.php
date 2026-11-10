<?php
declare(strict_types=1);

final class RateLimiter
{
    private $db;
    private $config;
    private $settings;

    public function __construct(Database $db, AppConfig $config, Settings $settings)
    {
        $this->db = $db;
        $this->config = $config;
        $this->settings = $settings;
    }

    // Reserve before password verification so simultaneous workers share the limit.
    // A crashed/unfinished verification counts until the rolling window expires.
    // null means blocked; zero means explicitly disabled for local development.
    public function reserveAttempt($username, $remoteIp)
    {
        $max = $this->settingInt('login_max_failures', 10, 0, 1000);
        if ($max <= 0) {
            return 0;
        }
        $window = $this->settingInt('login_window_seconds', 600, 60, 86400);
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $window);
        $canonical = Security::canonicalUsername($username);
        $pdo = $this->db->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Separate scopes prevent both username spraying and rotating IPs.
            $stmt = $pdo->prepare(
                'SELECT
                    SUM(CASE WHEN username_canonical = :username THEN 1 ELSE 0 END) AS account_count,
                    SUM(CASE WHEN remote_ip = :ip THEN 1 ELSE 0 END) AS ip_count
                 FROM login_attempts
                 WHERE success = 0 AND attempted_at >= :cutoff
                   AND (username_canonical = :username OR remote_ip = :ip)'
            );
            $stmt->execute(array(':username' => $canonical, ':ip' => (string) $remoteIp, ':cutoff' => $cutoff));
            $counts = $stmt->fetch();
            if ((int) $counts['account_count'] >= $max || (int) $counts['ip_count'] >= $max) {
                $pdo->exec('COMMIT');
                return null;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO login_attempts(username, username_canonical, remote_ip, attempted_at, success, reason)
                 VALUES(:username, :canonical, :ip, :now, 0, :reason)'
            );
            $stmt->execute(array(':username' => (string) $username, ':canonical' => $canonical,
                ':ip' => (string) $remoteIp, ':now' => $this->db->now(), ':reason' => 'pending'));
            $id = (int) $pdo->lastInsertId();
            $delete = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
            $delete->execute(array(':cutoff' => gmdate('Y-m-d\TH:i:s\Z', time() - $window * 6)));
            $pdo->exec('COMMIT');
            return $id;
        } catch (Exception $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function finishAttempt($id, $success, $reason)
    {
        if ($id === 0) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE login_attempts SET success = :success, reason = :reason
             WHERE id = :id AND reason = :pending'
        );
        $stmt->execute(array(':success' => $success ? 1 : 0, ':reason' => (string) $reason,
            ':id' => (int) $id, ':pending' => 'pending'));
    }

    private function settingInt($name, $default, $min, $max)
    {
        if ($this->config->isExplicit($name)) {
            return $this->config->getInt($name);
        }
        return $this->settings->getInt($name, $default, $min, $max);
    }
}
