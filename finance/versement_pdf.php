<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// REQUIRES ET AUTOLOAD
// =========================
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . "/fpdf186/fpdf.php";
require_once APP_ROOT . "/phpqrcode/qrlib.php"; // QRCode

// =========================
// IMPORTS
// =========================
use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
//use FPDF\FPDF;

// =========================
// SESSION & ERREURS
// =========================
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =========================
// CONNEXION PDO
// =========================
$pdo = DB::getConnection();
if (!$pdo) {
    die("❌ Impossible de se connecter à la base de données !");
}

// =========================
// SÉCURITÉ
// =========================
Auth::check();
Middleware::role(['developer','admin','manager']);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) die("Facture invalide");

/* =========================
   FACTURE + CLIENT + SOCIÉTÉ + VILLE
========================= */
$stmt = $pdo->prepare("
SELECT 
    i.*,
    c.name  AS client_name,
    c.phone,
    ci.name AS city_name,
    co.name AS company_name
FROM invoices i
JOIN clients c ON c.id = i.client_id
JOIN cities ci ON ci.id = i.city_id
JOIN companies co ON co.id = i.company_id
WHERE i.id=?
");
$stmt->execute([$invoice_id]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) die("Facture introuvable");

/* =========================
   PRODUITS FACTURE
========================= */
$items = $pdo->prepare("
SELECT p.name AS product_name, ii.quantity, ii.price, ii.total
FROM invoice_items ii
JOIN products p ON p.id = ii.product_id
WHERE ii.invoice_id = ?
");
$items->execute([$invoice_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   VERSEMENTS
========================= */
$pay = $pdo->prepare("
SELECT COALESCE(SUM(amount),0) FROM versements WHERE invoice_id=?
");
$pay->execute([$invoice_id]);
$total_paid = (float)$pay->fetchColumn();

$reste = $inv['total'] - $total_paid;

if ($reste <= 0) $status = "Payée";
elseif ($total_paid > 0) $status = "Partielle";
else $status = "Impayée";

/* =========================
   PDF
========================= */
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial','B',14);

/* =========================
   HEADER ENTREPRISE
========================= */
$pdf->Cell(0,10,$inv['company_name'],0,1,'L');
$pdf->SetFont('Arial','',10);
$pdf->Cell(0,6,"Magasin : ".$inv['city_name'],0,1);
$pdf->Ln(5);

/* =========================
   FACTURE INFO
========================= */
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,8,"FACTURE N° ".$inv['id'],0,1);
$pdf->SetFont('Arial','',10);
$pdf->Cell(0,6,"Date : ".$inv['created_at'],0,1);
$pdf->Ln(5);

/* =========================
   CLIENT INFO
========================= */
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,7,"Client",0,1);
$pdf->SetFont('Arial','',10);
$pdf->Cell(0,6,"Nom : ".$inv['client_name'],0,1);
$pdf->Cell(0,6,"Téléphone : ".$inv['phone'],0,1);
$pdf->Ln(5);

/* =========================
   TABLEAU PRODUITS
========================= */
$pdf->SetFont('Arial','B',10);
$pdf->Cell(80,8,'Produit',1);
$pdf->Cell(30,8,'Quantité',1,0,'C');
$pdf->Cell(40,8,'Prix',1,0,'R');
$pdf->Cell(40,8,'Total',1,1,'R');

$pdf->SetFont('Arial','',10);
foreach ($items as $it) {
    $pdf->Cell(80,8,$it['product_name'],1);
    $pdf->Cell(30,8,$it['quantity'],1,0,'C');
    $pdf->Cell(40,8,number_format($it['price'],2)." FCFA",1,0,'R');
    $pdf->Cell(40,8,number_format($it['total'],2)." FCFA",1,1,'R');
}

/* =========================
   RÉSUMÉ
========================= */
$pdf->Ln(5);
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,6,"Total facture : ".number_format($inv['total'],2)." FCFA",0,1);
$pdf->Cell(0,6,"Total versé : ".number_format($total_paid,2)." FCFA",0,1);
$pdf->Cell(0,6,"Reste : ".number_format($reste,2)." FCFA",0,1);
$pdf->Cell(0,6,"Statut : ".$status,0,1);

/* =========================
   SORTIE PDF
========================= */
$pdf->Output("I","Facture_".$invoice_id.".pdf");
