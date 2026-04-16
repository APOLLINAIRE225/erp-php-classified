<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';
session_start();
ini_set('display_errors', 0); error_reporting(0);
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once PROJECT_ROOT . '/messaging/app_alerts.php';
require APP_ROOT . '/vendor/autoload.php';
use App\Core\DB; use App\Core\Auth; use App\Core\Middleware;

Auth::check();
Middleware::role(['developer','admin','manager']);
$pdo = DB::getConnection();

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$user_id   = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? 'Utilisateur';

/*━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  CRÉATION TABLE client_deposits (si absente)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━*/
$pdo->exec("CREATE TABLE IF NOT EXISTS client_deposits (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  client_id    INT NOT NULL,
  company_id   INT NOT NULL,
  city_id      INT NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  type         ENUM('depot','application') NOT NULL DEFAULT 'depot',
  payment_mode VARCHAR(50) DEFAULT 'Espèce',
  reference    VARCHAR(100),
  invoice_id   INT DEFAULT NULL,
  note         TEXT,
  created_by   INT DEFAULT NULL,
  created_at   DATETIME DEFAULT NOW(),
  INDEX(client_id), INDEX(company_id,city_id), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$tableChecks = [
    "ALTER TABLE versements ADD COLUMN status ENUM('active','cancelled') NOT NULL DEFAULT 'active'",
    "ALTER TABLE versements ADD COLUMN cancelled_at DATETIME NULL",
    "ALTER TABLE versements ADD COLUMN cancelled_by INT NULL",
    "ALTER TABLE versements ADD COLUMN cancelled_note TEXT NULL",
    "ALTER TABLE versements ADD COLUMN updated_by INT NULL",
    "ALTER TABLE versements ADD COLUMN updated_note TEXT NULL",
    "ALTER TABLE versements ADD COLUMN cashier_name VARCHAR(100) NULL",
    "ALTER TABLE versements ADD COLUMN cashier_city VARCHAR(120) NULL",
    "ALTER TABLE client_deposits ADD COLUMN status ENUM('active','cancelled') NOT NULL DEFAULT 'active'",
    "ALTER TABLE client_deposits ADD COLUMN cancelled_at DATETIME NULL",
    "ALTER TABLE client_deposits ADD COLUMN cancelled_by INT NULL",
    "ALTER TABLE client_deposits ADD COLUMN cancelled_note TEXT NULL",
    "ALTER TABLE client_deposits ADD COLUMN updated_by INT NULL",
    "ALTER TABLE client_deposits ADD COLUMN updated_note TEXT NULL",
    "ALTER TABLE client_deposits ADD COLUMN cashier_name VARCHAR(100) NULL",
    "ALTER TABLE client_deposits ADD COLUMN cashier_city VARCHAR(120) NULL"
];
foreach ($tableChecks as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* colonne déjà présente */ }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS versement_audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tx_type ENUM('versement','depot') NOT NULL,
  tx_id INT NOT NULL,
  action_type ENUM('create','update','cancel','pdf','email','whatsapp') NOT NULL,
  client_id INT DEFAULT NULL,
  invoice_id INT DEFAULT NULL,
  amount_before DECIMAL(12,2) DEFAULT NULL,
  amount_after DECIMAL(12,2) DEFAULT NULL,
  actor_user_id INT DEFAULT NULL,
  actor_name VARCHAR(120) DEFAULT NULL,
  cashier_name VARCHAR(120) DEFAULT NULL,
  cashier_city VARCHAR(120) DEFAULT NULL,
  note TEXT,
  created_at DATETIME DEFAULT NOW(),
  INDEX idx_tx (tx_type, tx_id),
  INDEX idx_client (client_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function writeAudit(PDO $pdo, array $data): void {
    $pdo->prepare("
        INSERT INTO versement_audit_logs
        (tx_type, tx_id, action_type, client_id, invoice_id, amount_before, amount_after, actor_user_id, actor_name, cashier_name, cashier_city, note)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $data['tx_type'],
        $data['tx_id'],
        $data['action_type'],
        $data['client_id'] ?? null,
        $data['invoice_id'] ?? null,
        $data['amount_before'] ?? null,
        $data['amount_after'] ?? null,
        $data['actor_user_id'] ?? null,
        $data['actor_name'] ?? null,
        $data['cashier_name'] ?? null,
        $data['cashier_city'] ?? null,
        $data['note'] ?? null,
    ]);
}

function syncInvoiceStatus(PDO $pdo, int $invoiceId): void {
    if ($invoiceId <= 0) return;
    $st = $pdo->prepare("SELECT total FROM invoices WHERE id=?");
    $st->execute([$invoiceId]);
    $total = (float) $st->fetchColumn();
    if ($total <= 0) return;

    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM versements WHERE invoice_id=? AND status='active'");
    $st->execute([$invoiceId]);
    $paid = (float) $st->fetchColumn();
    $reste = $total - $paid;
    $status = $reste <= 0.01 ? 'Payée' : ($paid > 0 ? 'Partielle' : 'Impayée');
    $pdo->prepare("UPDATE invoices SET status=? WHERE id=?")->execute([$status, $invoiceId]);
}

function notifyFinanceWorkflowAlert(PDO $pdo, string $eventType, string $title, string $body, string $url, array $meta = []): void {
    try {
        appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
            'title' => $title,
            'body' => mb_strimwidth($body, 0, 180, '…', 'UTF-8'),
            'url' => $url,
            'tag' => 'finance-workflow-' . $eventType,
            'unread' => 1,
        ], [
            'event_type' => $eventType,
            'actor_user_id' => (int)($_SESSION['user_id'] ?? 0),
            'meta' => $meta,
        ]);
    } catch (Throwable $e) {
        error_log('[FINANCE WORKFLOW ALERT] ' . $e->getMessage());
    }
}

/*━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  PARAMÈTRES URL
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━*/
$company_id = (int)($_GET['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id']    ?? 0);
$client_id  = (int)($_GET['client_id'] ?? 0);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$q_search   = trim($_GET['q'] ?? '');
$active_tab = $_GET['tab'] ?? ($invoice_id ? 'payer' : 'payer');
$is_ajax_search = isset($_GET['ajax']) && $_GET['ajax'] === 'client_search';

/*━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  LISTES POUR FILTRES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━*/
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll();
$cities = [];
if ($company_id) {
    $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]); $cities = $st->fetchAll();
}

/*━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  RECHERCHE CLIENT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━*/
$search_results = [];
if ($q_search && strlen($q_search) >= 2) {
    $like = "%$q_search%";
    $w = ["(c.name LIKE ? OR c.phone LIKE ? OR CAST(c.id AS CHAR) LIKE ? OR co.name LIKE ? OR ci.name LIKE ? OR COALESCE(c.email,'') LIKE ? OR COALESCE(c.address,'') LIKE ?)"];
    $p = [$like, $like, $like, $like, $like, $like, $like];
    if ($company_id) { $w[] = "c.company_id=?"; $p[] = $company_id; }
    if ($city_id)    { $w[] = "c.city_id=?";    $p[] = $city_id; }
    $st = $pdo->prepare("
        SELECT c.id, c.name, c.phone, c.email, c.address, c.created_at, c.last_order_at, c.vip_status,
               co.name AS company_name, ci.name AS city_name, c.company_id, c.city_id,
               COALESCE(dep.total_deposits,0) AS total_deposits,
               COALESCE(dep.wallet_balance,0) AS wallet_balance,
               COALESCE(inv.total_debt,0) AS total_debt,
               COALESCE(inv.total_purchases,0) AS total_purchases,
               COALESCE(inv.purchase_count,0) AS purchase_count
        FROM clients c
        JOIN companies co ON co.id=c.company_id
        JOIN cities ci ON ci.id=c.city_id
        LEFT JOIN (
            SELECT client_id,
                   SUM(CASE WHEN type='depot' AND status='active' THEN amount ELSE 0 END) AS total_deposits,
                   SUM(CASE WHEN status='active' THEN CASE WHEN type='depot' THEN amount ELSE -amount END ELSE 0 END) AS wallet_balance
            FROM client_deposits
            GROUP BY client_id
        ) dep ON dep.client_id = c.id
        LEFT JOIN (
            SELECT i.client_id,
                   COUNT(*) AS purchase_count,
                   SUM(i.total) AS total_purchases,
                   SUM(GREATEST(i.total - COALESCE(v.paid,0),0)) AS total_debt
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid
                FROM versements
                WHERE status='active'
                GROUP BY invoice_id
            ) v ON v.invoice_id = i.id
            GROUP BY i.client_id
        ) inv ON inv.client_id = c.id
        WHERE ".implode(" AND ",$w)."
        ORDER BY c.name LIMIT 30
    ");
    $st->execute($p); $search_results = $st->fetchAll();
}

if ($is_ajax_search) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'results' => array_map(static function(array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'phone' => $row['phone'],
                'email' => $row['email'] ?? '',
                'address' => $row['address'] ?? '',
                'company_name' => $row['company_name'],
                'city_name' => $row['city_name'],
                'company_id' => (int) $row['company_id'],
                'city_id' => (int) $row['city_id'],
                'wallet_balance' => (float) ($row['wallet_balance'] ?? 0),
                'total_debt' => (float) ($row['total_debt'] ?? 0),
                'total_deposits' => (float) ($row['total_deposits'] ?? 0),
                'total_purchases' => (float) ($row['total_purchases'] ?? 0),
                'purchase_count' => (int) ($row['purchase_count'] ?? 0),
                'vip_status' => $row['vip_status'] ?? '',
                'created_at' => $row['created_at'] ?? null,
                'last_order_at' => $row['last_order_at'] ?? null,
            ];
        }, $search_results),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  PROFIL CLIENT SÉLECTIONNÉ
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━*/
$client = null; $client_invoices = []; $client_history = []; $client_audit_logs = [];
$solde_depot = $total_dette = $nb_factures_impayees = 0;
$client_stats = [
    'total_deposits' => 0.0,
    'total_consumed' => 0.0,
    'total_purchases' => 0.0,
    'purchase_count' => 0,
    'frequency' => 0.0,
    'last_activity' => null,
    'first_seen' => null,
    'score' => 2,
    'status_label' => 'A surveiller',
    'latest_deposit' => 0.0,
    'oldest_unpaid_days' => 0,
];
$client_alerts = [];

if ($client_id) {
    $st = $pdo->prepare("
        SELECT c.*, co.name AS company_name, ci.name AS city_name
        FROM clients c
        JOIN companies co ON co.id=c.company_id
        JOIN cities ci ON ci.id=c.city_id
        WHERE c.id=?
    ");
    $st->execute([$client_id]); $client = $st->fetch();

    if ($client) {
        $company_id = $client['company_id'];
        $city_id    = $client['city_id'];

        /* Factures impayées/partielles */
        $st = $pdo->prepare("
            SELECT i.id, i.total, i.status, i.created_at,
                   COALESCE(SUM(CASE WHEN v.status='active' THEN v.amount ELSE 0 END),0) AS paid
            FROM invoices i
            LEFT JOIN versements v ON v.invoice_id=i.id
            WHERE i.client_id=? AND i.status != 'Payée'
            GROUP BY i.id
            ORDER BY i.created_at DESC
        ");
        $st->execute([$client_id]); $client_invoices = $st->fetchAll();

        $total_dette = 0;
        foreach ($client_invoices as $inv) {
            $total_dette += max(0, $inv['total'] - $inv['paid']);
        }
        $nb_factures_impayees = count($client_invoices);

        /* Solde dépôts */
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='depot' THEN amount ELSE -amount END),0) FROM client_deposits WHERE client_id=? AND status='active'");
        $st->execute([$client_id]); $solde_depot = (float)$st->fetchColumn();

        /* Historique complet (versements + dépôts) */
        $st = $pdo->prepare("
            SELECT 'versement' AS source, v.id, v.amount, v.payment_mode, v.payment_date AS date,
                   v.receipt_number AS reference, v.note, v.invoice_id, NULL AS depot_type,
                   v.status, v.cashier_name, v.cashier_city, u.username AS actor_name, v.updated_note, v.cancelled_note
            FROM versements v
            LEFT JOIN users u ON u.id=v.created_by
            WHERE v.client_id=?
            UNION ALL
            SELECT 'depot' AS source, d.id, d.amount, d.payment_mode, d.created_at AS date,
                   d.reference, d.note, d.invoice_id, d.type AS depot_type,
                   d.status, d.cashier_name, d.cashier_city, u.username AS actor_name, d.updated_note, d.cancelled_note
            FROM client_deposits d
            LEFT JOIN users u ON u.id=d.created_by
            WHERE d.client_id=?
            ORDER BY date DESC LIMIT 100
        ");
        $st->execute([$client_id, $client_id]); $client_history = $st->fetchAll();

        $st = $pdo->prepare("
            SELECT *
            FROM versement_audit_logs
            WHERE client_id=?
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $st->execute([$client_id]); $client_audit_logs = $st->fetchAll();

        $st = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN d.type='depot' AND d.status='active' THEN d.amount ELSE 0 END),0) AS total_deposits,
                COALESCE(SUM(CASE WHEN d.type='application' AND d.status='active' THEN d.amount ELSE 0 END),0) AS total_consumed,
                COALESCE(MAX(CASE WHEN d.type='depot' AND d.status='active' THEN d.amount ELSE 0 END),0) AS latest_deposit
            FROM client_deposits d
            WHERE d.client_id=?
        ");
        $st->execute([$client_id]);
        $depStats = $st->fetch() ?: [];

        $st = $pdo->prepare("
            SELECT
                COUNT(*) AS purchase_count,
                COALESCE(SUM(total),0) AS total_purchases,
                MIN(created_at) AS first_invoice_at,
                MAX(created_at) AS last_invoice_at
            FROM invoices
            WHERE client_id=?
        ");
        $st->execute([$client_id]);
        $invoiceStats = $st->fetch() ?: [];

        $activityDates = array_filter([
            $client['created_at'] ?? null,
            $client['last_order_at'] ?? null,
            $invoiceStats['last_invoice_at'] ?? null,
            $client_history[0]['date'] ?? null,
        ]);
        $lastActivity = $activityDates ? max($activityDates) : null;
        $firstSeen = $client['created_at'] ?? ($invoiceStats['first_invoice_at'] ?? null);
        $monthsSince = $firstSeen ? max(1, ((int) date('Y') - (int) date('Y', strtotime($firstSeen))) * 12 + ((int) date('n') - (int) date('n', strtotime($firstSeen))) + 1) : 1;
        $frequency = ((int) ($invoiceStats['purchase_count'] ?? 0)) / $monthsSince;

        $oldestDays = 0;
        if (!empty($client_invoices)) {
            $oldestDate = min(array_map(fn($inv) => strtotime($inv['created_at']), $client_invoices));
            $oldestDays = max(0, (int) floor((time() - $oldestDate) / 86400));
        }

        $score = 2;
        if ($total_dette <= 0.01) $score++;
        if ((float) ($depStats['total_deposits'] ?? 0) >= 250000) $score++;
        if (($invoiceStats['purchase_count'] ?? 0) >= 4) $score++;
        if ($lastActivity && strtotime($lastActivity) >= strtotime('-30 days')) $score++;
        if ($oldestDays > 45) $score = max(1, $score - 2);
        elseif ($total_dette > $solde_depot && $total_dette > 0) $score = max(1, $score - 1);
        $score = max(1, min(5, $score));

        $statusMap = [
            5 => 'Bon client',
            4 => 'Client solide',
            3 => 'Client normal',
            2 => 'A surveiller',
            1 => 'Risqué',
        ];

        $client_stats = [
            'total_deposits' => (float) ($depStats['total_deposits'] ?? 0),
            'total_consumed' => (float) ($depStats['total_consumed'] ?? 0),
            'total_purchases' => (float) ($invoiceStats['total_purchases'] ?? 0),
            'purchase_count' => (int) ($invoiceStats['purchase_count'] ?? 0),
            'frequency' => $frequency,
            'last_activity' => $lastActivity,
            'first_seen' => $firstSeen,
            'score' => $score,
            'status_label' => $statusMap[$score],
            'latest_deposit' => (float) ($depStats['latest_deposit'] ?? 0),
            'oldest_unpaid_days' => $oldestDays,
        ];

        if ($total_dette > 0.01) $client_alerts[] = ['type' => 'danger', 'icon' => 'fa-triangle-exclamation', 'title' => 'Client avec dette', 'text' => number_format($total_dette, 0, ',', ' ') . ' FCFA restent à encaisser.'];
        if ($client_stats['latest_deposit'] >= 200000) $client_alerts[] = ['type' => 'info', 'icon' => 'fa-sack-dollar', 'title' => 'Dépôt élevé', 'text' => 'Dernier dépôt de ' . number_format($client_stats['latest_deposit'], 0, ',', ' ') . ' FCFA.'];
        if ($lastActivity && strtotime($lastActivity) < strtotime('-30 days')) $client_alerts[] = ['type' => 'warn', 'icon' => 'fa-fire', 'title' => 'Client inactif', 'text' => 'Aucune activité depuis le ' . date('d/m/Y', strtotime($lastActivity)) . '.'];
        if ($solde_depot > 0 && $solde_depot < 25000) $client_alerts[] = ['type' => 'warn', 'icon' => 'fa-wallet', 'title' => 'Solde faible', 'text' => 'Le wallet client est bas: ' . number_format($solde_depot, 0, ',', ' ') . ' FCFA.'];
        if ($client_stats['oldest_unpaid_days'] >= 30) $client_alerts[] = ['type' => 'danger', 'icon' => 'fa-file-circle-xmark', 'title' => 'Facture ancienne impayée', 'text' => $client_stats['oldest_unpaid_days'] . ' jours sans règlement complet.'];
    }
}

/*━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ACTIONS POST
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━*/
$flash_type = $flash_text = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $p_client   = (int)($_POST['client_id']  ?? 0);
    $p_company  = (int)($_POST['company_id'] ?? 0);
    $p_city     = (int)($_POST['city_id']    ?? 0);
    $p_mode     = in_array($_POST['payment_mode']??'',['Espèce','Virement bancaire','Chèque','Mobile Money','Virement interne'])
                  ? $_POST['payment_mode'] : 'Espèce';
    $p_note     = trim($_POST['note'] ?? '');
    $cityNameSt = $pdo->prepare("SELECT name FROM cities WHERE id=?");
    $cityNameSt->execute([$p_city]);
    $cashierCity = (string) ($cityNameSt->fetchColumn() ?: '');

    /* ── 1. Payer une facture ── */
    if ($action === 'payer_facture') {
        $p_invoice = (int)($_POST['invoice_id'] ?? 0);
        $p_amount  = (float)str_replace(',','.',$_POST['amount']??0);

        $st = $pdo->prepare("SELECT total FROM invoices WHERE id=? AND client_id=?");
        $st->execute([$p_invoice,$p_client]); $inv_total = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM versements WHERE invoice_id=? AND status='active'");
        $st->execute([$p_invoice]); $already = (float)$st->fetchColumn();
        $sec_reste = $inv_total - $already;

        if ($p_amount > 0 && $p_amount <= $sec_reste + 0.01 && $inv_total > 0) {
            $pdate   = date('Y-m-d H:i:s');
            $receipt = generate_yearly_reference($pdo, 'versements', 'receipt_number', 'PAY', 'payment_date', $pdate);
            $pdo->prepare("INSERT INTO versements(invoice_id,client_id,amount,payment_mode,payment_date,receipt_number,reference,note,created_by,cashier_name,cashier_city) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$p_invoice,$p_client,$p_amount,$p_mode,$pdate,$receipt,$receipt,$p_note,$user_id,$user_name,$cashierCity]);
            $txId = (int) $pdo->lastInsertId();

            /* Sync statut facture */
            syncInvoiceStatus($pdo, $p_invoice);
            writeAudit($pdo, [
                'tx_type' => 'versement',
                'tx_id' => $txId,
                'action_type' => 'create',
                'client_id' => $p_client,
                'invoice_id' => $p_invoice,
                'amount_after' => $p_amount,
                'actor_user_id' => $user_id,
                'actor_name' => $user_name,
                'cashier_name' => $user_name,
                'cashier_city' => $cashierCity,
                'note' => $p_note,
            ]);

            $flash_type = 'success';
            $flash_text = "✅ Versement de ".number_format($p_amount,0,',',' ')." FCFA enregistré — Reçu : $receipt";
            $_SESSION['last_receipt'] = ['id'=>$txId,'receipt'=>$receipt,'amount'=>$p_amount,'mode'=>$p_mode,'invoice'=>$p_invoice,'client'=>$p_client,'date'=>$pdate,'type'=>'versement'];
            try {
                $clientNameStmt = $pdo->prepare("SELECT name FROM clients WHERE id=? LIMIT 1");
                $clientNameStmt->execute([$p_client]);
                $clientName = (string)($clientNameStmt->fetchColumn() ?: ('Client #' . $p_client));
                notifyFinanceWorkflowAlert(
                    $pdo,
                    'invoice_payment_recorded',
                    'Paiement facture enregistré',
                    sprintf(
                        '💳 Facture #%d réglée: %s FCFA (%s) · Client %s',
                        $p_invoice,
                        number_format((float)$p_amount, 0, '', '.'),
                        $p_mode,
                        $clientName
                    ),
                    project_url('finance/ticket.php?invoice_id=' . $p_invoice),
                    [
                        'invoice_id' => $p_invoice,
                        'client_id' => $p_client,
                        'client_name' => $clientName,
                        'amount' => (float)$p_amount,
                        'payment_mode' => (string)$p_mode,
                        'receipt_number' => (string)$receipt,
                        'source' => 'versement',
                    ]
                );
            } catch (Throwable $e) {
                error_log('[VERSEMENT PAYMENT ALERT] ' . $e->getMessage());
            }
            $active_tab = 'payer';
        } else {
            $flash_type = 'error'; $flash_text = "Montant invalide ou supérieur au reste dû.";
        }
    }

    /* ── 2. Enregistrer un dépôt libre ── */
    if ($action === 'depot') {
        $p_amount  = (float)str_replace(',','.',$_POST['amount']??0);
        if ($p_amount > 0) {
            $ref = generate_yearly_reference($pdo, 'client_deposits', 'reference', 'DEP');
            $pdo->prepare("INSERT INTO client_deposits(client_id,company_id,city_id,amount,type,payment_mode,reference,note,created_by,cashier_name,cashier_city) VALUES(?,?,?,?,'depot',?,?,?,?,?,?)")
                ->execute([$p_client,$p_company,$p_city,$p_amount,$p_mode,$ref,$p_note,$user_id,$user_name,$cashierCity]);
            $txId = (int) $pdo->lastInsertId();
            writeAudit($pdo, [
                'tx_type' => 'depot',
                'tx_id' => $txId,
                'action_type' => 'create',
                'client_id' => $p_client,
                'amount_after' => $p_amount,
                'actor_user_id' => $user_id,
                'actor_name' => $user_name,
                'cashier_name' => $user_name,
                'cashier_city' => $cashierCity,
                'note' => $p_note,
            ]);
            $flash_type = 'success'; $flash_text = "💰 Dépôt de ".number_format($p_amount,0,',',' ')." FCFA enregistré — Réf : $ref";
            $_SESSION['last_receipt'] = ['id'=>$txId,'receipt'=>$ref,'amount'=>$p_amount,'mode'=>$p_mode,'invoice'=>null,'client'=>$p_client,'date'=>date('Y-m-d H:i:s'),'type'=>'depot'];
            $active_tab = 'depot';
        } else { $flash_type='error'; $flash_text="Montant invalide."; }
    }

    /* ── 3. Appliquer solde dépôt sur facture ── */
    if ($action === 'appliquer_depot') {
        $p_invoice = (int)($_POST['invoice_id'] ?? 0);
        $p_amount  = (float)str_replace(',','.',$_POST['amount']??0);

        /* Vérifier solde dépôt dispo */
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='depot' THEN amount ELSE -amount END),0) FROM client_deposits WHERE client_id=? AND status='active'");
        $st->execute([$p_client]); $solde_dispo = (float)$st->fetchColumn();

        $st = $pdo->prepare("SELECT total FROM invoices WHERE id=? AND client_id=?");
        $st->execute([$p_invoice,$p_client]); $inv_total = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM versements WHERE invoice_id=? AND status='active'");
        $st->execute([$p_invoice]); $already = (float)$st->fetchColumn();
        $sec_reste = $inv_total - $already;

        $p_amount = min($p_amount, $solde_dispo, $sec_reste);
        if ($p_amount > 0.01 && $inv_total > 0) {
            $pdate   = date('Y-m-d H:i:s');
            $receipt = generate_yearly_reference($pdo, 'versements', 'receipt_number', 'PAY', 'payment_date', $pdate);
            /* Versement sur facture */
            $pdo->prepare("INSERT INTO versements(invoice_id,client_id,amount,payment_mode,payment_date,receipt_number,reference,note,created_by,cashier_name,cashier_city) VALUES(?,?,?,'Solde compte',?,?,?,?,?,?,?)")
                ->execute([$p_invoice,$p_client,$p_amount,$pdate,$receipt,$receipt,'Prélèvement sur solde dépôt',$user_id,$user_name,$cashierCity]);
            $versementId = (int) $pdo->lastInsertId();
            /* Débit du solde dépôt */
            $ref = generate_yearly_reference($pdo, 'client_deposits', 'reference', 'APP');
            $app_note = 'Application sur facture #' . $p_invoice;
            $pdo->prepare("INSERT INTO client_deposits(client_id,company_id,city_id,amount,type,payment_mode,reference,invoice_id,note,created_by,cashier_name,cashier_city) VALUES(?,?,?,?,'application','Solde compte',?,?,?,?,?,?)")
                ->execute([$p_client,$p_company,$p_city,$p_amount,$ref,$p_invoice,$app_note,$user_id,$user_name,$cashierCity]);
            $applicationId = (int) $pdo->lastInsertId();
            /* Sync facture */
            syncInvoiceStatus($pdo, $p_invoice);
            writeAudit($pdo, [
                'tx_type' => 'versement',
                'tx_id' => $versementId,
                'action_type' => 'create',
                'client_id' => $p_client,
                'invoice_id' => $p_invoice,
                'amount_after' => $p_amount,
                'actor_user_id' => $user_id,
                'actor_name' => $user_name,
                'cashier_name' => $user_name,
                'cashier_city' => $cashierCity,
                'note' => 'Application du wallet client',
            ]);
            writeAudit($pdo, [
                'tx_type' => 'depot',
                'tx_id' => $applicationId,
                'action_type' => 'create',
                'client_id' => $p_client,
                'invoice_id' => $p_invoice,
                'amount_after' => $p_amount,
                'actor_user_id' => $user_id,
                'actor_name' => $user_name,
                'cashier_name' => $user_name,
                'cashier_city' => $cashierCity,
                'note' => $app_note,
            ]);
            $flash_type='success'; $flash_text="🔄 ".number_format($p_amount,0,',',' ')." FCFA déduit du solde et appliqué sur la Facture #$p_invoice";
            try {
                $clientNameStmt = $pdo->prepare("SELECT name FROM clients WHERE id=? LIMIT 1");
                $clientNameStmt->execute([$p_client]);
                $clientName = (string)($clientNameStmt->fetchColumn() ?: ('Client #' . $p_client));
                notifyFinanceWorkflowAlert(
                    $pdo,
                    'invoice_payment_wallet_applied',
                    'Paiement facture via solde client',
                    sprintf(
                        '🔄 Facture #%d: %s FCFA appliqués depuis wallet · Client %s',
                        $p_invoice,
                        number_format((float)$p_amount, 0, '', '.'),
                        $clientName
                    ),
                    project_url('finance/ticket.php?invoice_id=' . $p_invoice),
                    [
                        'invoice_id' => $p_invoice,
                        'client_id' => $p_client,
                        'client_name' => $clientName,
                        'amount' => (float)$p_amount,
                        'payment_mode' => 'Solde compte',
                        'receipt_number' => (string)$receipt,
                        'wallet_application_ref' => (string)$ref,
                        'source' => 'wallet_application',
                    ]
                );
            } catch (Throwable $e) {
                error_log('[VERSEMENT WALLET ALERT] ' . $e->getMessage());
            }
            $active_tab='payer';
        } else { $flash_type='error'; $flash_text="Solde insuffisant ou montant invalide."; }
    }

    if ($action === 'update_transaction') {
        $txType = ($_POST['tx_type'] ?? '') === 'depot' ? 'depot' : 'versement';
        $txId = (int) ($_POST['tx_id'] ?? 0);
        $newAmount = (float) str_replace(',', '.', $_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $table = $txType === 'depot' ? 'client_deposits' : 'versements';
        $dateCol = $txType === 'depot' ? 'created_at' : 'payment_date';
        $refCol = $txType === 'depot' ? 'reference' : 'receipt_number';

        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id=? AND client_id=? LIMIT 1");
        $st->execute([$txId, $p_client]);
        $row = $st->fetch();

        if ($row && ($row['status'] ?? 'active') === 'active' && $newAmount > 0) {
            $pdo->prepare("UPDATE {$table} SET amount=?, updated_by=?, updated_note=? WHERE id=?")
                ->execute([$newAmount, $user_id, $reason ?: 'Montant modifié', $txId]);
            if ($txType === 'versement') syncInvoiceStatus($pdo, (int) $row['invoice_id']);
            writeAudit($pdo, [
                'tx_type' => $txType,
                'tx_id' => $txId,
                'action_type' => 'update',
                'client_id' => $p_client,
                'invoice_id' => $row['invoice_id'] ?? null,
                'amount_before' => (float) $row['amount'],
                'amount_after' => $newAmount,
                'actor_user_id' => $user_id,
                'actor_name' => $user_name,
                'cashier_name' => $row['cashier_name'] ?? $user_name,
                'cashier_city' => $row['cashier_city'] ?? $cashierCity,
                'note' => $reason ?: 'Montant modifié',
            ]);
            try {
                $clientNameStmt = $pdo->prepare("SELECT name FROM clients WHERE id=? LIMIT 1");
                $clientNameStmt->execute([$p_client]);
                $clientName = (string)($clientNameStmt->fetchColumn() ?: ('Client #' . $p_client));
                $refValue = (string)($row[$refCol] ?? ('#' . $txId));
                $targetUrl = ($txType === 'versement' && (int)($row['invoice_id'] ?? 0) > 0)
                    ? project_url('finance/ticket.php?invoice_id=' . (int)$row['invoice_id'])
                    : project_url('finance/versement.php?company_id=' . $p_company . '&city_id=' . $p_city . '&client_id=' . $p_client . '&tab=historique');
                notifyFinanceWorkflowAlert(
                    $pdo,
                    'payment_updated',
                    'Transaction paiement modifiée',
                    sprintf(
                        '✏️ %s %s: %s → %s FCFA · Client %s',
                        $txType === 'depot' ? 'Dépôt' : 'Paiement',
                        $refValue,
                        number_format((float)$row['amount'], 0, '', '.'),
                        number_format((float)$newAmount, 0, '', '.'),
                        $clientName
                    ),
                    $targetUrl,
                    [
                        'tx_type' => $txType,
                        'tx_id' => $txId,
                        'reference' => $refValue,
                        'client_id' => $p_client,
                        'client_name' => $clientName,
                        'invoice_id' => isset($row['invoice_id']) ? (int)$row['invoice_id'] : null,
                        'amount_before' => (float)$row['amount'],
                        'amount_after' => (float)$newAmount,
                        'reason' => $reason ?: 'Montant modifié',
                    ]
                );
            } catch (Throwable $e) {
                error_log('[PAYMENT UPDATED ALERT] ' . $e->getMessage());
            }
            $flash_type = 'success';
            $flash_text = "✏️ Montant mis à jour pour " . ($txType === 'depot' ? 'le dépôt' : 'le paiement') . ' ' . ($row[$refCol] ?? ('#' . $txId));
            $active_tab = 'historique';
        } else {
            $flash_type = 'error';
            $flash_text = "Modification impossible.";
        }
    }

    if ($action === 'cancel_transaction') {
        $txType = ($_POST['tx_type'] ?? '') === 'depot' ? 'depot' : 'versement';
        $txId = (int) ($_POST['tx_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $table = $txType === 'depot' ? 'client_deposits' : 'versements';
        $refCol = $txType === 'depot' ? 'reference' : 'receipt_number';

        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id=? AND client_id=? LIMIT 1");
        $st->execute([$txId, $p_client]);
        $row = $st->fetch();

        if ($row && ($row['status'] ?? 'active') === 'active') {
            $pdo->prepare("UPDATE {$table} SET status='cancelled', cancelled_at=NOW(), cancelled_by=?, cancelled_note=? WHERE id=?")
                ->execute([$user_id, $reason ?: 'Annulation manuelle', $txId]);
            if ($txType === 'versement') syncInvoiceStatus($pdo, (int) $row['invoice_id']);
            writeAudit($pdo, [
                'tx_type' => $txType,
                'tx_id' => $txId,
                'action_type' => 'cancel',
                'client_id' => $p_client,
                'invoice_id' => $row['invoice_id'] ?? null,
                'amount_before' => (float) $row['amount'],
                'actor_user_id' => $user_id,
                'actor_name' => $user_name,
                'cashier_name' => $row['cashier_name'] ?? $user_name,
                'cashier_city' => $row['cashier_city'] ?? $cashierCity,
                'note' => $reason ?: 'Annulation manuelle',
            ]);
            try {
                $clientNameStmt = $pdo->prepare("SELECT name FROM clients WHERE id=? LIMIT 1");
                $clientNameStmt->execute([$p_client]);
                $clientName = (string)($clientNameStmt->fetchColumn() ?: ('Client #' . $p_client));
                $refValue = (string)($row[$refCol] ?? ('#' . $txId));
                $targetUrl = ($txType === 'versement' && (int)($row['invoice_id'] ?? 0) > 0)
                    ? project_url('finance/ticket.php?invoice_id=' . (int)$row['invoice_id'])
                    : project_url('finance/versement.php?company_id=' . $p_company . '&city_id=' . $p_city . '&client_id=' . $p_client . '&tab=audit');
                notifyFinanceWorkflowAlert(
                    $pdo,
                    'payment_cancelled',
                    'Transaction paiement annulée',
                    sprintf(
                        '🧯 %s %s annulé: %s FCFA · Client %s',
                        $txType === 'depot' ? 'Dépôt' : 'Paiement',
                        $refValue,
                        number_format((float)$row['amount'], 0, '', '.'),
                        $clientName
                    ),
                    $targetUrl,
                    [
                        'tx_type' => $txType,
                        'tx_id' => $txId,
                        'reference' => $refValue,
                        'client_id' => $p_client,
                        'client_name' => $clientName,
                        'invoice_id' => isset($row['invoice_id']) ? (int)$row['invoice_id'] : null,
                        'amount' => (float)$row['amount'],
                        'reason' => $reason ?: 'Annulation manuelle',
                    ]
                );
            } catch (Throwable $e) {
                error_log('[PAYMENT CANCELLED ALERT] ' . $e->getMessage());
            }
            $flash_type = 'success';
            $flash_text = "🧯 Transaction annulée : " . ($row[$refCol] ?? ('#' . $txId));
            $active_tab = 'audit';
        } else {
            $flash_type = 'error';
            $flash_text = "Annulation impossible.";
        }
    }

    header("Location: versement.php?company_id=$p_company&city_id=$p_city&client_id=$p_client&tab=$active_tab&ft=".urlencode($flash_type)."&fm=".urlencode($flash_text)); exit;
}

$flash_type = $_GET['ft'] ?? ''; $flash_text = $_GET['fm'] ?? '';

/*━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  KPI GLOBAUX
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━*/
$sw=[]; $sp=[];
if ($company_id){$sw[]="i.company_id=?";$sp[]=$company_id;}
if ($city_id)   {$sw[]="i.city_id=?";   $sp[]=$city_id;}
$swhere=$sw?"WHERE ".implode(" AND ",$sw):"";
$st = $pdo->prepare("SELECT COUNT(v.id) nb, COALESCE(SUM(v.amount),0) total, COALESCE(SUM(CASE WHEN DATE(v.payment_date)=CURDATE() THEN v.amount ELSE 0 END),0) auj FROM versements v JOIN invoices i ON i.id=v.invoice_id $swhere ".($swhere ? "AND v.status='active'" : "WHERE v.status='active'"));
$st->execute($sp); $kv = $st->fetch();

$sw2=[]; $sp2=[];
if ($company_id){$sw2[]="company_id=?";$sp2[]=$company_id;}
if ($city_id)   {$sw2[]="city_id=?";   $sp2[]=$city_id;}
$clients_where = $sw2 ? "WHERE ".implode(" AND ",$sw2) : "";
$st2 = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='depot' THEN amount ELSE -amount END),0) FROM client_deposits WHERE status='active' AND client_id IN (SELECT id FROM clients $clients_where)");
$st2->execute($sp2); $total_depots = (float)$st2->fetchColumn();

$st3 = $pdo->prepare("SELECT COALESCE(SUM(i.total - COALESCE(sub.paid,0)),0) FROM invoices i LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM versements WHERE status='active' GROUP BY invoice_id) sub ON sub.invoice_id=i.id ".($sw?"$swhere AND i.status!='Payée'":"WHERE i.status!='Payée'"));
$st3->execute($sp); $creances_total = (float)$st3->fetchColumn();

/* Derniers clients ayant payé */
$st4 = $pdo->prepare("SELECT c.name, c.phone, v.amount, v.payment_date FROM versements v JOIN clients c ON c.id=v.client_id JOIN invoices i ON i.id=v.invoice_id ".($swhere ? "$swhere AND v.status='active'" : "WHERE v.status='active'")." ORDER BY v.payment_date DESC LIMIT 5");
$st4->execute($sp); $recent_payments = $st4->fetchAll();

$st5 = $pdo->prepare("SELECT COUNT(*) FROM clients " . ($clients_where ?: '') . ($clients_where ? " AND COALESCE(last_order_at, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)" : " WHERE COALESCE(last_order_at, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)"));
$st5->execute($sp2); $active_clients = (int) $st5->fetchColumn();

$st6 = $pdo->prepare("SELECT COUNT(*) FROM clients " . ($clients_where ?: '') . ($clients_where ? " AND DATE(created_at)=CURDATE()" : " WHERE DATE(created_at)=CURDATE()"));
$st6->execute($sp2); $new_clients_today = (int) $st6->fetchColumn();

$depTodayWhere = ["type='depot'", "status='active'", "DATE(created_at)=CURDATE()"];
$depTodayParams = [];
if ($company_id) { $depTodayWhere[] = "company_id=?"; $depTodayParams[] = $company_id; }
if ($city_id) { $depTodayWhere[] = "city_id=?"; $depTodayParams[] = $city_id; }
$st7 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM client_deposits WHERE " . implode(' AND ', $depTodayWhere));
$st7->execute($depTodayParams); $deposits_today = (float) $st7->fetchColumn();

$st8 = $pdo->prepare("SELECT COUNT(*) FROM versements v JOIN invoices i ON i.id=v.invoice_id ".($swhere ? "$swhere AND DATE(v.payment_date)=CURDATE() AND v.status='active'" : "WHERE DATE(v.payment_date)=CURDATE() AND v.status='active'"));
$st8->execute($sp); $payments_today_count = (int) $st8->fetchColumn();

$journalWhere = [];
$journalParams = [];
if ($company_id) { $journalWhere[] = "company_id=?"; $journalParams[] = $company_id; }
if ($city_id) { $journalWhere[] = "city_id=?"; $journalParams[] = $city_id; }
$journalFilter = $journalWhere ? " AND " . implode(" AND ", $journalWhere) : '';

$jIn = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM client_deposits WHERE status='active' AND type='depot' AND DATE(created_at)=CURDATE(){$journalFilter}");
$jIn->execute($journalParams); $journalDepositEntries = (float) $jIn->fetchColumn();
$jPay = $pdo->prepare("SELECT COALESCE(SUM(v.amount),0) FROM versements v JOIN invoices i ON i.id=v.invoice_id WHERE v.status='active' AND DATE(v.payment_date)=CURDATE()" . ($company_id ? " AND i.company_id=?" : '') . ($city_id ? " AND i.city_id=?" : ''));
$jPay->execute($sp); $journalPaymentEntries = (float) $jPay->fetchColumn();
$auditOutWhere = ["action_type='cancel'", "DATE(created_at)=CURDATE()"];
$auditOutParams = [];
if ($company_id) {
    $auditOutWhere[] = "client_id IN (SELECT id FROM clients WHERE company_id=? " . ($city_id ? "AND city_id=?" : "") . ")";
    $auditOutParams[] = $company_id;
    if ($city_id) $auditOutParams[] = $city_id;
}
$jOut = $pdo->prepare("SELECT COALESCE(SUM(amount_before),0) FROM versement_audit_logs WHERE " . implode(' AND ', $auditOutWhere));
$jOut->execute($auditOutParams); $journalOut = (float) $jOut->fetchColumn();
$cash_in = $journalDepositEntries + $journalPaymentEntries;
$cash_out = $journalOut;
$cash_balance = $cash_in - $cash_out;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Guichet Financier — ESPERANCE H2O</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900}
:root{
  --bg:#0f1726;--surf:#162033;--card:#1b263b;--card2:#22324a;
  --bord:rgba(148,163,184,0.18);
  --neon:#00a86b;--red:#e53935;--orange:#f57c00;--blue:#1976d2;
  --gold:#f9a825;--purple:#a855f7;--cyan:#06b6d4;
  --text:#e8eef8;--text2:#bfd0e4;--muted:#8ea3bd;
  --glow:0 0 26px rgba(50,190,143,.45);--glow-r:0 0 26px rgba(255,53,83,.45);
  --glow-gold:0 0 26px rgba(255,208,96,.4);--glow-cyan:0 0 26px rgba(6,182,212,.4);
  --glow-purple:0 0 26px rgba(168,85,247,.4);
  --fh:'C059','Playfair Display',Georgia,serif;--fb:'Inter','Segoe UI',sans-serif
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--fb);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,.08) 0%,transparent 62%),
             radial-gradient(ellipse 52% 36% at 96% 88%,rgba(168,85,247,.07) 0%,transparent 62%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(50,190,143,.022) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(50,190,143,.022) 1px,transparent 1px);
  background-size:46px 46px}
.wrap{position:relative;z-index:1;max-width:1720px;margin:0 auto;padding:16px 16px 60px}

/* ── Topbar ── */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
  background:rgba(8,20,32,.94);border:1px solid var(--bord);border-radius:18px;
  padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px)}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--purple),var(--blue));
  border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;
  box-shadow:var(--glow-purple);animation:breathe 3s ease infinite;flex-shrink:0}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(168,85,247,.4)}50%{box-shadow:0 0 38px rgba(168,85,247,.85)}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);line-height:1.2}
.brand-txt p{font-size:11px;font-weight:700;color:var(--purple);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px}
.clock-d{font-family:var(--fh);font-size:32px;font-weight:900;color:var(--gold);letter-spacing:5px;text-shadow:0 0 22px rgba(255,208,96,.55);line-height:1}
.clock-sub{font-size:11px;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:5px}
.user-badge{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--purple),var(--blue));color:#fff;padding:11px 22px;border-radius:32px;font-family:var(--fh);font-size:14px;font-weight:900;box-shadow:var(--glow-purple);flex-shrink:0}

/* ── Navbar ── */
.nav-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;background:rgba(8,20,32,.90);border:1px solid var(--bord);border-radius:16px;padding:14px 22px;margin-bottom:18px;backdrop-filter:blur(20px)}
.nb{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:1.5px solid var(--bord);background:rgba(255,208,96,.07);color:var(--text2);font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;letter-spacing:.4px;transition:all .28s}
.nb:hover{background:var(--gold);color:var(--bg);border-color:var(--gold);box-shadow:var(--glow-gold);transform:translateY(-2px)}
.nb.active{background:var(--purple);color:#fff;border-color:var(--purple);box-shadow:var(--glow-purple)}
.nb.back{border-color:rgba(50,190,143,.3);color:var(--neon);background:rgba(50,190,143,.07)}
.nb.back:hover{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow)}

/* ── Alerts ── */
.alert{display:flex;align-items:center;gap:16px;border-radius:14px;padding:16px 22px;margin-bottom:18px;flex-wrap:wrap}
.alert.success{background:rgba(50,190,143,.08);border:1px solid rgba(50,190,143,.25)}
.alert.error{background:rgba(255,53,83,.08);border:1px solid rgba(255,53,83,.25)}
.alert i{font-size:22px;flex-shrink:0}
.alert.success i,.alert.success span{color:var(--neon)}
.alert.error   i,.alert.error   span{color:var(--red)}
.alert span{font-weight:700;font-size:14px}

/* ── KPI strip ── */
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:18px 16px;display:flex;align-items:center;gap:14px;transition:all .3s}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,.35),var(--glow)}
.ks-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.ks-val{font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text);line-height:1}
.ks-lbl{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px}

/* ── SEARCH BOX ── */
.search-section{background:var(--card);border:1px solid var(--bord);border-radius:18px;padding:28px 30px;margin-bottom:20px}
.search-section h2{font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text);margin-bottom:20px;display:flex;align-items:center;gap:10px}
.search-section h2 i{color:var(--purple)}
.search-row{display:flex;gap:12px;flex-wrap:wrap}
.search-input-big{flex:1;min-width:260px;padding:14px 20px;background:rgba(0,0,0,.35);border:2px solid rgba(168,85,247,.3);border-radius:14px;color:var(--text);font-family:var(--fb);font-size:16px;font-weight:600;transition:all .3s}
.search-input-big::placeholder{color:var(--muted);font-size:14px}
.search-input-big:focus{outline:none;border-color:var(--purple);box-shadow:var(--glow-purple);background:rgba(168,85,247,.06)}
.f-select{width:100%;padding:12px 16px;background:rgba(0,0,0,.3);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;margin-bottom:14px;transition:all .3s;appearance:none;-webkit-appearance:none}
.f-select:focus{outline:none;border-color:var(--purple);box-shadow:var(--glow-purple)}
.f-select option{background:#1b263b}
.f-select:disabled{opacity:.35;cursor:not-allowed}
.btn-search{padding:14px 28px;border-radius:14px;border:none;background:linear-gradient(135deg,var(--purple),var(--blue));color:#fff;font-family:var(--fh);font-size:15px;font-weight:900;cursor:pointer;box-shadow:var(--glow-purple);transition:all .3s;display:flex;align-items:center;gap:8px;white-space:nowrap}
.btn-search:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(168,85,247,.5)}

/* ── Search results ── */
.results-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:18px}
.client-card{background:var(--card2);border:1px solid var(--bord);border-radius:14px;padding:16px 18px;cursor:pointer;transition:all .3s;text-decoration:none;display:block}
.client-card:hover{border-color:rgba(168,85,247,.4);transform:translateY(-4px);box-shadow:0 12px 28px rgba(0,0,0,.4),var(--glow-purple)}
.client-card.rich{position:relative;overflow:hidden}
.client-card.rich::after{content:'';position:absolute;inset:auto -40px -40px auto;width:110px;height:110px;background:radial-gradient(circle,rgba(168,85,247,.14),transparent 70%)}
.cc-name{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);margin-bottom:6px}
.cc-phone{font-size:13px;color:var(--muted);font-weight:600}
.cc-loc{font-size:11px;color:var(--muted);margin-top:6px}
.cc-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.cc-avatar{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,var(--purple),var(--blue));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:18px;font-weight:900;color:#fff;flex-shrink:0;box-shadow:var(--glow-purple)}
.cc-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.cc-badge{padding:4px 8px;border-radius:999px;font-size:10px;font-weight:900;letter-spacing:.4px;text-transform:uppercase}
.cc-badge.green{background:rgba(50,190,143,.16);color:var(--neon)}
.cc-badge.red{background:rgba(255,53,83,.16);color:var(--red)}
.cc-badge.gold{background:rgba(255,208,96,.16);color:var(--gold)}
.cc-badge.blue{background:rgba(61,140,255,.16);color:var(--blue)}
.cc-badge.purple{background:rgba(168,85,247,.16);color:var(--purple)}
.cc-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.cc-stat{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:10px}
.cc-stat .k{font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.8px}
.cc-stat .v{font-family:var(--fh);font-size:15px;font-weight:900;margin-top:4px}
.cc-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.06)}
.cc-score{font-size:13px;letter-spacing:1px}
.search-live-meta{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px;color:var(--muted);font-size:12px;font-weight:700}
.search-spinner{display:none;width:16px;height:16px;border:2px solid rgba(168,85,247,.2);border-top-color:var(--purple);border-radius:50%;animation:spin .8s linear infinite}
.search-spinner.show{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Main layout (client sélectionné) ── */
.account-grid{display:grid;grid-template-columns:360px 1fr;gap:18px;align-items:start}

/* ── Panel ── */
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;transition:border-color .3s}
.panel:hover{border-color:rgba(50,190,143,.22)}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(0,0,0,.18)}
.ph-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:10px;letter-spacing:.4px}
.dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;animation:pdot 2.2s infinite}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.dot.n{background:var(--neon);box-shadow:0 0 9px var(--neon)}
.dot.r{background:var(--red);box-shadow:0 0 9px var(--red)}
.dot.g{background:var(--gold);box-shadow:0 0 9px var(--gold)}
.dot.p{background:var(--purple);box-shadow:0 0 9px var(--purple)}
.dot.c{background:var(--cyan);box-shadow:0 0 9px var(--cyan)}
.pbadge{font-size:11px;font-weight:800;padding:5px 14px;border-radius:20px;white-space:nowrap;background:rgba(50,190,143,.12);color:var(--neon)}
.pbadge.r{background:rgba(255,53,83,.12);color:var(--red)}
.pbadge.g{background:rgba(255,208,96,.12);color:var(--gold)}
.pbadge.p{background:rgba(168,85,247,.12);color:var(--purple)}
.pbadge.c{background:rgba(6,182,212,.12);color:var(--cyan)}
.pb{padding:20px 22px}

/* ── Client profile card ── */
.client-avatar{width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--purple),var(--blue));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:24px;font-weight:900;color:#fff;flex-shrink:0;box-shadow:var(--glow-purple)}
.client-info-row{display:flex;align-items:center;gap:16px;margin-bottom:20px}
.balance-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.bal-box{padding:14px;border-radius:12px;text-align:center}
.bal-box.depot{background:rgba(50,190,143,.08);border:1px solid rgba(50,190,143,.2)}
.bal-box.dette{background:rgba(255,53,83,.08);border:1px solid rgba(255,53,83,.2)}
.bal-box.net-pos{background:rgba(50,190,143,.1);border:1px solid rgba(50,190,143,.3)}
.bal-box.net-neg{background:rgba(255,53,83,.1);border:1px solid rgba(255,53,83,.3)}
.bal-lbl{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px}
.bal-val{font-family:var(--fh);font-size:17px;font-weight:900}

/* ── Tabs ── */
.tabs{display:flex;gap:4px;background:rgba(0,0,0,.25);border-radius:12px;padding:4px;margin-bottom:20px}
.tab-btn{flex:1;padding:10px 14px;border-radius:9px;border:none;background:transparent;color:var(--muted);font-family:var(--fh);font-size:13px;font-weight:900;cursor:pointer;transition:all .25s;display:flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap}
.tab-btn:hover{color:var(--text2);background:rgba(255,255,255,.05)}
.tab-btn.active{background:linear-gradient(135deg,var(--purple),var(--blue));color:#fff;box-shadow:var(--glow-purple)}
.tab-content{display:none}.tab-content.active{display:block}

/* ── Invoice list ── */
.invoice-item{background:rgba(0,0,0,.2);border:1px solid var(--bord);border-radius:12px;padding:14px 16px;
  margin-bottom:10px;cursor:pointer;transition:all .25s;position:relative;overflow:hidden}
.invoice-item::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px}
.invoice-item.imp::before{background:var(--red)}
.invoice-item.par::before{background:var(--gold)}
.invoice-item:hover{border-color:rgba(168,85,247,.35);background:rgba(168,85,247,.06);transform:translateX(4px)}
.invoice-item.selected{border-color:var(--purple);background:rgba(168,85,247,.1);box-shadow:var(--glow-purple)}
.inv-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.inv-num{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--text)}
.inv-status{font-size:11px;font-weight:800;padding:3px 10px;border-radius:12px}
.inv-status.imp{background:rgba(255,53,83,.15);color:var(--red)}
.inv-status.par{background:rgba(255,208,96,.15);color:var(--gold)}
.progress-bar{background:rgba(255,255,255,.07);border-radius:99px;height:5px;margin-top:6px;overflow:hidden}
.progress-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--purple),var(--blue));transition:width .5s}
.inv-amounts{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);font-weight:700;margin-top:4px}

/* ── Payment form inline ── */
.pay-form-box{background:rgba(168,85,247,.06);border:1px solid rgba(168,85,247,.25);border-radius:14px;padding:18px;margin-top:14px;display:none}
.pay-form-box.show{display:block}
.f-label{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;display:block;margin-bottom:7px}
.f-input{width:100%;padding:12px 16px;background:rgba(0,0,0,.3);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;margin-bottom:14px;transition:all .3s}
.f-input:focus{outline:none;border-color:var(--purple);box-shadow:var(--glow-purple)}
.amount-hero{width:100%;padding:16px;background:rgba(168,85,247,.08);border:2px solid rgba(168,85,247,.3);border-radius:14px;color:var(--text);font-family:var(--fh);font-size:28px;font-weight:900;text-align:center;letter-spacing:2px;margin-bottom:10px;transition:all .3s}
.amount-hero:focus{outline:none;border-color:var(--purple);box-shadow:var(--glow-purple)}
.amount-hero::placeholder{font-size:16px;letter-spacing:0;color:var(--muted);font-weight:500}

/* ── Quick amounts ── */
.quick-amounts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.qa{padding:7px 14px;border-radius:8px;border:1px solid rgba(168,85,247,.3);background:rgba(168,85,247,.08);
  color:var(--purple);font-family:var(--fh);font-size:12px;font-weight:900;cursor:pointer;transition:all .2s}
.qa:hover,.qa.active{background:var(--purple);color:#fff;box-shadow:var(--glow-purple)}

/* ── Mode paiement ── */
.mode-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.mode-opt input[type=radio]{position:absolute;opacity:0;width:0;height:0}
.mode-opt label{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 8px;border-radius:10px;border:1.5px solid var(--bord);cursor:pointer;font-size:11px;font-weight:700;color:var(--muted);transition:all .2s;text-align:center;background:rgba(0,0,0,.2);position:relative}
.mode-opt label i{font-size:18px}
.mode-opt input[type=radio]:checked+label{border-color:var(--purple);background:rgba(168,85,247,.12);color:var(--purple);box-shadow:var(--glow-purple)}
.mode-opt label:hover{border-color:var(--purple);color:var(--purple)}

/* ── Boutons ── */
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:12px;border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;letter-spacing:.4px;transition:all .28s;text-decoration:none;white-space:nowrap}
.btn-purple{background:rgba(168,85,247,.12);border:1.5px solid rgba(168,85,247,.3);color:var(--purple)}.btn-purple:hover{background:var(--purple);color:#fff;box-shadow:var(--glow-purple)}
.btn-neon  {background:rgba(50,190,143,.12);border:1.5px solid rgba(50,190,143,.3);color:var(--neon)}.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow)}
.btn-gold  {background:rgba(255,208,96,.12);border:1.5px solid rgba(255,208,96,.3);color:var(--gold)}.btn-gold:hover{background:var(--gold);color:var(--bg);box-shadow:var(--glow-gold)}
.btn-red   {background:rgba(255,53,83,.12);border:1.5px solid rgba(255,53,83,.3);color:var(--red)}.btn-red:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r)}
.btn-full  {width:100%;justify-content:center;padding:14px}
.btn-pay   {width:100%;padding:16px;background:linear-gradient(135deg,var(--purple),var(--blue));border:none;border-radius:14px;color:#fff;font-family:var(--fh);font-size:16px;font-weight:900;cursor:pointer;letter-spacing:.5px;box-shadow:var(--glow-purple);transition:all .3s;display:flex;align-items:center;justify-content:center;gap:10px}
.btn-pay:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(168,85,247,.55)}
.btn-pay:disabled{background:rgba(255,255,255,.06);color:var(--muted);box-shadow:none;cursor:not-allowed;transform:none}
.btn-sm{padding:7px 14px;font-size:12px;border-radius:9px}
.btn-xs{padding:5px 10px;font-size:11px;border-radius:7px}

/* ── Récap preview ── */
.recap-box{background:rgba(168,85,247,.07);border:1px solid rgba(168,85,247,.2);border-radius:12px;padding:14px 18px;margin-bottom:14px}
.recap-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:13px;font-weight:700}
.recap-row:not(:last-child){border-bottom:1px solid rgba(255,255,255,.04);padding-bottom:8px;margin-bottom:4px}

/* ── Historique ── */
.txn-item{display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.04);transition:all .2s}
.txn-item:last-child{border-bottom:none}
.txn-item:hover{padding-left:6px}
.txn-ico{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.txn-ico.vers{background:rgba(50,190,143,.12);color:var(--neon)}
.txn-ico.dep {background:rgba(255,208,96,.12);color:var(--gold)}
.txn-ico.app {background:rgba(6,182,212,.12);color:var(--cyan)}
.txn-body{flex:1;min-width:0}
.txn-title{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.txn-sub{font-size:11px;color:var(--muted);font-weight:600;margin-top:2px}
.txn-right{text-align:right;flex-shrink:0}
.txn-amount{font-family:var(--fh);font-size:15px;font-weight:900}
.txn-amount.vers{color:var(--neon)}
.txn-amount.dep {color:var(--gold)}
.txn-amount.app {color:var(--cyan)}
.txn-date{font-size:11px;color:var(--muted);font-weight:600;margin-top:2px}

/* ── No client state ── */
.empty-hero{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-hero i{font-size:56px;display:block;margin-bottom:16px;opacity:.15}
.empty-hero h3{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text2);margin-bottom:8px;opacity:.6}
.empty-hero p{font-size:14px}

/* ── Receipt modal ── */
.modal{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
.modal.open{display:flex}
.modal-bg{position:absolute;inset:0;background:rgba(4,9,14,.85);backdrop-filter:blur(10px)}
.modal-box{position:relative;z-index:1;background:var(--card);border:1px solid var(--bord);border-radius:20px;padding:32px;width:100%;max-width:460px;box-shadow:0 30px 80px rgba(0,0,0,.6)}
.receipt-header{text-align:center;margin-bottom:24px}
.receipt-header i{font-size:48px;color:var(--neon);margin-bottom:10px;display:block}
.receipt-header h2{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text)}
.receipt-header p{font-size:13px;color:var(--muted);font-weight:600;margin-top:4px}
.receipt-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;font-weight:700}
.receipt-row:last-of-type{border-bottom:none}
.receipt-row span:first-child{color:var(--muted)}
.receipt-row span:last-child{color:var(--text)}
.receipt-amount{text-align:center;background:rgba(50,190,143,.08);border:1px solid rgba(50,190,143,.2);border-radius:12px;padding:16px;margin:16px 0}
.receipt-amount .val{font-family:var(--fh);font-size:32px;font-weight:900;color:var(--neon)}
.receipt-amount .lbl{font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-top:4px}
.modal-btns{display:flex;gap:10px;margin-top:20px}
.modal-btns .btn{flex:1;justify-content:center}

/* ── Recent payments sidebar ── */
.recent-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.recent-item:last-child{border-bottom:none}
.recent-name{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text)}
.recent-phone{font-size:11px;color:var(--muted);font-weight:600}
.recent-amt{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--neon);white-space:nowrap}
.recent-date{font-size:10px;color:var(--muted);margin-top:2px;text-align:right}
.mini-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:-6px 0 20px}
.mini-card{background:rgba(13,30,44,.92);border:1px solid var(--bord);border-radius:14px;padding:14px 16px}
.mini-card .val{font-family:var(--fh);font-size:20px;font-weight:900}
.mini-card .lbl{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:1px;margin-top:4px}
.action-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.alert-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:16px 0}
.smart-alert{border-radius:12px;padding:14px;border:1px solid var(--bord);background:rgba(255,255,255,.03)}
.smart-alert.danger{border-color:rgba(255,53,83,.25);background:rgba(255,53,83,.08)}
.smart-alert.warn{border-color:rgba(255,208,96,.25);background:rgba(255,208,96,.08)}
.smart-alert.info{border-color:rgba(61,140,255,.25);background:rgba(61,140,255,.08)}
.smart-alert .ttl{font-family:var(--fh);font-size:13px;font-weight:900;margin:8px 0 4px}
.smart-alert .txt{font-size:12px;color:var(--text2);line-height:1.45}
.profile-metrics{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0}
.metric-chip{border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px;background:rgba(255,255,255,.03)}
.metric-chip .k{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:1px}
.metric-chip .v{font-family:var(--fh);font-size:18px;font-weight:900;margin-top:5px}
.score-box{margin-top:14px;padding:14px;border-radius:12px;background:linear-gradient(135deg,rgba(61,140,255,.12),rgba(168,85,247,.12));border:1px solid rgba(168,85,247,.22)}
.score-stars{font-size:20px;letter-spacing:2px}
.badge-line{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.status-badge{padding:6px 10px;border-radius:999px;font-size:11px;font-weight:900;letter-spacing:.4px;text-transform:uppercase}
.status-badge.green{background:rgba(50,190,143,.16);color:var(--neon)}
.status-badge.red{background:rgba(255,53,83,.16);color:var(--red)}
.status-badge.yellow{background:rgba(255,208,96,.16);color:var(--gold)}
.status-badge.blue{background:rgba(61,140,255,.16);color:var(--blue)}
.journal-box{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.journal-item{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:15px}
.journal-item .jv{font-family:var(--fh);font-size:22px;font-weight:900;margin-top:6px}
.tx-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.tx-pill{padding:3px 8px;border-radius:999px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.4px}
.tx-pill.active{background:rgba(50,190,143,.14);color:var(--neon)}
.tx-pill.cancelled{background:rgba(255,53,83,.14);color:var(--red)}
.audit-item{padding:12px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.audit-item:last-child{border-bottom:none}
.danger-btn{background:rgba(255,53,83,.08)!important;color:var(--red)!important;border:1px solid rgba(255,53,83,.2)!important}
.ghost-btn{background:rgba(255,255,255,.06)!important;color:var(--text2)!important;border:1px solid rgba(255,255,255,.08)!important}

@media(max-width:1100px){.account-grid{grid-template-columns:1fr}.mini-strip{grid-template-columns:1fr 1fr}.journal-box{grid-template-columns:1fr}}
@media(max-width:700px){.kpi-strip,.mini-strip,.profile-metrics{grid-template-columns:1fr 1fr}.mode-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="wrap">

<!-- ══ TOPBAR ══ -->
<div class="topbar">
  <div class="brand">
    <div class="brand-ico"><i class="fas fa-university"></i></div>
    <div class="brand-txt"><h1>Guichet Financier</h1><p>ESPERANCE H2O · Versements & Dépôts</p></div>
  </div>
  <div style="text-align:center;flex-shrink:0">
    <div class="clock-d" id="clk">--:--:--</div>
    <div class="clock-sub" id="clkd">Chargement…</div>
  </div>
  <div class="user-badge"><i class="fas fa-user-tie"></i>&nbsp;<?= htmlspecialchars($user_name) ?></div>
</div>

<!-- ══ NAVBAR ══ -->
<div class="nav-bar">
  <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>?company_id=<?= $company_id ?>&city_id=<?= $city_id ?>" class="nb back"><i class="fas fa-arrow-left"></i> Caisse</a>
  <a href="versement.php?company_id=<?= $company_id ?>&city_id=<?= $city_id ?>" class="nb active"><i class="fas fa-university"></i> Guichet</a>
  <a href="<?= project_url('finance/facture.php') ?>" class="nb"><i class="fas fa-file-invoice"></i> Factures</a>
  <a href="<?= project_url('clients/clients_erp_pro.php') ?>" class="nb"><i class="fas fa-users"></i> Clients</a>
  <a href="<?= project_url('dashboard/index.php') ?>" class="nb" style="margin-left:auto"><i class="fas fa-home"></i> Accueil</a>
</div>

<!-- ══ FLASH ══ -->
<?php if ($flash_text): ?>
<div class="alert <?= htmlspecialchars($flash_type) ?>">
  <i class="fas <?= $flash_type==='success'?'fa-circle-check':'fa-circle-exclamation' ?>"></i>
  <span><?= htmlspecialchars($flash_text) ?></span>
</div>
<?php endif; ?>

<!-- ══ KPI ══ -->
<div class="kpi-strip">
  <div class="ks">
    <div class="ks-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="fas fa-receipt"></i></div>
    <div><div class="ks-val" style="color:var(--purple)"><?= number_format($kv['nb']) ?></div><div class="ks-lbl">Versements total</div></div>
  </div>
  <div class="ks">
    <div class="ks-ico" style="background:rgba(50,190,143,.14);color:var(--neon)"><i class="fas fa-coins"></i></div>
    <div><div class="ks-val" style="color:var(--neon)"><?= number_format($kv['total'],0,',',' ') ?></div><div class="ks-lbl">Total encaissé (FCFA)</div></div>
  </div>
  <div class="ks">
    <div class="ks-ico" style="background:rgba(255,208,96,.14);color:var(--gold)"><i class="fas fa-piggy-bank"></i></div>
    <div><div class="ks-val" style="color:var(--gold)"><?= number_format($total_depots,0,',',' ') ?></div><div class="ks-lbl">Solde dépôts (FCFA)</div></div>
  </div>
  <div class="ks">
    <div class="ks-ico" style="background:rgba(255,53,83,.14);color:var(--red)"><i class="fas fa-file-invoice-dollar"></i></div>
    <div><div class="ks-val" style="color:var(--red)"><?= number_format($creances_total,0,',',' ') ?></div><div class="ks-lbl">Créances totales (FCFA)</div></div>
  </div>
</div>

<div class="mini-strip">
  <div class="mini-card"><div class="val" style="color:var(--blue)"><?= number_format($active_clients) ?></div><div class="lbl">Clients actifs</div></div>
  <div class="mini-card"><div class="val" style="color:var(--gold)"><?= number_format($deposits_today,0,',',' ') ?></div><div class="lbl">Dépôts aujourd'hui</div></div>
  <div class="mini-card"><div class="val" style="color:var(--neon)"><?= number_format($payments_today_count) ?></div><div class="lbl">Paiements du jour</div></div>
  <div class="mini-card"><div class="val" style="color:var(--purple)"><?= number_format($new_clients_today) ?></div><div class="lbl">Nouveaux clients</div></div>
</div>

<div class="panel" style="margin-bottom:20px">
  <div class="ph">
    <div class="ph-title"><div class="dot n"></div> Journal caisse</div>
    <span class="pbadge p"><?= date('d/m/Y') ?></span>
  </div>
  <div class="pb">
    <div class="journal-box">
      <div class="journal-item">
        <div class="bal-lbl">Entrées</div>
        <div class="jv" style="color:var(--neon)"><?= number_format($cash_in,0,',',' ') ?> FCFA</div>
      </div>
      <div class="journal-item">
        <div class="bal-lbl">Sorties</div>
        <div class="jv" style="color:var(--red)"><?= number_format($cash_out,0,',',' ') ?> FCFA</div>
      </div>
      <div class="journal-item">
        <div class="bal-lbl">Solde</div>
        <div class="jv" style="color:<?= $cash_balance >= 0 ? 'var(--gold)' : 'var(--red)' ?>"><?= number_format($cash_balance,0,',',' ') ?> FCFA</div>
      </div>
    </div>
  </div>
</div>

<!-- ══ RECHERCHE CLIENT ══ -->
<div class="search-section">
  <h2><i class="fas fa-search"></i> Identifier le client</h2>
  <form method="get" id="search-form">
    <input type="hidden" name="company_id" value="<?= $company_id ?>">
    <input type="hidden" name="city_id" value="<?= $city_id ?>">
    <div class="search-row" style="margin-bottom:14px">
      <select name="company_id" class="f-select" style="flex:1;max-width:240px;margin-bottom:0" onchange="this.form.submit()">
        <option value="">— Société —</option>
        <?php foreach($companies as $c): ?><option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="city_id" class="f-select" style="flex:1;max-width:200px;margin-bottom:0" <?= !$company_id?'disabled':'' ?> onchange="this.form.submit()">
        <option value="">— Ville —</option>
        <?php foreach($cities as $v): ?><option value="<?= $v['id'] ?>" <?= $city_id==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="q" value="<?= htmlspecialchars($q_search) ?>" class="search-input-big" id="client-search-input" placeholder="🔍 Nom, téléphone, ID, ville, entreprise, email…" autofocus autocomplete="off">
      <button type="submit" class="btn-search"><i class="fas fa-search"></i> Rechercher</button>
    </div>
  </form>
  <div class="search-live-meta">
    <span id="search-live-label">Recherche visuelle en direct</span>
    <span class="search-spinner" id="search-spinner"></span>
  </div>

  <?php if ($q_search && empty($search_results)): ?>
  <div id="search-empty" style="text-align:center;padding:24px;color:var(--muted);font-weight:700">
    <i class="fas fa-user-slash" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>
    Aucun client trouvé pour "<?= htmlspecialchars($q_search) ?>"
  </div>
  <?php elseif (!empty($search_results)): ?>
  <div class="results-grid" id="search-results">
    <?php foreach($search_results as $r): ?>
    <a href="versement.php?client_id=<?= $r['id'] ?>&company_id=<?= $r['company_id'] ?>&city_id=<?= $r['city_id'] ?>" class="client-card">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--purple),var(--blue));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:16px;font-weight:900;color:#fff;flex-shrink:0">
          <?= strtoupper(mb_substr($r['name'],0,1)) ?>
        </div>
        <div>
          <div class="cc-name"><?= htmlspecialchars($r['name']) ?></div>
          <div class="cc-phone"><i class="fas fa-phone"></i> <?= htmlspecialchars($r['phone']) ?></div>
        </div>
      </div>
      <div class="cc-loc" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.06)">
        <i class="fas fa-hashtag" style="color:var(--gold)"></i> ID <?= (int) $r['id'] ?>
        &nbsp;·&nbsp; <i class="fas fa-building" style="color:var(--purple)"></i> <?= htmlspecialchars($r['company_name']) ?>
        &nbsp;·&nbsp; <i class="fas fa-location-dot" style="color:var(--cyan)"></i> <?= htmlspecialchars($r['city_name']) ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <div id="search-empty" style="display:none;text-align:center;padding:24px;color:var(--muted);font-weight:700">
    <i class="fas fa-user-slash" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>
    Aucun client trouvé.
  </div>
  <?php else: ?>
  <div class="results-grid" id="search-results" style="display:none"></div>
  <div id="search-empty" style="display:none;text-align:center;padding:24px;color:var(--muted);font-weight:700">
    <i class="fas fa-user-slash" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>
    Aucun client trouvé.
  </div>
  <?php endif; ?>
</div>

<!-- ══ COMPTE CLIENT ══ -->
<?php if ($client): ?>
<div class="account-grid">

  <!-- ── Colonne gauche : profil ── -->
  <div>

    <!-- Carte client -->
    <div class="panel" style="margin-bottom:18px">
      <div class="ph">
        <div class="ph-title"><div class="dot p"></div> Compte client</div>
        <a href="versement.php?company_id=<?= $company_id ?>&city_id=<?= $city_id ?>" class="btn btn-sm" style="background:rgba(255,255,255,.06);color:var(--muted);border:1px solid rgba(255,255,255,.1);font-size:11px"><i class="fas fa-xmark"></i></a>
      </div>
      <div class="pb">
        <div class="client-info-row">
          <div class="client-avatar"><?= strtoupper(mb_substr($client['name'],0,1)) ?></div>
          <div>
            <div style="font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text)"><?= htmlspecialchars($client['name']) ?></div>
            <div style="font-size:13px;color:var(--muted);font-weight:600;margin-top:3px"><i class="fas fa-phone"></i> <?= htmlspecialchars($client['phone']) ?></div>
            <div style="font-size:11px;color:var(--muted);font-weight:600;margin-top:3px">
              <i class="fas fa-building" style="color:var(--purple)"></i> <?= htmlspecialchars($client['company_name']) ?>
              &nbsp;·&nbsp; <i class="fas fa-location-dot" style="color:var(--cyan)"></i> <?= htmlspecialchars($client['city_name']) ?>
            </div>
            <div class="badge-line">
              <span class="status-badge <?= $solde_depot > 0 ? 'green' : 'yellow' ?>"><?= $solde_depot > 0 ? 'Solde positif' : 'Dépôt faible' ?></span>
              <span class="status-badge <?= $total_dette > 0 ? 'red' : 'green' ?>"><?= $total_dette > 0 ? 'Dette' : 'Compte sain' ?></span>
              <?php if ($client_stats['total_deposits'] >= 500000): ?><span class="status-badge blue">Gros client</span><?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Soldes -->
        <div class="balance-grid">
          <div class="bal-box depot">
            <div class="bal-lbl"><i class="fas fa-piggy-bank"></i> Solde dépôts</div>
            <div class="bal-val" style="color:var(--neon)"><?= number_format($solde_depot,0,',',' ') ?></div>
            <div style="font-size:10px;color:var(--muted);font-weight:600;margin-top:3px">FCFA</div>
          </div>
          <div class="bal-box dette">
            <div class="bal-lbl"><i class="fas fa-file-invoice-dollar"></i> Total dettes</div>
            <div class="bal-val" style="color:var(--red)"><?= number_format($total_dette,0,',',' ') ?></div>
            <div style="font-size:10px;color:var(--muted);font-weight:600;margin-top:3px">FCFA</div>
          </div>
        </div>

        <?php $solde_net = $solde_depot - $total_dette; ?>
        <div class="bal-box <?= $solde_net >= 0?'net-pos':'net-neg' ?>" style="text-align:center;padding:12px">
          <div class="bal-lbl">Solde net (dépôts − dettes)</div>
          <div class="bal-val" style="color:<?= $solde_net>=0?'var(--neon)':'var(--red)' ?>;font-size:20px">
            <?= ($solde_net>=0?'+':'') . number_format($solde_net,0,',',' ') ?> FCFA
          </div>
        </div>

        <div class="score-box">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:center">
            <div>
              <div class="bal-lbl">Score client</div>
              <div class="score-stars"><?= str_repeat('★', (int) $client_stats['score']) . str_repeat('☆', 5 - (int) $client_stats['score']) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-family:var(--fh);font-size:15px;font-weight:900"><?= htmlspecialchars($client_stats['status_label']) ?></div>
              <div style="font-size:11px;color:var(--muted)">Historique complet</div>
            </div>
          </div>
        </div>

        <div class="profile-metrics">
          <div class="metric-chip"><div class="k">Total dépôts</div><div class="v" style="color:var(--gold)"><?= number_format($client_stats['total_deposits'],0,',',' ') ?> FCFA</div></div>
          <div class="metric-chip"><div class="k">Total consommé</div><div class="v" style="color:var(--neon)"><?= number_format($client_stats['total_consumed'],0,',',' ') ?> FCFA</div></div>
          <div class="metric-chip"><div class="k">Total achats</div><div class="v"><?= number_format($client_stats['total_purchases'],0,',',' ') ?> FCFA</div></div>
          <div class="metric-chip"><div class="k">Fréquence achat</div><div class="v"><?= number_format($client_stats['frequency'],1,',',' ') ?>/mois</div></div>
          <div class="metric-chip"><div class="k">Dernière activité</div><div class="v" style="font-size:15px"><?= $client_stats['last_activity'] ? date('d/m/Y', strtotime($client_stats['last_activity'])) : 'Aucune' ?></div></div>
          <div class="metric-chip"><div class="k">Client depuis</div><div class="v" style="font-size:15px"><?= $client_stats['first_seen'] ? date('M Y', strtotime($client_stats['first_seen'])) : 'N/A' ?></div></div>
        </div>

        <?php if (!empty($client_alerts)): ?>
        <div class="alert-grid">
          <?php foreach ($client_alerts as $alert): ?>
          <div class="smart-alert <?= $alert['type'] ?>">
            <i class="fas <?= $alert['icon'] ?>" style="font-size:18px"></i>
            <div class="ttl"><?= htmlspecialchars($alert['title']) ?></div>
            <div class="txt"><?= htmlspecialchars($alert['text']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($nb_factures_impayees > 0): ?>
        <div style="margin-top:14px;background:rgba(255,53,83,.07);border:1px solid rgba(255,53,83,.2);border-radius:10px;padding:12px;display:flex;align-items:center;gap:10px">
          <i class="fas fa-triangle-exclamation" style="color:var(--red);font-size:18px"></i>
          <div>
            <div style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--red)"><?= $nb_factures_impayees ?> facture(s) impayée(s)</div>
            <div style="font-size:11px;color:var(--muted);font-weight:600">Reste : <?= number_format($total_dette,0,',',' ') ?> FCFA</div>
          </div>
        </div>
        <?php else: ?>
        <div style="margin-top:14px;background:rgba(50,190,143,.07);border:1px solid rgba(50,190,143,.2);border-radius:10px;padding:12px;display:flex;align-items:center;gap:10px">
          <i class="fas fa-circle-check" style="color:var(--neon);font-size:18px"></i>
          <div style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--neon)">Aucune créance en cours !</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Derniers paiements globaux -->
    <div class="panel">
      <div class="ph"><div class="ph-title"><div class="dot n"></div> Derniers paiements</div></div>
      <div class="pb" style="padding-top:12px;padding-bottom:12px">
        <?php if (empty($recent_payments)): ?>
        <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Aucun paiement récent</div>
        <?php else: ?>
        <?php foreach($recent_payments as $rp): ?>
        <div class="recent-item">
          <div>
            <div class="recent-name"><?= htmlspecialchars($rp['name']) ?></div>
            <div class="recent-phone"><?= htmlspecialchars($rp['phone']) ?></div>
          </div>
          <div style="text-align:right">
            <div class="recent-amt"><?= number_format($rp['amount'],0,',',' ') ?> FCFA</div>
            <div class="recent-date"><?= date('d/m H:i',strtotime($rp['payment_date'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /left col -->

  <!-- ── Colonne droite : opérations ── -->
  <div class="panel">
    <div class="ph">
      <div class="ph-title"><div class="dot p"></div> Opérations</div>
      <span class="pbadge p"><?= htmlspecialchars($client['name']) ?></span>
    </div>
    <div class="pb">
      <div class="action-row">
        <button type="button" class="btn btn-neon btn-sm" onclick="activateTab('depot', this)"><i class="fas fa-bolt"></i> Dépôt rapide</button>
        <button type="button" class="btn btn-gold btn-sm" onclick="activateTab('payer', this)"><i class="fas fa-credit-card"></i> Paiement rapide</button>
        <a href="<?= project_url('finance/facture.php') ?>?client_id=<?= $client_id ?>" class="btn btn-sm ghost-btn"><i class="fas fa-file-circle-plus"></i> Nouvelle facture</a>
        <a href="<?= project_url('clients/client_edit.php') ?>?id=<?= $client_id ?>" class="btn btn-sm ghost-btn"><i class="fas fa-user-plus"></i> Nouveau client</a>
      </div>

      <!-- Tabs -->
      <div class="tabs" id="main-tabs">
        <button class="tab-btn <?= $active_tab==='payer'?'active':'' ?>" onclick="switchTab(event,'payer')"><i class="fas fa-hand-holding-dollar"></i> Payer créance</button>
        <button class="tab-btn <?= $active_tab==='depot'?'active':'' ?>" onclick="switchTab(event,'depot')"><i class="fas fa-piggy-bank"></i> Dépôt</button>
        <button class="tab-btn <?= $active_tab==='historique'?'active':'' ?>" onclick="switchTab(event,'historique')"><i class="fas fa-clock-rotate-left"></i> Historique <span style="background:rgba(168,85,247,.2);color:var(--purple);padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px"><?= count($client_history) ?></span></button>
        <button class="tab-btn <?= $active_tab==='audit'?'active':'' ?>" onclick="switchTab(event,'audit')"><i class="fas fa-shield-halved"></i> Audit <span style="background:rgba(255,53,83,.15);color:var(--red);padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px"><?= count($client_audit_logs) ?></span></button>
      </div>

      <!-- ─ TAB : PAYER CRÉANCE ─ -->
      <div class="tab-content <?= $active_tab==='payer'?'active':'' ?>" id="tab-payer">
        <?php if (empty($client_invoices)): ?>
        <div class="empty-hero">
          <i class="fas fa-circle-check"></i>
          <h3>Aucune créance</h3>
          <p>Ce client n'a aucune facture impayée.</p>
        </div>
        <?php else: ?>

        <?php if ($solde_depot > 0): ?>
        <div style="background:rgba(50,190,143,.07);border:1px solid rgba(50,190,143,.2);border-radius:12px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:10px">
            <i class="fas fa-piggy-bank" style="color:var(--neon);font-size:20px"></i>
            <div>
              <div style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--neon)">Solde disponible : <?= number_format($solde_depot,0,',',' ') ?> FCFA</div>
              <div style="font-size:11px;color:var(--muted);font-weight:600">Vous pouvez l'appliquer sur une facture</div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">
          Cliquez sur une facture pour payer
        </div>

        <?php foreach($client_invoices as $inv):
          $reste_inv = max(0, $inv['total'] - $inv['paid']);
          $pct = $inv['total']>0 ? min(100,round($inv['paid']/$inv['total']*100)) : 0;
          $is_par = $inv['paid'] > 0;
          $cls = $is_par ? 'par' : 'imp';
        ?>
        <div class="invoice-item <?= $cls ?> <?= $invoice_id==$inv['id']?'selected':'' ?>"
             onclick="selectInvoice(this, <?= $inv['id'] ?>, <?= $reste_inv ?>, '<?= date('d/m/Y',strtotime($inv['created_at'])) ?>')">
          <div class="inv-row">
            <span class="inv-num"><i class="fas fa-file-invoice"></i> Facture #<?= $inv['id'] ?></span>
            <span class="inv-status <?= $cls ?>"><?= $is_par?'Partielle':'Impayée' ?></span>
          </div>
          <div class="inv-amounts">
            <span>Total : <strong style="color:var(--text)"><?= number_format($inv['total'],0,',',' ') ?> FCFA</strong></span>
            <span>Versé : <strong style="color:var(--neon)"><?= number_format($inv['paid'],0,',',' ') ?> FCFA</strong></span>
            <span>Reste : <strong style="color:var(--red)"><?= number_format($reste_inv,0,',',' ') ?> FCFA</strong></span>
          </div>
          <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
          <div style="font-size:10px;color:var(--muted);font-weight:600;margin-top:6px;text-align:right"><?= $pct ?>% payé · <?= date('d/m/Y',strtotime($inv['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>

        <!-- Formulaire de paiement (apparaît à la sélection) -->
        <div class="pay-form-box <?= $invoice_id?'show':'' ?>" id="pay-form-box">
          <div style="font-family:var(--fh);font-size:14px;font-weight:900;color:var(--purple);margin-bottom:14px;display:flex;align-items:center;gap:8px">
            <i class="fas fa-receipt"></i>
            <span id="pay-form-title">Payer la Facture</span>
            <span id="pay-form-reste" style="margin-left:auto;font-size:12px;background:rgba(255,53,83,.12);color:var(--red);padding:3px 10px;border-radius:10px"></span>
          </div>
          <form method="post" id="pay-form">
            <input type="hidden" name="action" value="payer_facture">
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <input type="hidden" name="company_id" value="<?= $company_id ?>">
            <input type="hidden" name="city_id" value="<?= $city_id ?>">
            <input type="hidden" name="invoice_id" id="hid-invoice" value="<?= $invoice_id ?>">

            <label class="f-label">Montant (FCFA)</label>
            <input type="number" name="amount" id="pay-amount" class="amount-hero" min="1" step="1" placeholder="0" oninput="updateRecap()" required>

            <div class="quick-amounts" id="quick-btns"></div>

            <label class="f-label" style="margin-top:4px">Mode de paiement</label>
            <div class="mode-grid">
              <?php foreach([['Espèce','fa-money-bill-wave'],['Virement bancaire','fa-building-columns'],['Chèque','fa-file-invoice'],['Mobile Money','fa-mobile-screen']] as [$m,$ic]): ?>
              <div class="mode-opt">
                <input type="radio" name="payment_mode" id="m_<?= md5($m) ?>" value="<?= $m ?>" <?= $m==='Espèce'?'checked':'' ?>>
                <label for="m_<?= md5($m) ?>"><i class="fas <?= $ic ?>"></i><?= $m ?></label>
              </div>
              <?php endforeach; ?>
            </div>

            <label class="f-label">Note (optionnel)</label>
            <input type="text" name="note" class="f-input" placeholder="Ex : 2ème versement…">

            <div class="recap-box" id="recap-box" style="display:none">
              <div class="recap-row"><span>Montant versé</span><span id="recap-amount" style="color:var(--purple);font-family:var(--fh);font-weight:900">—</span></div>
              <div class="recap-row"><span>Reste après</span><span id="recap-reste" style="font-family:var(--fh);font-weight:900">—</span></div>
              <div class="recap-row"><span>Statut facture</span><span id="recap-status" style="font-weight:800">—</span></div>
            </div>

            <?php if ($solde_depot > 0): ?>
            <div style="background:rgba(50,190,143,.06);border:1px solid rgba(50,190,143,.15);border-radius:10px;padding:12px;margin-bottom:12px">
              <div style="font-size:12px;font-weight:700;color:var(--neon);margin-bottom:8px"><i class="fas fa-piggy-bank"></i> Utiliser le solde dépôts (<?= number_format($solde_depot,0,',',' ') ?> FCFA)</div>
              <button type="button" class="btn btn-neon btn-sm btn-full" onclick="useDepotBalance()">
                <i class="fas fa-arrow-right-arrow-left"></i> Appliquer le solde sur cette facture
              </button>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-pay" id="btn-pay" disabled>
              <i class="fas fa-lock"></i> Valider le versement
            </button>
          </form>

          <!-- Formulaire application dépôt (caché) -->
          <form method="post" id="apply-depot-form" style="display:none">
            <input type="hidden" name="action" value="appliquer_depot">
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <input type="hidden" name="company_id" value="<?= $company_id ?>">
            <input type="hidden" name="city_id" value="<?= $city_id ?>">
            <input type="hidden" name="invoice_id" id="apply-invoice-id" value="">
            <input type="hidden" name="amount" id="apply-amount" value="">
          </form>
        </div>

        <?php endif; ?>
      </div>

      <!-- ─ TAB : DÉPÔT ─ -->
      <div class="tab-content <?= $active_tab==='depot'?'active':'' ?>" id="tab-depot">
        <div style="background:rgba(255,208,96,.06);border:1px solid rgba(255,208,96,.15);border-radius:12px;padding:16px;margin-bottom:20px">
          <div style="display:flex;align-items:center;gap:10px">
            <i class="fas fa-info-circle" style="color:var(--gold);font-size:18px;flex-shrink:0"></i>
            <div style="font-size:13px;color:var(--text2);font-weight:600;line-height:1.6">
              Le client dépose de l'argent <strong style="color:var(--gold)">sans facture</strong>.
              Son solde sera crédité et il pourra récupérer ses colis ou solder ses factures plus tard.
            </div>
          </div>
        </div>

        <form method="post">
          <input type="hidden" name="action" value="depot">
          <input type="hidden" name="client_id" value="<?= $client_id ?>">
          <input type="hidden" name="company_id" value="<?= $company_id ?>">
          <input type="hidden" name="city_id" value="<?= $city_id ?>">

          <label class="f-label">Montant du dépôt (FCFA)</label>
          <input type="number" name="amount" id="depot-amount" class="amount-hero" min="1" step="1" placeholder="0"
                 oninput="updateDepotPreview(this.value)" required>

          <div class="quick-amounts">
            <?php foreach([5000,10000,25000,50000,100000,200000] as $h): ?>
            <button type="button" class="qa" onclick="setDepot(<?= $h ?>)"><?= number_format($h,0,',',' ') ?></button>
            <?php endforeach; ?>
          </div>

          <div id="depot-preview" style="background:rgba(255,208,96,.07);border:1px solid rgba(255,208,96,.2);border-radius:12px;padding:14px;margin-bottom:14px;display:none">
            <div class="recap-row"><span>Dépôt</span><span id="dp-amount" style="color:var(--gold);font-family:var(--fh);font-weight:900">—</span></div>
            <div class="recap-row"><span>Nouveau solde</span><span id="dp-solde" style="color:var(--neon);font-family:var(--fh);font-weight:900">—</span></div>
            <?php if ($total_dette > 0): ?>
            <div class="recap-row"><span>Couverture des dettes</span><span id="dp-cover" style="font-family:var(--fh);font-weight:900">—</span></div>
            <?php endif; ?>
          </div>

          <label class="f-label">Mode de paiement</label>
          <div class="mode-grid">
            <?php foreach([['Espèce','fa-money-bill-wave'],['Virement bancaire','fa-building-columns'],['Chèque','fa-file-invoice'],['Mobile Money','fa-mobile-screen']] as [$m,$ic]): ?>
            <div class="mode-opt">
              <input type="radio" name="payment_mode" id="dm_<?= md5($m) ?>" value="<?= $m ?>" <?= $m==='Espèce'?'checked':'' ?>>
              <label for="dm_<?= md5($m) ?>"><i class="fas <?= $ic ?>"></i><?= $m ?></label>
            </div>
            <?php endforeach; ?>
          </div>

          <label class="f-label">Note (optionnel)</label>
          <input type="text" name="note" class="f-input" placeholder="Ex : Dépôt pour commande de la semaine…">

          <button type="submit" id="btn-depot" class="btn-pay" style="background:linear-gradient(135deg,var(--gold),var(--orange));box-shadow:var(--glow-gold)" disabled>
            <i class="fas fa-lock"></i> Confirmer le dépôt
          </button>
        </form>
      </div>

      <!-- ─ TAB : HISTORIQUE ─ -->
      <div class="tab-content <?= $active_tab==='historique'?'active':'' ?>" id="tab-historique">
        <?php if (empty($client_history)): ?>
        <div class="empty-hero">
          <i class="fas fa-clock-rotate-left"></i>
          <h3>Aucun historique</h3>
          <p>Aucune transaction enregistrée pour ce client.</p>
        </div>
        <?php else: ?>
        <div style="margin-bottom:14px">
          <input type="text" class="f-input" id="hist-search" placeholder="🔍 Rechercher dans l'historique…" style="margin-bottom:0" oninput="filterHistory(this.value)">
        </div>
        <div id="hist-list">
        <?php foreach($client_history as $h):
          $is_dep = $h['source']==='depot';
          $is_app = ($h['source']==='depot' && $h['depot_type']==='application');
          if ($is_app) { $ico='app'; $ic='fa-arrow-right-arrow-left'; $label='Application sur Facture #'.$h['invoice_id']; }
          elseif($is_dep) { $ico='dep'; $ic='fa-piggy-bank'; $label='Dépôt'; }
          else { $ico='vers'; $ic='fa-receipt'; $label='Versement — Facture #'.$h['invoice_id']; }
        ?>
        <div class="txn-item" data-search="<?= strtolower(htmlspecialchars($label.' '.($h['reference']??'').' '.($h['payment_mode']??'').' '.($h['cashier_name']??''))) ?>">
          <div class="txn-ico <?= $ico ?>"><i class="fas <?= $ic ?>"></i></div>
          <div class="txn-body">
            <div class="txn-title"><?= $label ?></div>
            <div class="txn-sub">
              <?= htmlspecialchars($h['payment_mode']??'') ?>
              <?php if($h['reference']): ?> · <span style="font-family:monospace;font-size:10px;background:rgba(50,190,143,.1);color:var(--neon);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($h['reference']) ?></span><?php endif; ?>
              <?php if($h['note']): ?> · <em><?= htmlspecialchars($h['note']) ?></em><?php endif; ?>
            </div>
            <div class="tx-tools">
              <span class="tx-pill <?= $h['status']==='cancelled'?'cancelled':'active' ?>"><?= $h['status']==='cancelled'?'annulé':'actif' ?></span>
              <span class="tx-pill active">Encaissement par <?= htmlspecialchars($h['cashier_name'] ?: $h['actor_name'] ?: 'N/A') ?></span>
              <span class="tx-pill active">Guichet <?= htmlspecialchars($h['cashier_city'] ?: $client['city_name']) ?></span>
            </div>
            <?php if(!empty($h['updated_note'])): ?><div style="font-size:11px;color:var(--gold);margin-top:6px">Modification: <?= htmlspecialchars($h['updated_note']) ?></div><?php endif; ?>
            <?php if(!empty($h['cancelled_note'])): ?><div style="font-size:11px;color:var(--red);margin-top:6px">Annulation: <?= htmlspecialchars($h['cancelled_note']) ?></div><?php endif; ?>
          </div>
          <div class="txn-right">
            <div class="txn-amount <?= $ico ?>"><?= ($is_app?'−':'+') . number_format($h['amount'],0,',',' ') ?> FCFA</div>
            <div class="txn-date"><?= date('d/m/Y H:i',strtotime($h['date'])) ?></div>
            <?php if($h['status'] !== 'cancelled' && !$is_app): ?>
            <div class="tx-tools" style="justify-content:flex-end">
              <a target="_blank" class="btn btn-sm ghost-btn" href="receipt_pdf.php?type=<?= $is_dep ? 'depot' : 'versement' ?>&id=<?= (int) $h['id'] ?>"><i class="fas fa-file-pdf"></i></a>
              <button type="button" class="btn btn-sm ghost-btn" onclick="editTransaction('<?= $is_dep ? 'depot' : 'versement' ?>',<?= (int) $h['id'] ?>,<?= (float) $h['amount'] ?>)"><i class="fas fa-pen"></i></button>
              <button type="button" class="btn btn-sm danger-btn" onclick="cancelTransaction('<?= $is_dep ? 'depot' : 'versement' ?>',<?= (int) $h['id'] ?>,'<?= htmlspecialchars($h['reference'] ?: ('#'.$h['id']), ENT_QUOTES) ?>')"><i class="fas fa-ban"></i></button>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
        <div style="margin-top:16px;text-align:center;font-size:12px;color:var(--muted);font-weight:700">
          Total versements : <strong style="color:var(--neon)"><?= number_format(array_sum(array_column(array_filter($client_history,fn($x)=>$x['source']==='versement' && ($x['status'] ?? 'active')==='active'),'amount')),0,',',' ') ?> FCFA</strong>
          &nbsp;·&nbsp; Total dépôts : <strong style="color:var(--gold)"><?= number_format(array_sum(array_column(array_filter($client_history,fn($x)=>$x['source']==='depot'&&$x['depot_type']==='depot' && ($x['status'] ?? 'active')==='active'),'amount')),0,',',' ') ?> FCFA</strong>
        </div>
        <?php endif; ?>
      </div>

      <div class="tab-content <?= $active_tab==='audit'?'active':'' ?>" id="tab-audit">
        <?php if (empty($client_audit_logs)): ?>
        <div class="empty-hero">
          <i class="fas fa-shield-halved"></i>
          <h3>Aucun audit</h3>
          <p>Aucune action sensible enregistrée pour ce client.</p>
        </div>
        <?php else: ?>
        <?php foreach($client_audit_logs as $log): ?>
        <div class="audit-item">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
            <div>
              <div style="font-family:var(--fh);font-size:14px;font-weight:900">
                <?= strtoupper($log['action_type']) ?> · <?= strtoupper($log['tx_type']) ?> #<?= (int) $log['tx_id'] ?>
              </div>
              <div style="font-size:12px;color:var(--text2);margin-top:4px">
                Par <?= htmlspecialchars($log['actor_name'] ?: 'N/A') ?>
                · Caissier <?= htmlspecialchars($log['cashier_name'] ?: 'N/A') ?>
                · Guichet <?= htmlspecialchars($log['cashier_city'] ?: $client['city_name']) ?>
              </div>
              <?php if($log['note']): ?><div style="font-size:12px;color:var(--muted);margin-top:4px"><?= htmlspecialchars($log['note']) ?></div><?php endif; ?>
            </div>
            <div style="text-align:right">
              <div style="font-size:11px;color:var(--muted)"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></div>
              <?php if($log['amount_before'] !== null || $log['amount_after'] !== null): ?>
              <div style="font-family:var(--fh);font-size:13px;font-weight:900;color:var(--gold)">
                <?= $log['amount_before'] !== null ? number_format((float)$log['amount_before'],0,',',' ') : '—' ?>
                → <?= $log['amount_after'] !== null ? number_format((float)$log['amount_after'],0,',',' ') : '—' ?> FCFA
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div><!-- /pb -->
  </div><!-- /panel right -->

</div><!-- /account-grid -->
<?php else: ?>

<!-- No client state -->
<div class="panel" style="text-align:center;padding:0">
  <div class="pb" style="padding:60px 30px">
    <i class="fas fa-university" style="font-size:56px;color:var(--purple);opacity:.25;display:block;margin-bottom:20px"></i>
    <div style="font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text2);opacity:.6;margin-bottom:10px">Guichet en attente</div>
    <div style="color:var(--muted);font-size:14px;font-weight:600;max-width:400px;margin:0 auto">
      Recherchez un client par son nom ou numéro de téléphone pour accéder à son compte financier.
    </div>
  </div>
</div>

<?php endif; ?>

</div><!-- /wrap -->

<form method="post" id="tx-edit-form" style="display:none">
  <input type="hidden" name="action" value="update_transaction">
  <input type="hidden" name="client_id" value="<?= $client_id ?>">
  <input type="hidden" name="company_id" value="<?= $company_id ?>">
  <input type="hidden" name="city_id" value="<?= $city_id ?>">
  <input type="hidden" name="tx_type" id="edit-tx-type">
  <input type="hidden" name="tx_id" id="edit-tx-id">
  <input type="hidden" name="amount" id="edit-tx-amount">
  <input type="hidden" name="reason" id="edit-tx-reason">
</form>

<form method="post" id="tx-cancel-form" style="display:none">
  <input type="hidden" name="action" value="cancel_transaction">
  <input type="hidden" name="client_id" value="<?= $client_id ?>">
  <input type="hidden" name="company_id" value="<?= $company_id ?>">
  <input type="hidden" name="city_id" value="<?= $city_id ?>">
  <input type="hidden" name="tx_type" id="cancel-tx-type">
  <input type="hidden" name="tx_id" id="cancel-tx-id">
  <input type="hidden" name="reason" id="cancel-tx-reason">
</form>

<!-- ══ MODAL REÇU ══ -->
<?php if (isset($_SESSION['last_receipt']) && $flash_type==='success'): $lr=$_SESSION['last_receipt']; unset($_SESSION['last_receipt']); ?>
<div class="modal open" id="receipt-modal">
  <div class="modal-bg" onclick="closeReceipt()"></div>
  <div class="modal-box">
    <div class="receipt-header">
      <i class="fas fa-circle-check"></i>
      <h2>Transaction confirmée</h2>
      <p><?= date('d/m/Y à H:i',strtotime($lr['date'])) ?></p>
    </div>
    <div class="receipt-amount">
      <div class="val"><?= number_format($lr['amount'],0,',',' ') ?> FCFA</div>
      <div class="lbl"><?= $lr['type']==='depot'?'Dépôt enregistré':'Versement enregistré' ?></div>
    </div>
    <div class="receipt-row"><span>Référence</span><span style="font-family:monospace;color:var(--neon)"><?= htmlspecialchars($lr['receipt']) ?></span></div>
    <div class="receipt-row"><span>Mode</span><span><?= htmlspecialchars($lr['mode']) ?></span></div>
    <?php if ($lr['invoice_id']): ?><div class="receipt-row"><span>Facture</span><span>#<?= $lr['invoice_id'] ?></span></div><?php endif; ?>
    <div class="receipt-row"><span>Caissier</span><span><?= htmlspecialchars($user_name) ?></span></div>
    <div class="modal-btns">
      <button onclick="window.print()" class="btn btn-gold"><i class="fas fa-print"></i> Imprimer</button>
      <a href="receipt_pdf.php?type=<?= $lr['type']==='depot'?'depot':'versement' ?>&id=<?= (int) ($lr['id'] ?? 0) ?>" target="_blank" class="btn btn-neon"><i class="fas fa-file-pdf"></i> PDF</a>
      <?php
        $waPhone = normalize_phone_for_whatsapp($client['phone'] ?? '');
        $receiptLink = ((isset($_SERVER['HTTP_HOST']) ? (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : '') . project_url('finance/receipt_pdf.php') . '?type=' . ($lr['type']==='depot'?'depot':'versement') . '&id=' . (int) ($lr['id'] ?? 0));
        $shareBody = rawurlencode("Bonjour, votre reçu {$lr['receipt']} est disponible ici : {$receiptLink}");
      ?>
      <?php if ($waPhone): ?><a href="https://wa.me/<?= $waPhone ?>?text=<?= $shareBody ?>" target="_blank" class="btn btn-sm ghost-btn"><i class="fab fa-whatsapp"></i> WhatsApp</a><?php endif; ?>
      <?php if (!empty($client['email'])): ?><a href="mailto:<?= htmlspecialchars($client['email']) ?>?subject=Reçu%20<?= rawurlencode($lr['receipt']) ?>&body=<?= $shareBody ?>" class="btn btn-sm ghost-btn"><i class="fas fa-envelope"></i> Email</a><?php endif; ?>
      <button onclick="closeReceipt()" class="btn btn-red"><i class="fas fa-xmark"></i> Fermer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* ── Horloge ── */
(function tick(){
  const n=new Date();
  const pad=v=>String(v).padStart(2,'0');
  document.getElementById('clk').textContent=pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds());
  const D=['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
  const M=['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
  document.getElementById('clkd').textContent=D[n.getDay()]+' '+n.getDate()+' '+M[n.getMonth()]+' '+n.getFullYear();
  setTimeout(tick,1000);
})();

/* ── Tabs ── */
function switchTab(event,name){
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelector('#tab-'+name).classList.add('active');
  if(event && event.currentTarget){ event.currentTarget.classList.add('active'); }
}
function activateTab(name){
  const btn=document.querySelector(`.tab-btn[onclick*="${name}"]`);
  switchTab(btn?{currentTarget:btn}:null,name);
}

/* ── Sélection facture ── */
let currentReste = 0;
function selectInvoice(el, id, reste, dateStr) {
  document.querySelectorAll('.invoice-item').forEach(el=>el.classList.remove('selected'));
  el.classList.add('selected');
  currentReste = reste;
  document.getElementById('hid-invoice').value = id;
  document.getElementById('apply-invoice-id').value = id;
  document.getElementById('pay-form-title').textContent = 'Payer Facture #' + id;
  document.getElementById('pay-form-reste').textContent = 'Reste : ' + reste.toLocaleString('fr-FR') + ' FCFA';
  document.getElementById('pay-amount').max = Math.floor(reste);
  document.getElementById('pay-amount').value = '';
  document.getElementById('btn-pay').disabled = true;
  document.getElementById('btn-pay').innerHTML = '<i class="fas fa-lock"></i> Valider le versement';
  document.getElementById('recap-box').style.display = 'none';
  document.getElementById('pay-form-box').classList.add('show');

  // Boutons rapides
  const qb = document.getElementById('quick-btns');
  qb.innerHTML = '';
  [5000,10000,25000,50000,100000].forEach(v=>{
    if(v<=reste){
      const b=document.createElement('button');
      b.type='button'; b.className='qa';
      b.textContent=v.toLocaleString('fr-FR');
      b.onclick=()=>{document.getElementById('pay-amount').value=v;updateRecap();};
      qb.appendChild(b);
    }
  });
  const ball=document.createElement('button');
  ball.type='button'; ball.className='qa';
  ball.innerHTML='<i class="fas fa-check-double"></i> Tout';
  ball.onclick=()=>{document.getElementById('pay-amount').value=Math.floor(reste);updateRecap();};
  qb.appendChild(ball);

  document.getElementById('pay-form-box').scrollIntoView({behavior:'smooth',block:'nearest'});
}

/* ── Récap versement ── */
function updateRecap(){
  const val=parseFloat(document.getElementById('pay-amount').value)||0;
  const btn=document.getElementById('btn-pay');
  const rec=document.getElementById('recap-box');
  if(val>0 && val<=currentReste+0.01){
    const r=Math.max(0,currentReste-val);
    const status=r<=0.01?'✅ Soldée':val>0?'🟡 Partielle':'🔴 Impayée';
    document.getElementById('recap-amount').textContent=val.toLocaleString('fr-FR')+' FCFA';
    document.getElementById('recap-reste').textContent=r.toLocaleString('fr-FR')+' FCFA';
    document.getElementById('recap-reste').style.color=r<=0.01?'var(--neon)':'var(--red)';
    document.getElementById('recap-status').textContent=status;
    rec.style.display='block';
    btn.disabled=false;
    btn.innerHTML='<i class="fas fa-lock-open"></i> Valider '+val.toLocaleString('fr-FR')+' FCFA';
  } else {
    rec.style.display='none';
    btn.disabled=true;
    btn.innerHTML='<i class="fas fa-lock"></i> Valider le versement';
  }
}

/* ── Appliquer dépôt ── */
function useDepotBalance(){
  const solde=<?= $solde_depot ?>;
  const invoiceId=document.getElementById('hid-invoice').value;
  if(!invoiceId){alert('Sélectionnez d\'abord une facture.');return;}
  const apply=Math.min(solde,currentReste);
  document.getElementById('apply-invoice-id').value=invoiceId;
  document.getElementById('apply-amount').value=apply;
  if(confirm('Appliquer '+apply.toLocaleString('fr-FR')+' FCFA du solde dépôts sur cette facture ?')){
    document.getElementById('apply-depot-form').submit();
  }
}

/* ── Dépôt preview ── */
const soldeActuel=<?= $solde_depot ?>;
const detteActuelle=<?= $total_dette ?>;
function setDepot(v){
  document.getElementById('depot-amount').value=v;
  updateDepotPreview(v);
}
function updateDepotPreview(v){
  v=parseFloat(v)||0;
  const btn=document.getElementById('btn-depot');
  const prev=document.getElementById('depot-preview');
  if(v>0){
    const newSolde=soldeActuel+v;
    document.getElementById('dp-amount').textContent=v.toLocaleString('fr-FR')+' FCFA';
    document.getElementById('dp-solde').textContent=newSolde.toLocaleString('fr-FR')+' FCFA';
    const cover=document.getElementById('dp-cover');
    if(cover){
      const pct=detteActuelle>0?Math.min(100,Math.round(newSolde/detteActuelle*100)):100;
      cover.textContent=pct+'% des dettes couvertes';
      cover.style.color=pct>=100?'var(--neon)':'var(--gold)';
    }
    prev.style.display='block';
    btn.disabled=false;
    btn.innerHTML='<i class="fas fa-lock-open"></i> Confirmer le dépôt de '+v.toLocaleString('fr-FR')+' FCFA';
  } else {
    prev.style.display='none';
    btn.disabled=true;
    btn.innerHTML='<i class="fas fa-lock"></i> Confirmer le dépôt';
  }
}

/* ── Recherche historique ── */
function filterHistory(q){
  q=q.toLowerCase();
  document.querySelectorAll('#hist-list .txn-item').forEach(el=>{
    el.style.display=el.dataset.search.includes(q)?'':'none';
  });
}

function editTransaction(type,id,amount){
  const val=prompt('Nouveau montant FCFA', Math.round(amount));
  if(val===null){ return; }
  const parsed=parseFloat(String(val).replace(',', '.'));
  if(!(parsed>0)){ alert('Montant invalide.'); return; }
  const reason=prompt('Motif de modification', 'Correction de montant');
  if(reason===null){ return; }
  document.getElementById('edit-tx-type').value=type;
  document.getElementById('edit-tx-id').value=id;
  document.getElementById('edit-tx-amount').value=parsed;
  document.getElementById('edit-tx-reason').value=reason;
  document.getElementById('tx-edit-form').submit();
}

function cancelTransaction(type,id,ref){
  const reason=prompt("Motif d'annulation pour "+ref, 'Erreur de caisse');
  if(reason===null){ return; }
  document.getElementById('cancel-tx-type').value=type;
  document.getElementById('cancel-tx-id').value=id;
  document.getElementById('cancel-tx-reason').value=reason || 'Annulation manuelle';
  document.getElementById('tx-cancel-form').submit();
}

const searchInput=document.getElementById('client-search-input');
const searchResults=document.getElementById('search-results');
const searchEmpty=document.getElementById('search-empty');
const searchSpinner=document.getElementById('search-spinner');
const searchLabel=document.getElementById('search-live-label');
let searchTimer=null;

function renderClientCards(rows){
  if(!searchResults) return;
  if(!rows.length){
    searchResults.style.display='none';
    if(searchEmpty) searchEmpty.style.display='block';
    if(searchLabel) searchLabel.textContent='0 client trouvé';
    return;
  }
  searchResults.innerHTML=rows.map(r=>{
    const initial=(r.name||'?').trim().charAt(0).toUpperCase();
    const href=`versement.php?client_id=${r.id}&company_id=${r.company_id}&city_id=${r.city_id}`;
    const wallet=Number(r.wallet_balance || 0);
    const debt=Number(r.total_debt || 0);
    const deposits=Number(r.total_deposits || 0);
    const purchases=Number(r.total_purchases || 0);
    const count=Number(r.purchase_count || 0);
    const lastActivity=r.last_order_at || r.created_at || '';
    let score=2;
    if(debt <= 0.01) score++;
    if(deposits >= 250000) score++;
    if(count >= 4) score++;
    if(lastActivity && Date.parse(lastActivity) >= Date.now() - (30*24*60*60*1000)) score++;
    if(debt > wallet && debt > 0) score--;
    score=Math.max(1, Math.min(5, score));
    const status=score >= 4 ? 'Bon client' : (score === 3 ? 'Client normal' : 'Risqué');
    const stars='★★★★★'.slice(0,score)+'☆☆☆☆☆'.slice(0,5-score);
    const badges=[];
    badges.push(`<span class="cc-badge ${wallet>0?'green':'gold'}">${wallet>0?'Solde positif':'Dépôt faible'}</span>`);
    if(debt>0) badges.push('<span class="cc-badge red">Dette</span>');
    if(deposits>=500000) badges.push('<span class="cc-badge blue">Gros client</span>');
    if(String(r.vip_status||'').trim() && String(r.vip_status).toLowerCase() !== 'standard') badges.push(`<span class="cc-badge purple">${escapeHtml(r.vip_status)}</span>`);
    return `
      <a href="${href}" class="client-card rich">
        <div class="cc-top">
          <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <div class="cc-avatar">${initial}</div>
            <div style="min-width:0">
              <div class="cc-name">${escapeHtml(r.name || '')}</div>
              <div class="cc-phone"><i class="fas fa-phone"></i> ${escapeHtml(r.phone || '')}</div>
            </div>
          </div>
          <div>
            <div class="cc-score" style="color:var(--gold)">${stars}</div>
            <div style="font-size:10px;color:var(--muted);font-weight:800;text-align:right">${status}</div>
          </div>
        </div>
        <div class="cc-badges">${badges.join('')}</div>
        <div class="cc-stats">
          <div class="cc-stat">
            <div class="k">Solde</div>
            <div class="v" style="color:${wallet>=0?'var(--neon)':'var(--red)'}">${formatMoney(wallet)}</div>
          </div>
          <div class="cc-stat">
            <div class="k">Dette</div>
            <div class="v" style="color:${debt>0?'var(--red)':'var(--text)'}">${formatMoney(debt)}</div>
          </div>
          <div class="cc-stat">
            <div class="k">Dépôts</div>
            <div class="v" style="color:var(--gold)">${formatMoney(deposits)}</div>
          </div>
          <div class="cc-stat">
            <div class="k">Achats</div>
            <div class="v">${formatMoney(purchases)}</div>
          </div>
        </div>
        <div class="cc-loc" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.06)">
          <i class="fas fa-hashtag" style="color:var(--gold)"></i> ID ${r.id}
          &nbsp;·&nbsp; <i class="fas fa-building" style="color:var(--purple)"></i> ${escapeHtml(r.company_name || '')}
          &nbsp;·&nbsp; <i class="fas fa-location-dot" style="color:var(--cyan)"></i> ${escapeHtml(r.city_name || '')}
        </div>
        <div class="cc-footer">
          <span style="font-size:11px;color:var(--muted);font-weight:700">${count} facture(s)</span>
          <span style="font-size:11px;color:var(--muted);font-weight:700">${lastActivity ? 'Actif ' + formatDate(lastActivity) : 'Nouvel enregistrement'}</span>
        </div>
      </a>`;
  }).join('');
  searchResults.style.display='grid';
  if(searchEmpty) searchEmpty.style.display='none';
  if(searchLabel) searchLabel.textContent=`${rows.length} client(s) trouvé(s)`;
}

function escapeHtml(value){
  return String(value)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#039;');
}

function formatMoney(value){
  return `${Number(value || 0).toLocaleString('fr-FR')} FCFA`;
}

function formatDate(value){
  const d=new Date(value);
  if(Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('fr-FR');
}

async function runLiveSearch(term){
  if(!searchInput || !searchResults) return;
  const q=term.trim();
  if(q.length < 2){
    if(searchSpinner) searchSpinner.classList.remove('show');
    if(searchLabel) searchLabel.textContent='Recherche visuelle en direct';
    if(q.length === 0 && searchEmpty) searchEmpty.style.display='none';
    return;
  }
  if(searchSpinner) searchSpinner.classList.add('show');
  if(searchLabel) searchLabel.textContent='Recherche en cours...';
  const company=document.querySelector('select[name="company_id"]')?.value || '';
  const city=document.querySelector('select[name="city_id"]')?.value || '';
  const url=`versement.php?ajax=client_search&q=${encodeURIComponent(q)}&company_id=${encodeURIComponent(company)}&city_id=${encodeURIComponent(city)}`;
  try{
    const res=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data=await res.json();
    renderClientCards(Array.isArray(data.results)?data.results:[]);
  }catch(e){
    if(searchLabel) searchLabel.textContent='Recherche indisponible';
  }finally{
    if(searchSpinner) searchSpinner.classList.remove('show');
  }
}

if(searchInput){
  searchInput.addEventListener('input', e=>{
    clearTimeout(searchTimer);
    searchTimer=setTimeout(()=>runLiveSearch(e.target.value),180);
  });
}

/* ── Modal reçu ── */
function closeReceipt(){ document.getElementById('receipt-modal').classList.remove('open'); }

/* ── Print style ── */
window.onbeforeprint=()=>{document.querySelectorAll('.modal-btns').forEach(e=>e.style.display='none')};
window.onafterprint=()=>{document.querySelectorAll('.modal-btns').forEach(e=>e.style.display='')};
</script>
</body>
</html>
