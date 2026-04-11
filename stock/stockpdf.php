<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/tcpdf/tcpdf.php';

$company_id = (int)($_GET['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id'] ?? 0);
$search     = trim($_GET['search'] ?? '');

// =========================
// Récupérer le nom société et ville
// =========================
$company_name = "Toutes les sociétés";
if ($company_id) {
    $stmt = $pdo->prepare("SELECT name FROM companies WHERE id=?");
    $stmt->execute([$company_id]);
    $company_name = $stmt->fetchColumn();
}

$city_name = "Toutes les villes";
if ($city_id) {
    $stmt = $pdo->prepare("SELECT name FROM cities WHERE id=?");
    $stmt->execute([$city_id]);
    $city_name = $stmt->fetchColumn();
}

// =========================
// Produits triés A→Z
// =========================
$params = [];
$sql = "
SELECT 
    p.id,
    p.name,
    p.category,
    p.price,
    COALESCE(st.quantity,0) AS stock,
    COALESCE(ent.total_arrivals,0) AS total_entrees,
    COALESCE(sor.total_sales,0) AS total_sorties
FROM products p
LEFT JOIN stocks st ON st.product_id=p.id " . ($city_id ? " AND st.city_id=:city_st" : "") . "
LEFT JOIN (
    SELECT product_id, SUM(quantity) total_arrivals
    FROM arrivals " . ($city_id ? "WHERE city_id=:city_ent" : "") . "
    GROUP BY product_id
) ent ON ent.product_id=p.id
LEFT JOIN (
    SELECT product_id, SUM(quantity) total_sales
    FROM sales " . ($city_id ? "WHERE city_id=:city_sor" : "") . "
    GROUP BY product_id
) sor ON sor.product_id=p.id
WHERE 1 " 
. ($company_id ? " AND p.company_id=:company" : "") 
. ($search ? " AND p.name LIKE :search" : "") 
. " ORDER BY p.name ASC";

if ($company_id) $params[':company'] = $company_id;
if ($city_id) {
    $params[':city_st']  = $city_id;
    $params[':city_ent'] = $city_id;
    $params[':city_sor'] = $city_id;
}
if ($search) $params[':search'] = "%$search%";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================
// Création PDF
// =========================
class MYPDF extends TCPDF {
    public function Header() {
        $image_file = APP_ROOT . '/logo.jpg';
        if(file_exists($image_file)) {
            $this->Image($image_file, 15, 10, 30); // logo en haut à gauche
        }
        $this->SetFont('helvetica', 'B', 20);
        $this->Cell(0, 15, ' Rapport de Stock', 0, 1, 'C');
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica','I',8);
        $this->Cell(0,10,'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),0,0,'C');
    }
}

$pdf = new MYPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->SetCreator('EsperanceH2O');
$pdf->SetAuthor('EsperanceH2O');
$pdf->SetTitle('Stock Report');
$pdf->SetMargins(15, 45, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();

// =========================
// Informations société / ville / date
// =========================
$pdf->SetFont('helvetica','B',12);
$pdf->SetTextColor(0,77,64);
$pdf->Cell(0,6,"Société: $company_name | Magasin: $city_name | Export: ".date('d/m/Y H:i'),0,1,'C');
$pdf->Ln(5);

// =========================
// Tableau
// =========================
$pdf->SetFont('helvetica','',10);
$w = [10, 60, 40, 25, 25, 25, 20, 30]; // Largeur des colonnes
$header = ['#','Produit','Catégorie','Prix','Entrées','Sorties','Stock','État'];

// En-tête tableau
$pdf->SetFillColor(200, 230, 201); // vert clair
$pdf->SetTextColor(0,0,0);
foreach($header as $k=>$col){
    $pdf->Cell($w[$k],7,$col,1,0,'C',1);
}
$pdf->Ln();

// Contenu
$fill = false;
$i=1;
foreach($products as $p){
    $pdf->SetFillColor($fill ? 240 : 255, $fill ? 253 : 250, $fill ? 240 : 255);
    $etat = ($p['stock'] <= 0) ? 'Rupture' : (($p['stock'] <= $p['total_entrees']*0.1) ? 'À provisionner' : 'En stock');

    $pdf->Cell($w[0],6,$i,1,0,'C',$fill);
    $pdf->Cell($w[1],6,$p['name'],1,0,'L',$fill);
    $pdf->Cell($w[2],6,$p['category'],1,0,'L',$fill);
    $pdf->Cell($w[3],6,number_format($p['price'],2),1,0,'R',$fill);
    $pdf->Cell($w[4],6,$p['total_entrees'],1,0,'C',$fill);
    $pdf->Cell($w[5],6,$p['total_sorties'],1,0,'C',$fill);
    $pdf->Cell($w[6],6,$p['stock'],1,0,'C',$fill);
    $pdf->Cell($w[7],6,$etat,1,0,'C',$fill);
    $pdf->Ln();
    $fill = !$fill;
    $i++;
}

// =========================
// Sortie PDF
// =========================
$pdf->Output('stock_report.pdf','I');