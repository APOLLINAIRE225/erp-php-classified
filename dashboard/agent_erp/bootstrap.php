<?php
if (PHP_SAPI === 'cli') {
    $cliSessionPath = sys_get_temp_dir() . '/php_sessions';
    if (!is_dir($cliSessionPath)) {
        @mkdir($cliSessionPath, 0777, true);
    }
    if (is_dir($cliSessionPath) && is_writable($cliSessionPath)) {
        session_save_path($cliSessionPath);
    }
}

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

$agentErpBaseDir = __DIR__;
$agentErpPublicBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/dashboard/agent_erp.php'), '/');
$agentErpPageUrl = ($agentErpPublicBase ?: '/dashboard') . '/agent_erp.php';
$agentErpControllerUrl = ($agentErpPublicBase ?: '/dashboard') . '/agent_erp_controller.php';
$agentErpCssUrl = ($agentErpPublicBase ?: '/dashboard') . '/agent_erp/assets/agent_erp.css';
$agentErpJsUrl = ($agentErpPublicBase ?: '/dashboard') . '/agent_erp/assets/agent_erp.js';
$agentErpSetupFile = $agentErpBaseDir . '/SETUP_TOKEN.txt';
$agentErpInternalMode = true;

$pdo = null;
$db_error = '';
$erp_path = '/var/www/html/';
$db_host = 'localhost';
$db_name = 'ESPERANCEH20';
$db_user = 'root';
$db_pass = 'dev+Esperanceh20.dev';

foreach (["$erp_path/includes/config.php", "$erp_path/app/core/DB.php", "$erp_path/config.php", "$erp_path/includes/db.php", "$erp_path/db.php"] as $cf) {
    if (!file_exists($cf)) {
        continue;
    }
    $raw = (string) file_get_contents($cf);
    if (preg_match("/['\"]?DB_HOST['\"]?\s*[=:,>]+\s*['\"]([^'\";\s]+)/i", $raw, $m)) {
        $db_host = $m[1];
    }
    if (preg_match("/['\"]?DB_NAME['\"]?\s*[=:,>]+\s*['\"]([^'\";\s]+)/i", $raw, $m)) {
        $db_name = $m[1];
    }
    if (preg_match("/['\"]?DB_USER['\"]?\s*[=:,>]+\s*['\"]([^'\";\s]+)/i", $raw, $m)) {
        $db_user = $m[1];
    }
    if (preg_match("/['\"]?DB_PASS(?:WORD)?['\"]?\s*[=:,>]+\s*['\"]([^'\"]*)/i", $raw, $m)) {
        $db_pass = $m[1];
    }
    if (preg_match("/dbname=([A-Za-z0-9_]+)/", $raw, $m)) {
        $db_name = $m[1];
    }
    break;
}

$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
];

foreach ([[$db_host, $db_name, $db_user, $db_pass], [$db_host, $db_name, 'root', ''], [$db_host, $db_name, 'root', 'root'], [$db_host, $db_name, 'kali', ''], ['localhost', 'dev+Esperanceh20.dev', 'root', '']] as [$h, $n, $u, $p]) {
    try {
        $pdo = new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4", $u, $p, $opts);
        $db_name = $n;
        break;
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
        $pdo = null;
    }
}

$db_ok = ($pdo !== null);

function q(string $sql, array $p = []): array {
    global $pdo;
    if (!$pdo) {
        return [];
    }
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function q1(string $sql, array $p = []): array {
    $r = q($sql, $p);
    return $r[0] ?? [];
}

function qv(string $sql, array $p = []) {
    $r = q1($sql, $p);
    return $r ? reset($r) : null;
}

function qx(string $sql, array $p = []): bool {
    global $pdo;
    if (!$pdo) {
        return false;
    }
    try {
        return $pdo->prepare($sql)->execute($p);
    } catch (PDOException $e) {
        return false;
    }
}

function lid(): int {
    global $pdo;
    return $pdo ? (int) $pdo->lastInsertId() : 0;
}

function h($s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function normalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $map = ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ô' => 'o', 'ö' => 'o', 'î' => 'i', 'ï' => 'i', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae'];
    return strtr($s, $map);
}

function table_exists(string $table): bool {
    return (bool) qv("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
}

function column_exists(string $table, string $column): bool {
    return (bool) qv("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$table, $column]);
}

function ensure_column(string $table, string $column, string $definition): void {
    if (!table_exists($table) || column_exists($table, $column)) {
        return;
    }
    qx("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

function extract_search_keywords(string $text, array $stop = []): array {
    $defaultStop = ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'en', 'et', 'ou', 'ce', 'se', 'si', 'ne', 'je', 'tu', 'il', 'nous', 'vous', 'ils', 'comment', 'faire', 'pour', 'dans', 'sur', 'par', 'avec', 'sans', 'que', 'qui', 'est', 'son', 'sa', 'ses', 'mon', 'ma', 'mes', 'tout', 'bien', 'plus', 'mais', 'pas', 'cest', 'votre', 'notre', 'avoir', 'etre', 'cette', 'the', 'how', 'to', 'do', 'what', 'is', 'a', 'an'];
    $stopWords = $stop ?: $defaultStop;
    $words = preg_split('/[\s\-_,;:!?\.\'"]+/', normalize($text));
    $keywords = [];
    foreach ($words as $word) {
        $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        if ($word !== '' && mb_strlen($word, 'UTF-8') >= 2 && !in_array($word, $stopWords, true)) {
            $keywords[] = $word;
        }
    }
    return array_values(array_unique($keywords));
}

function ensure_agent_schema(): void {
    global $pdo;
    if (!$pdo) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_users(
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(80) DEFAULT 'viewer',
        full_name VARCHAR(150) DEFAULT NULL,
        avatar_color VARCHAR(7) DEFAULT '#a855f7',
        preferred_lang VARCHAR(5) DEFAULT 'fr',
        is_active TINYINT DEFAULT 1,
        must_change_password TINYINT DEFAULT 0,
        last_login DATETIME DEFAULT NULL,
        login_attempts TINYINT DEFAULT 0,
        locked_until DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_kb(
        id INT AUTO_INCREMENT PRIMARY KEY,
        keywords TEXT NOT NULL,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        action_url VARCHAR(500) DEFAULT NULL,
        action_label VARCHAR(200) DEFAULT NULL,
        category VARCHAR(100) DEFAULT 'general',
        company_scope VARCHAR(120) DEFAULT 'general',
        access_roles TEXT DEFAULT NULL,
        access_permissions TEXT DEFAULT NULL,
        intent_type VARCHAR(40) DEFAULT 'how_to',
        tone_admin TEXT DEFAULT NULL,
        tone_staff TEXT DEFAULT NULL,
        hits INT DEFAULT 0,
        version INT DEFAULT 1,
        created_by INT DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_kb_history(
        id INT AUTO_INCREMENT PRIMARY KEY,
        kb_id INT NOT NULL,
        question TEXT,
        answer TEXT,
        keywords TEXT,
        action_url VARCHAR(500),
        action_label VARCHAR(200),
        category VARCHAR(100),
        company_scope VARCHAR(120) DEFAULT 'general',
        access_roles TEXT,
        access_permissions TEXT,
        intent_type VARCHAR(40),
        version INT,
        changed_by INT DEFAULT NULL,
        change_note VARCHAR(255) DEFAULT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_logs(
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        user_role VARCHAR(80) DEFAULT NULL,
        question TEXT,
        kb_id INT DEFAULT NULL,
        intent_type VARCHAR(40) DEFAULT NULL,
        lang_detected VARCHAR(5) DEFAULT 'fr',
        company_scope VARCHAR(120) DEFAULT 'general',
        feedback TINYINT DEFAULT NULL,
        response_ms INT DEFAULT NULL,
        ip_address VARCHAR(60),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_audit(
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        user_role VARCHAR(80),
        action VARCHAR(100),
        details TEXT,
        ip_address VARCHAR(60),
        requires_confirm TINYINT DEFAULT 0,
        confirmed TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_rate_limit(
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        ip_address VARCHAR(60),
        action VARCHAR(40),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_conversations(
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        session_id VARCHAR(128),
        context JSON,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_permissions(
        id INT AUTO_INCREMENT PRIMARY KEY,
        permission_key VARCHAR(120) NOT NULL UNIQUE,
        label VARCHAR(180) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_role_permissions(
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(80) NOT NULL,
        permission_key VARCHAR(120) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY role_perm_unique(role_name, permission_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_bootstrap_tokens(
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash VARCHAR(255) NOT NULL,
        token_preview VARCHAR(24) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_site_index(
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_path VARCHAR(255) NOT NULL UNIQUE,
        module_name VARCHAR(120) DEFAULT NULL,
        page_title VARCHAR(255) DEFAULT NULL,
        route_url VARCHAR(255) DEFAULT NULL,
        summary_text TEXT DEFAULT NULL,
        keywords TEXT DEFAULT NULL,
        source_type VARCHAR(40) DEFAULT 'page',
        last_indexed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    ensure_column('agent_users', 'must_change_password', "TINYINT DEFAULT 0 AFTER is_active");
    ensure_column('agent_kb', 'company_scope', "VARCHAR(120) DEFAULT 'general' AFTER category");
    ensure_column('agent_kb', 'access_permissions', "TEXT DEFAULT NULL AFTER access_roles");
    ensure_column('agent_kb', 'intent_type', "VARCHAR(40) DEFAULT 'how_to' AFTER access_permissions");
    ensure_column('agent_kb', 'version', "INT DEFAULT 1 AFTER hits");
    ensure_column('agent_kb', 'updated_by', "INT DEFAULT NULL AFTER created_by");
    ensure_column('agent_kb', 'updated_at', "DATETIME DEFAULT NULL AFTER updated_by");
    ensure_column('agent_kb', 'tone_admin', "TEXT DEFAULT NULL AFTER intent_type");
    ensure_column('agent_kb', 'tone_staff', "TEXT DEFAULT NULL AFTER tone_admin");
    ensure_column('agent_kb_history', 'keywords', "TEXT DEFAULT NULL AFTER answer");
    ensure_column('agent_kb_history', 'company_scope', "VARCHAR(120) DEFAULT 'general' AFTER category");
    ensure_column('agent_kb_history', 'access_permissions', "TEXT DEFAULT NULL AFTER access_roles");
    ensure_column('agent_kb_history', 'intent_type', "VARCHAR(40) DEFAULT 'how_to' AFTER access_permissions");
    ensure_column('agent_logs', 'intent_type', "VARCHAR(40) DEFAULT NULL AFTER kb_id");
    ensure_column('agent_logs', 'lang_detected', "VARCHAR(5) DEFAULT 'fr' AFTER intent_type");
    ensure_column('agent_logs', 'company_scope', "VARCHAR(120) DEFAULT 'general' AFTER lang_detected");
    ensure_column('agent_logs', 'feedback', "TINYINT DEFAULT NULL AFTER company_scope");
    ensure_column('agent_logs', 'response_ms', "INT DEFAULT NULL AFTER feedback");

    qx("UPDATE agent_kb SET intent_type='how_to' WHERE intent_type IS NULL OR intent_type=''");
    qx("UPDATE agent_kb SET version=1 WHERE version IS NULL OR version=0");
    qx("UPDATE agent_logs SET lang_detected='fr' WHERE lang_detected IS NULL OR lang_detected=''");
    qx("UPDATE agent_logs SET company_scope='general' WHERE company_scope IS NULL OR company_scope=''");

    qx("UPDATE agent_kb SET access_permissions='sales.create' WHERE access_permissions IS NULL AND question LIKE 'Comment faire une vente%'");
    qx("UPDATE agent_kb SET access_permissions='stock.request' WHERE access_permissions IS NULL AND category='stock' AND intent_type='action'");
    qx("UPDATE agent_kb SET access_permissions='invoice.create' WHERE access_permissions IS NULL AND question LIKE 'Comment créer une facture%'");
    qx("UPDATE agent_kb SET access_permissions='hr.employee.manage' WHERE access_permissions IS NULL AND category='rh' AND question LIKE 'Comment ajouter un employé%'");
    qx("UPDATE agent_kb SET access_permissions='hr.payroll.run' WHERE access_permissions IS NULL AND question LIKE 'Comment payer les salaires%'");
    qx("UPDATE agent_kb SET access_permissions='stock.view' WHERE access_permissions IS NULL AND question LIKE 'Comment vérifier le stock%'");
    qx("UPDATE agent_kb SET access_permissions='client.create' WHERE access_permissions IS NULL AND question LIKE 'Comment créer un client%'");
    qx("UPDATE agent_kb SET access_permissions='user.manage' WHERE access_permissions IS NULL AND category='admin' AND question LIKE 'Comment créer un utilisateur%'");
    qx("UPDATE agent_kb SET access_permissions='system.backup' WHERE access_permissions IS NULL AND question LIKE 'Comment faire un backup%'");
    qx("UPDATE agent_kb SET access_permissions='notify.send' WHERE access_permissions IS NULL AND question LIKE 'Comment envoyer une notification WhatsApp%'");
    qx("UPDATE agent_kb SET access_permissions='auth.password.change' WHERE access_permissions IS NULL AND question LIKE 'Comment changer un mot de passe%'");
    qx("UPDATE agent_kb SET access_permissions='system.terminal.view' WHERE access_permissions IS NULL AND question LIKE 'Comment accéder au terminal web%'");
}

function seed_permissions(): void {
    $permissionLabels = [
        'chat.ask' => 'Poser des questions',
        'chat.learn' => 'Enseigner à l’agent',
        'context.clear' => 'Effacer le contexte',
        'feedback.send' => 'Envoyer un feedback',
        'kb.view' => 'Voir la base KB',
        'kb.manage' => 'Créer / éditer KB',
        'kb.delete' => 'Supprimer KB',
        'kb.import' => 'Importer KB',
        'kb.export' => 'Exporter KB',
        'sales.create' => 'Créer des ventes',
        'invoice.create' => 'Créer des factures',
        'stock.view' => 'Voir le stock',
        'stock.request' => 'Créer des demandes de stock',
        'client.create' => 'Créer des clients',
        'hr.employee.manage' => 'Gérer les employés',
        'hr.payroll.run' => 'Lancer la paie',
        'user.manage' => 'Gérer les utilisateurs',
        'audit.view' => 'Voir l’audit',
        'diag.view' => 'Voir le diagnostic',
        'analytics.view' => 'Voir les analytics',
        'notify.send' => 'Envoyer des notifications',
        'system.backup' => 'Faire des sauvegardes',
        'system.terminal.view' => 'Accéder au terminal',
        'auth.password.change' => 'Changer le mot de passe',
    ];

    foreach ($permissionLabels as $permission => $label) {
        qx("INSERT IGNORE INTO agent_permissions(permission_key,label) VALUES(?,?)", [$permission, $label]);
    }

    $roleMap = [
        'viewer' => ['chat.ask', 'kb.view', 'feedback.send', 'auth.password.change'],
        'staff' => ['chat.ask', 'chat.learn', 'context.clear', 'feedback.send', 'kb.view', 'stock.view', 'stock.request', 'client.create', 'notify.send', 'auth.password.change'],
        'caissiere' => ['chat.ask', 'chat.learn', 'context.clear', 'feedback.send', 'kb.view', 'sales.create', 'invoice.create', 'client.create', 'stock.view', 'notify.send', 'auth.password.change'],
        'manager' => ['chat.ask', 'chat.learn', 'context.clear', 'feedback.send', 'kb.view', 'sales.create', 'invoice.create', 'client.create', 'stock.view', 'stock.request', 'analytics.view', 'auth.password.change'],
        'Directrice' => ['chat.ask', 'chat.learn', 'context.clear', 'feedback.send', 'kb.view', 'sales.create', 'invoice.create', 'client.create', 'stock.view', 'stock.request', 'hr.employee.manage', 'hr.payroll.run', 'analytics.view', 'notify.send', 'auth.password.change'],
        'PDG' => ['chat.ask', 'chat.learn', 'context.clear', 'feedback.send', 'kb.view', 'kb.export', 'sales.create', 'invoice.create', 'client.create', 'stock.view', 'stock.request', 'hr.employee.manage', 'hr.payroll.run', 'analytics.view', 'audit.view', 'diag.view', 'notify.send', 'system.backup', 'auth.password.change'],
        'admin' => array_keys($permissionLabels),
        'developer' => array_keys($permissionLabels),
    ];

    foreach ($roleMap as $role => $permissions) {
        foreach ($permissions as $permission) {
            qx("INSERT IGNORE INTO agent_role_permissions(role_name,permission_key) VALUES(?,?)", [$role, $permission]);
        }
    }
}

function detect_intent(string $q): string {
    $q = mb_strtolower($q, 'UTF-8');
    $patterns = [
        'diagnostic' => ['erreur', 'problème', 'bug', 'marche pas', 'fonctionne pas', 'crash', 'lent', 'diagnostic', 'réparer', 'résoudre', 'pourquoi', 'cause'],
        'action' => ['créer', 'ajouter', 'faire', 'lancer', 'exécuter', 'envoyer', 'supprimer', 'modifier', 'mettre à jour', 'enregistrer', 'émettre', 'payer', 'commander', 'valider'],
        'info' => ['qu\'est', 'kesako', 'définition', 'signifie', 'c\'est quoi', 'qu\'est-ce', 'liste', 'afficher', 'voir', 'consulter', 'vérifier', 'montrer'],
        'how_to' => ['comment', 'étape', 'procédure', 'guide', 'tutoriel', 'expliquer', 'aide', 'how to', 'étapes'],
        'doc' => ['documentation', 'doc', 'manuel', 'pdf', 'fichier', 'export', 'rapport', 'télécharger'],
    ];
    $scores = [];
    foreach ($patterns as $intent => $kws) {
        $score = 0;
        foreach ($kws as $kw) {
            if (mb_strpos($q, $kw) !== false) {
                $score++;
            }
        }
        $scores[$intent] = $score;
    }
    arsort($scores);
    $best = array_key_first($scores);
    return $scores[$best] > 0 ? $best : 'how_to';
}

function detect_lang(string $q): string {
    $frWords = ['comment', 'faire', 'créer', 'vente', 'stock', 'salaire', 'facture', 'employé', 'client', 'approvisionner', 'est', 'une', 'les', 'des', 'pour', 'dans', 'avec', 'que', 'qui', 'son', 'tout', 'mais', 'pas', 'je', 'tu', 'vous', 'nous'];
    $enWords = ['how', 'create', 'make', 'invoice', 'stock', 'salary', 'employee', 'client', 'supply', 'what', 'the', 'for', 'with', 'that', 'your', 'our', 'all', 'but', 'not', 'you'];
    $ql = mb_strtolower($q, 'UTF-8');
    $frScore = 0;
    $enScore = 0;
    foreach ($frWords as $w) {
        if (mb_strpos($ql, $w) !== false) {
            $frScore++;
        }
    }
    foreach ($enWords as $w) {
        if (mb_strpos($ql, $w) !== false) {
            $enScore++;
        }
    }
    return $enScore > $frScore ? 'en' : 'fr';
}

function detect_company_scope(string $text): string {
    $text = normalize($text);
    $companies = [
        'saint_james' => ['saint james', 'saint-james'],
        'esperance_h2o' => ['esperance h2o', 'esperanceh20', 'h2o'],
        'coredesk_africa' => ['coredesk', 'coredesk africa'],
    ];
    foreach ($companies as $scope => $aliases) {
        foreach ($aliases as $alias) {
            if (mb_strpos($text, normalize($alias)) !== false) {
                return $scope;
            }
        }
    }
    return 'general';
}

function get_tone_prefix(string $role, string $intent): string {
    $tones = [
        'admin' => ['how_to' => '⚙️ **Procédure technique** :', 'action' => '🔧 **Action admin** :', 'diagnostic' => '🔍 **Diagnostic** :', 'info' => '📊 **Information système** :', 'doc' => '📄 **Documentation** :'],
        'PDG' => ['how_to' => '📋 **Procédure** :', 'action' => '✅ **Action requise** :', 'diagnostic' => '🔍 **Analyse** :', 'info' => '📈 **Vue d\'ensemble** :', 'doc' => '📄 **Document** :'],
        'developer' => ['how_to' => '🧑‍💻 **Guide dev** :', 'action' => '⚡ **Exécution** :', 'diagnostic' => '🐛 **Debug** :', 'info' => 'ℹ️ **Info technique** :', 'doc' => '📁 **Docs** :'],
        'caissiere' => ['how_to' => '👋 **Voici comment faire** :', 'action' => '💡 **Étapes simples** :', 'diagnostic' => '⚠️ **Que faire** :', 'info' => 'ℹ️ **Information** :', 'doc' => '📄 **Document** :'],
        'staff' => ['how_to' => '👋 **Voici comment faire** :', 'action' => '💡 **Étapes** :', 'diagnostic' => '⚠️ **Problème détecté** :', 'info' => 'ℹ️ **Information** :', 'doc' => '📄 **Document** :'],
    ];
    $roleTone = $tones[$role] ?? $tones['staff'];
    return $roleTone[$intent] ?? '📌';
}

function get_query_category_hints(string $query): array {
    $map = [
        'stock' => ['stock', 'inventaire', 'appro', 'approvisionnement', 'rupture', 'magasin'],
        'finance' => ['vente', 'facture', 'caisse', 'paiement', 'reglement', 'ticket'],
        'rh' => ['employe', 'rh', 'salaire', 'paie', 'recruter'],
        'admin' => ['admin', 'utilisateur', 'permission', 'backup', 'sauvegarde', 'terminal'],
        'clients' => ['client', 'crm', 'prospect'],
    ];
    $q = normalize($query);
    $hits = [];
    foreach ($map as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($q, $keyword) !== false) {
                $hits[] = $category;
                break;
            }
        }
    }
    return $hits;
}

function permission_list_for_role(string $role): array {
    return array_column(q("SELECT permission_key FROM agent_role_permissions WHERE role_name=? ORDER BY permission_key", [$role]), 'permission_key');
}

function refresh_session_permissions(string $role): void {
    $_SESSION['permissions'] = permission_list_for_role($role);
}

function can(string $permission): bool {
    global $agentErpInternalMode;
    if ($agentErpInternalMode && !empty($_SESSION['user_id'])) {
        return true;
    }
    $permissions = $_SESSION['permissions'] ?? [];
    return in_array($permission, $permissions, true) || in_array($_SESSION['role'] ?? '', ['admin', 'developer'], true);
}

function can_access_entry(?string $accessPermissions, ?string $accessRoles, string $role): bool {
    global $agentErpInternalMode;
    if ($agentErpInternalMode && !empty($_SESSION['user_id'])) {
        return true;
    }
    if ($accessPermissions) {
        $required = array_filter(array_map('trim', explode(',', $accessPermissions)));
        if (!$required) {
            return true;
        }
        foreach ($required as $permission) {
            if (can($permission)) {
                return true;
            }
        }
        return false;
    }
    if (!$accessRoles) {
        return true;
    }
    return in_array($role, array_map('trim', explode(',', $accessRoles)), true);
}

function list_indexable_site_files(): array {
    $root = dirname(__DIR__, 2);
    $dirs = ['admin', 'api_support', 'auth', 'clients', 'dashboard', 'documents', 'finance', 'hr', 'legal', 'messaging', 'orders', 'stock', 'system'];
    $files = [];
    foreach ($dirs as $dir) {
        $abs = $root . '/' . $dir;
        if (!is_dir($abs)) {
            continue;
        }
        foreach (new DirectoryIterator($abs) as $item) {
            if ($item->isDot() || !$item->isFile()) {
                continue;
            }
            $ext = strtolower($item->getExtension());
            if (!in_array($ext, ['php', 'html', 'js'], true)) {
                continue;
            }
            $files[] = $dir . '/' . $item->getFilename();
        }
    }
    sort($files);
    return $files;
}

function extract_file_summary(string $relativePath): array {
    $root = dirname(__DIR__, 2);
    $fullPath = $root . '/' . $relativePath;
    $content = @file_get_contents($fullPath);
    if ($content === false) {
        return [];
    }
    $snippet = implode("\n", array_slice(preg_split("/\r\n|\n|\r/", $content), 0, 160));
    $title = '';
    if (preg_match('/<title>([^<]+)<\/title>/i', $snippet, $m)) {
        $title = trim($m[1]);
    } elseif (preg_match('/^\s*\/\*+\s*\n?\s*([^*\n][^\n]+)/m', $snippet, $m)) {
        $title = trim($m[1]);
    } elseif (preg_match('/^\s*\/\/\s*([^\n]{6,120})/m', $snippet, $m)) {
        $title = trim($m[1]);
    } elseif (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $snippet, $m)) {
        $title = trim($m[1]);
    }
    if ($title === '') {
        $title = ucwords(str_replace(['_', '-', '.php', '.html', '.js', '/'], [' ', ' ', '', '', '', ' / '], $relativePath));
    }
    $module = strtok($relativePath, '/');
    $plain = strip_tags($snippet);
    $plain = preg_replace('/\s+/', ' ', $plain);
    $plain = trim((string) $plain);
    $summary = mb_substr($plain, 0, 420, 'UTF-8');
    $keywords = implode(',', array_slice(extract_search_keywords($title . ' ' . $relativePath . ' ' . $summary), 0, 60));
    $route = '/' . ltrim($relativePath, './');
    $type = str_ends_with($relativePath, '.js') ? 'script' : 'page';
    return [
        'module_name' => $module,
        'page_title' => $title,
        'route_url' => $route,
        'summary_text' => $summary,
        'keywords' => $keywords,
        'source_type' => $type,
    ];
}

function rebuild_site_index(bool $force = false): int {
    if (!table_exists('agent_site_index')) {
        return 0;
    }
    $count = (int) qv("SELECT COUNT(*) FROM agent_site_index");
    $lastIndexed = qv("SELECT MAX(last_indexed_at) FROM agent_site_index");
    $stale = !$lastIndexed || strtotime((string) $lastIndexed) < time() - 21600;
    if (!$force && $count > 0 && !$stale) {
        return $count;
    }
    $files = list_indexable_site_files();
    foreach ($files as $relativePath) {
        $meta = extract_file_summary($relativePath);
        if (!$meta) {
            continue;
        }
        qx(
            "INSERT INTO agent_site_index(file_path,module_name,page_title,route_url,summary_text,keywords,source_type,last_indexed_at)
             VALUES(?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE module_name=VALUES(module_name),page_title=VALUES(page_title),route_url=VALUES(route_url),summary_text=VALUES(summary_text),keywords=VALUES(keywords),source_type=VALUES(source_type),last_indexed_at=NOW()",
            [$relativePath, $meta['module_name'], $meta['page_title'], $meta['route_url'], $meta['summary_text'], $meta['keywords'], $meta['source_type']]
        );
    }
    return (int) qv("SELECT COUNT(*) FROM agent_site_index");
}

function role_is_admin(): bool {
    $role = $_SESSION['role'] ?? 'viewer';
    return in_array($role, ['admin', 'developer', 'PDG'], true);
}

function csrf_ok(): bool {
    $raw = file_get_contents('php://input');
    $decoded = null;
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
    }
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? (($decoded['csrf_token'] ?? ''));
    return hash_equals($_SESSION['csrf_token'] ?? '', (string) $token);
}

function require_ajax_csrf(): void {
    if (!csrf_ok()) {
        echo json_encode(['ok' => false, 'msg' => '⛔ Token invalide.']);
        exit;
    }
}

function rate_limit(string $action, int $max = 20, int $window = 60): bool {
    global $pdo;
    if (!$pdo) {
        return true;
    }
    $uid = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    qx("DELETE FROM agent_rate_limit WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)", [$window]);
    $cnt = (int) qv("SELECT COUNT(*) FROM agent_rate_limit WHERE (user_id=? OR ip_address=?) AND action=?", [$uid, $ip, $action]);
    if ($cnt >= $max) {
        return false;
    }
    qx("INSERT INTO agent_rate_limit(user_id,ip_address,action) VALUES(?,?,?)", [$uid, $ip, $action]);
    return true;
}

function audit(string $action, string $details = '', bool $sensitive = false): void {
    $uid = $_SESSION['user_id'] ?? null;
    $urole = $_SESSION['role'] ?? null;
    qx("INSERT INTO agent_audit(user_id,user_role,action,details,ip_address,requires_confirm) VALUES(?,?,?,?,?,?)", [$uid, $urole, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '', $sensitive ? 1 : 0]);
}

function push_context(string $q, string $a, string $category): void {
    $ctx = $_SESSION['chat_context'] ?? [];
    $ctx[] = ['q' => $q, 'a' => substr($a, 0, 120), 'cat' => $category, 'ts' => time()];
    if (count($ctx) > 6) {
        array_shift($ctx);
    }
    $_SESSION['chat_context'] = $ctx;
}

function kb_save_version(int $kbId, int $changedBy, string $note = ''): void {
    $row = q1("SELECT * FROM agent_kb WHERE id=?", [$kbId]);
    if (!$row) {
        return;
    }
    qx(
        "INSERT INTO agent_kb_history(kb_id,question,answer,keywords,action_url,action_label,category,company_scope,access_roles,access_permissions,intent_type,version,changed_by,change_note)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$kbId, $row['question'], $row['answer'], $row['keywords'], $row['action_url'], $row['action_label'], $row['category'], $row['company_scope'] ?? 'general', $row['access_roles'], $row['access_permissions'], $row['intent_type'], $row['version'], $changedBy, $note]
    );
}

function issue_bootstrap_token_if_needed(): void {
    global $agentErpPageUrl, $agentErpSetupFile;
    if (!table_exists('agent_users') || (int) qv("SELECT COUNT(*) FROM agent_users") > 0) {
        return;
    }
    $active = q1("SELECT * FROM agent_bootstrap_tokens WHERE used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    if ($active) {
        return;
    }
    $token = bin2hex(random_bytes(24));
    $preview = substr($token, 0, 8) . '...' . substr($token, -6);
    qx("INSERT INTO agent_bootstrap_tokens(token_hash,token_preview,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))", [password_hash($token, PASSWORD_DEFAULT), $preview]);
    $content = "Agent ERP bootstrap token\n";
    $content .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $content .= "URL: " . $agentErpPageUrl . "?setup_token=" . $token . "\n";
    $content .= "Token: " . $token . "\n";
    $content .= "This token expires in 24 hours and is single-use.\n";
    @file_put_contents($agentErpSetupFile, $content);
}

function validate_bootstrap_token(string $token): array {
    $rows = q("SELECT * FROM agent_bootstrap_tokens WHERE used_at IS NULL AND expires_at > NOW() ORDER BY id DESC");
    foreach ($rows as $row) {
        if (password_verify($token, $row['token_hash'])) {
            return $row;
        }
    }
    return [];
}

function agent_search(string $query, array $context = []): array {
    $queryNorm = normalize($query);
    $intent = detect_intent($query);
    $company = detect_company_scope($query);
    $queryCategories = get_query_category_hints($query);
    $keywords = extract_search_keywords($queryNorm);
    if ($context) {
        $lastQ = $context[count($context) - 1]['q'] ?? '';
        foreach (array_slice(extract_search_keywords($lastQ), 0, 4) as $ctxKey) {
            $keywords[] = $ctxKey;
        }
    }
    $keywords = array_values(array_unique($keywords));
    if (!$keywords) {
        return [];
    }

    $synonyms = [
        'vente' => ['facture', 'ticket', 'caisse', 'encaisser'],
        'facture' => ['vente', 'invoice', 'emettre'],
        'stock' => ['inventaire', 'quantite', 'magasin', 'rupture'],
        'approvisionnement' => ['appro', 'commander', 'reappro', 'fournisseur'],
        'client' => ['crm', 'prospect', 'acheteur'],
        'salaire' => ['paie', 'payroll', 'remuneration'],
        'utilisateur' => ['compte', 'acces', 'role', 'permission'],
        'backup' => ['sauvegarde', 'export', 'dump'],
        'terminal' => ['ssh', 'console', 'shell'],
    ];

    $expandedKeywords = $keywords;
    foreach ($keywords as $keyword) {
        foreach ($synonyms[$keyword] ?? [] as $synonym) {
            $expandedKeywords[] = normalize($synonym);
        }
    }
    $expandedKeywords = array_values(array_unique($expandedKeywords));

    $all = q("SELECT * FROM agent_kb ORDER BY hits DESC, id DESC LIMIT 500");
    $scored = [];
    foreach ($all as $row) {
        $question = normalize($row['question'] ?? '');
        $keywordsField = normalize($row['keywords'] ?? '');
        $answer = normalize($row['answer'] ?? '');
        $haystack = trim($question . ' ' . $keywordsField . ' ' . $answer);
        $score = 0;

        foreach ($expandedKeywords as $keyword) {
            if ($keyword === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $question)) {
                $score += 9;
            } elseif (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $keywordsField)) {
                $score += 7;
            } elseif (mb_strpos($haystack, $keyword) !== false) {
                $score += 4;
            } else {
                foreach (preg_split('/\s+/', $question . ' ' . $keywordsField) as $candidate) {
                    if ($candidate === '' || abs(strlen($candidate) - strlen($keyword)) > 3) {
                        continue;
                    }
                    similar_text($keyword, $candidate, $pct);
                    if ($pct >= 82) {
                        $score += 2;
                        break;
                    }
                    if (levenshtein($keyword, $candidate) <= 2) {
                        $score += 1;
                        break;
                    }
                }
            }
        }

        if (mb_strpos($question, $queryNorm) !== false) {
            $score += 12;
        }
        if ($intent === ($row['intent_type'] ?? 'how_to')) {
            $score += 5;
        }
        if ($company !== 'general' && ($row['company_scope'] ?? 'general') === $company) {
            $score += 6;
        }
        if ($queryCategories && in_array($row['category'] ?? 'general', $queryCategories, true)) {
            $score += 4;
        }
        if ($context) {
            $lastCat = $context[count($context) - 1]['cat'] ?? null;
            if ($lastCat && $lastCat === ($row['category'] ?? 'general')) {
                $score += 3;
            }
        }
        $score += min(8, (int) floor(((int) ($row['hits'] ?? 0)) / 2));

        if ($score > 0) {
            $row['_score'] = $score;
            $scored[] = $row;
        }
    }

    usort($scored, static function (array $a, array $b): int {
        return $b['_score'] <=> $a['_score'] ?: (($b['hits'] ?? 0) <=> ($a['hits'] ?? 0));
    });

    $scored = array_slice($scored, 0, 5);

    $siteRows = q("SELECT * FROM agent_site_index ORDER BY module_name, page_title LIMIT 400");
    foreach ($siteRows as $row) {
        $haystack = normalize(($row['page_title'] ?? '') . ' ' . ($row['file_path'] ?? '') . ' ' . ($row['summary_text'] ?? '') . ' ' . ($row['keywords'] ?? ''));
        $score = 0;
        foreach ($expandedKeywords as $keyword) {
            if ($keyword !== '' && mb_strpos($haystack, $keyword) !== false) {
                $score += 4;
                if (mb_strpos(normalize((string) ($row['page_title'] ?? '')), $keyword) !== false) {
                    $score += 5;
                }
            }
        }
        if ($queryCategories && in_array($row['module_name'] ?? '', $queryCategories, true)) {
            $score += 3;
        }
        if ($score <= 0) {
            continue;
        }
        $scored[] = [
            'id' => 'site:' . $row['id'],
            'question' => $row['page_title'] ?: $row['file_path'],
            'answer' => "Module interne ERP : **" . ($row['page_title'] ?: $row['file_path']) . "**\n\n" .
                ($row['summary_text'] ?: 'Page interne indexée automatiquement.') .
                "\n\n• Module : **" . ($row['module_name'] ?: 'general') . "**\n" .
                "• Fichier : `" . ($row['file_path'] ?: '') . "`\n" .
                "• Route : `" . ($row['route_url'] ?: '') . "`",
            'action_url' => $row['route_url'] ?? null,
            'action_label' => 'Ouvrir la page',
            'category' => $row['module_name'] ?: 'general',
            'company_scope' => 'general',
            'access_permissions' => null,
            'access_roles' => null,
            'intent_type' => 'info',
            'hits' => 0,
            '_score' => $score,
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        return $b['_score'] <=> $a['_score'] ?: (($b['hits'] ?? 0) <=> ($a['hits'] ?? 0));
    });

    return array_slice($scored, 0, 7);
}

function seed_default_kb(): void {
    if ((int) qv("SELECT COUNT(*) FROM agent_kb") >= 13) {
        return;
    }

    $seeds = [
        ['vente,vendre,faire vente,nouvelle vente,ticket,caisse,enregistrer vente,bon vente,ventes', 'Comment faire une vente ?', "Enregistrer une vente :\n1. **Factures** → **Nouvelle facture**\n2. Sélectionnez le **client**\n3. Ajoutez les **produits** et quantités\n4. Choisissez le **mode de paiement**\n5. Cliquez **Émettre la facture**\n\n✅ Le stock est automatiquement décrémenté.\n\n| Étape | Action | Résultat |\n|-------|--------|----------|\n| 1 | Choisir client | Client associé |\n| 2 | Ajouter produits | Panier constitué |\n| 3 | Émettre | Facture + stock mis à jour |", 'https://app.esperanceh20.com/factures', 'Créer une vente', 'finance', null, 'sales.create', 'how_to', 'general'],
        ['approvisionnement,appro,commander,stock,réapprovisionner,fournisseur,demande', 'Comment faire un approvisionnement ?', "Demande d'approvisionnement :\n1. **Approvisionnement** → **Nouvelle demande**\n2. Sélectionnez le produit et la quantité\n3. Validez avec votre superviseur\n\n⚠️ Vérifiez le stock actuel avant de commander.", 'https://app.esperanceh20.com/appro_requests.php', "Nouvelle demande d'appro", 'stock', null, 'stock.request', 'action', 'general'],
        ['facture,facturer,client,créer facture,nouvelle facture,émettre', 'Comment créer une facture ?', "Créer une facture :\n1. **Factures** → **Nouvelle facture**\n2. Sélectionnez client + ville\n3. Ajoutez les produits\n4. Cliquez **Émettre**", 'https://app.esperanceh20.com/factures', 'Créer une facture', 'finance', null, 'invoice.create', 'action', 'general'],
        ['employé,salarié,embauche,créer employé,ajouter employé,recruter,embaucher', 'Comment ajouter un employé ?', "Ajouter un employé :\n1. **RH** → **Employés** → **Ajouter**\n2. Renseignez les infos personnelles\n3. Assignez le poste et le salaire\n4. Créez le compte utilisateur", 'https://app.esperanceh20.com/employees_manager.php', 'Gérer les employés', 'rh', null, 'hr.employee.manage', 'how_to', 'general'],
        ['paie,salaire,payer salaire,fiche paie,virement,rémunération', 'Comment payer les salaires ?', "Paiement des salaires :\n1. **RH** → **Paie** → Sélectionnez le mois\n2. Vérifiez les fiches\n3. Validez et cliquez **Payer**\n\n⚠️ **Action irréversible.** Vérifiez deux fois avant de valider.", 'https://app.esperanceh20.com/payroll', 'Module Paie', 'rh', null, 'hr.payroll.run', 'action', 'general'],
        ['stock,inventaire,niveau stock,vérifier stock,rupture,quantité', 'Comment vérifier le stock ?', "Consulter le stock :\n1. **Stock** → **Inventaire**\n2. Filtrez par société + ville\n3. 🟢 OK · 🟠 Bas · 🔴 RUPTURE\n\n| Indicateur | Signification |\n|-----------|---------------|\n| 🟢 Vert | Stock suffisant |\n| 🟠 Orange | Stock bas, approvisionner |\n| 🔴 Rouge | Rupture totale |", 'https://cdn.esperanceh20.com?module=stock', 'Vue stock', 'stock', null, 'stock.view', 'info', 'general'],
        ['client,ajouter client,créer client,nouveau client', 'Comment créer un client ?', "Créer un client :\n1. **Clients** → **Nouveau client**\n2. Renseignez nom, téléphone, ville\n3. Sauvegardez", 'https://app.esperanceh20.com/clients_erp_pro.php', 'Gestion clients', 'clients', null, 'client.create', 'action', 'general'],
        ['utilisateur,compte,créer compte,droits,accès,permission,ajouter utilisateur', 'Comment créer un utilisateur ?', "Créer un compte :\n1. **Admin** → **Utilisateurs** → **Ajouter**\n2. Définissez username, mot de passe, rôle\n3. Assignez à une société", 'https://admin.esperanceh20.com?module=users', 'Gérer les utilisateurs', 'admin', null, 'user.manage', 'how_to', 'general'],
        ['backup,sauvegarde,sql,exporter,mysqldump', 'Comment faire un backup ?', "Sauvegarde DB :\n1. **Admin** → **Database** → **Backup SQL**\n\nOu en CLI :\n`mysqldump ESPERANCEH20 > backup_$(date +%Y%m%d).sql`\n\n⚠️ Planifiez des backups quotidiens automatiques.", 'https://admin.esperanceh20.com?module=database', 'Module Database', 'admin', null, 'system.backup', 'diagnostic', 'general'],
        ['whatsapp,message,notification,notifier,sms', 'Comment envoyer une notification WhatsApp ?', "Notifications WhatsApp :\n• Bouton **Notifier** sur les factures, appros, paies\n• Les envois automatiques se déclenchent sur changements de statut\n• Logs dans **CDN Dashboard → Activité**", 'https://cdn.esperanceh20.com?module=activite', 'Logs WhatsApp', 'general', null, 'notify.send', 'action', 'general'],
        ['mot de passe,password,changer password,réinitialiser,modifier mdp', 'Comment changer un mot de passe ?', "Changer le mot de passe :\n**Vous-même :** Profil → Modifier → Mot de passe\n**Autre utilisateur (admin) :** Admin → Utilisateurs → ✏️ → Nouveau mot de passe", 'https://admin.esperanceh20.com?module=users', 'Gestion utilisateurs', 'admin', null, 'auth.password.change', 'how_to', 'general'],
        ['saint james,approvisionnement saint james,commander saint', 'Comment approvisionner un produit Saint James ?', "Appro Saint James :\n1. **Approvisionnement** → **Nouvelle demande**\n2. Société : **SAINT JAMES**\n3. Choisissez le magasin et les produits\n4. Soumettez pour validation", 'https://app.esperanceh20.com/appro_requests.php', "Nouvelle demande d'appro", 'stock', null, 'stock.request', 'action', 'saint_james'],
        ['terminal,ssh,commande,shell,linux,console,web terminal', 'Comment accéder au terminal web ?', "Terminal web :\n• URL : `https://login.esperanceh20.com/terminal.php`\n• Token : `cyberlab2024`\n\n⚠️ Réservé aux admins et informaticiens. Toutes les commandes sont auditées.", 'https://login.esperanceh20.com/terminal.php', 'Ouvrir le terminal', 'admin', null, 'system.terminal.view', 'diagnostic', 'general'],
    ];

    $existing = array_column(q("SELECT LOWER(question) q FROM agent_kb"), 'q');
    foreach ($seeds as $seed) {
        if (!in_array(mb_strtolower($seed[1], 'UTF-8'), $existing, true)) {
            qx(
                "INSERT INTO agent_kb(keywords,question,answer,action_url,action_label,category,access_roles,access_permissions,intent_type,company_scope)
                 VALUES(?,?,?,?,?,?,?,?,?,?)",
                [$seed[0], $seed[1], $seed[2], $seed[3], $seed[4], $seed[5], $seed[6], $seed[7], $seed[8], $seed[9]]
            );
        }
    }
}

ensure_agent_schema();
seed_permissions();
seed_default_kb();
issue_bootstrap_token_if_needed();
rebuild_site_index();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$login_error = '';
$setup_error = '';
$setup_success = '';
$bootstrap_token_hint = q1("SELECT token_preview, expires_at FROM agent_bootstrap_tokens WHERE used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
$setup_mode = isset($_GET['setup_token']) && (int) qv("SELECT COUNT(*) FROM agent_users") === 0;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $agentErpPageUrl);
    exit;
}

if (isset($_POST['do_setup']) && (int) qv("SELECT COUNT(*) FROM agent_users") === 0) {
    $token = trim($_POST['setup_token'] ?? '');
    $username = trim($_POST['setup_user'] ?? '');
    $password = (string) ($_POST['setup_pass'] ?? '');
    $fullName = trim($_POST['setup_name'] ?? 'Administrateur');
    $tokenRow = $token ? validate_bootstrap_token($token) : [];
    if (!$tokenRow) {
        $setup_error = 'Token d’initialisation invalide ou expiré.';
    } elseif ($username === '' || $password === '') {
        $setup_error = 'Identifiant et mot de passe requis.';
    } elseif (strlen($password) < 12) {
        $setup_error = 'Mot de passe trop faible (12 caractères min.).';
    } else {
        qx(
            "INSERT INTO agent_users(username,password_hash,role,full_name,avatar_color,is_active,must_change_password)
             VALUES(?,?,?,?,?,?,1)",
            [$username, password_hash($password, PASSWORD_DEFAULT), 'admin', $fullName, '#a855f7', 1]
        );
        qx("UPDATE agent_bootstrap_tokens SET used_at=NOW() WHERE id=?", [$tokenRow['id']]);
        $setup_success = 'Compte administrateur initialisé. Connectez-vous puis changez immédiatement le mot de passe.';
        audit('bootstrap_admin', "Admin bootstrap completed for {$username}", true);
    }
}

if (isset($_POST['do_login'])) {
    if (!$pdo) {
        $login_error = '⚠️ Base de données non disponible.';
    } else {
        $lu = trim($_POST['login_user'] ?? '');
        $lp = (string) ($_POST['login_pass'] ?? '');
        if ($lu === '' || $lp === '') {
            $login_error = '❌ Identifiant et mot de passe requis.';
        } else {
            $u = q1("SELECT * FROM agent_users WHERE (username=? OR LOWER(username)=LOWER(?)) AND is_active=1", [$lu, $lu]);
            if ($u) {
                if (!empty($u['locked_until']) && strtotime((string) $u['locked_until']) > time()) {
                    $login_error = "⛔ Compte bloqué jusqu'à " . date('H:i', strtotime((string) $u['locked_until'])) . '.';
                } elseif (password_verify($lp, (string) $u['password_hash'])) {
                    qx("UPDATE agent_users SET login_attempts=0,locked_until=NULL,last_login=NOW() WHERE id=?", [$u['id']]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $u['id'];
                    $_SESSION['username'] = $u['username'];
                    $_SESSION['full_name'] = $u['full_name'] ?: $u['username'];
                    $_SESSION['role'] = $u['role'];
                    $_SESSION['av_color'] = $u['avatar_color'] ?: '#a855f7';
                    $_SESSION['lang'] = $u['preferred_lang'] ?: 'fr';
                    $_SESSION['must_change_password'] = (int) ($u['must_change_password'] ?? 0);
                    $_SESSION['chat_context'] = [];
                    refresh_session_permissions((string) $u['role']);
                    header('Location: ' . $agentErpPageUrl);
                    exit;
                } else {
                    $att = (int) $u['login_attempts'] + 1;
                    $lock = $att >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                    qx("UPDATE agent_users SET login_attempts=?,locked_until=? WHERE id=?", [$att, $lock, $u['id']]);
                    $login_error = $att >= 5 ? '⛔ Compte bloqué 15 min après 5 tentatives.' : "❌ Mot de passe incorrect. ($att/5)";
                }
            } else {
                $login_error = '❌ Utilisateur introuvable ou inactif.';
            }
        }
    }
}

$isAuthenticated = !empty($_SESSION['user_id']);

if ($isAuthenticated && !isset($_SESSION['permissions'])) {
    refresh_session_permissions((string) ($_SESSION['role'] ?? 'viewer'));
}

$uid = $_SESSION['user_id'] ?? null;
$uname = $_SESSION['username'] ?? '';
$ufname = $_SESSION['full_name'] ?? $uname;
$urole = $_SESSION['role'] ?? 'viewer';
$ucolor = $_SESSION['av_color'] ?? '#a855f7';
$ulang = $_SESSION['lang'] ?? 'fr';
$mustChangePassword = (int) ($_SESSION['must_change_password'] ?? 0);
if (!isset($_SESSION['chat_context'])) {
    $_SESSION['chat_context'] = [];
}

$cp_ok = false;
$cp_msg = '';
if ($isAuthenticated && isset($_POST['do_change_pass']) && $pdo) {
    $u2 = q1("SELECT * FROM agent_users WHERE id=?", [$uid]);
    $op = (string) ($_POST['old_pass'] ?? '');
    $np = (string) ($_POST['new_pass'] ?? '');
    $np2 = (string) ($_POST['new_pass2'] ?? '');
    if (!password_verify($op, (string) ($u2['password_hash'] ?? ''))) {
        $cp_msg = '❌ Ancien mot de passe incorrect.';
    } elseif (strlen($np) < 12) {
        $cp_msg = '❌ Trop court (min 12 caractères).';
    } elseif ($np !== $np2) {
        $cp_msg = '❌ Les mots de passe ne correspondent pas.';
    } else {
        qx("UPDATE agent_users SET password_hash=?,must_change_password=0 WHERE id=?", [password_hash($np, PASSWORD_DEFAULT), $uid]);
        $_SESSION['must_change_password'] = 0;
        $mustChangePassword = 0;
        $cp_ok = true;
        $cp_msg = '✅ Mot de passe mis à jour.';
    }
}

$total_kb = $db_ok ? (int) qv("SELECT COUNT(*) FROM agent_kb") : 0;
$total_asks = $db_ok ? (int) qv("SELECT COUNT(*) FROM agent_logs") : 0;
$top_q = $db_ok ? q("SELECT question,hits,category FROM agent_kb ORDER BY hits DESC LIMIT 5") : [];
$cats = $db_ok ? q("SELECT category,COUNT(*) cnt FROM agent_kb GROUP BY category ORDER BY cnt DESC") : [];
$permissionCatalog = $db_ok ? q("SELECT permission_key,label FROM agent_permissions ORDER BY permission_key") : [];
$siteIndexCount = $db_ok ? (int) qv("SELECT COUNT(*) FROM agent_site_index") : 0;
$ctx_count = count($_SESSION['chat_context'] ?? []);
