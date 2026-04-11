<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// =========================
// REQUIRES ET AUTOLOAD
// =========================
require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/fpdf186/fpdf.php';
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

/* =========================
   Filtres
========================= */
$company_id = (int)($_GET['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id'] ?? 0);
$search     = trim($_GET['search'] ?? '');

/* =========================
   WHERE dynamique sécurisé
========================= */
$where  = [];
$params = [];

if($company_id > 0){
    $where[] = "e.company_id = ?";
    $params[] = $company_id;
}
if($city_id > 0){
    $where[] = "e.city_id = ?";
    $params[] = $city_id;
}
if($search !== ''){
    $where[] = "(e.category LIKE ? OR e.note LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

/* =========================
   Récupération dépenses
========================= */
$sql = "
SELECT 
    e.id,
    e.category,
    e.amount,
    e.expense_date,
    e.note,
    co.name AS company_name,
    ci.name AS city_name
FROM expenses e
JOIN companies co ON co.id = e.company_id
JOIN cities ci    ON ci.id = e.city_id
$whereSQL
ORDER BY e.expense_date DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(!$expenses){
    die("Aucune dépense trouvée");
}

/* =========================
   PDF
========================= */
$pdf = new FPDF('L','mm','A4');
$pdf->AddPage();
$pdf->SetFont('Arial','B',16);
$pdf->SetTextColor(124,45,18);

$pdf->Cell(0,10,"RAPPORT DES DÉPENSES",0,1,'C');
$pdf->Ln(5);

$pdf->SetFont('Arial','',11);
$pdf->Cell(0,8,"Utilisateur : ".$_SESSION['username']." | Date : ".date('d/m/Y H:i'),0,1);
$pdf->Ln(5);

/* =========================
   Table
========================= */
$pdf->SetFillColor(217,119,6);
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('Arial','B',10);

$pdf->Cell(15,8,"ID",1,0,'C',true);
$pdf->Cell(45,8,"Entreprise",1,0,'C',true);
$pdf->Cell(35,8,"Ville",1,0,'C',true);
$pdf->Cell(40,8,"Catégorie",1,0,'C',true);
$pdf->Cell(35,8,"Montant CFA",1,0,'C',true);
$pdf->Cell(60,8,"Note",1,0,'C',true);
$pdf->Cell(35,8,"Date",1,1,'C',true);

$pdf->SetFont('Arial','',10);
$pdf->SetTextColor(0,0,0);

$total_expenses = 0;

foreach($expenses as $e){
    $pdf->Cell(15,7,$e['id'],1);
    $pdf->Cell(45,7,$e['company_name'],1);
    $pdf->Cell(35,7,$e['city_name'],1);
    $pdf->Cell(40,7,$e['category'],1);
    $pdf->Cell(35,7,number_format($e['amount'],0,' ',' '),1,0,'R');
    $pdf->Cell(60,7,substr($e['note'],0,45),1);
    $pdf->Cell(35,7,date('d/m/Y H:i',strtotime($e['expense_date'])),1,1);

    $total_expenses += $e['amount'];
}

/* =========================
   Total général
========================= */
$pdf->Ln(4);
$pdf->SetFont('Arial','B',11);
$pdf->Cell(195,8,"TOTAL DÉPENSES",1);
$pdf->Cell(35,8,number_format($total_expenses,0,' ',' ')." CFA",1,1,'R');

$pdf->Ln(8);
$pdf->SetFont('Arial','I',9);
$pdf->Cell(0,6,"Esperance H2O — Rapport de dépenses officiel",0,1,'C');

$pdf->Output("I","depenses_export.pdf");
