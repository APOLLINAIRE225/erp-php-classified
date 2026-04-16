<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

session_start();
Auth::check();
Middleware::role(['developer']);

$pdo = DB::getConnection();

$users = [
    ['username' => 'H20', 'password' => 'appH20',   'role' => 'admin'],
    ['username' => 'lorddev',     'password' => 'edoh',     'role' => 'developer'],
    ['username' => 'manager1',    'password' => 'appH20', 'role' => 'manager'],
    ['username' => 'staff1',      'password' => 'appH20',   'role' => 'staff'],
    ['username' => 'viewer1',     'password' => 'appH20',  'role' => 'viewer'],
];

$stmt = $pdo->prepare("
    INSERT INTO users (username, password, role)
    VALUES (:username, :password, :role)
");

foreach ($users as $u) {
    $stmt->execute([
        'username' => $u['username'],
        'password' => password_hash($u['password'], PASSWORD_DEFAULT),
        'role'     => $u['role'],
    ]);
}

echo "✅ 5 utilisateurs créés proprement avec rôles distincts.";
