<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * CAISSE PRO v2.2 — ESPERANCE H2O
 * Style: Dark Neon · C059 Bold
 * + Onglet Demande d'Approvisionnement
 * + Sécurité rôles : seuls admin/developer peuvent confirmer/rejeter
 * ═══════════════════════════════════════════════════════════════
 */
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once PROJECT_ROOT . '/messaging/app_alerts.php';
require APP_ROOT . '/vendor/autoload.php';
require APP_ROOT . '/fpdf186/fpdf.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::check();
Middleware::role(['developer', 'admin']);

$pdo = DB::getConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS appro_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  company_id      INT NOT NULL,
  city_id         INT NOT NULL,
  product_id      INT NOT NULL,
  requested_by    INT NOT NULL,
  quantity        DECIMAL(10,2) NOT NULL,
  unit_type       ENUM('detail','carton') NOT NULL DEFAULT 'detail',
  note            TEXT,
  status          ENUM('en_attente','confirmee','rejetee','annulee') NOT NULL DEFAULT 'en_attente',
  admin_id        INT DEFAULT NULL,
  admin_note      TEXT,
  created_at      DATETIME DEFAULT NOW(),
  updated_at      DATETIME DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS appro_request_history (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  request_id  INT NOT NULL,
  user_id     INT NOT NULL,
  action      VARCHAR(80) NOT NULL,
  details     TEXT,
  ip_address  VARCHAR(45),
  created_at  DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$user_id   = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['username'] ?? $_SESSION['user_name'] ?? '';
$user_role = $_SESSION['role'] ?? '';

$st = $pdo->prepare("SELECT username, role FROM users WHERE id=?");
$st->execute([$user_id]);
$__u = $st->fetch(PDO::FETCH_ASSOC);
if ($__u) {
    $user_name = $__u['username'];
    $user_role = $__u['role'];
    $_SESSION['username'] = $user_name;
    $_SESSION['role']     = $user_role;
}
if (!$user_name) $user_name = 'Utilisateur';

$CAN_CONFIRM_APPRO = in_array($user_role, ['admin', 'developer']);
$CAN_REQUEST_APPRO = true;
$CAN_MANAGE_CAISSE = in_array($user_role, ['admin', 'developer', 'caissiere', 'cashier', 'user']);

function denyUnauthorized($role_needed = 'admin/developer') {
    http_response_code(403);
    die(json_encode(['error' => "⛔ Accès refusé. Rôle requis : $role_needed"]));
}

function logAction($pdo, $uid, $type, $desc, $pid=null, $iid=null, $amt=null, $qty=null) {
    $st = $pdo->prepare("INSERT INTO cash_log
        (user_id,session_id,action_type,action_description,product_id,invoice_id,amount,quantity,ip_address,user_agent)
        VALUES(?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$uid,session_id(),$type,$desc,$pid,$iid,$amt,$qty,
        $_SERVER['REMOTE_ADDR']??'?',$_SERVER['HTTP_USER_AGENT']??'?']);
}

function logApproHistory($pdo, $request_id, $user_id, $action, $details = '') {
    $st = $pdo->prepare("INSERT INTO appro_request_history (request_id, user_id, action, details, ip_address) VALUES (?,?,?,?,?)");
    $st->execute([$request_id, $user_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '?']);
}

if (!isset($_SESSION['caisse_company_id'])) $_SESSION['caisse_company_id'] = 0;
if (!isset($_SESSION['caisse_city_id']))    $_SESSION['caisse_city_id']    = 0;

if (isset($_GET['company_id']))   { $_SESSION['caisse_company_id'] = (int)$_GET['company_id']; }
if (isset($_GET['confirm_location'], $_GET['city_id'])) {
    $_SESSION['caisse_city_id'] = (int)$_GET['city_id'];
    logAction($pdo,$user_id,'LOCATION_CHANGE',"Localisation confirmée");
    header("Location: caisse_complete_enhanced.php"); exit;
}

$company_id   = $_SESSION['caisse_company_id'];
$city_id      = $_SESSION['caisse_city_id'];
$location_set = ($company_id > 0 && $city_id > 0);
$date_filter  = $_GET['date_filter'] ?? date('Y-m-d');
$view_mode    = $_GET['view'] ?? 'pos';

$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cities = [];
if ($company_id) {
    $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]);
    $cities = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ── APPRO handlers (inchangés) ── */
if (isset($_POST['submit_appro_request']) && $location_set) {
    if (!$user_id) { $error_message = "⛔ Vous devez être connecté pour soumettre une demande."; }
    else {
    $product_id = (int)$_POST['appro_product_id'];
    $quantity   = (float)$_POST['appro_quantity'];
    $unit_type  = ($_POST['appro_unit_type'] ?? 'detail') === 'carton' ? 'carton' : 'detail';
    $note       = trim($_POST['appro_note'] ?? '');
    if ($product_id > 0 && $quantity > 0) {
        $st = $pdo->prepare("INSERT INTO appro_requests (company_id,city_id,product_id,requested_by,quantity,unit_type,note,status) VALUES (?,?,?,?,?,?,?,'en_attente')");
        $st->execute([$company_id,$city_id,$product_id,$user_id,$quantity,$unit_type,$note]);
        $req_id = $pdo->lastInsertId();
        $st2 = $pdo->prepare("SELECT name FROM products WHERE id=?"); $st2->execute([$product_id]);
        $pname = $st2->fetchColumn();
        logApproHistory($pdo,$req_id,$user_id,'SOUMISSION',"Demande créée par $user_name (rôle: $user_role) — Produit: $pname — Qté: $quantity $unit_type — Note: $note");
        logAction($pdo,$user_id,'APPRO_REQUEST',"Demande appro: $pname x$quantity ($unit_type)",null,null,null,$quantity);
        try {
            appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
                'title' => 'Nouvelle demande d’approvisionnement',
                'body' => mb_strimwidth("$user_name demande $quantity $unit_type de $pname", 0, 180, '…', 'UTF-8'),
                'url' => project_url('finance/caisse_complete_enhanced.php?view=appro&appro_view=list'),
                'tag' => 'appro-request-' . $req_id,
                'unread' => 1,
            ], [
                'event_type' => 'appro_request',
                'event_key' => 'appro-request-' . $req_id,
                'actor_user_id' => (int)$user_id,
            ]);
        } catch (Throwable $e) {
            error_log('[APPRO ALERT] ' . $e->getMessage());
        }
        $success_message = "✅ Demande d'appro envoyée ! L'admin va recevoir votre demande pour <strong>$pname</strong>.";
    } else { $error_message = "❌ Veuillez sélectionner un produit et une quantité valide."; }
    }
    $view_mode = 'appro';
}
if (isset($_POST['update_appro_request']) && $location_set) {
    $req_id = (int)$_POST['appro_req_id']; $quantity = (float)$_POST['appro_quantity'];
    $unit_type = ($_POST['appro_unit_type'] ?? 'detail') === 'carton' ? 'carton' : 'detail';
    $note = trim($_POST['appro_note'] ?? '');
    $st = $pdo->prepare("SELECT * FROM appro_requests WHERE id=? AND status='en_attente' AND requested_by=?");
    $st->execute([$req_id, $user_id]); $req = $st->fetch(PDO::FETCH_ASSOC);
    if ($req) {
        if ($quantity <= 0) { $error_message = "❌ La quantité doit être supérieure à 0."; }
        else {
            $old = $req['quantity'].' '.$req['unit_type'];
            $pdo->prepare("UPDATE appro_requests SET quantity=?,unit_type=?,note=?,updated_at=NOW() WHERE id=?")->execute([$quantity,$unit_type,$note,$req_id]);
            logApproHistory($pdo,$req_id,$user_id,'MODIFICATION',"Modifié par $user_name — Qté: $old → $quantity $unit_type — Note: $note");
            logAction($pdo,$user_id,'APPRO_EDIT',"Modification demande appro #$req_id");
            $success_message = "✅ Demande #$req_id modifiée avec succès.";
        }
    } else { $error_message = "❌ Impossible de modifier."; }
    $view_mode = 'appro';
}
if (isset($_POST['cancel_appro_request']) && $location_set) {
    $req_id = (int)$_POST['appro_req_id']; $motif = trim($_POST['appro_cancel_motif'] ?? '') ?: "Annulé par $user_name";
    $st = $pdo->prepare("SELECT * FROM appro_requests WHERE id=? AND status='en_attente' AND requested_by=?");
    $st->execute([$req_id,$user_id]); $req = $st->fetch(PDO::FETCH_ASSOC);
    if ($req) {
        $pdo->prepare("UPDATE appro_requests SET status='annulee',admin_note=?,updated_at=NOW() WHERE id=?")->execute([$motif,$req_id]);
        logApproHistory($pdo,$req_id,$user_id,'ANNULATION',"Annulé par $user_name — Motif: $motif");
        logAction($pdo,$user_id,'APPRO_CANCEL',"Annulation demande appro #$req_id — $motif");
        $success_message = "✅ Demande #$req_id annulée.";
    } else { $error_message = "❌ Impossible d'annuler."; }
    $view_mode = 'appro';
}
if (isset($_POST['confirm_appro_request']) && $location_set) {
    if (!$CAN_CONFIRM_APPRO) {
        logAction($pdo,$user_id,'SECURITY_BREACH',"🚨 TENTATIVE CONFIRMATION APPRO non autorisée — user: $user_name — rôle: $user_role");
        $error_message = "🚨 Accès refusé. Seuls les rôles <strong>admin</strong> et <strong>developer</strong> peuvent confirmer un approvisionnement.";
        $view_mode = 'appro';
    } else {
        $req_id = (int)$_POST['appro_req_id']; $admin_note = trim($_POST['appro_admin_note'] ?? '');
        $st = $pdo->prepare("SELECT ar.*, p.name product_name, u.username requester_name FROM appro_requests ar JOIN products p ON p.id=ar.product_id JOIN users u ON u.id=ar.requested_by WHERE ar.id=? AND ar.status='en_attente'");
        $st->execute([$req_id]); $req = $st->fetch(PDO::FETCH_ASSOC);
        if ($req) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE appro_requests SET status='confirmee',admin_id=?,admin_note=?,updated_at=NOW() WHERE id=?")->execute([$user_id,$admin_note,$req_id]);
                $ref = "APPRO-REQ-{$req_id}";
                $pdo->prepare("INSERT INTO stock_movements (product_id,reference,company_id,city_id,type,quantity,movement_date) VALUES (?,?,?,?,'entry',?,NOW())")->execute([$req['product_id'],$ref,$req['company_id'],$req['city_id'],$req['quantity']]);
                logApproHistory($pdo,$req_id,$user_id,'CONFIRMATION',"✅ Confirmé par $user_name — Stock +{$req['quantity']} {$req['unit_type']} [{$req['product_name']}] — Note: $admin_note");
                logAction($pdo,$user_id,'APPRO_CONFIRMED',"✅ Appro #$req_id confirmée — {$req['product_name']} +{$req['quantity']} {$req['unit_type']}",$req['product_id'],null,null,$req['quantity']);
                $pdo->commit();
                try {
                    appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
                        'title' => 'Approvisionnement confirmé',
                        'body' => mb_strimwidth(
                            sprintf(
                                '✅ Demande #%d confirmée: %s +%s %s (demandeur: %s)',
                                $req_id,
                                (string)$req['product_name'],
                                rtrim(rtrim((string)$req['quantity'], '0'), '.'),
                                (string)$req['unit_type'],
                                (string)$req['requester_name']
                            ),
                            0,
                            180,
                            '…',
                            'UTF-8'
                        ),
                        'url' => project_url('finance/caisse_complete_enhanced.php?view=appro&appro_view=history&req_id=' . $req_id),
                        'tag' => 'appro-confirmed-' . $req_id,
                        'unread' => 1,
                    ], [
                        'event_type' => 'appro_request_confirmed',
                        'event_key' => 'appro-request-confirmed-' . $req_id,
                        'actor_user_id' => (int)$user_id,
                        'request_id' => (int)$req_id,
                        'product_id' => (int)$req['product_id'],
                        'product_name' => (string)$req['product_name'],
                        'quantity' => (float)$req['quantity'],
                        'unit_type' => (string)$req['unit_type'],
                        'requester_name' => (string)$req['requester_name'],
                        'admin_note' => (string)$admin_note,
                    ]);
                } catch (Throwable $e) {
                    error_log('[APPRO CONFIRM ALERT] ' . $e->getMessage());
                }
                $success_message = "✅ Appro <strong>#$req_id</strong> confirmée ! Stock mis à jour (+{$req['quantity']} {$req['unit_type']} de <strong>{$req['product_name']}</strong>).";
            } catch (Exception $e) { $pdo->rollBack(); $error_message = "❌ Erreur : " . $e->getMessage(); }
        } else { $error_message = "❌ Demande introuvable ou déjà traitée."; }
        $view_mode = 'appro';
    }
}
if (isset($_POST['reject_appro_request']) && $location_set) {
    if (!$CAN_CONFIRM_APPRO) {
        logAction($pdo,$user_id,'SECURITY_BREACH',"🚨 TENTATIVE REJET APPRO non autorisée — user: $user_name — rôle: $user_role");
        $error_message = "🚨 Accès refusé.";
        $view_mode = 'appro';
    } else {
        $req_id = (int)$_POST['appro_req_id']; $admin_note = trim($_POST['appro_admin_note'] ?? '');
        if (empty($admin_note)) { $error_message = "❌ Le motif du rejet est obligatoire."; $view_mode = 'appro'; }
        else {
            $st = $pdo->prepare("SELECT ar.*, p.name product_name, u.username requester_name FROM appro_requests ar JOIN products p ON p.id=ar.product_id JOIN users u ON u.id=ar.requested_by WHERE ar.id=? AND ar.status='en_attente'");
            $st->execute([$req_id]); $req = $st->fetch(PDO::FETCH_ASSOC);
            if ($req) {
                $pdo->prepare("UPDATE appro_requests SET status='rejetee',admin_id=?,admin_note=?,updated_at=NOW() WHERE id=?")->execute([$user_id,$admin_note,$req_id]);
                logApproHistory($pdo,$req_id,$user_id,'REJET',"❌ Rejeté par $user_name — Motif: $admin_note — Produit: {$req['product_name']}");
                logAction($pdo,$user_id,'APPRO_REJECTED',"❌ Appro #$req_id rejetée — {$req['product_name']} — Motif: $admin_note");
                try {
                    appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
                        'title' => 'Approvisionnement rejeté',
                        'body' => mb_strimwidth(
                            sprintf(
                                '❌ Demande #%d rejetée: %s (%s) · Motif: %s',
                                $req_id,
                                (string)$req['product_name'],
                                (string)$req['requester_name'],
                                (string)$admin_note
                            ),
                            0,
                            180,
                            '…',
                            'UTF-8'
                        ),
                        'url' => project_url('finance/caisse_complete_enhanced.php?view=appro&appro_view=history&req_id=' . $req_id),
                        'tag' => 'appro-rejected-' . $req_id,
                        'unread' => 1,
                    ], [
                        'event_type' => 'appro_request_rejected',
                        'event_key' => 'appro-request-rejected-' . $req_id,
                        'actor_user_id' => (int)$user_id,
                        'request_id' => (int)$req_id,
                        'product_id' => (int)$req['product_id'],
                        'product_name' => (string)$req['product_name'],
                        'quantity' => (float)$req['quantity'],
                        'unit_type' => (string)$req['unit_type'],
                        'requester_name' => (string)$req['requester_name'],
                        'admin_note' => (string)$admin_note,
                    ]);
                } catch (Throwable $e) {
                    error_log('[APPRO REJECT ALERT] ' . $e->getMessage());
                }
                $success_message = "Demande <strong>#$req_id</strong> rejetée.";
            } else { $error_message = "❌ Demande introuvable ou déjà traitée."; }
            $view_mode = 'appro';
        }
    }
}

if (isset($_POST['delete_invoice']) && $location_set) {
    $invoice_id = (int)$_POST['invoice_id']; $password = $_POST['delete_password'] ?? '';
    $st = $pdo->prepare("SELECT password FROM users WHERE id=?"); $st->execute([$user_id]); $usr = $st->fetch(PDO::FETCH_ASSOC);
    if (password_verify($password, $usr['password'])) {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=?"); $st->execute([$invoice_id]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as $item) {
                $pdo->prepare("INSERT INTO stock_movements (product_id,reference,company_id,city_id,type,quantity,movement_date) VALUES(?,?,?,?,'entry',?,NOW())")->execute([$item['product_id'],"ANNULATION-VENTE-{$invoice_id}",$company_id,$city_id,$item['quantity']]);
            }
            $pdo->prepare("DELETE FROM versements    WHERE invoice_id=?")->execute([$invoice_id]);
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$invoice_id]);
            $st = $pdo->prepare("
                SELECT i.total, c.name AS client_name
                FROM invoices i
                LEFT JOIN clients c ON c.id=i.client_id
                WHERE i.id=?
                LIMIT 1
            ");
            $st->execute([$invoice_id]);
            $invRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'client_name' => 'Client inconnu'];
            $inv_total = (float)($invRow['total'] ?? 0);
            $pdo->prepare("DELETE FROM invoices WHERE id=?")->execute([$invoice_id]);
            logAction($pdo,$user_id,'DELETE_INVOICE',"🚫 Annulation vente #$invoice_id — stock retourné",null,$invoice_id,$inv_total);
            $pdo->commit();
            try {
                appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
                    'title' => 'Facture annulée',
                    'body' => mb_strimwidth(
                        sprintf(
                            '🚫 Facture #%d annulée · Client %s · Montant %s FCFA',
                            $invoice_id,
                            (string)($invRow['client_name'] ?? 'Client inconnu'),
                            number_format((float)$inv_total, 0, '', '.')
                        ),
                        0,
                        180,
                        '…',
                        'UTF-8'
                    ),
                    'url' => project_url('finance/caisse_complete_enhanced.php?view=tickets'),
                    'tag' => 'invoice-cancelled-' . $invoice_id,
                    'unread' => 1,
                ], [
                    'event_type' => 'invoice_cancelled',
                    'event_key' => 'invoice-cancelled-' . $invoice_id,
                    'actor_user_id' => (int)$user_id,
                    'invoice_id' => (int)$invoice_id,
                    'invoice_total' => (float)$inv_total,
                    'client_name' => (string)($invRow['client_name'] ?? ''),
                ]);
            } catch (Throwable $e) {
                error_log('[INVOICE CANCEL ALERT] ' . $e->getMessage());
            }
            $success_message = "✅ Vente annulée. Stock retourné et marqué 'ANNULATION VENTE' dans l'onglet Stock.";
        } catch (Exception $e) { $pdo->rollBack(); $error_message = "Erreur : " . $e->getMessage(); }
    } else { $error_message = "❌ Mot de passe incorrect !"; }
}

if (isset($_POST['add_expense']) && $location_set) {
    $st = $pdo->prepare("INSERT INTO expenses(company_id,city_id,category,amount,note,expense_date)VALUES(?,?,?,?,?,NOW())");
    $st->execute([$company_id,$city_id,trim($_POST['category']),(float)$_POST['amount'],trim($_POST['note'])]);
    logAction($pdo,$user_id,'ADD_EXPENSE',"Dépense: ".$_POST['category']." ".$_POST['amount']." CFA",null,null,(float)$_POST['amount']);
    header("Location: caisse_complete_enhanced.php?view=expenses"); exit;
}

if (isset($_POST['edit_invoice']) && $location_set) {
    $invoice_id = (int)$_POST['invoice_id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE invoices SET client_id=?,created_at=? WHERE id=?")->execute([(int)$_POST['client_id'],$_POST['sale_date'],$invoice_id]);
        foreach ($_POST['item_price'] as $item_id => $np) {
            $np = (float)$np;
            $qty = $pdo->prepare("SELECT quantity FROM invoice_items WHERE id=?"); $qty->execute([$item_id]); $q = $qty->fetchColumn();
            $pdo->prepare("UPDATE invoice_items SET price=?,total=? WHERE id=?")->execute([$np,$np*$q,$item_id]);
        }
        $tot = $pdo->prepare("SELECT SUM(total) FROM invoice_items WHERE invoice_id=?"); $tot->execute([$invoice_id]);
        $pdo->prepare("UPDATE invoices SET total=? WHERE id=?")->execute([$tot->fetchColumn(),$invoice_id]);
        logAction($pdo,$user_id,'EDIT_INVOICE',"Modification facture #$invoice_id",null,$invoice_id);
        $pdo->commit();
        $success_message = "✅ Facture modifiée avec succès !";
    } catch(Exception $e) { $pdo->rollBack(); $error_message = $e->getMessage(); }
}

if (isset($_POST['duplicate_invoice']) && $location_set) {
    $orig_id = (int)$_POST['invoice_id'];
    $pdo->beginTransaction();
    try {
        $orig = $pdo->prepare("SELECT * FROM invoices WHERE id=?"); $orig->execute([$orig_id]); $o = $orig->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO invoices(client_id,company_id,city_id,total,status)VALUES(?,?,?,?,'Impayée')")->execute([$o['client_id'],$o['company_id'],$o['city_id'],$o['total']]);
        $new_id = $pdo->lastInsertId();
        $items2 = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=?"); $items2->execute([$orig_id]);
        foreach($items2->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $pdo->prepare("INSERT INTO invoice_items(invoice_id,product_id,quantity,price,total)VALUES(?,?,?,?,?)")->execute([$new_id,$it['product_id'],$it['quantity'],$it['price'],$it['total']]);
            $pdo->prepare("INSERT INTO stock_movements(product_id,reference,company_id,city_id,type,quantity,movement_date)VALUES(?,?,?,?,'exit',?,NOW())")->execute([$it['product_id'],"DUPLICATA-$new_id",$company_id,$city_id,$it['quantity']]);
        }
        logAction($pdo,$user_id,'DUPLICATE_INVOICE',"Duplicata facture #$orig_id → #$new_id",null,$new_id,$o['total']);
        $pdo->commit();
        header("Location: ticket.php?invoice_id=$new_id"); exit;
    } catch(Exception $e) { $pdo->rollBack(); $error_message = $e->getMessage(); }
}

if (isset($_POST['process_sale']) && $location_set) {
    $client_id = (int)$_POST['client_id']; $pay_mode = $_POST['payment_mode'];
    $sale_date = $_POST['sale_date'] ?? date('Y-m-d H:i:s');
    $cart_data = json_decode($_POST['cart_data'], true);
    if (empty($cart_data)) die("Panier vide !");
    $pdo->beginTransaction();
    try {
        foreach ($cart_data as $it) {
            $chk = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='initial' THEN quantity END),0)+COALESCE(SUM(CASE WHEN type='entry' THEN quantity END),0)-COALESCE(SUM(CASE WHEN type='exit' THEN quantity END),0)+COALESCE(SUM(CASE WHEN type='adjustment' THEN quantity END),0) AS s FROM stock_movements WHERE product_id=? AND company_id=? AND city_id=?");
            $chk->execute([$it['product_id'],$company_id,$city_id]);
            if ($chk->fetchColumn() < $it['quantity']) throw new Exception("Stock insuffisant : ".$it['product_name']);
        }
        $total = array_sum(array_column($cart_data,'total'));
        $status = ($pay_mode === 'Crédit') ? 'Impayée' : 'Payée';
        $pdo->prepare("INSERT INTO invoices(client_id,company_id,city_id,total,status,created_at)VALUES(?,?,?,?,?,?)")->execute([$client_id,$company_id,$city_id,$total,$status,$sale_date]);
        $iid = $pdo->lastInsertId();
        foreach ($cart_data as $it) {
            $pdo->prepare("INSERT INTO invoice_items(invoice_id,product_id,quantity,price,total)VALUES(?,?,?,?,?)")->execute([$iid,$it['product_id'],$it['quantity'],$it['price'],$it['total']]);
            $pdo->prepare("INSERT INTO stock_movements(product_id,reference,company_id,city_id,type,quantity,movement_date)VALUES(?,?,?,?,'exit',?,?)")->execute([$it['product_id'],"VENTE-$iid",$company_id,$city_id,$it['quantity'],$sale_date]);
        }
        if ($pay_mode !== 'Crédit') {
            $receipt_number = generate_receipt_number($pdo, $sale_date);
            $pdo->prepare("INSERT INTO versements(invoice_id,client_id,amount,payment_mode,payment_date,receipt_number,created_by)VALUES(?,?,?,?,?,?,?)")
                ->execute([$iid,$client_id,$total,$pay_mode,$sale_date,$receipt_number,$user_id ?: null]);
        }
        logAction($pdo,$user_id,'PROCESS_SALE',"Vente $pay_mode",null,$iid,$total);
        $pdo->commit();
        try {
            $clientStmt = $pdo->prepare("SELECT name, phone FROM clients WHERE id=? LIMIT 1");
            $clientStmt->execute([$client_id]);
            $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Client #' . $client_id, 'phone' => ''];
            $lineParts = [];
            foreach (array_slice($cart_data, 0, 5) as $line) {
                $lineParts[] = ($line['product_name'] ?? 'Article') . ' x' . (float)($line['quantity'] ?? 0);
            }
            appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
                'title' => 'Nouvelle facture caisse',
                'body' => mb_strimwidth(
                    sprintf(
                        'Facture #%d · %s · %s CFA · %s · %s',
                        $iid,
                        $clientRow['name'],
                        number_format((float)$total, 0, '', '.'),
                        $pay_mode,
                        implode(', ', $lineParts)
                    ),
                    0,
                    180,
                    '…',
                    'UTF-8'
                ),
                'url' => project_url('finance/ticket.php?invoice_id=' . $iid),
                'tag' => 'invoice-' . $iid,
                'unread' => 1,
            ], [
                'event_type' => 'invoice_created',
                'event_key' => 'invoice-created-' . $iid,
                'actor_user_id' => (int)$user_id,
                'invoice_id' => (int)$iid,
                'client_name' => (string)$clientRow['name'],
                'client_phone' => (string)($clientRow['phone'] ?? ''),
                'payment_mode' => (string)$pay_mode,
                'cart_lines' => $cart_data,
            ]);
        } catch (Throwable $e) {
            error_log('[INVOICE ALERT] ' . $e->getMessage());
        }
        header("Location: ticket.php?invoice_id=$iid"); exit;
    } catch(Exception $e) {
        $pdo->rollBack(); $error_message = $e->getMessage();
        logAction($pdo,$user_id,'SALE_ERROR',"Erreur: ".$error_message);
    }
}

if (isset($_POST['create_client']) && $location_set) {
    $nm = trim($_POST['client_name']); $ph = trim($_POST['client_phone']);
    if ($nm && $ph) {
        $pdo->prepare("INSERT INTO clients(company_id,city_id,name,phone,id_type)VALUES(?,?,?,?,'nouveau')")->execute([$company_id,$city_id,$nm,$ph]);
        logAction($pdo,$user_id,'CREATE_CLIENT',"Nouveau client: $nm");
    }
    $st = $pdo->prepare("SELECT id,name,phone FROM clients WHERE company_id=? AND city_id=? ORDER BY name"); $st->execute([$company_id,$city_id]);
    $clients = $st->fetchAll(PDO::FETCH_ASSOC);
}

$products = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT p.id, p.name, p.price, p.category, p.alert_quantity, p.image_path,
        COALESCE(SUM(CASE WHEN sm.type='initial' THEN sm.quantity END),0)+COALESCE(SUM(CASE WHEN sm.type='entry' THEN sm.quantity END),0)-COALESCE(SUM(CASE WHEN sm.type='exit' THEN sm.quantity END),0)+COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) AS stock_disponible
        FROM products p LEFT JOIN stock_movements sm ON sm.product_id=p.id AND sm.company_id=? AND sm.city_id=?
        WHERE p.company_id=? GROUP BY p.id HAVING stock_disponible > 0 ORDER BY p.name");
    $st->execute([$company_id,$city_id,$company_id]);
    $products = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($products as &$_p) { $_p['image_url'] = !empty($_p['image_path']) ? project_url($_p['image_path']) . '?v=' . urlencode((string)@filemtime(project_path($_p['image_path']))) : ''; } unset($_p);
}

$all_products = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT p.id, p.name, p.price, p.category, p.alert_quantity, p.image_path,
        COALESCE(SUM(CASE WHEN sm.type='initial' THEN sm.quantity END),0)+COALESCE(SUM(CASE WHEN sm.type='entry' THEN sm.quantity END),0)-COALESCE(SUM(CASE WHEN sm.type='exit' THEN sm.quantity END),0)+COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) AS stock_disponible
        FROM products p LEFT JOIN stock_movements sm ON sm.product_id=p.id AND sm.company_id=? AND sm.city_id=?
        WHERE p.company_id=? GROUP BY p.id ORDER BY p.name");
    $st->execute([$company_id,$city_id,$company_id]);
    $all_products = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_products as &$_p) { $_p['image_url'] = !empty($_p['image_path']) ? project_url($_p['image_path']) . '?v=' . urlencode((string)@filemtime(project_path($_p['image_path']))) : ''; } unset($_p);
}

$clients = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT id,name,phone FROM clients WHERE company_id=? AND city_id=? ORDER BY name"); $st->execute([$company_id,$city_id]);
    $clients = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pos_stats = ['ventes_jour'=>0,'ca_jour'=>0,'credits_jour'=>0,'low_stock'=>0];
if ($location_set) {
    $st = $pdo->prepare("SELECT COUNT(*) nb, COALESCE(SUM(total),0) ca, COALESCE(SUM(CASE WHEN status='Impayée' THEN total ELSE 0 END),0) cr FROM invoices WHERE company_id=? AND city_id=? AND DATE(created_at)=CURDATE()");
    $st->execute([$company_id,$city_id]); $r = $st->fetch(PDO::FETCH_ASSOC);
    $pos_stats['ventes_jour'] = (int)$r['nb']; $pos_stats['ca_jour'] = (float)$r['ca']; $pos_stats['credits_jour'] = (float)$r['cr'];
    $pos_stats['low_stock'] = count(array_filter($products, fn($p) => $p['stock_disponible'] <= $p['alert_quantity']));
    $lowStockProducts = array_values(array_filter($products, fn($p) => $p['stock_disponible'] <= $p['alert_quantity']));
    if ($lowStockProducts) {
        try {
            $summaryParts = [];
            foreach (array_slice($lowStockProducts, 0, 4) as $item) {
                $summaryParts[] = $item['name'] . ' (' . (float)$item['stock_disponible'] . ')';
            }
            $snapshot = [];
            foreach ($lowStockProducts as $item) {
                $snapshot[] = [
                    'id' => (int)$item['id'],
                    'stock' => (float)$item['stock_disponible'],
                    'alert' => (float)$item['alert_quantity'],
                ];
            }
            appAlertNotifyRoles($pdo, appAlertOpsRoles(), [
                'title' => 'Stock bas détecté',
                'body' => mb_strimwidth(
                    $pos_stats['low_stock'] . ' produit(s) sous seuil · ' . implode(', ', $summaryParts),
                    0,
                    180,
                    '…',
                    'UTF-8'
                ),
                'url' => project_url('finance/caisse_complete_enhanced.php'),
                'tag' => 'low-stock-' . $company_id . '-' . $city_id,
                'unread' => 1,
            ], [
                'event_type' => 'low_stock',
                'event_key' => 'low-stock-' . $company_id . '-' . $city_id,
                'cooldown_seconds' => 1800,
                'actor_user_id' => (int)$user_id,
                'snapshot' => $snapshot,
            ]);
        } catch (Throwable $e) {
            error_log('[LOW STOCK ALERT] ' . $e->getMessage());
        }
    }
}

/* ══════════════════════════════════════════════════
   ★ TICKETS : invoices + orders (bons de livraison)
══════════════════════════════════════════════════ */
$daily_tickets = [];
if ($location_set && $view_mode === 'tickets') {
    $st = $pdo->prepare("
        SELECT
            i.id,
            'invoice' COLLATE utf8mb4_unicode_ci AS source,
            i.total           AS total,
            i.status COLLATE utf8mb4_unicode_ci AS status,
            i.created_at,
            c.name COLLATE utf8mb4_unicode_ci AS client_name,
            c.phone COLLATE utf8mb4_unicode_ci AS client_phone,
            NULL              AS order_number,
            NULL              AS payment_method,
            NULL              AS order_status
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.company_id = ? AND i.city_id = ? AND DATE(i.created_at) = ?

        UNION ALL

        SELECT
            o.id,
            'order' COLLATE utf8mb4_unicode_ci AS source,
            o.total_amount    AS total,
            CASE o.status
                WHEN 'done'       THEN 'Payée'
                WHEN 'delivering' THEN 'En livraison'
                WHEN 'confirmed'  THEN 'Confirmé'
                ELSE o.status
            END COLLATE utf8mb4_unicode_ci AS status,
            o.created_at,
            COALESCE(c.name, '') COLLATE utf8mb4_unicode_ci AS client_name,
            COALESCE(c.phone, '') COLLATE utf8mb4_unicode_ci AS client_phone,
            o.order_number COLLATE utf8mb4_unicode_ci,
            o.payment_method COLLATE utf8mb4_unicode_ci,
            o.status COLLATE utf8mb4_unicode_ci AS order_status
        FROM orders o
        LEFT JOIN clients c ON c.id = o.client_id
        WHERE o.company_id = ? AND o.city_id = ?
          AND DATE(o.created_at) = ?
          AND o.status IN ('confirmed','delivering','done')

        ORDER BY created_at DESC
    ");
    $st->execute([$company_id, $city_id, $date_filter,
                  $company_id, $city_id, $date_filter]);
    $daily_tickets = $st->fetchAll(PDO::FETCH_ASSOC);
}

$daily_expenses = [];
if ($location_set && $view_mode === 'expenses') {
    $st = $pdo->prepare("SELECT * FROM expenses WHERE company_id=? AND city_id=? AND DATE(expense_date)=? ORDER BY expense_date DESC");
    $st->execute([$company_id,$city_id,$date_filter]);
    $daily_expenses = $st->fetchAll(PDO::FETCH_ASSOC);
}

$stock_realtime = [];
if ($location_set && $view_mode === 'stock') {
    $st = $pdo->prepare("SELECT p.id, p.name, p.price, p.alert_quantity,
        COALESCE(SUM(CASE WHEN sm.type='initial' THEN sm.quantity END),0) AS initial_stock,
        COALESCE(SUM(CASE WHEN sm.type='entry' AND sm.reference NOT LIKE 'ANNULATION-VENTE-%' THEN sm.quantity END),0) AS entrees,
        COALESCE(SUM(CASE WHEN sm.type='exit' THEN sm.quantity END),0) AS sorties,
        COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) AS ajustements,
        COALESCE(SUM(CASE WHEN sm.type='entry' AND sm.reference LIKE 'ANNULATION-VENTE-%' THEN sm.quantity END),0) AS ventes_annulees,
        COALESCE(SUM(CASE WHEN sm.type='initial' THEN sm.quantity END),0)+COALESCE(SUM(CASE WHEN sm.type='entry' THEN sm.quantity END),0)-COALESCE(SUM(CASE WHEN sm.type='exit' THEN sm.quantity END),0)+COALESCE(SUM(CASE WHEN sm.type='adjustment' THEN sm.quantity END),0) AS stock_actuel
        FROM products p LEFT JOIN stock_movements sm ON sm.product_id=p.id AND sm.company_id=? AND sm.city_id=?
        WHERE p.company_id=? GROUP BY p.id ORDER BY p.name");
    $st->execute([$company_id,$city_id,$company_id]);
    $stock_realtime = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ══════════════════════════════════════════════════
   ★ LOGS : cash_log + caisse_logs (bons de livraison)
══════════════════════════════════════════════════ */
$logs = [];
if ($location_set && $view_mode === 'logs') {
    $st = $pdo->prepare("
        SELECT
            cl.created_at,
            cl.action_type COLLATE utf8mb4_unicode_ci AS action_type,
            cl.action_description COLLATE utf8mb4_unicode_ci AS action_description,
            COALESCE(cl.amount, 0) AS amount,
            COALESCE(u.username, 'Inconnu') COLLATE utf8mb4_unicode_ci AS user_name,
            'cash_log' COLLATE utf8mb4_unicode_ci AS source
        FROM cash_log cl
        LEFT JOIN users u ON u.id = cl.user_id
        WHERE DATE(cl.created_at) = ?

        UNION ALL

        SELECT
            lg.created_at,
            CONCAT('BON_', UPPER(lg.action)) COLLATE utf8mb4_unicode_ci AS action_type,
            CONCAT(
                'Bon ',
                COALESCE(o.order_number, CONCAT('#', o.id)),
                ' — ',
                COALESCE(lg.details, '')
            ) COLLATE utf8mb4_unicode_ci AS action_description,
            COALESCE(o.total_amount, 0) AS amount,
            COALESCE(lg.user_name, 'Inconnu') COLLATE utf8mb4_unicode_ci AS user_name,
            'caisse_logs' COLLATE utf8mb4_unicode_ci AS source
        FROM caisse_logs lg
        LEFT JOIN orders o ON lg.order_id = o.id
        WHERE DATE(lg.created_at) = ?
          AND o.company_id = ?
          AND o.city_id    = ?

        ORDER BY created_at DESC
        LIMIT 500
    ");
    $st->execute([$date_filter, $date_filter, $company_id, $city_id]);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);
}

$client_debts = [];
if ($location_set && $view_mode === 'credits') {
    $st = $pdo->prepare("SELECT c.name,c.phone, COUNT(i.id) nb_factures, SUM(i.total) total_du, COALESCE(SUM(v.amount),0) total_paye, SUM(i.total)-COALESCE(SUM(v.amount),0) solde_restant
        FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN versements v ON v.invoice_id=i.id
        WHERE i.company_id=? AND i.city_id=? AND i.status='Impayée' GROUP BY c.id HAVING solde_restant>0 ORDER BY solde_restant DESC");
    $st->execute([$company_id,$city_id]);
    $client_debts = $st->fetchAll(PDO::FETCH_ASSOC);
}

$my_appro_requests = []; $appro_history_req = []; $appro_viewed_req = null;
$appro_view = $_GET['appro_view'] ?? 'list'; $appro_pending_all = 0;
if ($location_set && $view_mode === 'appro') {
    if ($CAN_CONFIRM_APPRO) {
        $st = $pdo->prepare("SELECT ar.*, p.name product_name, p.category product_category, u.username requester_name, u.role requester_role, a.username admin_name FROM appro_requests ar JOIN products p ON p.id=ar.product_id JOIN users u ON u.id=ar.requested_by LEFT JOIN users a ON a.id=ar.admin_id WHERE ar.company_id=? AND ar.city_id=? ORDER BY FIELD(ar.status,'en_attente','confirmee','rejetee','annulee'), ar.created_at DESC");
        $st->execute([$company_id,$city_id]);
    } else {
        $st = $pdo->prepare("SELECT ar.*, p.name product_name, p.category product_category, u.username requester_name, u.role requester_role, a.username admin_name FROM appro_requests ar JOIN products p ON p.id=ar.product_id JOIN users u ON u.id=ar.requested_by LEFT JOIN users a ON a.id=ar.admin_id WHERE ar.company_id=? AND ar.city_id=? AND ar.requested_by=? ORDER BY FIELD(ar.status,'en_attente','confirmee','rejetee','annulee'), ar.created_at DESC");
        $st->execute([$company_id,$city_id,$user_id]);
    }
    $my_appro_requests = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($appro_view === 'history' && isset($_GET['req_id'])) {
        $hid = (int)$_GET['req_id'];
        if ($CAN_CONFIRM_APPRO) { $st2 = $pdo->prepare("SELECT ar.*, p.name product_name, u.username requester_name FROM appro_requests ar JOIN products p ON p.id=ar.product_id JOIN users u ON u.id=ar.requested_by WHERE ar.id=?"); $st2->execute([$hid]); }
        else { $st2 = $pdo->prepare("SELECT ar.*, p.name product_name, u.username requester_name FROM appro_requests ar JOIN products p ON p.id=ar.product_id JOIN users u ON u.id=ar.requested_by WHERE ar.id=? AND ar.requested_by=?"); $st2->execute([$hid,$user_id]); }
        $appro_viewed_req = $st2->fetch(PDO::FETCH_ASSOC);
        if ($appro_viewed_req) {
            $st3 = $pdo->prepare("SELECT arh.*, u.username user_name, u.role user_role FROM appro_request_history arh LEFT JOIN users u ON u.id=arh.user_id WHERE arh.request_id=? ORDER BY arh.created_at ASC"); $st3->execute([$hid]);
            $appro_history_req = $st3->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$appro_pending_mine = 0;
if ($location_set) {
    if ($CAN_CONFIRM_APPRO) { $st = $pdo->prepare("SELECT COUNT(*) FROM appro_requests WHERE company_id=? AND city_id=? AND status='en_attente'"); $st->execute([$company_id,$city_id]); }
    else { $st = $pdo->prepare("SELECT COUNT(*) FROM appro_requests WHERE company_id=? AND city_id=? AND requested_by=? AND status='en_attente'"); $st->execute([$company_id,$city_id,$user_id]); }
    $appro_pending_mine = (int)$st->fetchColumn();
}

$sales_by_product = [];
$cash_report = ['nb_ventes'=>0,'total_ventes'=>0,'total_paye'=>0,'total_credit'=>0,'total_depenses'=>0,'solde_net'=>0];
if ($location_set && $view_mode === 'reports') {
    $st = $pdo->prepare("SELECT p.name produit,SUM(ii.quantity) quantite_vendue,SUM(ii.total) chiffre_affaires FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id JOIN products p ON p.id=ii.product_id WHERE i.company_id=? AND i.city_id=? AND DATE(i.created_at)=? GROUP BY p.id ORDER BY chiffre_affaires DESC"); $st->execute([$company_id,$city_id,$date_filter]); $sales_by_product = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT COUNT(*) nb_ventes,COALESCE(SUM(total),0) total_ventes, COALESCE(SUM(CASE WHEN status='Payée' THEN total ELSE 0 END),0) total_paye, COALESCE(SUM(CASE WHEN status='Impayée' THEN total ELSE 0 END),0) total_credit FROM invoices WHERE company_id=? AND city_id=? AND DATE(created_at)=?"); $st->execute([$company_id,$city_id,$date_filter]); $cash_report = $st->fetch(PDO::FETCH_ASSOC); $cash_report = array_map('floatval', $cash_report);
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company_id=? AND city_id=? AND DATE(expense_date)=?"); $st->execute([$company_id,$city_id,$date_filter]); $cash_report['total_depenses'] = (float)$st->fetchColumn();
    $cash_report['solde_net'] = $cash_report['total_paye'] - $cash_report['total_depenses'];
}

if (isset($_GET['export_expenses']) && $location_set) {
    $st = $pdo->prepare("SELECT * FROM expenses WHERE company_id=? AND city_id=? AND DATE(expense_date)=? ORDER BY expense_date DESC"); $st->execute([$company_id,$city_id,$date_filter]); $exps = $st->fetchAll(PDO::FETCH_ASSOC);
    $sp = new Spreadsheet(); $sh = $sp->getActiveSheet(); $sh->setTitle('Dépenses');
    $sh->fromArray(['ID','Catégorie','Montant','Note','Date'],null,'A1'); $row=2; $tot=0;
    foreach($exps as $e){ $sh->fromArray([$e['id'],$e['category'],$e['amount'],$e['note'],$e['expense_date']],null,"A$row"); $tot+=$e['amount']; $row++; }
    $sh->setCellValue("B$row","TOTAL"); $sh->setCellValue("C$row",$tot);
    logAction($pdo,$user_id,'EXPORT_EXPENSES',"Export dépenses $date_filter",null,null,$tot);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="depenses_'.$date_filter.'.xlsx"'); header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output'); exit;
}
if (isset($_GET['export_report']) && $location_set) {
    $sp = new Spreadsheet(); $sh = $sp->getActiveSheet(); $sh->setTitle('Ventes');
    $sh->fromArray(['Produit','Qté vendue','CA'],null,'A1'); $row=2;
    foreach($sales_by_product as $it){ $sh->fromArray([$it['produit'],$it['quantite_vendue'],$it['chiffre_affaires']],null,"A$row"); $row++; }
    $sh2 = $sp->createSheet(); $sh2->setTitle('Résumé');
    $sh2->fromArray([['Ventes',(int)$cash_report['nb_ventes']],['Total ventes',$cash_report['total_ventes']],['Payé',$cash_report['total_paye']],['Crédit',$cash_report['total_credit']],['Dépenses',$cash_report['total_depenses']],['Solde net',$cash_report['solde_net']]],null,'A1');
    logAction($pdo,$user_id,'EXPORT_REPORT',"Export rapport $date_filter");
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="rapport_'.$date_filter.'.xlsx"'); header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output'); exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Caisse Pro — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;font-style:normal}
:root{--bg:#0f1726;--surf:#162033;--card:#1b263b;--card2:#22324a;--bord:rgba(148,163,184,0.18);--neon:#00a86b;--neon2:#00c87a;--red:#e53935;--orange:#f57c00;--blue:#1976d2;--gold:#f9a825;--purple:#a855f7;--cyan:#06b6d4;--text:#e8eef8;--text2:#bfd0e4;--muted:#8ea3bd;--glow:0 8px 24px rgba(0,168,107,0.18);--glow-r:0 8px 24px rgba(229,57,53,0.18);--glow-gold:0 8px 24px rgba(249,168,37,0.18);--glow-cyan:0 8px 24px rgba(0,151,167,0.18);--fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;--fb:'Inter','Segoe UI',system-ui,sans-serif}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:var(--fb);font-size:15px;line-height:1.7;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.08) 0%,transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.07) 0%,transparent 62%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);background-size:46px 46px}
.wrap{position:relative;z-index:1;max-width:1680px;margin:0 auto;padding:16px 16px 48px}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;background:rgba(22,32,51,0.96);border:1px solid var(--bord);border-radius:18px;padding:18px 28px;margin-bottom:16px;backdrop-filter:blur(24px)}
.brand{display:flex;align-items:center;gap:16px;flex-shrink:0}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,var(--gold),var(--orange));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;box-shadow:var(--glow-gold);animation:breathe 3.2s ease-in-out infinite;flex-shrink:0}
@keyframes breathe{0%,100%{box-shadow:0 0 14px rgba(255,208,96,0.4)}50%{box-shadow:0 0 38px rgba(255,208,96,0.85)}}
.brand-txt h1{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);letter-spacing:0.5px;line-height:1.2}
.brand-txt p{font-size:11px;font-weight:700;color:var(--gold);letter-spacing:2.8px;text-transform:uppercase;margin-top:3px}
.clock-d{font-family:var(--fh);font-size:32px;font-weight:900;color:var(--gold);letter-spacing:5px;text-shadow:0 0 22px rgba(255,208,96,0.55);line-height:1}
.clock-sub{font-size:11px;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:5px}
.user-badge{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--gold),var(--orange));color:var(--bg);padding:11px 22px;border-radius:32px;font-family:var(--fh);font-size:14px;font-weight:900;box-shadow:var(--glow-gold);flex-shrink:0}
.nav-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;background:rgba(27,38,59,0.9);border:1px solid var(--bord);border-radius:16px;padding:14px 22px;margin-bottom:18px;backdrop-filter:blur(20px)}
.nb{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:1.5px solid var(--bord);background:rgba(255,208,96,0.07);color:var(--text2);font-family:var(--fh);font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;letter-spacing:0.4px;transition:all 0.28s cubic-bezier(0.23,1,0.32,1)}
.nb:hover{background:var(--gold);color:var(--bg);border-color:var(--gold);box-shadow:var(--glow-gold);transform:translateY(-2px)}
.nb.active{background:var(--gold);color:var(--bg);border-color:var(--gold);box-shadow:var(--glow-gold)}
.nb.green{border-color:rgba(50,190,143,0.3);color:var(--neon);background:rgba(50,190,143,0.07)}
.nb.green:hover,.nb.green.active{background:var(--neon);color:var(--bg);border-color:var(--neon);box-shadow:var(--glow)}
.nb.cyan{border-color:rgba(6,182,212,0.3);color:var(--cyan);background:rgba(6,182,212,0.07)}
.nb.cyan:hover,.nb.cyan.active{background:var(--cyan);color:var(--bg);border-color:var(--cyan);box-shadow:var(--glow-cyan)}
.nb.purple{border-color:rgba(168,85,247,0.3);color:var(--purple);background:rgba(168,85,247,0.07)}
.nb.purple:hover,.nb.purple.active{background:var(--purple);color:#fff;border-color:var(--purple);box-shadow:0 0 26px rgba(168,85,247,0.45)}
.nb.red{border-color:rgba(255,53,83,0.3);color:var(--red);background:rgba(255,53,83,0.07)}
.nb.red:hover{background:var(--red);color:#fff;border-color:var(--red);box-shadow:var(--glow-r)}
.nav-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 4px;background:var(--orange);color:#fff;border-radius:20px;font-size:10px;font-weight:900;margin-left:2px;animation:pulse-o 1.8s ease infinite}
@keyframes pulse-o{0%,100%{box-shadow:0 0 0 0 rgba(255,145,64,0.5)}50%{box-shadow:0 0 0 5px transparent}}
.alert{display:flex;align-items:center;gap:16px;flex-wrap:wrap;border-radius:14px;padding:16px 22px;margin-bottom:18px}
.alert.success{background:rgba(50,190,143,0.08);border:1px solid rgba(50,190,143,0.25)}
.alert.error{background:rgba(255,53,83,0.08);border:1px solid rgba(255,53,83,0.25)}
.alert i{font-size:22px;flex-shrink:0}
.alert.success i{color:var(--neon)}.alert.error i{color:var(--red)}
.alert span{font-family:var(--fb);font-size:14px;font-weight:700;line-height:1.6}
.alert.success span{color:var(--neon)}.alert.error span{color:var(--red)}
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.ks{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:20px 18px;display:flex;align-items:center;gap:14px;transition:all 0.3s}
.ks:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,0.38),var(--glow)}
.ks-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.ks-val{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);line-height:1}
.ks-lbl{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
.pos-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:18px}
.panel{background:var(--card);border:1px solid var(--bord);border-radius:18px;overflow:hidden;transition:border-color 0.3s}
.panel:hover{border-color:rgba(50,190,143,0.26)}
.ph{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.18)}
.ph-title{font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:10px;letter-spacing:0.4px;flex-wrap:wrap}
.dot{width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 9px var(--neon);flex-shrink:0;animation:pdot 2.2s infinite}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.75)}}
.dot.r{background:var(--red);box-shadow:0 0 9px var(--red)}.dot.g{background:var(--gold);box-shadow:0 0 9px var(--gold)}.dot.o{background:var(--orange);box-shadow:0 0 9px var(--orange)}.dot.b{background:var(--blue);box-shadow:0 0 9px var(--blue)}.dot.p{background:var(--purple);box-shadow:0 0 9px var(--purple)}.dot.c{background:var(--cyan);box-shadow:0 0 9px var(--cyan)}
.pbadge{font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 14px;border-radius:20px;white-space:nowrap;background:rgba(50,190,143,0.12);color:var(--neon);letter-spacing:0.5px}
.pbadge.r{background:rgba(255,53,83,0.12);color:var(--red)}.pbadge.g{background:rgba(255,208,96,0.12);color:var(--gold)}.pbadge.b{background:rgba(61,140,255,0.12);color:var(--blue)}.pbadge.c{background:rgba(6,182,212,0.12);color:var(--cyan)}.pbadge.o{background:rgba(255,145,64,0.12);color:var(--orange)}
.pb{padding:18px 20px}
.search-wrap{margin-bottom:16px}
.search-wrap input{width:100%;padding:13px 18px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:500;transition:all 0.3s}
.search-wrap input::placeholder{color:var(--muted)}
.search-wrap input:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,0.05)}
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:12px;max-height:480px;overflow-y:auto;padding-right:4px}
.products-grid::-webkit-scrollbar{width:6px}.products-grid::-webkit-scrollbar-track{background:rgba(0,0,0,0.2);border-radius:10px}.products-grid::-webkit-scrollbar-thumb{background:var(--neon);border-radius:10px;opacity:.5}
.prod-card{background:var(--card2);border:1px solid var(--bord);border-radius:14px;padding:16px 12px;cursor:pointer;text-align:center;transition:all 0.3s cubic-bezier(0.23,1,0.32,1);position:relative;overflow:hidden}
.prod-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--neon),transparent);opacity:0;transition:opacity 0.3s}
.prod-card:hover::before{opacity:1}
.prod-card:hover{transform:translateY(-5px);border-color:rgba(50,190,143,0.35);box-shadow:0 12px 28px rgba(0,0,0,0.4),var(--glow)}
.prod-card.low{border-color:rgba(255,208,96,0.3)}.prod-card.low::before{background:linear-gradient(90deg,transparent,var(--gold),transparent)}
.prod-img{width:100%;height:80px;border-radius:10px;overflow:hidden;margin-bottom:10px;background:rgba(0,0,0,0.18)}.prod-img img{width:100%;height:100%;object-fit:cover;display:block}.prod-img-empty{display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;opacity:.35}
.prod-name{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--text);margin-bottom:10px;line-height:1.35}
.prod-price{font-family:var(--fh);font-size:17px;font-weight:900;color:var(--neon);margin-bottom:6px}
.prod-stock{font-family:var(--fb);font-size:11px;font-weight:700;color:var(--muted)}.prod-stock.low{color:var(--gold)}
.cart-list{max-height:300px;overflow-y:auto;margin-bottom:16px}
.cart-list::-webkit-scrollbar{width:5px}.cart-list::-webkit-scrollbar-thumb{background:var(--neon);border-radius:10px}
.cart-item{display:flex;align-items:center;gap:12px;padding:12px 6px;border-bottom:1px solid rgba(255,255,255,0.04);transition:all 0.25s;border-radius:8px}
.cart-item:last-child{border-bottom:none}.cart-item:hover{background:rgba(50,190,143,0.04);padding-left:12px}
.ci-info{flex:1;min-width:0}
.ci-name{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ci-det{font-family:var(--fb);font-size:11px;color:var(--muted);margin-top:3px}
.ci-qty{display:flex;align-items:center;gap:6px;flex-shrink:0}
.qty-btn{background:rgba(50,190,143,0.15);border:1px solid rgba(50,190,143,0.3);color:var(--neon);width:28px;height:28px;border-radius:8px;cursor:pointer;font-weight:900;font-size:16px;display:flex;align-items:center;justify-content:center;transition:all 0.2s}
.qty-btn:hover{background:var(--neon);color:var(--bg)}.qty-btn.rm{background:rgba(255,53,83,0.15);border-color:rgba(255,53,83,0.3);color:var(--red)}.qty-btn.rm:hover{background:var(--red);color:#fff}
.qty-val{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);min-width:28px;text-align:center}
.cart-empty{text-align:center;padding:40px 20px;color:var(--muted)}.cart-empty i{font-size:44px;display:block;margin-bottom:14px;opacity:.2}.cart-empty p{font-family:var(--fb);font-size:14px;font-weight:500}
.cart-total-box{background:rgba(50,190,143,0.07);border:1px solid rgba(50,190,143,0.2);border-radius:14px;padding:16px 20px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center}
.cart-total-lbl{font-family:var(--fb);font-size:13px;font-weight:700;color:var(--muted)}
.cart-total-val{font-family:var(--fh);font-size:26px;font-weight:900;color:var(--neon)}
.f-select,.f-input{width:100%;padding:12px 16px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;margin-bottom:12px;transition:all 0.3s;appearance:none;-webkit-appearance:none}
.f-select:focus,.f-input:focus{outline:none;border-color:var(--neon);box-shadow:var(--glow);background:rgba(50,190,143,0.05)}
.f-select option{background:#1b263b;color:var(--text)}
.f-textarea{width:100%;padding:12px 16px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:12px;color:var(--text);font-family:var(--fb);font-size:14px;font-weight:600;margin-bottom:12px;transition:all 0.3s;resize:vertical;min-height:80px}
.f-textarea:focus{outline:none;border-color:var(--cyan);box-shadow:var(--glow-cyan)}
.f-label{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;display:block;margin-bottom:7px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;border:none;cursor:pointer;font-family:var(--fh);font-size:13px;font-weight:900;letter-spacing:0.4px;transition:all 0.28s;text-decoration:none;white-space:nowrap}
.btn-neon{background:rgba(50,190,143,0.12);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon)}.btn-neon:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow)}
.btn-gold{background:rgba(255,208,96,0.12);border:1.5px solid rgba(255,208,96,0.3);color:var(--gold)}.btn-gold:hover{background:var(--gold);color:var(--bg);box-shadow:var(--glow-gold)}
.btn-red{background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red)}.btn-red:hover{background:var(--red);color:#fff;box-shadow:var(--glow-r)}
.btn-blue{background:rgba(61,140,255,0.12);border:1.5px solid rgba(61,140,255,0.3);color:var(--blue)}.btn-blue:hover{background:var(--blue);color:#fff}
.btn-cyan{background:rgba(6,182,212,0.12);border:1.5px solid rgba(6,182,212,0.3);color:var(--cyan)}.btn-cyan:hover{background:var(--cyan);color:var(--bg);box-shadow:var(--glow-cyan)}
.btn-orange{background:rgba(255,145,64,0.12);border:1.5px solid rgba(255,145,64,0.3);color:var(--orange)}.btn-orange:hover{background:var(--orange);color:#fff}
.btn-purple{background:rgba(168,85,247,0.12);border:1.5px solid rgba(168,85,247,0.3);color:var(--purple)}.btn-purple:hover{background:var(--purple);color:#fff}
.btn-sm{padding:8px 14px;font-size:12px;border-radius:9px}.btn-xs{padding:5px 10px;font-size:11px;border-radius:7px}
.checkout-btn{width:100%;padding:16px;background:linear-gradient(135deg,var(--neon),var(--blue));border:none;border-radius:14px;color:var(--bg);font-family:var(--fh);font-size:16px;font-weight:900;cursor:pointer;letter-spacing:0.5px;box-shadow:var(--glow);transition:all 0.3s}
.checkout-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(50,190,143,0.5)}.checkout-btn:disabled{background:rgba(255,255,255,0.06);color:var(--muted);box-shadow:none;cursor:not-allowed;transform:none}
.tbl{width:100%;border-collapse:collapse}
.tbl th{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,0.06);text-align:left;background:rgba(0,0,0,0.15);white-space:nowrap}
.tbl td{font-family:var(--fb);font-size:13px;font-weight:500;color:var(--text2);padding:13px 14px;border-bottom:1px solid rgba(255,255,255,0.04);line-height:1.55;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}.tbl tbody tr{transition:all 0.25s}.tbl tbody tr:hover{background:rgba(50,190,143,0.04)}.tbl td strong{font-family:var(--fh);font-weight:900;color:var(--text)}
.bdg{font-family:var(--fb);font-size:10px;font-weight:800;padding:4px 11px;border-radius:20px;letter-spacing:0.5px;display:inline-block}
.bdg-g{background:rgba(50,190,143,0.14);color:var(--neon)}.bdg-r{background:rgba(255,53,83,0.14);color:var(--red)}.bdg-gold{background:rgba(255,208,96,0.14);color:var(--gold)}.bdg-blue{background:rgba(61,140,255,0.14);color:var(--blue)}.bdg-purple{background:rgba(168,85,247,0.14);color:var(--purple)}.bdg-cyan{background:rgba(6,182,212,0.14);color:var(--cyan)}.bdg-orange{background:rgba(255,145,64,0.14);color:var(--orange)}.bdg-muted{background:rgba(90,128,112,0.14);color:var(--muted)}.bdg-annule{background:rgba(255,53,83,0.2);color:var(--red);border:1px solid rgba(255,53,83,0.4);font-size:11px;padding:5px 12px;font-weight:900;letter-spacing:0.5px}
/* ★ Badge Source (invoice vs bon livraison) */
.src-invoice{background:rgba(50,190,143,0.1);color:var(--neon);border:1px solid rgba(50,190,143,0.2);font-size:9px;font-weight:800;padding:3px 8px;border-radius:20px;display:inline-block}
.src-order{background:rgba(6,182,212,0.1);color:var(--cyan);border:1px solid rgba(6,182,212,0.2);font-size:9px;font-weight:800;padding:3px 8px;border-radius:20px;display:inline-block}
/* ★ Badge source dans les logs */
.log-src-cash{background:rgba(50,190,143,0.1);color:var(--neon);border:1px solid rgba(50,190,143,0.2);font-size:9px;font-weight:800;padding:2px 7px;border-radius:20px;display:inline-block;margin-left:4px}
.log-src-bon{background:rgba(6,182,212,0.1);color:var(--cyan);border:1px solid rgba(6,182,212,0.2);font-size:9px;font-weight:800;padding:2px 7px;border-radius:20px;display:inline-block;margin-left:4px}
.s-badge{display:inline-flex;align-items:center;gap:6px;font-family:var(--fb);font-size:11px;font-weight:800;padding:5px 12px;border-radius:20px;white-space:nowrap}
.s-attente{background:rgba(255,208,96,0.14);color:var(--gold);border:1px solid rgba(255,208,96,0.25)}.s-confirmee{background:rgba(50,190,143,0.14);color:var(--neon);border:1px solid rgba(50,190,143,0.25)}.s-rejetee{background:rgba(255,53,83,0.14);color:var(--red);border:1px solid rgba(255,53,83,0.25)}.s-annulee{background:rgba(90,128,112,0.14);color:var(--muted);border:1px solid rgba(90,128,112,0.25)}
.unit-toggle{display:flex;gap:12px;margin-bottom:14px}.unit-opt{flex:1;position:relative}.unit-opt input[type=radio]{position:absolute;opacity:0;width:0;height:0}.unit-opt label{display:flex;flex-direction:column;align-items:center;gap:7px;padding:18px 12px;border:2px solid var(--bord);border-radius:14px;cursor:pointer;transition:all 0.28s;background:rgba(0,0,0,0.2)}.unit-opt label i{font-size:24px;color:var(--muted)}.unit-opt label .u-title{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--muted)}.unit-opt label .u-sub{font-family:var(--fb);font-size:11px;color:var(--muted);opacity:.7;text-align:center;line-height:1.4}.unit-opt input[type=radio]:checked + label{border-color:var(--cyan);background:rgba(6,182,212,0.08);box-shadow:0 0 20px rgba(6,182,212,0.2)}.unit-opt input[type=radio]:checked + label i,.unit-opt input[type=radio]:checked + label .u-title{color:var(--cyan)}
.unit-detail{background:rgba(6,182,212,0.12);color:var(--cyan);border:1px solid rgba(6,182,212,0.25);font-size:11px;font-weight:800;padding:4px 11px;border-radius:20px;display:inline-flex;align-items:center;gap:5px}.unit-carton{background:rgba(255,145,64,0.12);color:var(--orange);border:1px solid rgba(255,145,64,0.25);font-size:11px;font-weight:800;padding:4px 11px;border-radius:20px;display:inline-flex;align-items:center;gap:5px}
.timeline{padding:8px 0}.tl-item{display:flex;gap:16px;position:relative}.tl-item::before{content:'';position:absolute;left:19px;top:46px;bottom:0;width:2px;background:linear-gradient(to bottom,var(--bord),transparent)}.tl-item:last-child::before{display:none}.tl-ico{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;margin-top:4px;border:2px solid}.tl-body{flex:1;padding-bottom:22px}.tl-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:5px}.tl-action{font-family:var(--fh);font-size:13px;font-weight:900;color:var(--text)}.tl-time{font-family:var(--fb);font-size:11px;color:var(--muted);white-space:nowrap;flex-shrink:0}.tl-who{font-family:var(--fb);font-size:12px;font-weight:700;color:var(--cyan);margin-bottom:5px}.tl-detail{font-family:var(--fb);font-size:12px;color:var(--text2);background:rgba(0,0,0,0.2);border:1px solid var(--bord);border-radius:10px;padding:9px 14px;line-height:1.65}
.appro-subnav{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap}.asn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:11px;border:1.5px solid var(--bord);background:rgba(6,182,212,0.05);color:var(--text2);font-family:var(--fh);font-size:12px;font-weight:900;text-decoration:none;white-space:nowrap;transition:all 0.25s}.asn:hover,.asn.active{background:var(--cyan);color:var(--bg);border-color:var(--cyan);box-shadow:var(--glow-cyan)}
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}.stat-box{background:var(--card);border:1px solid var(--bord);border-radius:16px;padding:22px 18px;position:relative;overflow:hidden;transition:all 0.3s}.stat-box:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,0.38)}.stat-val{font-family:var(--fh);font-size:28px;font-weight:900;color:var(--text);line-height:1;margin-bottom:8px}.stat-lbl{font-family:var(--fb);font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(4px)}.modal.show{display:flex}.modal-box{background:var(--card);border:1px solid var(--bord);border-radius:20px;padding:30px;max-width:560px;width:92%;max-height:85vh;overflow-y:auto;animation:mzoom .25s ease}
@keyframes mzoom{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}
.modal-box h2{font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text);margin-bottom:22px;line-height:1.3}.modal-box h2.danger{color:var(--red)}.modal-box h2.cyan{color:var(--cyan)}.modal-box h2.gold{color:var(--gold)}.modal-sep{height:1px;background:rgba(255,255,255,0.05);margin:18px 0}.modal-btns{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap}.modal-btns>*{flex:1;justify-content:center}
.calc-wrap{position:fixed;bottom:80px;right:24px;z-index:999}.calc-toggle{width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--blue));border:none;color:var(--bg);font-size:22px;cursor:pointer;box-shadow:var(--glow);transition:all 0.3s}.calc-toggle:hover{transform:scale(1.1)}.calculator{position:absolute;bottom:72px;right:0;width:290px;background:var(--card);border:1px solid var(--bord);border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,0.5),var(--glow);display:none;animation:mzoom .2s ease}.calculator.show{display:block}.calc-hdr{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;justify-content:space-between;align-items:center}.calc-hdr span{font-family:var(--fh);font-size:14px;font-weight:900;color:var(--neon)}.calc-hdr button{background:none;border:none;color:var(--muted);cursor:pointer;font-size:20px;line-height:1}.calc-hdr button:hover{color:var(--red)}.calc-disp{width:calc(100% - 32px);margin:14px 16px;padding:12px 16px;background:rgba(15,23,38,0.76);border:1px solid var(--bord);border-radius:10px;color:var(--neon);font-family:var(--fh);font-size:22px;font-weight:900;text-align:right;letter-spacing:2px}.calc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding:0 16px 16px}.calc-grid button{padding:14px;border:1px solid var(--bord);border-radius:10px;background:rgba(50,190,143,0.08);color:var(--text);font-family:var(--fh);font-size:16px;font-weight:900;cursor:pointer;transition:all 0.2s}.calc-grid button:hover{background:var(--neon);color:var(--bg);box-shadow:var(--glow)}.calc-grid button.op{background:rgba(61,140,255,0.1);color:var(--blue)}.calc-grid button.op:hover{background:var(--blue);color:#fff}.calc-grid button.eq{background:var(--neon);color:var(--bg)}.calc-grid button.cl{background:rgba(255,53,83,0.12);color:var(--red);grid-column:span 4}.calc-grid button.cl:hover{background:var(--red);color:#fff}
.log-item{padding:13px 16px;border-bottom:1px solid rgba(255,255,255,0.04);font-size:12px;display:flex;gap:12px;align-items:flex-start;transition:all 0.2s;border-radius:6px}.log-item:hover{background:rgba(50,190,143,0.04)}.log-time{font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);white-space:nowrap;flex-shrink:0}.log-type{font-family:var(--fb);font-size:10px;font-weight:800;padding:3px 9px;border-radius:10px;flex-shrink:0}.log-desc{font-family:var(--fb);font-size:12px;color:var(--text2);line-height:1.5;flex:1}
.sec-title{display:flex;align-items:center;gap:14px;margin:28px 0 16px}.sec-title h2{font-family:var(--fh);font-size:18px;font-weight:900;color:var(--text);letter-spacing:0.5px}.sec-title::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--bord),transparent)}.sec-line{width:4px;height:24px;border-radius:4px;background:linear-gradient(to bottom,var(--gold),var(--orange));flex-shrink:0}
.loc-box{background:var(--card);border:1px solid var(--bord);border-radius:18px;padding:40px;text-align:center}.loc-box h2{font-family:var(--fh);font-size:22px;font-weight:900;color:var(--text);margin-bottom:8px}.loc-box p{font-family:var(--fb);font-size:14px;color:var(--muted);margin-bottom:28px}
.empty-st{text-align:center;padding:44px 20px;color:var(--muted)}.empty-st i{font-size:48px;display:block;margin-bottom:14px;opacity:.15}.empty-st p{font-family:var(--fb);font-size:14px;opacity:.6;margin-bottom:16px}
@media(max-width:1100px){.pos-grid{grid-template-columns:1fr}.kpi-strip{grid-template-columns:repeat(2,1fr)}.stat-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:720px){
body{line-height:1.5}
.wrap{padding:8px 8px 88px;max-width:680px}
.topbar{position:sticky;top:0;z-index:80;padding:10px 12px;border-radius:16px;margin-bottom:12px;background:rgba(22,32,51,.97);backdrop-filter:blur(16px)}
.brand{gap:10px}
.brand-ico{width:38px;height:38px;border-radius:12px;font-size:18px}
.brand-txt h1{font-size:16px}
.brand-txt p{font-size:9px;letter-spacing:1.4px}
.clock-d{font-size:20px;letter-spacing:2px}
.clock-sub{font-size:9px}
.user-badge{padding:8px 12px;font-size:11px;border-radius:24px}
.nav-bar{padding:10px 12px;border-radius:14px;gap:6px;margin-bottom:12px}
.nb{flex:1 1 calc(50% - 6px);justify-content:center;padding:9px 10px;font-size:10px;border-radius:12px}
.nav-badge{min-width:16px;height:16px;font-size:8px}
.alert{padding:12px 14px;font-size:12px;border-radius:12px;margin-bottom:12px}
.alert i{font-size:18px}
.kpi-strip{grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px}
.ks{padding:12px 10px;gap:10px;border-radius:14px}
.ks-ico{width:38px;height:38px;border-radius:11px;font-size:18px}
.ks-val{font-size:19px}
.ks-lbl{font-size:9px;letter-spacing:.7px}
.pos-grid{gap:10px;margin-bottom:12px}
.panel{border-radius:16px}
.ph{padding:12px 14px;gap:10px}
.ph-title{font-size:13px;gap:8px}
.pbadge{font-size:9px;padding:4px 9px}
.pb{padding:12px}
.search-wrap{margin-bottom:12px}
.search-wrap input{padding:11px 12px;font-size:13px;border-radius:12px}
.products-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:none}
.prod-card{padding:12px 8px;border-radius:14px}
.prod-img{height:62px;border-radius:9px;margin-bottom:8px}
.prod-name{font-size:11px;margin-bottom:7px}
.prod-price{font-size:14px}
.prod-price span{font-size:9px !important}
.prod-stock{font-size:9px}
.cart-list{max-height:none;margin-bottom:12px}
.cart-item{padding:10px 2px;gap:10px}
.ci-name{font-size:12px}
.ci-det{font-size:10px}
.ci-qty{gap:4px}
.qty-btn{width:24px;height:24px;border-radius:7px;font-size:13px}
.qty-val{min-width:22px;font-size:13px}
.cart-empty{padding:24px 12px}
.cart-empty i{font-size:30px;margin-bottom:8px}
.cart-empty p{font-size:12px}
.cart-total-box{padding:12px 14px;border-radius:12px;margin-bottom:12px}
.cart-total-lbl{font-size:11px}
.cart-total-val{font-size:20px}
.f-label{font-size:10px;margin-bottom:6px}
.f-select,.f-input,.f-textarea{padding:11px 12px;font-size:13px;border-radius:12px;margin-bottom:10px}
.f-textarea{min-height:88px}
.btn{padding:10px 12px;font-size:11px;border-radius:12px}
.btn-sm{padding:8px 10px;font-size:10px}
.btn-xs{padding:6px 8px;font-size:10px}
.checkout-btn{padding:12px 14px;font-size:13px;border-radius:12px}
.tbl th{padding:10px 8px;font-size:10px}
.tbl td{padding:10px 8px;font-size:10px}
.bdg,.s-badge{font-size:9px;padding:4px 8px}
.src-invoice,.src-order,.log-src-cash,.log-src-bon{font-size:8px;padding:2px 6px}
.stat-row{grid-template-columns:1fr;gap:8px;margin-bottom:12px}
.stat-box{padding:14px 12px;border-radius:14px}
.stat-val{font-size:20px}
.stat-lbl{font-size:10px}
.appro-subnav{gap:8px;margin-bottom:12px}
.asn{padding:8px 10px;font-size:10px;border-radius:11px}
.unit-toggle{flex-direction:column;gap:8px}
.unit-opt label{padding:12px 10px;border-radius:12px}
.unit-opt label i{font-size:18px}
.unit-opt label .u-title{font-size:11px}
.unit-opt label .u-sub{font-size:9px}
.timeline .tl-item{gap:10px}
.tl-ico{width:32px;height:32px;font-size:12px}
.tl-item::before{left:15px;top:36px}
.tl-body{padding-bottom:14px}
.tl-action{font-size:12px}
.tl-time,.tl-who,.tl-detail{font-size:10px}
.modal-box{padding:18px 14px;border-radius:16px;width:96%}
.modal-box h2{font-size:16px;margin-bottom:14px}
.modal-btns{gap:8px}
.calc-wrap{bottom:78px;right:14px}
.calc-toggle{width:48px;height:48px;font-size:18px}
.calculator{width:260px;bottom:58px;border-radius:16px}
.calc-disp{font-size:18px}
.calc-grid button{padding:11px;font-size:14px}
.sec-title{margin:18px 0 10px;gap:10px}
.sec-title h2{font-size:14px}
.sec-line{height:18px}
}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.panel{animation:fadeUp .5s ease .08s backwards}

/* ══════════════════════════════════════════════════════
   ANDROID NATIVE BOTTOM NAVIGATION BAR — CAISSE PRO
══════════════════════════════════════════════════════ */
.nav-bar{display:none!important;}
.wrap{padding-bottom:120px!important;}

.android-nav{
    position:fixed;bottom:0;left:0;right:0;z-index:895;
    background:rgba(10,17,32,0.98);
    border-top:1px solid var(--bord);
    box-shadow:0 -4px 28px rgba(0,0,0,.45);
    display:flex;align-items:stretch;
    height:64px;
    padding-bottom:env(safe-area-inset-bottom,0px);
    overflow-x:auto;overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:none;
    backdrop-filter:blur(20px);
}
.android-nav::-webkit-scrollbar{display:none;}

.bnav-item{
    flex:1;min-width:58px;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    gap:3px;padding:6px 2px 10px;
    border:none;background:transparent;
    cursor:pointer;position:relative;
    -webkit-tap-highlight-color:transparent;
    text-decoration:none;overflow:hidden;
    transition:none;
}
.bnav-item::before{
    content:'';position:absolute;top:6px;left:50%;
    transform:translateX(-50%) scaleX(0);
    width:52px;height:28px;
    background:rgba(255,208,96,.14);
    border-radius:14px;
    transition:transform .3s cubic-bezier(.34,1.4,.64,1),background .2s ease;
}
.bnav-item.active::before{transform:translateX(-50%) scaleX(1);}
.bnav-icon{
    font-size:19px;color:var(--muted);
    position:relative;z-index:1;
    transition:color .22s ease,transform .32s cubic-bezier(.34,1.56,.64,1);
}
.bnav-item.active .bnav-icon{color:var(--gold);transform:translateY(-2px) scale(1.12);}
.bnav-lbl{
    font-family:var(--fh);font-size:8.5px;font-weight:900;
    color:var(--muted);letter-spacing:.3px;
    white-space:nowrap;position:relative;z-index:1;
    transition:color .22s ease;
}
.bnav-item.active .bnav-lbl{color:var(--gold);}
.bnav-badge{
    position:absolute;top:4px;left:calc(50% + 6px);
    min-width:15px;height:15px;
    background:var(--orange);color:#fff;
    font-size:8px;font-weight:900;font-family:var(--fh);
    border-radius:8px;padding:0 4px;
    display:flex;align-items:center;justify-content:center;
    border:1.5px solid rgba(10,17,32,.98);
    animation:pulse-o 1.8s ease infinite;
}

/* Plus slide-up sheet */
.bnav-more-overlay{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);
    z-index:891;backdrop-filter:blur(6px);
}
.bnav-more-overlay.show{display:block;}
.bnav-more-sheet{
    position:fixed;bottom:64px;left:0;right:0;z-index:892;
    background:var(--card);border:1px solid var(--bord);
    border-radius:22px 22px 0 0;
    padding:14px 14px 22px;
    transform:translateY(110%);
    transition:transform .32s cubic-bezier(.23,1,.32,1);
    max-height:72vh;overflow-y:auto;
}
.bnav-more-sheet.show{transform:translateY(0);}
.bnav-sheet-handle{
    width:40px;height:4px;background:var(--bord);
    border-radius:2px;margin:0 auto 14px;
}
.bnav-sheet-title{
    font-family:var(--fh);font-size:10px;font-weight:900;
    color:var(--muted);letter-spacing:1.8px;text-transform:uppercase;
    margin-bottom:14px;
}
.bnav-sheet-grid{
    display:grid;grid-template-columns:repeat(4,1fr);gap:10px;
}
.bnav-sheet-item{
    display:flex;flex-direction:column;align-items:center;
    gap:6px;padding:13px 4px;border-radius:14px;
    border:1px solid var(--bord);background:rgba(255,255,255,.025);
    text-decoration:none;cursor:pointer;
    transition:background .2s ease,border-color .2s ease;
}
.bnav-sheet-item:hover,.bnav-sheet-item.active{
    background:rgba(255,208,96,.1);border-color:rgba(255,208,96,.35);
}
.bnav-sheet-ico{font-size:20px;color:var(--muted);}
.bnav-sheet-item.active .bnav-sheet-ico{color:var(--gold);}
.bnav-sheet-item.s-purple .bnav-sheet-ico{color:var(--purple);}
.bnav-sheet-item.s-red    .bnav-sheet-ico{color:var(--red);}
.bnav-sheet-item.s-cyan   .bnav-sheet-ico{color:var(--cyan);}
.bnav-sheet-lbl{
    font-family:var(--fh);font-size:9px;font-weight:900;
    color:var(--text2);text-align:center;
}
</style>
</head>
<body>
<div class="wrap">

<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-cash-register"></i></div>
        <div class="brand-txt"><h1>Caisse </h1><p>ESPERANCE H2O &nbsp;·&nbsp; Point de Vente</p></div>
    </div>
    <div style="text-align:center;flex-shrink:0">
        <div class="clock-d" id="clk">--:--:--</div>
        <div class="clock-sub" id="clkd">Chargement…</div>
    </div>
    <div class="user-badge"><i class="fas fa-user-shield"></i><?= htmlspecialchars($user_name) ?></div>
</div>

<!-- Navigation déplacée dans la barre Android en bas de page -->

<?php if(isset($error_message)): ?>
<div class="alert error"><i class="fas fa-exclamation-triangle"></i><span><?= $error_message ?></span></div>
<?php endif; ?>
<?php if(isset($success_message)): ?>
<div class="alert success"><i class="fas fa-check-circle"></i><span><?= $success_message ?></span></div>
<?php endif; ?>

<?php if(!$location_set): ?>
<div class="loc-box">
    <h2><i class="fas fa-map-marker-alt" style="color:var(--gold)"></i> &nbsp;Sélectionnez votre localisation</h2>
    <p>Choisissez votre société et votre magasin pour accéder à la caisse</p>
    <form method="get" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <select name="company_id" class="f-select" style="max-width:250px" required onchange="this.form.submit()">
            <option value="">— Société —</option>
            <?php foreach($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $company_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="city_id" class="f-select" style="max-width:250px" required>
            <option value="">— Magasin —</option>
            <?php foreach($cities as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $city_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="confirm_location" class="btn btn-neon" style="height:fit-content;padding:12px 28px">
            <i class="fas fa-check"></i> Valider
        </button>
    </form>
</div>

<?php else: ?>

<?php if(in_array($view_mode,['pos','tickets'])): ?>
<div class="kpi-strip">
    <div class="ks"><div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-receipt"></i></div><div><div class="ks-val"><?= $pos_stats['ventes_jour'] ?></div><div class="ks-lbl">Ventes aujourd'hui</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-coins"></i></div><div><div class="ks-val" style="color:var(--gold)"><?= number_format($pos_stats['ca_jour'],0,',',' ') ?></div><div class="ks-lbl">CA du jour (FCFA)</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-clock"></i></div><div><div class="ks-val" style="color:var(--red)"><?= number_format($pos_stats['credits_jour'],0,',',' ') ?></div><div class="ks-lbl">Crédits du jour (FCFA)</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,145,64,0.14);color:var(--orange)"><i class="fas fa-exclamation-triangle"></i></div><div><div class="ks-val" style="color:var(--orange)"><?= $pos_stats['low_stock'] ?></div><div class="ks-lbl">Produits stock bas</div></div></div>
</div>
<?php endif; ?>

<?php if($view_mode === 'pos'): ?>
<div class="pos-grid">
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot g"></div> Produits disponibles</div><span class="pbadge g"><?= count($products) ?> articles</span></div>
        <div class="pb">
            <div class="search-wrap"><input type="text" id="prod-search" placeholder="🔍 Rechercher un produit…"></div>
            <div class="products-grid" id="prod-grid">
                <?php foreach($products as $p): $low = $p['stock_disponible'] <= $p['alert_quantity']; $p_img = $p['image_url'] ?? ''; ?>
                <div class="prod-card <?= $low?'low':'' ?>" data-id="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-price="<?= $p['price'] ?>" data-stock="<?= (int)$p['stock_disponible'] ?>" onclick="addToCart(this)">
                    <?php if($p_img): ?>
                    <div class="prod-img"><img src="<?= htmlspecialchars($p_img) ?>" alt="<?= htmlspecialchars($p['name']) ?>"></div>
                    <?php else: ?>
                    <div class="prod-img prod-img-empty"><i class="fas fa-box-open"></i></div>
                    <?php endif; ?>
                    <div class="prod-name"><?= htmlspecialchars($p['name']) ?></div>
                    <div class="prod-price"><?= number_format($p['price'],0,',',' ') ?> <span style="font-size:11px">FCFA</span></div>
                    <div class="prod-stock <?= $low?'low':'' ?>"><i class="fas fa-boxes"></i> <?= (int)$p['stock_disponible'] ?><?= $low?' ⚠️':'' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="panel">
        <div class="ph"><div class="ph-title"><div class="dot"></div> Panier</div><span class="pbadge" id="cart-count">0 article</span></div>
        <div class="pb">
            <div class="cart-list" id="cart-list"><div class="cart-empty"><i class="fas fa-shopping-basket"></i><p>Panier vide<br>Cliquez sur un produit</p></div></div>
            <div class="cart-total-box"><span class="cart-total-lbl">TOTAL</span><span class="cart-total-val" id="cart-total">0 FCFA</span></div>
            <form method="post" id="checkout-form">
                <label class="f-label">Client</label>
                <select name="client_id" class="f-select" required id="client-select">
                    <option value="">— Sélectionner un client —</option>
                    <?php foreach($clients as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['phone']) ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-blue btn-sm" style="width:100%;margin-bottom:14px;justify-content:center" onclick="openModal('modal-client')"><i class="fas fa-user-plus"></i> Nouveau client</button>
                <label class="f-label">Date & heure de vente</label>
                <input type="datetime-local" name="sale_date" class="f-input" value="<?= date('Y-m-d\TH:i') ?>">
                <label class="f-label">Mode de paiement</label>
                <select name="payment_mode" class="f-select" required>
                    <option value="">— Mode de paiement —</option>
                    <option value="Espèce">💵 Espèce</option>
                    <option value="Mobile Money">📱 Mobile Money</option>
                    <option value="Crédit">📝 Crédit</option>
                </select>
                <input type="hidden" name="cart_data" id="cart-data">
                <button type="submit" name="process_sale" class="checkout-btn" id="checkout-btn" disabled><i class="fas fa-check-circle"></i> &nbsp;ENCAISSER</button>
            </form>
        </div>
    </div>
</div>

<?php elseif($view_mode === 'tickets'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot g"></div> Tickets &amp; Bons de livraison
            <span style="font-family:var(--fb);font-size:11px;color:var(--muted);font-weight:500">
                — <span class="src-invoice">Vente caisse</span> + <span class="src-order">Bon livraison</span>
            </span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="date" value="<?= $date_filter ?>" onchange="window.location='?view=tickets&date_filter='+this.value" class="f-input" style="width:auto;margin:0;padding:9px 14px">
            <span class="pbadge"><?= count($daily_tickets) ?> doc(s)</span>
        </div>
    </div>
    <div class="pb">
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead><tr>
                <th>Source</th><th>N°</th><th>Client</th><th>Total</th>
                <th>Statut</th><th>Heure</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach($daily_tickets as $tk):
                $isOrder = ($tk['source'] === 'order');
            ?>
            <tr style="<?= $isOrder ? 'border-left:2px solid rgba(6,182,212,0.3)' : '' ?>">
                <td>
                    <?php if($isOrder): ?>
                    <span class="src-order"><i class="fas fa-truck"></i> Bon livraison</span>
                    <?php else: ?>
                    <span class="src-invoice"><i class="fas fa-cash-register"></i> Vente caisse</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($isOrder): ?>
                    <strong style="color:var(--cyan)"><?= htmlspecialchars($tk['order_number'] ?? '#'.$tk['id']) ?></strong>
                    <?php else: ?>
                    <strong>#<?= $tk['id'] ?></strong>
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($tk['client_name'] ?? '—') ?>
                    <br><small style="color:var(--muted)"><?= htmlspecialchars($tk['client_phone'] ?? '') ?></small>
                </td>
                <td><strong style="color:var(--gold)"><?= number_format($tk['total'],0,',',' ') ?> FCFA</strong></td>
                <td>
                    <?php if($isOrder):
                        $os = $tk['order_status'] ?? '';
                        if($os==='done')          echo '<span class="bdg bdg-g">✅ Livré</span>';
                        elseif($os==='delivering') echo '<span class="bdg bdg-cyan">🚚 En livraison</span>';
                        else                       echo '<span class="bdg bdg-gold">⏳ Confirmé</span>';
                    else:
                        echo $tk['status']==='Payée' ? '<span class="bdg bdg-g">✅ Payée</span>' : '<span class="bdg bdg-gold">⏳ Crédit</span>';
                    endif; ?>
                    <?php if($isOrder && $tk['payment_method']): ?>
                    <br><small style="color:var(--muted);font-size:10px">
                        <?= $tk['payment_method']==='cash'?'💵 Espèces':($tk['payment_method']==='mobile_money'?'📱 Mobile Money':htmlspecialchars($tk['payment_method'])) ?>
                    </small>
                    <?php endif; ?>
                </td>
                <td style="color:var(--muted)"><?= date('H:i',strtotime($tk['created_at'])) ?></td>
                <td>
                    <?php if($isOrder): ?>
                    <a href="ticket.php?order_id=<?= $tk['id'] ?>" class="btn btn-cyan btn-xs" target="_blank" title="Voir ticket bon livraison"><i class="fas fa-eye"></i></a>
                    <a href="ticket.php?order_id=<?= $tk['id'] ?>&download=1" class="btn btn-neon btn-xs" target="_blank" title="Télécharger PDF"><i class="fas fa-download"></i></a>
                    <a href="<?= project_url('finance/bon.php') ?>" class="btn btn-blue btn-xs" title="Gérer dans Bons livraison"><i class="fas fa-truck"></i></a>
                    <?php else: ?>
                    <a href="ticket.php?invoice_id=<?= $tk['id'] ?>" class="btn btn-blue btn-xs" target="_blank"><i class="fas fa-eye"></i></a>
                    <button onclick="openEditModal(<?= $tk['id'] ?>)" class="btn btn-gold btn-xs"><i class="fas fa-edit"></i></button>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="invoice_id" value="<?= $tk['id'] ?>">
                        <button type="submit" name="duplicate_invoice" class="btn btn-neon btn-xs" onclick="return confirm('Dupliquer ?')"><i class="fas fa-copy"></i></button>
                    </form>
                    <button onclick="openDeleteModal(<?= $tk['id'] ?>)" class="btn btn-red btn-xs"><i class="fas fa-trash"></i></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($daily_tickets)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">
                <i class="fas fa-receipt" style="font-size:36px;display:block;margin-bottom:10px;opacity:.2"></i>Aucun document pour cette date
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php elseif($view_mode === 'expenses'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot g"></div> Dépenses</div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="date" value="<?= $date_filter ?>" onchange="window.location='?view=expenses&date_filter='+this.value" class="f-input" style="width:auto;margin:0;padding:9px 14px">
            <a href="?view=expenses&date_filter=<?= $date_filter ?>&export_expenses=1" class="btn btn-neon btn-sm"><i class="fas fa-file-excel"></i> Excel</a>
            <button onclick="openModal('modal-expense')" class="btn btn-gold btn-sm"><i class="fas fa-plus"></i> Ajouter</button>
        </div>
    </div>
    <div class="pb">
        <?php $total_exp = array_sum(array_column($daily_expenses,'amount')); ?>
        <?php if($total_exp > 0): ?>
        <div style="margin-bottom:16px;padding:14px 18px;background:rgba(255,53,83,0.07);border:1px solid rgba(255,53,83,0.2);border-radius:12px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-family:var(--fb);font-size:13px;font-weight:700;color:var(--muted)">Total dépenses du jour</span>
            <span style="font-family:var(--fh);font-size:22px;font-weight:900;color:var(--red)">-<?= number_format($total_exp,0,',',' ') ?> FCFA</span>
        </div>
        <?php endif; ?>
        <table class="tbl">
            <thead><tr><th>Catégorie</th><th>Montant</th><th>Note</th><th>Heure</th></tr></thead>
            <tbody>
                <?php foreach($daily_expenses as $e): ?>
                <tr><td><strong><?= htmlspecialchars($e['category']) ?></strong></td><td style="color:var(--red);font-family:var(--fh);font-weight:900">-<?= number_format($e['amount'],0,',',' ') ?> FCFA</td><td><?= htmlspecialchars($e['note']) ?></td><td style="color:var(--muted)"><?= date('H:i',strtotime($e['expense_date'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if(empty($daily_expenses)): ?><tr><td colspan="4" style="text-align:center;padding:28px;color:var(--muted)">Aucune dépense ce jour</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif($view_mode === 'stock'): ?>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot o"></div> Stock en temps réel<span style="font-family:var(--fb);font-size:11px;color:var(--muted);font-weight:600">— <span style="color:var(--red)">❌ Ventes Annulées</span> = retours après suppression</span></div></div>
    <div class="pb"><div style="overflow-x:auto">
    <table class="tbl">
        <thead><tr><th>Produit</th><th>Prix</th><th>Initial</th><th>Entrées</th><th style="color:var(--red)">❌ Annulées</th><th>Ajustements</th><th>Sorties</th><th>Stock actuel</th><th>État</th></tr></thead>
        <tbody>
            <?php foreach($stock_realtime as $item): $stock=(int)$item['stock_actuel']; $ann=(int)$item['ventes_annulees'];
                if($stock<=0){$etat='Rupture';$cls='bdg-r';}elseif($stock<=$item['alert_quantity']){$etat='Alerte';$cls='bdg-gold';}else{$etat='OK';$cls='bdg-g';}?>
            <tr><td><strong><?= htmlspecialchars($item['name']) ?></strong></td><td style="color:var(--gold);font-family:var(--fh);font-weight:900"><?= number_format($item['price'],0,',',' ') ?></td><td><?= (int)$item['initial_stock'] ?></td><td><span class="bdg bdg-g">+<?= (int)$item['entrees'] ?></span></td><td><?php if($ann>0): ?><span class="bdg bdg-annule"><i class="fas fa-undo"></i> +<?= $ann ?></span><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td><td><?php $adj=(int)$item['ajustements'];if($adj>0):?><span class="bdg bdg-g">+<?= $adj ?></span><?php elseif($adj<0):?><span class="bdg bdg-gold"><?= $adj ?></span><?php else:?><span style="color:var(--muted)">0</span><?php endif;?></td><td><span class="bdg bdg-r">-<?= (int)$item['sorties'] ?></span></td><td><strong style="font-family:var(--fh);font-size:16px;color:<?= $stock<=0?'var(--red)':($stock<=$item['alert_quantity']?'var(--gold)':'var(--text)') ?>"><?= $stock ?></strong></td><td><span class="bdg <?= $cls ?>"><?= $etat ?></span></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div></div>
</div>

<?php elseif($view_mode === 'credits'): ?>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot r"></div> Créances Clients</div><span class="pbadge r"><?= count($client_debts) ?> client(s) débiteur(s)</span></div>
    <div class="pb">
        <?php $total_creances = array_sum(array_column($client_debts,'solde_restant')); ?>
        <?php if($total_creances>0): ?>
        <div style="margin-bottom:18px;padding:16px 20px;background:rgba(255,53,83,0.07);border:1px solid rgba(255,53,83,0.2);border-radius:14px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-family:var(--fb);font-size:13px;font-weight:700;color:var(--muted)">Total créances à recouvrer</span>
            <span style="font-family:var(--fh);font-size:26px;font-weight:900;color:var(--red)"><?= number_format($total_creances,0,',',' ') ?> FCFA</span>
        </div>
        <?php endif; ?>
        <table class="tbl">
            <thead><tr><th>Client</th><th>Téléphone</th><th>Nb Factures</th><th>Total Dû</th><th>Déjà Payé</th><th>Solde Restant</th></tr></thead>
            <tbody>
                <?php foreach($client_debts as $d): ?>
                <tr><td><strong><?= htmlspecialchars($d['name']) ?></strong></td><td style="color:var(--muted)"><?= htmlspecialchars($d['phone']) ?></td><td><span class="bdg bdg-blue"><?= $d['nb_factures'] ?> facture(s)</span></td><td style="font-family:var(--fh);font-weight:900"><?= number_format($d['total_du'],0,',',' ') ?> FCFA</td><td style="color:var(--neon);font-family:var(--fh);font-weight:900">+<?= number_format($d['total_paye'],0,',',' ') ?></td><td><strong style="font-family:var(--fh);font-size:16px;color:var(--red)"><?= number_format($d['solde_restant'],0,',',' ') ?> FCFA</strong></td></tr>
                <?php endforeach; ?>
                <?php if(empty($client_debts)): ?><tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)"><i class="fas fa-check-circle" style="font-size:36px;display:block;margin-bottom:10px;color:var(--neon);opacity:.5"></i>Aucune créance en cours ! 🎉</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif($view_mode === 'logs'): ?>
<div class="panel">
    <div class="ph">
        <div class="ph-title">
            <div class="dot b"></div> Journal des actions
            <span style="font-family:var(--fb);font-size:11px;color:var(--muted);font-weight:500">
                — <span class="log-src-cash">Caisse POS</span> + <span class="log-src-bon">Bons livraison</span>
            </span>
        </div>
        <input type="date" value="<?= $date_filter ?>" onchange="window.location='?view=logs&date_filter='+this.value" class="f-input" style="width:auto;margin:0;padding:9px 14px">
    </div>
    <div class="pb">
        <div style="max-height:600px;overflow-y:auto">
            <?php foreach($logs as $lg):
                $src = $lg['source'] ?? 'cash_log';
                $isBoL = ($src === 'caisse_logs');
                $lc = match(true) {
                    str_contains($lg['action_type'],'DELETE') || str_contains($lg['action_type'],'ERROR') => 'bdg-r',
                    str_contains($lg['action_type'],'BON_')                                               => 'bdg-cyan',
                    str_contains($lg['action_type'],'SALE')                                               => 'bdg-g',
                    str_contains($lg['action_type'],'EXPORT')                                             => 'bdg-blue',
                    str_contains($lg['action_type'],'EXPENSE')                                            => 'bdg-gold',
                    str_contains($lg['action_type'],'APPRO')                                              => 'bdg-cyan',
                    default                                                                                => 'bdg-purple'
                };
            ?>
            <div class="log-item" style="<?= $isBoL ? 'border-left:2px solid rgba(6,182,212,0.25);background:rgba(6,182,212,0.02)' : '' ?>">
                <span class="log-time"><?= date('H:i:s',strtotime($lg['created_at'])) ?></span>
                <span class="bdg <?= $lc ?>"><?= htmlspecialchars($lg['action_type']) ?></span>
                <?php if($isBoL): ?>
                <span class="log-src-bon" title="Action depuis interface Bons de livraison"><i class="fas fa-truck"></i> BL</span>
                <?php else: ?>
                <span class="log-src-cash" title="Action depuis Caisse POS"><i class="fas fa-cash-register"></i> POS</span>
                <?php endif; ?>
                <span class="log-desc">
                    <strong><?= htmlspecialchars($lg['user_name']??'?') ?></strong> —
                    <?= htmlspecialchars($lg['action_description'] ?? '') ?>
                    <?php if(!empty($lg['amount']) && $lg['amount']>0): ?>
                    &nbsp;<strong style="color:var(--gold)"><?= number_format($lg['amount'],0,',',' ') ?> FCFA</strong>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if(empty($logs)): ?>
            <div style="text-align:center;padding:32px;color:var(--muted)">Aucun log pour cette date</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif($view_mode === 'reports'): ?>
<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px">
    <input type="date" value="<?= $date_filter ?>" onchange="window.location='?view=reports&date_filter='+this.value" class="f-input" style="width:auto;margin:0;padding:10px 16px">
    <a href="?view=reports&date_filter=<?= $date_filter ?>&export_report=1" class="btn btn-neon"><i class="fas fa-file-excel"></i> Exporter Excel</a>
</div>
<div class="stat-row">
    <div class="stat-box"><div class="stat-val"><?= (int)$cash_report['nb_ventes'] ?></div><div class="stat-lbl">Nombre de ventes</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--gold)"><?= number_format($cash_report['total_ventes'],0,',',' ') ?></div><div class="stat-lbl">Total ventes (FCFA)</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--neon)"><?= number_format($cash_report['total_paye'],0,',',' ') ?></div><div class="stat-lbl">Total payé (FCFA)</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--orange)"><?= number_format($cash_report['total_credit'],0,',',' ') ?></div><div class="stat-lbl">Total crédit (FCFA)</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--red)"><?= number_format($cash_report['total_depenses'],0,',',' ') ?></div><div class="stat-lbl">Total dépenses (FCFA)</div></div>
    <div class="stat-box" style="border-color:rgba(50,190,143,0.4)"><div class="stat-val" style="color:var(--neon)"><?= number_format($cash_report['solde_net'],0,',',' ') ?></div><div class="stat-lbl">Solde net (FCFA)</div></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px">
    <div class="panel"><div class="ph"><div class="ph-title"><div class="dot g"></div> Répartition ventes</div></div><div class="pb"><div style="height:280px"><canvas id="pieChart"></canvas></div></div></div>
    <div class="panel"><div class="ph"><div class="ph-title"><div class="dot b"></div> Vue financière</div></div><div class="pb"><div style="height:280px"><canvas id="barChart"></canvas></div></div></div>
</div>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot g"></div> Ventes par produit</div></div>
    <div class="pb">
        <table class="tbl"><thead><tr><th>Produit</th><th>Qté vendue</th><th>Chiffre d'affaires</th></tr></thead><tbody>
            <?php foreach($sales_by_product as $it): ?><tr><td><strong><?= htmlspecialchars($it['produit']) ?></strong></td><td><span class="bdg bdg-blue"><?= (int)$it['quantite_vendue'] ?> unité(s)</span></td><td style="font-family:var(--fh);font-weight:900;color:var(--gold)"><?= number_format($it['chiffre_affaires'],0,',',' ') ?> FCFA</td></tr><?php endforeach; ?>
            <?php if(empty($sales_by_product)): ?><tr><td colspan="3" style="text-align:center;padding:28px;color:var(--muted)">Aucune vente ce jour</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>

<?php elseif($view_mode === 'appro'): ?>
<div class="appro-subnav">
    <a href="?view=appro&appro_view=list"    class="asn <?= $appro_view==='list'   ?'active':'' ?>"><i class="fas fa-list"></i> Mes demandes</a>
    <a href="?view=appro&appro_view=new"     class="asn <?= $appro_view==='new'    ?'active':'' ?>"><i class="fas fa-plus-circle"></i> Nouvelle demande</a>
    <a href="?view=appro&appro_view=history" class="asn <?= $appro_view==='history'?'active':'' ?>" style="<?= $appro_view==='history'?'':'pointer-events:none;opacity:0.4' ?>"><i class="fas fa-timeline"></i> Historique demande</a>
</div>

<?php if($appro_view === 'new'): ?>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot c"></div> Nouvelle Demande d'Approvisionnement</div></div>
    <div class="pb">
        <form method="post">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-bottom:4px">
                <div><label class="f-label"><i class="fas fa-box"></i> Produit à approvisionner</label>
                    <select name="appro_product_id" class="f-select" required id="appro-prod-select" onchange="updateApproStock(this)">
                        <option value="">— Sélectionner un produit —</option>
                        <?php foreach($all_products as $p): ?><option value="<?= $p['id'] ?>" data-stock="<?= (int)$p['stock_disponible'] ?>" data-alert="<?= (int)$p['alert_quantity'] ?>"><?= htmlspecialchars($p['name']) ?><?php if($p['category']): ?> (<?= htmlspecialchars($p['category']) ?>)<?php endif; ?> — Stock: <?= (int)$p['stock_disponible'] ?></option><?php endforeach; ?>
                    </select></div>
                <div><label class="f-label"><i class="fas fa-hashtag"></i> Quantité demandée</label><input type="number" name="appro_quantity" class="f-input" min="1" step="1" placeholder="Ex: 10" required></div>
                <div><label class="f-label"><i class="fas fa-info-circle"></i> Stock actuel</label>
                    <div id="appro-stock-info" style="padding:12px 16px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:12px;font-family:var(--fh);font-size:20px;font-weight:900;color:var(--muted);text-align:center;margin-bottom:12px;transition:all 0.3s">—</div></div>
            </div>
            <label class="f-label" style="margin-top:8px"><i class="fas fa-boxes"></i> Type d'unité <span style="color:var(--red);font-family:var(--fb);font-size:11px;font-weight:700;text-transform:none;letter-spacing:0"> * obligatoire</span></label>
            <div class="unit-toggle">
                <div class="unit-opt"><input type="radio" name="appro_unit_type" id="u-detail" value="detail" checked><label for="u-detail"><i class="fas fa-cube"></i><span class="u-title">Côté Détail</span><span class="u-sub">Unités individuelles<br>par pièce</span></label></div>
                <div class="unit-opt"><input type="radio" name="appro_unit_type" id="u-carton" value="carton"><label for="u-carton"><i class="fas fa-box-open"></i><span class="u-title">Côté Carton</span><span class="u-sub">Par carton complet<br>ou par lot</span></label></div>
            </div>
            <label class="f-label"><i class="fas fa-comment-alt"></i> Note / Urgence (optionnel)</label>
            <textarea name="appro_note" class="f-textarea" placeholder="Ex: Stock critique, besoin urgent…"></textarea>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px">
                <button type="submit" name="submit_appro_request" style="flex:2;padding:15px;background:linear-gradient(135deg,var(--cyan),var(--blue));border:none;border-radius:14px;color:#fff;font-family:var(--fh);font-size:15px;font-weight:900;cursor:pointer;box-shadow:var(--glow-cyan);display:flex;align-items:center;justify-content:center;gap:10px"><i class="fas fa-paper-plane"></i> Envoyer la demande à l'Admin</button>
                <a href="?view=appro&appro_view=list" style="flex:1;padding:15px;background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);border-radius:14px;color:var(--red);font-family:var(--fh);font-size:15px;font-weight:900;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px"><i class="fas fa-times"></i> Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php elseif($appro_view === 'history' && $appro_viewed_req): ?>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot p"></div>Historique — Demande #<?= $appro_viewed_req['id'] ?> <span style="font-family:var(--fb);font-size:12px;color:var(--muted);font-weight:500">· <?= htmlspecialchars($appro_viewed_req['product_name']) ?> · <?= ($appro_viewed_req['quantity']+0) ?> <?= $appro_viewed_req['unit_type'] ?></span></div><a href="?view=appro&appro_view=list" class="btn btn-red btn-sm"><i class="fas fa-arrow-left"></i> Retour</a></div>
    <div class="pb">
        <?php if(empty($appro_history_req)): ?><div class="empty-st"><i class="fas fa-history"></i><p>Aucune action enregistrée.</p></div><?php else: ?>
        <div class="timeline">
            <?php foreach($appro_history_req as $h):
                $im=['SOUMISSION'=>['fas fa-paper-plane','var(--cyan)','rgba(6,182,212,0.15)'],'MODIFICATION'=>['fas fa-edit','var(--gold)','rgba(255,208,96,0.15)'],'ANNULATION'=>['fas fa-ban','var(--muted)','rgba(90,128,112,0.15)'],'CONFIRMATION'=>['fas fa-check-circle','var(--neon)','rgba(50,190,143,0.15)'],'REJET'=>['fas fa-times-circle','var(--red)','rgba(255,53,83,0.15)']];
                [$ico,$col,$bg]=$im[$h['action']]??['fas fa-circle','var(--blue)','rgba(61,140,255,0.15)']; ?>
            <div class="tl-item"><div class="tl-ico" style="background:<?= $bg ?>;border-color:<?= $col ?>;color:<?= $col ?>"><i class="<?= $ico ?>"></i></div>
                <div class="tl-body"><div class="tl-head"><span class="tl-action"><?= htmlspecialchars($h['action']) ?></span><span class="tl-time"><?= date('d/m/Y à H:i:s',strtotime($h['created_at'])) ?></span></div>
                <div class="tl-who"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($h['user_name']??'Inconnu') ?></div>
                <?php if($h['details']): ?><div class="tl-detail"><?= nl2br(htmlspecialchars($h['details'])) ?></div><?php endif; ?>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:14px"><a href="?view=appro&appro_view=new" class="btn btn-cyan"><i class="fas fa-plus-circle"></i> Nouvelle demande d'appro</a></div>
<?php $appro_stats=['en_attente'=>0,'confirmee'=>0,'rejetee'=>0,'annulee'=>0];foreach($my_appro_requests as $r)$appro_stats[$r['status']]=($appro_stats[$r['status']]??0)+1;?>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px">
    <div class="ks"><div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-clock"></i></div><div><div class="ks-val" style="color:var(--gold)"><?= $appro_stats['en_attente'] ?></div><div class="ks-lbl">En attente</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-check-circle"></i></div><div><div class="ks-val" style="color:var(--neon)"><?= $appro_stats['confirmee'] ?></div><div class="ks-lbl">Confirmées</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(255,53,83,0.14);color:var(--red)"><i class="fas fa-times-circle"></i></div><div><div class="ks-val" style="color:var(--red)"><?= $appro_stats['rejetee'] ?></div><div class="ks-lbl">Rejetées</div></div></div>
    <div class="ks"><div class="ks-ico" style="background:rgba(90,128,112,0.14);color:var(--muted)"><i class="fas fa-ban"></i></div><div><div class="ks-val"><?= $appro_stats['annulee'] ?></div><div class="ks-lbl">Annulées</div></div></div>
</div>
<div class="panel">
    <div class="ph"><div class="ph-title"><div class="dot c"></div> Mes demandes d'approvisionnement</div><span class="pbadge c"><?= count($my_appro_requests) ?> demande(s)</span></div>
    <div class="pb">
        <?php if(empty($my_appro_requests)): ?>
        <div class="empty-st"><i class="fas fa-truck-loading"></i><p>Vous n'avez pas encore soumis de demande d'appro.</p><a href="?view=appro&appro_view=new" class="btn btn-cyan"><i class="fas fa-plus-circle"></i> Créer ma première demande</a></div>
        <?php else: ?><div style="overflow-x:auto"><table class="tbl">
            <thead><tr><th>#</th><th>Produit</th><th>Qté</th><th>Unité</th><th>Note</th><th>Date</th><th>Statut</th><th>Réponse Admin</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach($my_appro_requests as $r): ?>
                <tr style="<?= $r['status']==='en_attente'?'background:rgba(6,182,212,0.02)':'' ?>">
                    <td><strong style="color:var(--cyan)">#<?= $r['id'] ?></strong></td>
                    <td><strong><?= htmlspecialchars($r['product_name']) ?></strong><?php if($r['product_category']): ?><br><small style="color:var(--muted)"><?= htmlspecialchars($r['product_category']) ?></small><?php endif; ?></td>
                    <td><strong style="font-family:var(--fh);font-size:16px"><?= ($r['quantity']+0) ?></strong></td>
                    <td><?php if($r['unit_type']==='carton'): ?><span class="unit-carton"><i class="fas fa-box-open"></i> Carton</span><?php else: ?><span class="unit-detail"><i class="fas fa-cube"></i> Détail</span><?php endif; ?></td>
                    <td style="max-width:150px;font-size:12px;color:var(--muted);font-style:italic"><?= $r['note'] ? htmlspecialchars(mb_strimwidth($r['note'],0,60,'…')) : '—' ?></td>
                    <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= date('d/m/Y',strtotime($r['created_at'])) ?><br><strong style="color:var(--text);font-size:12px"><?= date('H:i',strtotime($r['created_at'])) ?></strong></td>
                    <td><?php $sm=['en_attente'=>['s-attente','⏳ En attente'],'confirmee'=>['s-confirmee','✅ Confirmée'],'rejetee'=>['s-rejetee','❌ Rejetée'],'annulee'=>['s-annulee','🚫 Annulée']];[$sc,$sl]=$sm[$r['status']]??['s-attente',$r['status']]; ?>
                        <span class="s-badge <?= $sc ?>"><?= $sl ?></span>
                        <?php if($r['admin_name'] && $r['status']!=='en_attente'): ?><br><small style="color:var(--muted);font-size:10px;display:block;margin-top:3px">par <?= htmlspecialchars($r['admin_name']) ?></small><?php endif; ?></td>
                    <td style="max-width:160px;font-size:12px"><?php if($r['admin_note']): ?><span style="color:<?= $r['status']==='rejetee'?'var(--red)':'var(--neon)' ?>"><?= htmlspecialchars(mb_strimwidth($r['admin_note'],0,70,'…')) ?></span><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
                    <td><div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                        <a href="?view=appro&appro_view=history&req_id=<?= $r['id'] ?>" class="btn btn-purple btn-xs" title="Historique"><i class="fas fa-history"></i></a>
                        <?php if($r['status'] === 'en_attente'): ?>
                        <button onclick="openApproEditModal(<?= htmlspecialchars(json_encode(['id'=>$r['id'],'pname'=>$r['product_name'],'quantity'=>$r['quantity'],'unit_type'=>$r['unit_type'],'note'=>$r['note']])) ?>)" class="btn btn-gold btn-xs" title="Modifier"><i class="fas fa-edit"></i></button>
                        <button onclick="openApproCancelModal(<?= $r['id'] ?>)" class="btn btn-red btn-xs" title="Annuler"><i class="fas fa-times"></i></button>
                        <?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

</div>

<!-- MODALS -->
<div id="modal-client" class="modal"><div class="modal-box"><h2><i class="fas fa-user-plus" style="color:var(--neon)"></i> &nbsp;Nouveau Client</h2><form method="post"><label class="f-label">Nom complet</label><input type="text" name="client_name" class="f-input" placeholder="Ex : Koné Mamadou" required><label class="f-label">Téléphone</label><input type="tel" name="client_phone" class="f-input" placeholder="Ex : 07 00 00 00 00" required><div class="modal-btns"><button type="submit" name="create_client" class="btn btn-neon"><i class="fas fa-save"></i> Créer</button><button type="button" class="btn btn-red" onclick="closeModal('modal-client')">Annuler</button></div></form></div></div>
<div id="modal-expense" class="modal"><div class="modal-box"><h2><i class="fas fa-wallet" style="color:var(--gold)"></i> &nbsp;Nouvelle Dépense</h2><form method="post"><label class="f-label">Catégorie</label><input type="text" name="category" class="f-input" placeholder="Ex : Transport…" required><label class="f-label">Montant (FCFA)</label><input type="number" step="1" name="amount" class="f-input" placeholder="0" required><label class="f-label">Note (optionnel)</label><textarea name="note" class="f-textarea" rows="3" placeholder="Détails…"></textarea><div class="modal-btns"><button type="submit" name="add_expense" class="btn btn-gold"><i class="fas fa-save"></i> Ajouter</button><button type="button" class="btn btn-red" onclick="closeModal('modal-expense')">Annuler</button></div></form></div></div>
<div id="modal-edit" class="modal"><div class="modal-box"><h2><i class="fas fa-edit" style="color:var(--gold)"></i> &nbsp;Modifier la facture</h2><div id="edit-content"></div></div></div>
<div id="modal-delete" class="modal"><div class="modal-box"><h2 class="danger"><i class="fas fa-exclamation-triangle"></i> &nbsp;Annuler la vente</h2><div style="background:rgba(255,53,83,0.08);border:1px solid rgba(255,53,83,0.25);border-radius:12px;padding:16px 20px;margin-bottom:20px"><p style="font-family:var(--fb);font-size:14px;font-weight:700;color:var(--red);line-height:1.7">⚠️ <strong>ATTENTION</strong> : Cette action va :<br>1. Supprimer la facture définitivement<br>2. Retourner le stock de chaque article<br>3. Enregistrer une ligne <strong>"VENTE ANNULÉE"</strong> dans l'onglet Stock</p></div><form method="post"><input type="hidden" name="invoice_id" id="del-id"><label class="f-label">Confirmez avec votre mot de passe</label><input type="password" name="delete_password" class="f-input" placeholder="Mot de passe…" required><div class="modal-btns"><button type="submit" name="delete_invoice" class="btn btn-red"><i class="fas fa-ban"></i> Confirmer l'annulation</button><button type="button" class="btn btn-neon" onclick="closeModal('modal-delete')">Retour</button></div></form></div></div>
<div id="modal-appro-edit" class="modal"><div class="modal-box"><h2 class="gold"><i class="fas fa-edit"></i> &nbsp;Modifier la demande d'appro</h2><form method="post"><input type="hidden" name="appro_req_id" id="ae-req-id"><label class="f-label">Produit</label><div id="ae-prod-name" style="padding:12px 16px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:12px;font-family:var(--fh);font-weight:900;color:var(--cyan);margin-bottom:14px">—</div><label class="f-label">Quantité</label><input type="number" name="appro_quantity" id="ae-qty" class="f-input" min="1" step="1" required><label class="f-label">Type d'unité</label><div class="unit-toggle"><div class="unit-opt"><input type="radio" name="appro_unit_type" id="ae-detail" value="detail" checked><label for="ae-detail"><i class="fas fa-cube"></i><span class="u-title">Côté Détail</span><span class="u-sub">Unités individuelles</span></label></div><div class="unit-opt"><input type="radio" name="appro_unit_type" id="ae-carton" value="carton"><label for="ae-carton"><i class="fas fa-box-open"></i><span class="u-title">Côté Carton</span><span class="u-sub">Par carton / lot</span></label></div></div><label class="f-label">Note</label><textarea name="appro_note" id="ae-note" class="f-textarea" placeholder="Note ou précision…"></textarea><div class="modal-btns"><button type="submit" name="update_appro_request" class="btn btn-gold"><i class="fas fa-save"></i> Enregistrer</button><button type="button" class="btn btn-red" onclick="closeModal('modal-appro-edit')"><i class="fas fa-times"></i> Annuler</button></div></form></div></div>
<div id="modal-appro-cancel" class="modal"><div class="modal-box"><h2 class="danger"><i class="fas fa-ban"></i> &nbsp;Annuler la demande</h2><div style="background:rgba(255,53,83,0.06);border:1px solid rgba(255,53,83,0.2);border-radius:12px;padding:14px 18px;margin-bottom:20px"><p style="font-family:var(--fb);font-size:13px;color:var(--text2);line-height:1.7">Cette demande d'appro sera annulée et ne sera plus traitée par l'admin.</p></div><form method="post"><input type="hidden" name="appro_req_id" id="ac-req-id"><label class="f-label">Motif d'annulation (optionnel)</label><input type="text" name="appro_cancel_motif" class="f-input" placeholder="Ex: Plus nécessaire…"><div class="modal-btns"><button type="submit" name="cancel_appro_request" class="btn btn-red"><i class="fas fa-ban"></i> Confirmer l'annulation</button><button type="button" class="btn btn-neon" onclick="closeModal('modal-appro-cancel')"><i class="fas fa-arrow-left"></i> Retour</button></div></form></div></div>

<div class="calc-wrap">
    <div class="calculator" id="calc">
        <div class="calc-hdr"><span><i class="fas fa-calculator"></i> Calculatrice</span><button onclick="toggleCalc()">×</button></div>
        <input type="text" id="calc-disp" class="calc-disp" readonly>
        <div class="calc-grid">
            <button onclick="cp('7')">7</button><button onclick="cp('8')">8</button><button onclick="cp('9')">9</button><button class="op" onclick="cp('/')">÷</button>
            <button onclick="cp('4')">4</button><button onclick="cp('5')">5</button><button onclick="cp('6')">6</button><button class="op" onclick="cp('*')">×</button>
            <button onclick="cp('1')">1</button><button onclick="cp('2')">2</button><button onclick="cp('3')">3</button><button class="op" onclick="cp('-')">−</button>
            <button onclick="cp('0')">0</button><button onclick="cp('.')">.</button><button class="eq" onclick="calcEq()">=</button><button class="op" onclick="cp('+')">+</button>
            <button class="cl" onclick="calcCl()">C — Effacer</button>
        </div>
    </div>
    <button class="calc-toggle" onclick="toggleCalc()"><i class="fas fa-calculator"></i></button>
</div>

<script>
function tick(){const n=new Date();document.getElementById('clk').textContent=n.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});document.getElementById('clkd').textContent=n.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});}
tick();setInterval(tick,1000);
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show');}));
function openDeleteModal(id){document.getElementById('del-id').value=id;openModal('modal-delete');}
function openEditModal(id){
    document.getElementById('edit-content').innerHTML='<div style="text-align:center;padding:30px;color:var(--muted)"><i class="fas fa-spinner fa-spin" style="font-size:32px"></i></div>';
    openModal('modal-edit');
    fetch('/../api_support/get_invoice_items.php?invoice_id='+id).then(r=>r.json()).then(data=>{
        let cl=<?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name']],$clients)) ?>;
        let opts=cl.map(c=>`<option value="${c.id}" ${data.invoice.client_id==c.id?'selected':''}>${c.name}</option>`).join('');
        let items=data.items.map(it=>`<div style="padding:12px;background:rgba(0,0,0,0.2);border-radius:10px;margin-bottom:12px;border:1px solid var(--bord)"><div style="font-family:var(--fh);font-weight:900;color:var(--text);margin-bottom:8px">${it.product_name} (Qté: ${it.quantity})</div><input type="number" step="0.01" value="${it.price}" name="item_price[${it.id}]" class="f-input" style="margin:0" placeholder="Prix unitaire"></div>`).join('');
        document.getElementById('edit-content').innerHTML=`<form method="post"><input type="hidden" name="invoice_id" value="${id}"><label class="f-label">Client</label><select name="client_id" class="f-select">${opts}</select><label class="f-label">Date de vente</label><input type="datetime-local" name="sale_date" class="f-input" value="${data.invoice.created_at.replace(' ','T').substring(0,16)}"><div class="modal-sep"></div><label class="f-label">Articles</label>${items}<div class="modal-btns"><button type="submit" name="edit_invoice" class="btn btn-gold"><i class="fas fa-save"></i> Enregistrer</button><button type="button" class="btn btn-red" onclick="closeModal('modal-edit')">Annuler</button></div></form>`;
    }).catch(()=>{document.getElementById('edit-content').innerHTML='<div style="color:var(--red)">Erreur de chargement</div>';});
}
let cart=[];
function addToCart(el){const id=el.dataset.id,name=el.dataset.name,price=parseFloat(el.dataset.price),stock=parseInt(el.dataset.stock);const exist=cart.find(i=>i.product_id==id);if(exist){if(exist.quantity>=stock){alert('Stock insuffisant !');return;}exist.quantity++;exist.total=exist.quantity*exist.price;}else{cart.push({product_id:id,product_name:name,price,quantity:1,total:price,max_stock:stock});}el.style.transform='scale(0.92)';setTimeout(()=>el.style.transform='',200);renderCart();}
function updateQty(id,d){const it=cart.find(i=>i.product_id==id);if(!it)return;it.quantity+=d;if(it.quantity<=0){cart=cart.filter(i=>i.product_id!=id);}else if(it.quantity>it.max_stock){alert('Stock insuffisant !');it.quantity=it.max_stock;}else{it.total=it.quantity*it.price;}renderCart();}
function removeItem(id){cart=cart.filter(i=>i.product_id!=id);renderCart();}
function renderCart(){const cl=document.getElementById('cart-list'),tot=document.getElementById('cart-total'),cd=document.getElementById('cart-data'),btn=document.getElementById('checkout-btn'),cnt=document.getElementById('cart-count');if(!cl)return;if(cart.length===0){cl.innerHTML='<div class="cart-empty"><i class="fas fa-shopping-basket"></i><p>Panier vide<br>Cliquez sur un produit</p></div>';tot.textContent='0 FCFA';btn.disabled=true;cnt.textContent='0 article';return;}let total=0,html='';cart.forEach(it=>{total+=it.total;html+=`<div class="cart-item"><div class="ci-info"><div class="ci-name">${it.product_name}</div><div class="ci-det">${it.price.toLocaleString('fr-FR')} × ${it.quantity} = ${it.total.toLocaleString('fr-FR')} FCFA</div></div><div class="ci-qty"><button type="button" class="qty-btn" onclick="updateQty(${it.product_id},-1)">−</button><span class="qty-val">${it.quantity}</span><button type="button" class="qty-btn" onclick="updateQty(${it.product_id},1)">+</button><button type="button" class="qty-btn rm" onclick="removeItem(${it.product_id})"><i class="fas fa-trash"></i></button></div></div>`;});cl.innerHTML=html;tot.textContent=total.toLocaleString('fr-FR')+' FCFA';cd.value=JSON.stringify(cart);btn.disabled=false;cnt.textContent=cart.length+' article'+(cart.length>1?'s':'');}
const ps=document.getElementById('prod-search');if(ps)ps.addEventListener('input',e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('.prod-card').forEach(c=>{c.style.display=c.dataset.name.toLowerCase().includes(q)?'':'none';});});
function updateApproStock(sel){const opt=sel.options[sel.selectedIndex];const s=parseInt(opt.dataset.stock||0),a=parseInt(opt.dataset.alert||5);const el=document.getElementById('appro-stock-info');if(!el||!opt.value)return;el.textContent=s+' unités';el.style.color=s<=0?'var(--red)':s<=a?'var(--gold)':'var(--neon)';el.style.borderColor=s<=0?'rgba(255,53,83,0.4)':s<=a?'rgba(255,208,96,0.4)':'rgba(50,190,143,0.3)';}
function openApproEditModal(r){document.getElementById('ae-req-id').value=r.id;document.getElementById('ae-prod-name').textContent=r.pname;document.getElementById('ae-qty').value=r.quantity;document.getElementById('ae-note').value=r.note||'';document.getElementById('ae-detail').checked=r.unit_type!=='carton';document.getElementById('ae-carton').checked=r.unit_type==='carton';openModal('modal-appro-edit');}
function openApproCancelModal(id){document.getElementById('ac-req-id').value=id;openModal('modal-appro-cancel');}
let cv='';
function toggleCalc(){document.getElementById('calc').classList.toggle('show');}
function cp(v){cv+=v;document.getElementById('calc-disp').value=cv;}
function calcEq(){try{cv=eval(cv).toString();}catch(e){cv='Erreur';}document.getElementById('calc-disp').value=cv;}
function calcCl(){cv='';document.getElementById('calc-disp').value='';}
<?php if($view_mode==='reports' && !empty($sales_by_product)): ?>
Chart.defaults.color='#5a8070';Chart.defaults.borderColor='rgba(255,255,255,0.04)';Chart.defaults.font.family="'Inter',sans-serif";
const pie=document.getElementById('pieChart');
if(pie){new Chart(pie,{type:'doughnut',data:{labels:[<?= implode(',',array_map(fn($i)=>'"'.addslashes($i['produit']).'"',$sales_by_product)) ?>],datasets:[{data:[<?= implode(',',array_column($sales_by_product,'chiffre_affaires')) ?>],backgroundColor:['#32be8f','#3d8cff','#ff9140','#ffd060','#a855f7','#ff3553','#19ffa3','#06b6d4'],borderColor:'#22324a',borderWidth:3,hoverOffset:8}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom',labels:{padding:16,usePointStyle:true,color:'#8a9fad'}},tooltip:{backgroundColor:'rgba(22,32,51,0.98)',padding:14,cornerRadius:10}}}});}
const bar=document.getElementById('barChart');
if(bar){new Chart(bar,{type:'bar',data:{labels:['Ventes totales','Total payé','Crédits','Dépenses'],datasets:[{label:'FCFA',data:[<?= $cash_report['total_ventes']?>,<?= $cash_report['total_paye']?>,<?= $cash_report['total_credit']?>,<?= $cash_report['total_depenses']?>],backgroundColor:['rgba(50,190,143,0.75)','rgba(61,140,255,0.75)','rgba(255,208,96,0.75)','rgba(255,53,83,0.75)'],borderRadius:10,borderSkipped:false}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{backgroundColor:'rgba(22,32,51,0.98)',padding:14,cornerRadius:10}},scales:{x:{grid:{display:false},ticks:{color:'#8a9fad'}},y:{beginAtZero:true,grid:{color:'rgba(148,163,184,0.12)'},ticks:{color:'#8a9fad'}}}}});}
<?php endif; ?>
setTimeout(()=>location.reload(),600000);
</script>

<!-- ══════════════════════════════════════════════════════
     ANDROID NATIVE BOTTOM NAVIGATION BAR — CAISSE PRO
══════════════════════════════════════════════════════ -->

<!-- Overlay + feuille "Plus" -->
<div class="bnav-more-overlay" id="bnavOverlay" onclick="closeBnavMore()"></div>
<div class="bnav-more-sheet" id="bnavSheet">
    <div class="bnav-sheet-handle"></div>
    <div class="bnav-sheet-title">Plus d'options</div>
    <div class="bnav-sheet-grid">
        <a href="?view=expenses" class="bnav-sheet-item <?= $view_mode==='expenses'?'active':'' ?>">
            <i class="fas fa-wallet bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Dépenses</span>
        </a>
        <a href="?view=credits" class="bnav-sheet-item <?= $view_mode==='credits'?'active':'' ?>">
            <i class="fas fa-hand-holding-usd bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Créances</span>
        </a>
        <a href="<?= project_url('finance/versement.php') ?>?company_id=<?= $company_id ?>&city_id=<?= $city_id ?>" class="bnav-sheet-item s-purple">
            <i class="fas fa-money-check-alt bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Versements</span>
        </a>
        <a href="?view=reports" class="bnav-sheet-item <?= $view_mode==='reports'?'active':'' ?>">
            <i class="fas fa-chart-bar bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Rapports</span>
        </a>
        <a href="?view=logs" class="bnav-sheet-item <?= $view_mode==='logs'?'active':'' ?>">
            <i class="fas fa-history bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Logs</span>
        </a>
        <a href="<?= project_url('stock/stock_update_fixed.php') ?>" class="bnav-sheet-item s-cyan">
            <i class="fas fa-warehouse bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Appro Stock</span>
        </a>
        <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="bnav-sheet-item">
            <i class="fas fa-id-badge bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">ADMIN</span>
        </a>
        <a href="<?= project_url('dashboard/index.php') ?>" class="bnav-sheet-item s-red">
            <i class="fas fa-home bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Accueil</span>
        </a>
        <a href="<?= project_url('finance/bon.php') ?>" class="bnav-sheet-item s-red">
            <i class="fas fa-truck bnav-sheet-ico"></i>
            <span class="bnav-sheet-lbl">Bons livraison</span>
        </a>
    </div>
</div>

<!-- Barre de navigation principale -->
<nav class="android-nav" role="navigation" aria-label="Navigation Caisse">
    <a href="?view=pos" class="bnav-item <?= $view_mode==='pos'?'active':'' ?>" aria-label="Vente">
        <i class="fas fa-shopping-cart bnav-icon"></i>
    </a>
    <a href="?view=tickets" class="bnav-item <?= $view_mode==='tickets'?'active':'' ?>" aria-label="Tickets">
        <i class="fas fa-receipt bnav-icon"></i>
    </a>
    <a href="?view=stock" class="bnav-item <?= $view_mode==='stock'?'active':'' ?>" aria-label="Stock">
        <i class="fas fa-boxes bnav-icon"></i>
    </a>
    <a href="?view=appro" class="bnav-item <?= $view_mode==='appro'?'active':'' ?>" aria-label="Demande Appro">
        <i class="fas fa-truck-loading bnav-icon"></i>
        <?php if(($appro_pending_mine??0) > 0): ?>
        <span class="bnav-badge"><?= $appro_pending_mine ?></span>
        <?php endif; ?>
    </a>
    <button class="bnav-item <?= in_array($view_mode,['expenses','credits','reports','logs'])?'active':'' ?>"
            onclick="toggleBnavMore()" aria-label="Plus">
        <i class="fas fa-ellipsis-h bnav-icon"></i>
    </button>
</nav>

<script>
function toggleBnavMore(){
    document.getElementById('bnavSheet').classList.toggle('show');
    document.getElementById('bnavOverlay').classList.toggle('show');
}
function closeBnavMore(){
    document.getElementById('bnavSheet').classList.remove('show');
    document.getElementById('bnavOverlay').classList.remove('show');
}
// Si l'onglet actif est dans "Plus", ouvrir automatiquement le panneau
<?php if(in_array($view_mode,['expenses','credits','reports','logs'])): ?>
document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('bnavSheet').classList.add('show');
    document.getElementById('bnavOverlay').classList.add('show');
});
<?php endif; ?>
</script>

</body>
</html>
