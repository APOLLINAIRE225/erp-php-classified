<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// REQUIRES
// =========================
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once APP_ROOT . '/fpdf186/fpdf.php';
require_once APP_ROOT . '/phpqrcode/qrlib.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

// =========================
// SESSION & SÉCURITÉ
// =========================
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

Auth::check();
Middleware::role(['developer','admin','manager']);

// =========================
// DB
// =========================
$pdo = DB::getConnection();
if (!$pdo) die("Erreur DB");

// =========================
// PARAM
// =========================
$invoice_id = $_GET['id'] ?? 0;
if (!$invoice_id) die("Facture non spécifiée");

// =========================
// FACTURE
// =========================
$stmt = $pdo->prepare("
    SELECT f.id, f.total, f.created_at,
           cl.name client_name,
           co.name company_name,
           ci.name city_name
    FROM invoices f
    JOIN clients cl ON cl.id=f.client_id
    JOIN companies co ON co.id=f.company_id
    JOIN cities ci ON ci.id=f.city_id
    WHERE f.id=?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) die("Facture introuvable");

// =========================
// PRODUITS
// =========================
$stmt = $pdo->prepare("
    SELECT p.name, i.quantity, i.price, i.total
    FROM invoice_items i
    JOIN products p ON p.id=i.product_id
    WHERE i.invoice_id=?
    ORDER BY p.name ASC
");
$stmt->execute([$invoice_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================
// PDF THERMIQUE 80mm
// =========================
$pdf = new FPDF('P','mm',[80,220]); // largeur 80mm
$pdf->SetMargins(3,5,3);
$pdf->AddPage();

// =========================
// LOGO
// =========================
$logo = APP_ROOT . '/assets/logo.png';
if (file_exists($logo)) {
    $pdf->Image($logo,25,5,30);
    $pdf->Ln(23);
}

// =========================
// SOCIÉTÉ
// =========================
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,5,strtoupper($invoice['company_name']),0,1,'C');

$pdf->SetFont('Arial','',9);
$pdf->Cell(0,4,"RECU / FACTURE",0,1,'C');
$pdf->Cell(0,4,"N° ".$invoice['id'],0,1,'C');
$pdf->Cell(0,4,date("d/m/Y H:i",strtotime($invoice['created_at'])),0,1,'C');

$pdf->Ln(2);
$pdf->Cell(0,4,str_repeat('-',32),0,1,'C');

// =========================
// CLIENT
// =========================
$pdf->SetFont('Arial','',8);
$pdf->Cell(0,4,"Client : ".$invoice['client_name'],0,1);
$pdf->Cell(0,4,"Ville  : ".$invoice['city_name'],0,1);

$pdf->Ln(1);
$pdf->Cell(0,4,str_repeat('-',32),0,1,'C');

// =========================
// PRODUITS
// =========================
foreach ($items as $item) {

    // Nom produit
    $pdf->SetFont('Arial','B',8);
    $pdf->MultiCell(0,4,$item['name']);

    // Ligne calcul
    $pdf->SetFont('Arial','',8);
    $line = $item['quantity']." x ".number_format($item['price'],0)
          ." = ".number_format($item['total'],0)." FCFA";
    $pdf->Cell(0,4,$line,0,1);

    $pdf->Ln(1);
}

// =========================
// TOTAL
// =========================
$pdf->Cell(0,4,str_repeat('-',32),0,1,'C');

$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,6,"TOTAL : ".number_format($invoice['total'],0)." FCFA",0,1,'C');

// =========================
// QR CODE
// =========================
$tmp = tempnam(sys_get_temp_dir(),'qr').'.png';
QRcode::png(
    "Facture ".$invoice['id']." | ".$invoice['total']." FCFA",
    $tmp,'L',3,1
);
$pdf->Ln(2);
$pdf->Image($tmp,22,$pdf->GetY(),35);
unlink($tmp);

$pdf->Ln(38);

// =========================
// FOOTER
// =========================
$pdf->SetFont('Arial','',8);
$pdf->Cell(0,4,"Merci pour votre confiance",0,1,'C');
$pdf->Cell(0,4,"A bientot 🙏",0,1,'C');

// =========================
// OUTPUT
// =========================
$pdf->Output("I","Recu_".$invoice['id'].".pdf");
exit;
