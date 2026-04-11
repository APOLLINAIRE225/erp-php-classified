<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/fpdf186/fpdf.php';
require_once APP_ROOT . '/phpqrcode/qrlib.php';

use App\Core\Auth;
use App\Core\DB;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer', 'admin', 'manager']);

$pdo = DB::getConnection();

$type = ($_GET['type'] ?? '') === 'depot' ? 'depot' : 'versement';
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Transaction invalide');
}

if ($type === 'depot') {
    $stmt = $pdo->prepare("
        SELECT d.*,
               c.name AS client_name, c.phone, c.email, c.created_at AS client_since,
               co.name AS company_name, ci.name AS city_name,
               u.username AS cashier_name
        FROM client_deposits d
        JOIN clients c ON c.id = d.client_id
        JOIN companies co ON co.id = d.company_id
        JOIN cities ci ON ci.id = d.city_id
        LEFT JOIN users u ON u.id = d.created_by
        WHERE d.id = ?
        LIMIT 1
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT v.*,
               c.name AS client_name, c.phone, c.email, c.created_at AS client_since,
               i.total AS invoice_total, i.status AS invoice_status,
               co.name AS company_name, ci.name AS city_name,
               u.username AS cashier_name
        FROM versements v
        JOIN clients c ON c.id = v.client_id
        JOIN invoices i ON i.id = v.invoice_id
        JOIN companies co ON co.id = i.company_id
        JOIN cities ci ON ci.id = i.city_id
        LEFT JOIN users u ON u.id = v.created_by
        WHERE v.id = ?
        LIMIT 1
    ");
}
$stmt->execute([$id]);
$tx = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tx) {
    http_response_code(404);
    exit('Transaction introuvable');
}

$reference = $type === 'depot'
    ? ($tx['reference'] ?: 'DEPOT-' . $tx['id'])
    : ($tx['receipt_number'] ?: $tx['reference'] ?: 'PAY-' . $tx['id']);
$createdAt = $type === 'depot' ? ($tx['created_at'] ?? date('Y-m-d H:i:s')) : ($tx['payment_date'] ?? $tx['created_at']);
$cashier = $tx['cashier_name'] ?: ($_SESSION['username'] ?? 'Utilisateur');
$amount = (float) $tx['amount'];

$baseUrl = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://'
) . ($_SERVER['HTTP_HOST'] ?? 'localhost') . project_url('finance/receipt_pdf.php') . '?type=' . urlencode($type) . '&id=' . $id;

$tmpQr = sys_get_temp_dir() . '/receipt_qr_' . md5($type . '_' . $id . '_' . $reference) . '.png';
QRcode::png($baseUrl, $tmpQr, QR_ECLEVEL_L, 4, 2);

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 12);

$pdf->SetFillColor(13, 30, 44);
$pdf->Rect(0, 0, 210, 34, 'F');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 10, utf8_decode($type === 'depot' ? 'RECU DE DEPOT' : 'RECU DE VERSEMENT'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, utf8_decode($tx['company_name'] . ' - Guichet ' . $tx['city_name']), 0, 1, 'L');
$pdf->Ln(8);

$pdf->SetTextColor(20, 20, 20);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(95, 8, utf8_decode('Référence : ' . $reference), 0, 0, 'L');
$pdf->Cell(0, 8, 'Date : ' . date('d/m/Y H:i', strtotime($createdAt)), 0, 1, 'R');

$pdf->SetDrawColor(230, 233, 236);
$pdf->SetFillColor(246, 248, 250);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 9, 'Client', 1, 1, 'L', true);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, utf8_decode('Nom : ' . $tx['client_name']), 1, 1);
$pdf->Cell(0, 7, utf8_decode('Téléphone : ' . ($tx['phone'] ?: 'N/A')), 1, 1);
$pdf->Cell(0, 7, utf8_decode('Email : ' . ($tx['email'] ?: 'N/A')), 1, 1);
$pdf->Cell(0, 7, utf8_decode('Client depuis : ' . date('M Y', strtotime($tx['client_since'] ?: $createdAt))), 1, 1);
$pdf->Ln(4);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 9, 'Transaction', 1, 1, 'L', true);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, utf8_decode('Type : ' . ($type === 'depot' ? 'Dépôt client' : 'Paiement facture')), 1, 1);
$pdf->Cell(0, 7, utf8_decode('Mode : ' . ($tx['payment_mode'] ?: 'Espèce')), 1, 1);
$pdf->Cell(0, 7, utf8_decode('Montant : ' . number_format($amount, 0, ',', ' ') . ' FCFA'), 1, 1);
if ($type !== 'depot') {
    $pdf->Cell(0, 7, utf8_decode('Facture : #' . $tx['invoice_id'] . ' - Statut ' . ($tx['invoice_status'] ?: 'N/A')), 1, 1);
}
if (!empty($tx['note'])) {
    $pdf->MultiCell(0, 7, utf8_decode('Note : ' . $tx['note']), 1);
}
$pdf->Ln(4);

$pdf->SetFont('Arial', 'B', 26);
$pdf->SetTextColor(40, 167, 69);
$pdf->Cell(0, 14, utf8_decode(number_format($amount, 0, ',', ' ') . ' FCFA'), 0, 1, 'C');
$pdf->SetTextColor(20, 20, 20);

$pdf->SetFont('Arial', '', 10);
$pdf->MultiCell(
    125,
    6,
    utf8_decode("Document généré automatiquement après encaissement. Vérifiez le QR code ou l'URL pour confirmer l'authenticité du reçu.")
);

$pdf->Image($tmpQr, 160, 128, 34, 34);
$pdf->SetXY(12, 168);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 6, utf8_decode('Lien de vérification : ' . $baseUrl), 0, 1);

$pdf->SetY(-40);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(90, 6, utf8_decode('Encaissement par : ' . $cashier), 0, 0, 'L');
$pdf->Cell(0, 6, utf8_decode('Guichet : ' . $tx['city_name']), 0, 1, 'R');
$pdf->Ln(10);
$pdf->Cell(90, 6, utf8_decode('Signature guichetier'), 0, 0, 'L');
$pdf->Cell(0, 6, 'Signature client', 0, 1, 'R');
$pdf->Ln(10);
$pdf->Line(12, $pdf->GetY(), 82, $pdf->GetY());
$pdf->Line(128, $pdf->GetY(), 198, $pdf->GetY());

if (is_file($tmpQr)) {
    @unlink($tmpQr);
}

$filename = ($type === 'depot' ? 'recu_depot_' : 'recu_versement_') . preg_replace('/[^A-Z0-9\-]/i', '_', $reference) . '.pdf';
$pdf->Output('I', $filename);
