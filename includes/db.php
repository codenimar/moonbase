<?php
/**
 * Database connection (PDO singleton)
 */
require_once __DIR__ . '/../config/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Support Unix socket (preferred on local Linux) or TCP
    if (defined('DB_SOCKET') && DB_SOCKET) {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            DB_SOCKET, DB_NAME, DB_CHARSET
        );
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
    }
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}
