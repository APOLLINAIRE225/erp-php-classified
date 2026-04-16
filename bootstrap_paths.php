<?php

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('PROJECT_URL_BASE')) {
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $projectRoot = realpath(PROJECT_ROOT) ?: PROJECT_ROOT;
    $basePath = '';

    if ($documentRoot && strncmp($projectRoot, $documentRoot, strlen($documentRoot)) === 0) {
        $basePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($projectRoot, strlen($documentRoot)));
    }

    define('PROJECT_URL_BASE', rtrim($basePath, '/'));
}

$appLocalConfig = PROJECT_ROOT . '/config.local.php';
if (is_file($appLocalConfig)) {
    require_once $appLocalConfig;
}

if (!function_exists('app_env')) {
    function app_env(): string
    {
        $env = strtolower(trim((string)($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')));
        return in_array($env, ['local', 'dev', 'development', 'test', 'staging', 'production'], true)
            ? $env
            : 'production';
    }
}

if (!function_exists('app_is_production')) {
    function app_is_production(): bool
    {
        return app_env() === 'production';
    }
}

if (!function_exists('app_bootstrap_security')) {
    function app_bootstrap_security(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (PHP_SAPI !== 'cli') {
            if (!headers_sent()) {
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Content-Type-Options: nosniff');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            }

            if (session_status() === PHP_SESSION_NONE) {
                ini_set('session.use_strict_mode', '1');
                ini_set('session.cookie_httponly', '1');
                ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
                ini_set('session.cookie_samesite', 'Lax');
            }
        }
    }
}

if (!function_exists('app_bootstrap_runtime')) {
    function app_bootstrap_runtime(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (PHP_SAPI === 'cli') {
            return;
        }

        if (app_is_production()) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('html_errors', '0');
            ini_set('log_errors', '1');
            error_reporting(E_ALL);

            set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
                error_log(sprintf('[APP PHP ERROR][%d] %s in %s:%d', $severity, $message, $file, $line));
                return true;
            });

            set_exception_handler(static function (Throwable $e): void {
                error_log('[APP PHP EXCEPTION] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/plain; charset=UTF-8');
                }
                echo 'Une erreur interne est survenue.';
            });

            register_shutdown_function(static function (): void {
                $error = error_get_last();
                if (!$error) {
                    return;
                }
                $fatalLevels = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
                if (!in_array((int)$error['type'], $fatalLevels, true)) {
                    return;
                }
                error_log(sprintf('[APP PHP FATAL][%d] %s in %s:%d', $error['type'], $error['message'] ?? '', $error['file'] ?? '', $error['line'] ?? 0));
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/plain; charset=UTF-8');
                    echo 'Une erreur interne est survenue.';
                }
            });
        } else {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            ini_set('html_errors', '1');
            error_reporting(E_ALL);
        }
    }
}

if (!function_exists('app_ensure_csrf_token')) {
    function app_ensure_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            return '';
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('app_csrf_validate')) {
    function app_csrf_validate(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            return false;
        }
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $token = (string)$token;
        return $sessionToken !== '' && $token !== '' && hash_equals($sessionToken, $token);
    }
}

app_bootstrap_security();
app_bootstrap_runtime();

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        if ($path === '') {
            return APP_ROOT;
        }

        return APP_ROOT . '/' . ltrim($path, '/');
    }
}

if (!function_exists('project_path')) {
    function project_path(string $path = ''): string
    {
        if ($path === '') {
            return PROJECT_ROOT;
        }

        return PROJECT_ROOT . '/' . ltrim($path, '/');
    }
}

if (!function_exists('project_url')) {
    function project_url(string $path = ''): string
    {
        $base = PROJECT_URL_BASE;
        $path = ltrim($path, '/');

        if ($path === '') {
            return $base !== '' ? $base . '/' : '/';
        }

        return ($base !== '' ? $base : '') . '/' . $path;
    }
}

if (!function_exists('generate_receipt_number')) {
    function generate_receipt_number(PDO $pdo, ?string $paymentDate = null): string
    {
        $date = $paymentDate ? substr($paymentDate, 0, 10) : date('Y-m-d');
        $ymd = date('Ymd', strtotime($date));

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM versements WHERE DATE(COALESCE(payment_date, created_at)) = ?"
        );
        $stmt->execute([$date]);
        $next = ((int) $stmt->fetchColumn()) + 1;

        do {
            $receipt = sprintf('REC-%s-%04d', $ymd, $next);
            $check = $pdo->prepare("SELECT COUNT(*) FROM versements WHERE receipt_number = ?");
            $check->execute([$receipt]);
            $exists = (int) $check->fetchColumn() > 0;
            $next++;
        } while ($exists);

        return $receipt;
    }
}

if (!function_exists('generate_yearly_reference')) {
    function generate_yearly_reference(
        PDO $pdo,
        string $table,
        string $column,
        string $prefix,
        ?string $dateColumn = null,
        ?string $dateValue = null
    ): string {
        $date = $dateValue ? substr($dateValue, 0, 10) : date('Y-m-d');
        $year = date('Y', strtotime($date));
        $dateExpr = $dateColumn ?: 'created_at';

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE YEAR(COALESCE({$dateExpr}, created_at)) = ?"
        );
        $stmt->execute([$year]);
        $next = ((int) $stmt->fetchColumn()) + 1;

        do {
            $reference = sprintf('%s-%s-%05d', strtoupper($prefix), $year, $next);
            $check = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
            $check->execute([$reference]);
            $exists = (int) $check->fetchColumn() > 0;
            $next++;
        } while ($exists);

        return $reference;
    }
}

if (!function_exists('normalize_phone_for_whatsapp')) {
    function normalize_phone_for_whatsapp(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }

        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
            $digits = '225' . substr($digits, 1);
        }

        return $digits;
    }
}
