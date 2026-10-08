<?php
/**
 * OutagePulse – Database Connection Handler
 * Provides secure PDO connection to MySQL with auto-reconnect and error resilience.
 */

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'outagepulse_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // If DB doesn't exist yet, try connecting to MySQL server to create it
        if ($e->getCode() == 1049) {
            try {
                $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
                $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, $options);
                $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                initializeTables($pdo);
                return $pdo;
            } catch (Exception $initEx) {
                handleDbError($initEx->getMessage());
            }
        }
        handleDbError($e->getMessage());
    }

    return $pdo;
}

function initializeTables(PDO $pdo): void {
    $sqlFile = __DIR__ . '/schema.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $pdo->exec($sql);
    }
}

function handleDbError(string $message): void {
    // If request is an API request (JSON expected)
    if (
        (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
        (!empty($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], 'get_status.php') !== false || strpos($_SERVER['REQUEST_URI'], 'submit_report.php') !== false))
    ) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Database connection error: ' . $message
        ]);
        exit;
    }

    // Otherwise render clean error page
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Database Connection Error</title><style>body{background:#090d16;color:#f87171;font-family:sans-serif;padding:40px;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}.box{background:#111827;border:1px solid #ef4444;border-radius:12px;padding:24px;max-width:500px;text-align:center;box-shadow:0 10px 25px rgba(239,68,68,0.2);}h2{margin-top:0;color:#ef4444;}p{color:#94a3b8;font-size:14px;}</style></head><body><div class="box"><h2>Database Connection Failed</h2><p>' . htmlspecialchars($message) . '</p><p>Please ensure MySQL is running and <code>schema.sql</code> is imported.</p></div></body></html>';
    exit;
}

// Automatically create $pdo handle for scripts including this file
$pdo = getDbConnection();
