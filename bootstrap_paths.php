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
