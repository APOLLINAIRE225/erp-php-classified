<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════
 * TICKET DE CAISSE ULTRA PRO — ESPERANCE H2O
 * ═══════════════════════════════════════════════════════════════
 * ✅ Stock géré via stock_movements (type='exit') — MÊME LOGIQUE
 *    que caisse_complete_enhanced.php
 * ✅ Anti-doublon via invoiced_at
 * ✅ Logs complets
 * ✅ Support invoice_id ET order_id
 *
 * 🔧 CORRECTION PRINCIPALE :
 *    Dans ce projet le stock N'EST PAS dans products.quantity
 *    Il est calculé depuis stock_movements :
 *    stock = SUM(initial) + SUM(entry) - SUM(exit) + SUM(adjustment)
 *    → Pour décrémenter : on INSERT une ligne type='exit'
 *    → Référence : 'BON-{order_id}' (même format que 'VENTE-{invoice_id}')
 */
session_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/fpdf186/fpdf.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer', 'admin', 'manager', 'staff']);

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_name = $_SESSION['username'] ?? 'Caissiere';
$user_id   = $_SESSION['user_id'] ?? 0;

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$order_id   = (int)($_GET['order_id']   ?? 0);

if (!$invoice_id && !$order_id) {
    die("❌ Bon introuvable — paramètre invoice_id ou order_id requis.");
}

/* ══════════════════════════════════════════════════════════
   FONCTION LOG
══════════════════════════════════════════════════════════ */
function logAction($pdo, $order_id, $action, $old_val, $new_val, $details = '') {
    global $user_name, $user_id;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS caisse_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            old_status VARCHAR(50),
            new_status VARCHAR(50),
            user_name VARCHAR(100),
            user_id INT,
            details TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(order_id), INDEX(created_at)
        ) ENGINE=InnoDB");
        $pdo->prepare("
            INSERT INTO caisse_logs (order_id, action, old_status, new_status, user_name, user_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $order_id, $action, $old_val, $new_val,
            $user_name, $user_id, $details,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    } catch (Exception $e) {}
}

/* ══════════════════════════════════════════════════════════
   🔧 MISE À JOUR STOCK — LOGIQUE CORRECTE
   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   Dans ce projet le stock est géré par stock_movements.
   Pour vendre → on INSERT type='exit' (comme dans process_sale)
   company_id et city_id viennent de la commande elle-même.
══════════════════════════════════════════════════════════ */
function updateStock($pdo, $order_id, $items, $company_id, $city_id) {
    global $user_id, $user_name;
    $success       = true;
    $inTransaction = false;

    try {
        $pdo->beginTransaction();
        $inTransaction = true;

        foreach ($items as $item) {
            $product_id   = (int)($item['product_id']  ?? 0);
            $product_name = trim($item['product_name'] ?? '');
            $quantity     = (int)($item['quantity']     ?? 0);

            if ($quantity <= 0) continue;

            // Vérifier que le produit existe
            if ($product_id > 0) {
                $st = $pdo->prepare("SELECT id, name FROM products WHERE id = ? LIMIT 1");
                $st->execute([$product_id]);
            } else {
                $st = $pdo->prepare("SELECT id, name FROM products WHERE name = ? LIMIT 1");
                $st->execute([$product_name]);
            }
            $product = $st->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                logAction($pdo, $order_id, 'STOCK_ERROR', null, null,
                    "Produit introuvable — product_id=$product_id | nom='$product_name'"
                );
                $success = false;
                continue;
            }

            $pid = $product['id'];

            // Calculer le stock actuel depuis stock_movements (même formule que caisse_complete_enhanced)
            $stk = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN type='initial'    THEN quantity END), 0) +
                    COALESCE(SUM(CASE WHEN type='entry'      THEN quantity END), 0) -
                    COALESCE(SUM(CASE WHEN type='exit'       THEN quantity END), 0) +
                    COALESCE(SUM(CASE WHEN type='adjustment' THEN quantity END), 0) AS stock_dispo
                FROM stock_movements
                WHERE product_id = ? AND company_id = ? AND city_id = ?
            ");
            $stk->execute([$pid, $company_id, $city_id]);
            $stock_dispo = (int)$stk->fetchColumn();

            // ✅ INSERT une ligne 'exit' dans stock_movements
            // C'est EXACTEMENT ce que fait process_sale dans caisse_complete_enhanced.php
            $ref = 'BON-' . $order_id;
            $pdo->prepare("
                INSERT INTO stock_movements
                    (product_id, reference, company_id, city_id, type, quantity, movement_date)
                VALUES (?, ?, ?, ?, 'exit', ?, NOW())
            ")->execute([$pid, $ref, $company_id, $city_id, $quantity]);

            logAction($pdo, $order_id, 'STOCK_UPDATE',
                $stock_dispo, $stock_dispo - $quantity,
                "Produit: {$product['name']} (id=$pid) | Vendu: -$quantity | Stock avant: $stock_dispo → après: " . ($stock_dispo - $quantity) . " | Réf: $ref"
            );
        }

        $pdo->commit();
        $inTransaction = false;
        return $success;

    } catch (Exception $e) {
        if ($inTransaction) {
            try { $pdo->rollBack(); } catch (Exception $re) {}
        }
        error_log("updateStock error: " . $e->getMessage());
        return false;
    }
}

function convertEncoding($text) {
    return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
}

/* ══════════════════════════════════════════════════════════
   CHARGEMENT DONNÉES
══════════════════════════════════════════════════════════ */
$invoice    = null;
$items      = [];
$payment    = null;
$mode       = $invoice_id ? 'invoice' : 'order';
$company_id = 0;
$city_id    = 0;

// ── MODE FACTURE ──
if ($mode === 'invoice') {
    $stmt = $pdo->prepare("
        SELECT i.*, i.id AS doc_id, i.total AS total_amount,
               'TICKET DE CAISSE' AS doc_type,
               c.name AS client_name, c.phone AS client_phone,
               co.name AS company_name, ci.name AS city_name,
               NULL AS order_number, NULL AS status,
               NULL AS delivery_address, NULL AS notes, NULL AS payment_method,
               NULL AS invoiced_at, NULL AS invoiced_by
        FROM invoices i
        JOIN clients   c  ON c.id  = i.client_id
        JOIN companies co ON co.id = i.company_id
        JOIN cities    ci ON ci.id = i.city_id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) die("❌ Facture introuvable.");

    $company_id = (int)($invoice['company_id'] ?? 0);
    $city_id    = (int)($invoice['city_id']    ?? 0);

    $stmt = $pdo->prepare("
        SELECT ii.product_id, p.name AS product_name,
               ii.quantity, ii.price AS unit_price, ii.total AS subtotal
        FROM invoice_items ii
        JOIN products p ON p.id = ii.product_id
        WHERE ii.invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT payment_mode AS method, amount, payment_date FROM versements WHERE invoice_id=? LIMIT 1");
    $stmt->execute([$invoice_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── MODE COMMANDE ──
if ($mode === 'order') {
    $stmt = $pdo->prepare("
        SELECT o.id AS doc_id, o.id AS order_id,
               o.order_number, o.status, o.total_amount,
               o.payment_method, o.delivery_address, o.notes, o.created_at,
               o.invoiced_at, o.invoiced_by,
               o.company_id, o.city_id,
               'BON DE LIVRAISON' AS doc_type,
               c.name AS client_name, c.phone AS client_phone,
               co.name AS company_name, ci.name AS city_name,
               NULL AS total
        FROM orders o
        LEFT JOIN clients   c  ON c.id  = o.client_id
        LEFT JOIN companies co ON co.id = o.company_id
        LEFT JOIN cities    ci ON ci.id = o.city_id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) die("❌ Commande introuvable.");

    // ✅ Récupérer company_id et city_id depuis la commande
    $company_id = (int)($invoice['company_id'] ?? 0);
    $city_id    = (int)($invoice['city_id']    ?? 0);

    if (empty($invoice['total_amount'])) $invoice['total_amount'] = 0;

    // ✅ Récupérer product_id pour updateStock
    $stmt = $pdo->prepare("
        SELECT id, product_id, product_name, quantity, unit_price, subtotal
        FROM order_items
        WHERE order_id = ?
        ORDER BY id
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Paiement simulé
    $PAY_LABELS = [
        'cash'          => 'Espèces',
        'mobile_money'  => 'Mobile Money',
        'bank_transfer' => 'Virement',
        'credit'        => 'Crédit'
    ];
    $pm = $invoice['payment_method'] ?? null;
    if ($pm) {
        $payment = [
            'method'       => $PAY_LABELS[$pm] ?? $pm,
            'amount'       => $invoice['total_amount'],
            'payment_date' => $invoice['created_at'],
            'is_credit'    => ($invoice['status'] !== 'done'),
        ];
    }

    /* ══════════════════════════════════════════════════════════
       ANTI-DOUBLON + MISE À JOUR STOCK
       ✅ Déclenchée à la 1ère ouverture
       ✅ invoiced_at protège contre la double facturation
    ══════════════════════════════════════════════════════════ */
    if (!empty($invoice['invoiced_at'])) {
        $already_invoiced = true;
        $invoiced_date    = $invoice['invoiced_at'];
        $invoiced_by_user = $invoice['invoiced_by'] ?? 'Inconnu';
    } else {
        $already_invoiced = false;

        // ✅ Décrémenter le stock via stock_movements (type='exit')
        $stock_updated = updateStock($pdo, $order_id, $items, $company_id, $city_id);

        // Marquer comme facturé
        $pdo->prepare("UPDATE orders SET invoiced_at = NOW(), invoiced_by = ? WHERE id = ?")
            ->execute([$user_name, $order_id]);

        logAction($pdo, $order_id, 'INVOICE_GENERATED',
            $invoice['status'], $invoice['status'],
            "Ticket ouvert | company=$company_id | city=$city_id | Stock mis à jour: " . ($stock_updated ? '✓' : '✗')
        );
    }
}

/* ══════════════════════════════════════════════════════════
   VARIABLES D'AFFICHAGE
══════════════════════════════════════════════════════════ */
$display_id    = $mode === 'invoice' ? $invoice_id : $order_id;
$display_label = $mode === 'invoice'
    ? str_pad($invoice_id, 6, '0', STR_PAD_LEFT)
    : ($invoice['order_number'] ?? str_pad($order_id, 6, '0', STR_PAD_LEFT));
$total        = (float)($invoice['total_amount'] ?? $invoice['total'] ?? 0);
$doc_type     = $invoice['doc_type'] ?? ($mode === 'invoice' ? 'TICKET DE CAISSE' : 'BON DE LIVRAISON');
$status_label = '';
if ($mode === 'order') {
    $SL = [
        'confirmed'  => 'A LIVRER',
        'delivering' => 'EN LIVRAISON',
        'done'       => 'LIVREE',
        'cancelled'  => 'ANNULEE',
        'pending'    => 'EN ATTENTE'
    ];
    $status_label = $SL[$invoice['status']] ?? strtoupper($invoice['status'] ?? '');
}

/* ══════════════════════════════════════════════════════════
   GÉNÉRATION PDF — ?download=1
   Stock déjà mis à jour à l'ouverture, ici on génère le PDF
══════════════════════════════════════════════════════════ */
if (isset($_GET['download'])) {

    logAction($pdo, $order_id ?: 0, 'PDF_DOWNLOAD', null, null,
        "Téléchargement PDF — $display_label"
    );

    $pdf = new FPDF('P', 'mm', [80, 297]);
    $pdf->AddPage();
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(false);

    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(70, 6, convertEncoding($invoice['company_name'] ?? 'ESPERANCE H2O'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(70, 5, convertEncoding($invoice['city_name'] ?? ''), 0, 1, 'C');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(70, 6, '** ' . convertEncoding($doc_type) . ' **', 0, 1, 'C');
    $pdf->Ln(2);

    $pdf->SetLineWidth(0.5);
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(70, 5, 'N. ' . ($mode === 'invoice' ? 'Facture' : 'Commande') . ': ' . $display_label, 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(70, 4, 'Date: ' . date('d/m/Y H:i', strtotime($invoice['created_at'] ?? 'now')), 0, 1);
    $pdf->Cell(70, 4, convertEncoding('Client: ' . ($invoice['client_name'] ?? '—')), 0, 1);
    $pdf->Cell(70, 4, convertEncoding('Tel: '    . ($invoice['client_phone'] ?? '—')), 0, 1);
    if ($mode === 'order' && !empty($invoice['delivery_address'])) {
        $pdf->Cell(70, 4, convertEncoding('Adresse: ' . mb_substr($invoice['delivery_address'], 0, 35)), 0, 1);
    }
    $pdf->Ln(2);

    $pdf->SetLineWidth(0.3);
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(32, 5, 'Article', 0);
    $pdf->Cell(12, 5, 'Qte',    0, 0, 'C');
    $pdf->Cell(13, 5, 'P.U',    0, 0, 'R');
    $pdf->Cell(13, 5, 'Total',  0, 1, 'R');
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(1);

    $pdf->SetFont('Arial', '', 9);
    foreach ($items as $item) {
        $name = convertEncoding(mb_substr($item['product_name'] ?? '', 0, 18));
        $qty  = $item['quantity'] ?? 0;
        $pu   = number_format((float)($item['unit_price'] ?? $item['price']  ?? 0), 0, ',', ' ');
        $tot  = number_format((float)($item['subtotal']   ?? $item['total']  ?? 0), 0, ',', ' ');
        $pdf->Cell(32, 5, $name, 0);
        $pdf->Cell(12, 5, $qty,  0, 0, 'C');
        $pdf->Cell(13, 5, $pu,   0, 0, 'R');
        $pdf->Cell(13, 5, $tot,  0, 1, 'R');
    }
    $pdf->Ln(1);

    $pdf->SetLineWidth(0.5);
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(45, 7, 'TOTAL', 0, 0, 'R');
    $pdf->Cell(25, 7, number_format($total, 0, ',', ' ') . ' CFA', 0, 1, 'R');

    $pdf->SetLineWidth(0.5);
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(3);

    if ($payment) {
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(70, 4, convertEncoding('Mode: ' . ($payment['method'] ?? '—')), 0, 1, 'C');
        if (empty($payment['is_credit'])) {
            $pdf->Cell(70, 4, 'Montant: ' . number_format((float)$payment['amount'], 0, ',', ' ') . ' CFA', 0, 1, 'C');
            $pdf->Ln(1);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(70, 6, '*** PAYEE ***', 0, 1, 'C');
        } else {
            $pdf->Ln(1);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(70, 6, '*** ' . $status_label . ' ***', 0, 1, 'C');
        }
    } elseif ($mode === 'order') {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(70, 6, '*** ' . $status_label . ' ***', 0, 1, 'C');
    } else {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(70, 6, '*** CREDIT - A PAYER ***', 0, 1, 'C');
    }

    if ($mode === 'order' && !empty($invoice['notes'])) {
        $pdf->Ln(1);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(70, 4, convertEncoding('Note: ' . mb_substr($invoice['notes'], 0, 40)), 0, 1, 'C');
    }

    $pdf->Ln(3);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(70, 4, 'Merci de votre confiance !', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(70, 5, 'ESPERANCE H2O', 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(70, 4, '|||| ' . str_pad($display_id, 8, '0', STR_PAD_LEFT) . ' ||||', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->Cell(70, 4, '- - - - - - - - - - - - - - - - - -', 0, 1, 'C');

    $pdf->Output('I', 'ticket_' . $display_label . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($doc_type) ?> — <?= htmlspecialchars($display_label) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;font-style:normal}
:root{--bg:#0f1726;--card:#1b263b;--bord:rgba(148,163,184,0.18);--neon:#00a86b;--red:#e53935;--gold:#f9a825;--cyan:#06b6d4;--blue:#1976d2;--text:#e8eef8;--muted:#8ea3bd;--fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;--fb:'Inter','Segoe UI',system-ui,sans-serif}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--fb);background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;position:relative}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.08) 0%,transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.07) 0%,transparent 62%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);background-size:46px 46px}
.container{position:relative;z-index:1;background:var(--card);max-width:480px;width:100%;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.5);overflow:hidden;border:1px solid var(--bord)}
.header{background:linear-gradient(135deg,var(--neon),var(--cyan));color:#04090e;padding:32px;text-align:center}
.header-icon{font-size:52px;margin-bottom:16px;animation:scaleIn .5s ease}
@keyframes scaleIn{from{transform:scale(0)}to{transform:scale(1)}}
.header h1{font-family:var(--fh);font-size:24px;font-weight:900;margin-bottom:8px}
.body{padding:32px}
.alert-err{background:rgba(255,53,83,0.12);border:1.5px solid rgba(255,53,83,0.3);color:var(--red);padding:18px;border-radius:12px;margin-bottom:24px;text-align:center;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.7}}
.alert-err i{font-size:38px;display:block;margin-bottom:10px}
.alert-err h3{font-family:var(--fh);font-size:18px;font-weight:900;margin-bottom:8px}
.alert-err p{font-size:13px;margin-top:8px;opacity:.9}
.alert-ok{background:rgba(50,190,143,0.10);border:1.5px solid rgba(50,190,143,0.3);color:var(--neon);padding:14px;border-radius:12px;margin-bottom:20px;text-align:center;font-family:var(--fh);font-size:13px;font-weight:700}
.info-section{margin-bottom:24px;padding-bottom:18px;border-bottom:1px dashed rgba(255,255,255,0.06)}
.info-row{display:flex;justify-content:space-between;margin-bottom:10px;font-size:14px}
.info-label{color:var(--muted);font-weight:600}
.info-value{color:var(--text);font-weight:700;text-align:right;flex:1;margin-left:12px}
.items h3{font-family:var(--fh);color:var(--neon);margin-bottom:14px;font-size:17px;font-weight:900}
.item-row{display:flex;justify-content:space-between;margin-bottom:10px;padding:10px;background:rgba(0,0,0,0.18);border-radius:10px;border:1px solid rgba(255,255,255,0.04)}
.item-row:hover{background:rgba(50,190,143,0.05)}
.item-name{font-weight:800;color:var(--text);margin-bottom:3px;font-family:var(--fh)}
.item-calc{font-size:12px;color:var(--muted)}
.item-total{font-weight:900;color:var(--neon);font-size:16px;white-space:nowrap;margin-left:10px;font-family:var(--fh)}
.total{background:linear-gradient(135deg,var(--neon),var(--cyan));color:#04090e;padding:20px;border-radius:12px;margin:24px 0;text-align:center}
.total h2{font-size:13px;font-weight:700;margin-bottom:6px;opacity:.8}
.total .amount{font-family:var(--fh);font-size:36px;font-weight:900}
.status{padding:16px;border-radius:10px;margin-bottom:20px;text-align:center;font-weight:900;font-family:var(--fh)}
.status.paid{background:rgba(50,190,143,0.14);color:var(--neon);border:1px solid rgba(50,190,143,0.3)}
.status.unpaid{background:rgba(255,208,96,0.14);color:var(--gold);border:1px solid rgba(255,208,96,0.3)}
.status.delivering{background:rgba(6,182,212,0.14);color:var(--cyan);border:1px solid rgba(6,182,212,0.3)}
.actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px}
.btn{padding:13px;border:none;border-radius:10px;font-weight:900;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;font-size:13px;font-family:var(--fh)}
.btn:hover{transform:translateY(-2px)}
.btn-primary{background:linear-gradient(135deg,var(--neon),var(--cyan));color:#04090e}
.btn-secondary{background:rgba(255,255,255,0.04);border:1.5px solid var(--bord);color:#b8d8cc}
.btn-success{background:linear-gradient(135deg,var(--neon),var(--cyan));color:#04090e;grid-column:1/-1}
.footer{text-align:center;color:var(--muted);font-size:12px;margin-top:20px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.06)}
@media print{body{background:#fff}.actions,.footer{display:none}}
@media(max-width:480px){.actions{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="container">

  <div class="header">
    <div class="header-icon">
      <?php if ($mode === 'order' && !empty($already_invoiced)): ?>
        <i class="fas fa-check-circle"></i>
      <?php else: ?>
        <i class="fas fa-<?= $mode === 'order' ? 'truck' : 'receipt' ?>"></i>
      <?php endif; ?>
    </div>
    <h1><?= htmlspecialchars($doc_type) ?></h1>
    <p><?= $mode === 'invoice' ? 'Facture N°' : 'Bon N°' ?> <?= htmlspecialchars($display_label) ?></p>
  </div>

  <div class="body">

    <?php if ($mode === 'order' && !empty($already_invoiced)): ?>
    <div class="alert-err">
      <i class="fas fa-exclamation-triangle"></i>
      <h3>⚠️ BON DÉJÀ FACTURÉ</h3>
      <p>Facturé le <strong><?= date('d/m/Y à H:i', strtotime($invoiced_date)) ?></strong>
         par <strong><?= htmlspecialchars($invoiced_by_user) ?></strong></p>
      <p style="margin-top:10px">Le stock a déjà été mis à jour. Visualisation uniquement.</p>
    </div>

    <?php elseif ($mode === 'order'): ?>
    <div class="alert-ok">
      <i class="fas fa-check-circle"></i>
      &nbsp;✅ Stock mis à jour (sortie enregistrée) — facturé par <strong><?= htmlspecialchars($user_name) ?></strong>
    </div>
    <?php endif; ?>

    <!-- INFOS -->
    <div class="info-section">
      <div class="info-row">
        <span class="info-label"><i class="fas fa-building"></i> Société</span>
        <span class="info-value"><?= htmlspecialchars($invoice['company_name'] ?? '—') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-map-marker-alt"></i> Ville</span>
        <span class="info-value"><?= htmlspecialchars($invoice['city_name'] ?? '—') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-user"></i> Client</span>
        <span class="info-value"><?= htmlspecialchars($invoice['client_name'] ?? '—') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-phone"></i> Téléphone</span>
        <span class="info-value"><?= htmlspecialchars($invoice['client_phone'] ?? '—') ?></span>
      </div>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-calendar"></i> Date</span>
        <span class="info-value"><?= date('d/m/Y H:i', strtotime($invoice['created_at'] ?? 'now')) ?></span>
      </div>
      <?php if ($mode === 'order' && !empty($invoice['delivery_address'])): ?>
      <div class="info-row">
        <span class="info-label"><i class="fas fa-location-dot"></i> Adresse</span>
        <span class="info-value"><?= htmlspecialchars($invoice['delivery_address']) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- ARTICLES -->
    <div class="items">
      <h3><i class="fas fa-list"></i> Articles</h3>
      <?php foreach ($items as $item): ?>
      <div class="item-row">
        <div style="flex:1">
          <div class="item-name"><?= htmlspecialchars($item['product_name'] ?? '—') ?></div>
          <div class="item-calc">
            <?= number_format((float)($item['unit_price'] ?? $item['price'] ?? 0), 0, ',', ' ') ?> FCFA
            × <?= $item['quantity'] ?? 0 ?>
          </div>
        </div>
        <div class="item-total">
          <?= number_format((float)($item['subtotal'] ?? $item['total'] ?? 0), 0, ',', ' ') ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- TOTAL -->
    <div class="total">
      <h2>TOTAL À PAYER</h2>
      <div class="amount"><?= number_format($total, 0, ',', ' ') ?> FCFA</div>
    </div>

    <!-- STATUT PAIEMENT -->
    <?php if ($payment): ?>
      <?php if (empty($payment['is_credit'])): ?>
      <div class="status paid">
        <i class="fas fa-check-circle"></i> PAYÉE
        <div style="font-size:12px;margin-top:6px;opacity:.8">
          Mode : <?= htmlspecialchars($payment['method'] ?? '—') ?>
          &nbsp;|&nbsp; <?= number_format((float)$payment['amount'], 0, ',', ' ') ?> FCFA
        </div>
      </div>
      <?php else: ?>
      <div class="status delivering">
        <i class="fas fa-truck"></i> <?= htmlspecialchars($status_label) ?>
        <div style="font-size:12px;margin-top:6px;opacity:.8">
          Mode : <?= htmlspecialchars($payment['method'] ?? '—') ?>
        </div>
      </div>
      <?php endif; ?>
    <?php else: ?>
    <div class="status unpaid">
      <i class="fas fa-exclamation-circle"></i>
      <?= $mode === 'order' ? htmlspecialchars($status_label) : 'CRÉDIT — À PAYER' ?>
    </div>
    <?php endif; ?>

    <!-- BOUTONS -->
    <div class="actions">
      <?php if ($mode === 'order' && !empty($already_invoiced)): ?>
        <a href="?order_id=<?= $order_id ?>&download=1" class="btn btn-secondary" style="grid-column:1/-1">
          <i class="fas fa-eye"></i> Voir PDF (déjà facturé)
        </a>
      <?php else: ?>
        <a href="?<?= $mode === 'invoice' ? 'invoice_id=' . $invoice_id : 'order_id=' . $order_id ?>&download=1"
           class="btn btn-primary">
          <i class="fas fa-download"></i> Télécharger PDF
        </a>
        <button onclick="window.print()" class="btn btn-secondary">
          <i class="fas fa-print"></i> Imprimer
        </button>
      <?php endif; ?>

      <?php if ($mode === 'order'): ?>
        <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="btn btn-success">
          <i class="fas fa-arrow-left"></i> Administrateur
        </a>
      <?php else: ?>
        <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="btn btn-success">
          <i class="fas fa-plus-circle"></i> Nouvelle Vente
        </a>
      <?php endif; ?>
    </div>

    <div class="footer">
      <p><i class="fas fa-heart"></i> Merci de votre confiance !</p>
      <p><strong>ESPERANCE H2O</strong></p>
    </div>

  </div>
</div>
</body>
</html>
