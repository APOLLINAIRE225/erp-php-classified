<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/fpdf186/fpdf.php';

if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN'){
    die("Accès refusé");
}

$stmt = $pdo->query("
    SELECT l.*, u.username
    FROM logs l
    JOIN users u ON u.id = l.user_id
    ORDER BY l.log_date DESC
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdf = new FPDF('L','mm','A4');
$pdf->AddPage();

$pdf->SetFont('Arial','B',16);
$pdf->Cell(0,10,'RAPPORT DES ACTIVITES SYSTEME',0,1,'C');
$pdf->Ln(5);

$pdf->SetFont('Arial','B',9);
$pdf->Cell(40,8,'Date',1);
$pdf->Cell(30,8,'Utilisateur',1);
$pdf->Cell(30,8,'Module',1);
$pdf->Cell(90,8,'Action',1);
$pdf->Cell(35,8,'IP',1);
$pdf->Cell(45,8,'Localisation',1);
$pdf->Ln();

$pdf->SetFont('Arial','',8);

foreach($logs as $l){
    $pdf->Cell(40,8,$l['log_date'],1);
    $pdf->Cell(30,8,$l['username'],1);
    $pdf->Cell(30,8,$l['module'],1);
    $pdf->Cell(90,8,substr($l['action'],0,60),1);
    $pdf->Cell(35,8,$l['ip'],1);
    $pdf->Cell(45,8,$l['location'],1);
    $pdf->Ln();
}

$pdf->Output('I','logs_systeme.pdf');
