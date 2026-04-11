<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ════════════════════════════════════════════════════════════════
 * SYSTÈME RH UNIFIÉ — ESPERANCE H2O
 * Dark Neon Pro — Version Corrigée & Unifiée
 * ════════════════════════════════════════════════════════════════
 * ✅ Gestion Employés (CRUD complet)
 * ✅ Demandes (Permissions + Avances)
 * ✅ Paie (génération + validation + export Excel)
 * ✅ Logs d'activité
 * ✅ Style Dark Neon identique admin_nasa.php
 * ✅ Toutes erreurs CSS corrigées
 */

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require APP_ROOT . '/vendor/autoload.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

Auth::check();
Middleware::role(['developer', 'admin', 'manager']);

$pdo = DB::getConnection();
$user_id   = $_SESSION['user_id']   ?? 0;
$user_name = $_SESSION['username']  ?? 'USER';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

date_default_timezone_set('Africa/Abidjan');

$success_msg = '';
$error_msg   = '';
$view        = $_GET['view'] ?? 'employees';

/* ═══════════════════════════════════════════
   DB SETUP — colonnes optionnelles
═══════════════════════════════════════════ */
$optional_columns = [
    "ALTER TABLE employees ADD COLUMN password VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE employees ADD COLUMN start_date DATE DEFAULT NULL",
    "ALTER TABLE employees ADD COLUMN notes TEXT DEFAULT NULL",
    "ALTER TABLE employees ADD COLUMN contract_type VARCHAR(50) DEFAULT 'CDI'",
    "ALTER TABLE employees ADD COLUMN emergency_contact VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE employees ADD COLUMN performance_score DECIMAL(3,1) DEFAULT 5.0",
];
foreach ($optional_columns as $sql) {
    try { $pdo->exec($sql); } catch(Exception $e) {}
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS payroll (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    month VARCHAR(7) NOT NULL,
    base_salary DECIMAL(12,2) NOT NULL,
    overtime_amount DECIMAL(12,2) DEFAULT 0,
    advances_deduction DECIMAL(12,2) DEFAULT 0,
    absences_deduction DECIMAL(12,2) DEFAULT 0,
    net_salary DECIMAL(12,2) NOT NULL,
    status ENUM('impaye','paye') DEFAULT 'impaye',
    payment_date DATETIME NULL,
    paid_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_payroll (employee_id, month),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_month (month), INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id), INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ═══════════════════════════════════════════
   LOG HELPER
═══════════════════════════════════════════ */
function logActivity($pdo, $user_id, $action, $details) {
    try {
        $pdo->prepare("INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    } catch(Exception $e) {}
}

function csrfOk(): bool {
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '');
}

/* ═══════════════════════════════════════════
   CRUD EMPLOYÉS
═══════════════════════════════════════════ */
if (isset($_POST['create_employee'])) {
    if (!csrfOk()) {
        $error_msg = "Token CSRF invalide";
    } else {
        try {
            $password   = !empty($_POST['auto_password']) && $_POST['auto_password'] == '1'
                          ? bin2hex(random_bytes(4))
                          : (trim($_POST['custom_password']) ?: 'emp2024');
            $hashed     = password_hash($password, PASSWORD_DEFAULT);
            $code       = strtoupper(trim($_POST['employee_code']));
            $name       = trim($_POST['full_name']);

            $pdo->prepare("
                INSERT INTO employees
                    (employee_code, full_name, category_id, position_id, salary_type, salary_amount,
                     phone, address, hire_date, start_date, status, password, contract_type, emergency_contact, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,'actif',?,?,?,?)
            ")->execute([
                $code, $name,
                (int)$_POST['category_id'], (int)$_POST['position_id'],
                $_POST['salary_type'], (float)$_POST['salary_amount'],
                trim($_POST['phone']), trim($_POST['address']),
                $_POST['hire_date'], $_POST['start_date'],
                $hashed,
                $_POST['contract_type'] ?? 'CDI',
                trim($_POST['emergency_contact'] ?? ''),
                trim($_POST['notes'] ?? '')
            ]);

            logActivity($pdo, $user_id, 'CREATE_EMPLOYEE', "Créé : $name ($code)");
            $success_msg = "Employé créé avec succès !|Code : $code|Mot de passe : $password";
        } catch(Exception $e) {
            $error_msg = "Erreur : " . $e->getMessage();
        }
    }
}

if (isset($_POST['update_employee'])) {
    if (!csrfOk()) {
        $error_msg = "Token CSRF invalide";
    } else {
        try {
            $id = (int)$_POST['employee_id'];
            $pdo->prepare("
                UPDATE employees SET
                    full_name=?, category_id=?, position_id=?, salary_type=?, salary_amount=?,
                    phone=?, address=?, hire_date=?, start_date=?, status=?,
                    contract_type=?, emergency_contact=?, notes=?
                WHERE id=?
            ")->execute([
                trim($_POST['full_name']), (int)$_POST['category_id'], (int)$_POST['position_id'],
                $_POST['salary_type'], (float)$_POST['salary_amount'],
                trim($_POST['phone']), trim($_POST['address']),
                $_POST['hire_date'], $_POST['start_date'], $_POST['status'],
                $_POST['contract_type'] ?? 'CDI',
                trim($_POST['emergency_contact'] ?? ''),
                trim($_POST['notes'] ?? ''),
                $id
            ]);
            logActivity($pdo, $user_id, 'UPDATE_EMPLOYEE', "Modifié : ID $id");
            $success_msg = "Employé modifié avec succès !";
        } catch(Exception $e) {
            $error_msg = "Erreur : " . $e->getMessage();
        }
    }
}

if (isset($_POST['reset_password'])) {
    if (!csrfOk()) {
        $error_msg = "Token CSRF invalide";
    } else {
        try {
            $id  = (int)$_POST['employee_id'];
            $pwd = bin2hex(random_bytes(4));
            $pdo->prepare("UPDATE employees SET password=? WHERE id=?")->execute([password_hash($pwd, PASSWORD_DEFAULT), $id]);
            $st  = $pdo->prepare("SELECT employee_code, full_name FROM employees WHERE id=?");
            $st->execute([$id]);
            $emp = $st->fetch(PDO::FETCH_ASSOC);
            logActivity($pdo, $user_id, 'RESET_PASSWORD', "Reset : {$emp['full_name']}");
            $success_msg = "Mot de passe réinitialisé !|{$emp['full_name']} ({$emp['employee_code']})|Nouveau : $pwd";
        } catch(Exception $e) {
            $error_msg = "Erreur : " . $e->getMessage();
        }
    }
}

if (isset($_POST['update_performance'])) {
    try {
        $id    = (int)$_POST['employee_id'];
        $score = min(10, max(0, (float)$_POST['performance_score']));
        $pdo->prepare("UPDATE employees SET performance_score=? WHERE id=?")->execute([$score, $id]);
        logActivity($pdo, $user_id, 'UPDATE_PERFORMANCE', "Score : ID $id → $score/10");
        $success_msg = "Score de performance mis à jour !|Score : $score/10";
    } catch(Exception $e) {
        $error_msg = "Erreur : " . $e->getMessage();
    }
}

if (isset($_POST['delete_employee'])) {
    if (!csrfOk()) {
        $error_msg = "Token CSRF invalide";
    } else {
        try {
            $id   = (int)$_POST['employee_id'];
            $name = $pdo->prepare("SELECT full_name FROM employees WHERE id=?");
            $name->execute([$id]);
            $n    = $name->fetchColumn();
            $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            logActivity($pdo, $user_id, 'DELETE_EMPLOYEE', "Supprimé : $n");
            $success_msg = "Employé supprimé !|$n";
        } catch(Exception $e) {
            $error_msg = "Erreur : " . $e->getMessage();
        }
    }
}

/* ═══════════════════════════════════════════
   HELPER: Notification employé
═══════════════════════════════════════════ */
function notifyEmployee(PDO $pdo, int $employee_id, string $type, string $message): void {
    try {
        $pdo->prepare("
            INSERT INTO employee_notifications (employee_id, type, message, created_at, is_read)
            VALUES (?, ?, ?, NOW(), 0)
        ")->execute([$employee_id, $type, $message]);
    } catch (Throwable $e) {}
}

/* ═══════════════════════════════════════════
   DEMANDES
═══════════════════════════════════════════ */
if (isset($_POST['approve_permission'])) {
    $pid = (int)$_POST['permission_id'];
    $pdo->prepare("UPDATE permissions SET status='accepte' WHERE id=?")->execute([$pid]);
    logActivity($pdo, $user_id, 'APPROVE_PERMISSION', "Permission #{$pid} approuvée");
    $success_msg = "Permission approuvée !|Status : ACCEPTÉ";
    // Notifier l'employé
    $row = $pdo->prepare("SELECT employee_id, start_date, end_date FROM permissions WHERE id=?");
    $row->execute([$pid]);
    if ($r = $row->fetch(PDO::FETCH_ASSOC)) {
        $from = date('d/m/Y', strtotime($r['start_date']));
        $to   = date('d/m/Y', strtotime($r['end_date']));
        notifyEmployee($pdo, (int)$r['employee_id'], 'permission',
            "✅ Votre demande de permission du {$from} au {$to} a été ACCEPTÉE par l'administration.");
    }
}
if (isset($_POST['reject_permission'])) {
    $pid = (int)$_POST['permission_id'];
    $pdo->prepare("UPDATE permissions SET status='rejete' WHERE id=?")->execute([$pid]);
    $success_msg = "Permission rejetée !|Status : REJETÉ";
    $row = $pdo->prepare("SELECT employee_id, start_date, end_date FROM permissions WHERE id=?");
    $row->execute([$pid]);
    if ($r = $row->fetch(PDO::FETCH_ASSOC)) {
        $from = date('d/m/Y', strtotime($r['start_date']));
        $to   = date('d/m/Y', strtotime($r['end_date']));
        notifyEmployee($pdo, (int)$r['employee_id'], 'permission',
            "❌ Votre demande de permission du {$from} au {$to} a été REJETÉE par l'administration.");
    }
}
if (isset($_POST['approve_advance'])) {
    $aid = (int)$_POST['advance_id'];
    $pdo->prepare("UPDATE advances SET status='approuve' WHERE id=?")->execute([$aid]);
    logActivity($pdo, $user_id, 'APPROVE_ADVANCE', "Avance #{$aid} approuvée");
    $success_msg = "Avance approuvée !|Status : APPROUVÉ";
    $row = $pdo->prepare("SELECT employee_id, amount FROM advances WHERE id=?");
    $row->execute([$aid]);
    if ($r = $row->fetch(PDO::FETCH_ASSOC)) {
        $amt = number_format((float)$r['amount'], 0, ',', ' ');
        notifyEmployee($pdo, (int)$r['employee_id'], 'advance',
            "✅ Votre demande d'avance de {$amt} FCFA a été APPROUVÉE par l'administration.");
    }
}
if (isset($_POST['reject_advance'])) {
    $aid = (int)$_POST['advance_id'];
    $pdo->prepare("UPDATE advances SET status='rejete' WHERE id=?")->execute([$aid]);
    $success_msg = "Avance rejetée !|Status : REJETÉ";
    $row = $pdo->prepare("SELECT employee_id, amount FROM advances WHERE id=?");
    $row->execute([$aid]);
    if ($r = $row->fetch(PDO::FETCH_ASSOC)) {
        $amt = number_format((float)$r['amount'], 0, ',', ' ');
        notifyEmployee($pdo, (int)$r['employee_id'], 'advance',
            "❌ Votre demande d'avance de {$amt} FCFA a été REJETÉE par l'administration.");
    }
}

/* ═══════════════════════════════════════════
   PAIE
═══════════════════════════════════════════ */
if (isset($_POST['generate_payroll'])) {
    $month = $_POST['month'];
    try {
        $pdo->beginTransaction();
        $emps      = $pdo->query("SELECT * FROM employees WHERE status='actif'")->fetchAll(PDO::FETCH_ASSOC);
        $generated = 0;
        foreach ($emps as $emp) {
            $exists = $pdo->prepare("SELECT id FROM payroll WHERE employee_id=? AND month=?");
            $exists->execute([$emp['id'], $month]);
            if ($exists->fetch()) continue;

            if ($emp['salary_type'] === 'journalier') {
                $days = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? AND status IN ('present','retard')");
                $days->execute([$emp['id'], $month]);
                $base = $emp['salary_amount'] * (int)$days->fetchColumn();
            } else {
                $base = $emp['salary_amount'];
            }
            $ov  = $pdo->prepare("SELECT COALESCE(SUM(hours*rate_per_hour),0) FROM overtime WHERE employee_id=? AND DATE_FORMAT(work_date,'%Y-%m')=?");
            $ov->execute([$emp['id'], $month]);
            $overtime = (float)$ov->fetchColumn();

            $adv = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM advances WHERE employee_id=? AND status='approuve' AND DATE_FORMAT(advance_date,'%Y-%m')=?");
            $adv->execute([$emp['id'], $month]);
            $advances = (float)$adv->fetchColumn();

            $abs = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? AND status='absent'");
            $abs->execute([$emp['id'], $month]);
            $absences = (int)$abs->fetchColumn();
            $abs_ded  = $emp['salary_type'] === 'journalier' ? ($emp['salary_amount'] * $absences) : 0;

            $net = max(0, $base + $overtime - $advances - $abs_ded);
            $pdo->prepare("INSERT INTO payroll (employee_id,month,base_salary,overtime_amount,advances_deduction,absences_deduction,net_salary,status) VALUES (?,?,?,?,?,?,?,'impaye')")
                ->execute([$emp['id'], $month, $base, $overtime, $advances, $abs_ded, $net]);
            $generated++;
        }
        $pdo->commit();
        logActivity($pdo, $user_id, 'GENERATE_PAYROLL', "Paie générée : $generated employés — Mois : $month");
        $success_msg = "Paie générée !|$generated employés — Mois : $month";
    } catch(Exception $e) {
        $pdo->rollBack();
        $error_msg = "Erreur génération : " . $e->getMessage();
    }
}

if (isset($_POST['mark_paid'])) {
    $id = (int)$_POST['payroll_id'];
    $pdo->prepare("UPDATE payroll SET status='paye', payment_date=NOW(), paid_by=? WHERE id=?")->execute([$user_id, $id]);
    logActivity($pdo, $user_id, 'MARK_PAID', "Paie #$id marquée payée");
    $success_msg = "Paiement confirmé !|Status : PAYÉ";
}

/* ═══════════════════════════════════════════
   EXPORT EXCEL
═══════════════════════════════════════════ */
if (isset($_GET['export_payroll'])) {
    $month = $_GET['month'] ?? date('Y-m');
    $stmt  = $pdo->prepare("
        SELECT c.name as categorie, e.employee_code, e.full_name, p.title as poste,
               e.salary_type, pr.base_salary, pr.overtime_amount, pr.advances_deduction,
               pr.absences_deduction, pr.net_salary, pr.status, pr.payment_date
        FROM payroll pr
        JOIN employees e ON e.id=pr.employee_id
        JOIN categories c ON e.category_id=c.id
        JOIN positions  p ON e.position_id=p.id
        WHERE pr.month=? ORDER BY c.name, e.full_name
    ");
    $stmt->execute([$month]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ss    = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Paie ' . $month);
    $sheet->setCellValue('A1', 'ESPERANCE H2O — RAPPORT DE PAIE — ' . strtoupper($month));
    $sheet->mergeCells('A1:L1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(30);

    $headers = ['Catégorie','Code','Nom complet','Poste','Type','Salaire base','H.Sup','Avances','Absences','Net à payer','Statut','Date paiement'];
    $sheet->fromArray($headers, null, 'A3');
    $sheet->getStyle('A3:L3')->getFont()->setBold(true);
    $sheet->getStyle('A3:L3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1a3a2a');
    $sheet->getStyle('A3:L3')->getFont()->getColor()->setARGB('FF32be8f');
    $sheet->getStyle('A3:L3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $row = 4; $cat_total = 0; $grand_total = 0; $cur_cat = '';
    foreach ($data as $item) {
        if ($cur_cat !== '' && $cur_cat !== $item['categorie']) {
            $sheet->setCellValue("I$row", "TOTAL {$cur_cat}:");
            $sheet->setCellValue("J$row", number_format($cat_total, 0) . ' CFA');
            $sheet->getStyle("I$row:J$row")->getFont()->setBold(true);
            $row++; $cat_total = 0;
        }
        $cur_cat = $item['categorie'];
        $sheet->fromArray([
            $item['categorie'], $item['employee_code'], $item['full_name'], $item['poste'],
            ucfirst($item['salary_type']),
            number_format($item['base_salary'], 0) . ' CFA',
            number_format($item['overtime_amount'], 0) . ' CFA',
            number_format($item['advances_deduction'], 0) . ' CFA',
            number_format($item['absences_deduction'], 0) . ' CFA',
            number_format($item['net_salary'], 0) . ' CFA',
            strtoupper($item['status']),
            $item['payment_date'] ? date('d/m/Y H:i', strtotime($item['payment_date'])) : '—',
        ], null, "A$row");
        $sheet->getStyle("A$row:L$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $cat_total   += $item['net_salary'];
        $grand_total += $item['net_salary'];
        $row++;
    }
    if ($cur_cat !== '') {
        $sheet->setCellValue("I$row", "TOTAL {$cur_cat}:");
        $sheet->setCellValue("J$row", number_format($cat_total, 0) . ' CFA');
        $row++;
    }
    $row++;
    $sheet->setCellValue("I$row", "TOTAL GÉNÉRAL :");
    $sheet->setCellValue("J$row", number_format($grand_total, 0) . ' CFA');
    $sheet->getStyle("I$row:J$row")->getFont()->setBold(true)->setSize(13);

    foreach (range('A','L') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="paie_' . $month . '_' . date('Ymd') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

/* ═══════════════════════════════════════════
   DONNÉES
═══════════════════════════════════════════ */
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$positions  = $pdo->query("SELECT * FROM positions ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

$employees = $pdo->query("
    SELECT e.*, c.name as category_name, p.title as position_title,
        DATEDIFF(CURDATE(), e.start_date) as days_since_start,
        (SELECT COUNT(*) FROM attendance WHERE employee_id=e.id
            AND DATE_FORMAT(work_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')
            AND status IN ('present','retard')) as days_present,
        (SELECT COUNT(*) FROM attendance WHERE employee_id=e.id
            AND DATE_FORMAT(work_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')
            AND status='absent') as days_absent,
        (SELECT COUNT(*) FROM attendance WHERE employee_id=e.id
            AND DATE_FORMAT(work_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')
            AND status='retard') as days_late
    FROM employees e
    LEFT JOIN categories c ON e.category_id=c.id
    LEFT JOIN positions  p ON e.position_id=p.id
    ORDER BY e.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pending_permissions = $pdo->query("
    SELECT p.*, e.full_name, e.employee_code FROM permissions p
    JOIN employees e ON e.id=p.employee_id
    WHERE p.status='en_attente' ORDER BY p.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pending_advances = $pdo->query("
    SELECT a.*, e.full_name, e.employee_code FROM advances a
    JOIN employees e ON e.id=a.employee_id
    WHERE a.status='en_attente' ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$current_month = date('Y-m');
$st = $pdo->prepare("
    SELECT pr.*, e.full_name, e.employee_code, e.salary_type, c.name as category_name
    FROM payroll pr
    JOIN employees e ON e.id=pr.employee_id
    JOIN categories c ON e.category_id=c.id
    WHERE pr.month=? ORDER BY c.name, e.full_name
");
$st->execute([$current_month]);
$current_payroll = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE month=? AND status='impaye'"); $st->execute([$current_month]);
$unpaid_count = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE month=? AND status='paye'"); $st->execute([$current_month]);
$paid_count   = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM payroll WHERE month=?"); $st->execute([$current_month]);
$total_payroll = (float)$st->fetchColumn();

$payment_alert = ((int)date('d') >= 25 && $unpaid_count > 0);

$recent_logs = [];
try {
    $recent_logs = $pdo->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Parse messages
$success_parts = $success_msg ? explode('|', $success_msg) : [];
$error_parts   = $error_msg   ? explode('|', $error_msg)   : [];

// Stats employés
$total_emp        = count($employees);
$actifs           = count(array_filter($employees, fn($e) => $e['status'] === 'actif'));
$suspendus        = count(array_filter($employees, fn($e) => $e['status'] === 'suspendu'));
$total_present_all= array_sum(array_column($employees, 'days_present'));
$avg_perf         = $total_emp > 0 ? round(array_sum(array_column($employees, 'performance_score')) / $total_emp, 1) : 0;
$total_pending    = count($pending_permissions) + count($pending_advances);
$csrf             = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Système RH — ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════
   DARK NEON — ESPERANCE H2O — même charte admin_nasa
═══════════════════════════════════════════════════════ */
:root {
    --bg:     #04090e;
    --surf:   #081420;
    --card:   #0d1e2c;
    --card2:  #122030;
    --bord:   rgba(50,190,143,0.14);
    --bord2:  rgba(50,190,143,0.30);
    --neon:   #32be8f;
    --neon2:  #19ffa3;
    --red:    #ff3553;
    --orange: #ff9140;
    --blue:   #3d8cff;
    --gold:   #ffd060;
    --purple: #a855f7;
    --cyan:   #06b6d4;
    --pink:   #ec4899;
    --text:   #e0f2ea;
    --text2:  #b8d8cc;
    --muted:  #5a8070;
    --glow:   0 0 26px rgba(50,190,143,0.45);
    --glow-r: 0 0 26px rgba(255,53,83,0.45);
    --fh: 'Playfair Display', Georgia, serif;
    --fb: 'Inter', 'Segoe UI', system-ui, sans-serif;
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }

body {
    font-family: var(--fb);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

/* Grille BG */
body::before {
    content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:
        radial-gradient(ellipse 65% 42% at 4% 8%, rgba(50,190,143,0.07) 0%,transparent 62%),
        radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.06) 0%,transparent 62%),
        radial-gradient(ellipse 40% 30% at 50% 50%,rgba(168,85,247,0.03) 0%,transparent 70%);
}
body::after {
    content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:
        linear-gradient(rgba(50,190,143,0.018) 1px,transparent 1px),
        linear-gradient(90deg,rgba(50,190,143,0.018) 1px,transparent 1px);
    background-size:48px 48px;
}

.wrap { position:relative;z-index:1;max-width:1900px;margin:0 auto;padding:14px 16px 56px; }

/* ── Scrollbar ── */
::-webkit-scrollbar { width:7px;height:7px; }
::-webkit-scrollbar-track { background:var(--surf); }
::-webkit-scrollbar-thumb { background:rgba(50,190,143,0.4);border-radius:4px; }
::-webkit-scrollbar-thumb:hover { background:var(--neon); }

/* ── ANIMATIONS ── */
@keyframes fadeUp   { from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)} }
@keyframes breathe  { 0%,100%{box-shadow:0 0 16px rgba(168,85,247,0.4)}50%{box-shadow:0 0 42px rgba(168,85,247,0.9)} }
@keyframes pdot     { 0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.65)} }
@keyframes pulse-r  { 0%,100%{box-shadow:0 0 0 0 rgba(255,53,83,0.4)}50%{box-shadow:0 0 0 6px transparent} }
@keyframes scanBar  { 0%{left:-100%}100%{left:110%} }
@keyframes zoomIn   { from{opacity:0;transform:scale(0.88)}to{opacity:1;transform:scale(1)} }
@keyframes toast-in { from{opacity:0;transform:translateX(50px)}to{opacity:1;transform:translateX(0)} }
@keyframes toast-out{ from{opacity:1}to{opacity:0;transform:translateX(50px)} }

/* ══ TOPBAR ══ */
.topbar {
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
    background:rgba(8,20,32,0.96);border:1px solid var(--bord);border-radius:18px;
    padding:16px 24px;margin-bottom:12px;backdrop-filter:blur(28px);
    box-shadow:0 4px 32px rgba(0,0,0,0.4);position:relative;overflow:hidden;
    animation:fadeUp .4s ease;
}
.topbar::after {
    content:'';position:absolute;top:0;left:-100%;width:40%;height:2px;
    background:linear-gradient(90deg,transparent,var(--neon),transparent);
    animation:scanBar 3.5s linear infinite;
}
.brand { display:flex;align-items:center;gap:14px;flex-shrink:0; }
.brand-ico {
    width:46px;height:46px;background:linear-gradient(135deg,var(--neon),var(--cyan));
    border-radius:13px;display:flex;align-items:center;justify-content:center;
    font-size:20px;color:var(--bg);box-shadow:var(--glow);animation:breathe 3.5s ease-in-out infinite;
}
.brand-txt h1 { font-family:var(--fh);font-size:20px;font-weight:900;color:var(--text);line-height:1.2; }
.brand-txt p  { font-size:10px;font-weight:700;color:var(--neon);letter-spacing:2px;text-transform:uppercase; }

.clock-val  { font-family:var(--fh);font-size:26px;font-weight:900;color:var(--gold);letter-spacing:4px;text-shadow:0 0 22px rgba(255,208,96,0.55); }
.clock-sub  { font-size:10px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;margin-top:2px; }

.user-badge {
    display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:11px;
    background:rgba(168,85,247,0.1);border:1.5px solid rgba(168,85,247,0.25);
    font-family:var(--fh);font-size:12px;font-weight:900;color:var(--purple);
}
.user-role {
    font-size:9px;font-weight:800;padding:2px 8px;border-radius:8px;
    background:rgba(168,85,247,0.18);color:var(--purple);letter-spacing:1px;
}

/* ══ TAB NAV ══ */
.tab-nav {
    display:flex;align-items:center;flex-wrap:wrap;gap:6px;
    background:rgba(8,20,32,0.88);border:1px solid var(--bord);border-radius:14px;
    padding:10px 16px;margin-bottom:16px;backdrop-filter:blur(20px);
}
.tab-lnk {
    display:flex;align-items:center;gap:7px;padding:9px 16px;border-radius:11px;
    border:1.5px solid var(--bord);background:rgba(50,190,143,0.04);color:var(--text2);
    font-family:var(--fh);font-size:12px;font-weight:700;text-decoration:none;
    cursor:pointer;transition:all 0.28s;white-space:nowrap;position:relative;
}
.tab-lnk:hover  { background:rgba(50,190,143,0.1);color:var(--text);border-color:var(--bord2);transform:translateY(-2px); }
.tab-lnk.active { background:linear-gradient(135deg,rgba(50,190,143,0.18),rgba(6,182,212,0.12));
    color:#fff;border-color:rgba(50,190,143,0.45);box-shadow:0 0 16px rgba(50,190,143,0.18); }
.tab-badge {
    display:inline-flex;align-items:center;justify-content:center;
    min-width:18px;height:18px;border-radius:9px;font-size:9px;font-weight:900;
    background:var(--red);color:#fff;padding:0 4px;animation:pulse-r 1.5s infinite;
}

/* ══ ALERTS ══ */
.alert {
    display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-radius:13px;
    margin-bottom:14px;border:1.5px solid;animation:fadeUp .3s ease;
}
.alert-success { background:rgba(50,190,143,0.08);border-color:rgba(50,190,143,0.28);color:var(--neon); }
.alert-error   { background:rgba(255,53,83,0.08);border-color:rgba(255,53,83,0.28);color:var(--red); }
.alert-warning { background:rgba(255,208,96,0.08);border-color:rgba(255,208,96,0.28);color:var(--gold); }
.alert-ico  { font-size:20px;flex-shrink:0;margin-top:1px; }
.alert-main { font-family:var(--fh);font-size:14px;font-weight:900; }
.alert-sub  { font-size:12px;opacity:.8;margin-top:4px; }
.alert-pill {
    display:inline-block;margin-top:6px;margin-right:6px;padding:3px 10px;border-radius:8px;
    background:rgba(255,255,255,0.08);font-family:var(--fh);font-size:11px;font-weight:900;
}

/* ══ KPI STRIP ══ */
.kpi-strip { display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:16px; }
.ks {
    background:var(--card);border:1px solid var(--bord);border-radius:15px;padding:18px 16px;
    display:flex;align-items:center;gap:12px;transition:all 0.3s;animation:fadeUp .4s ease backwards;
}
.ks:hover { transform:translateY(-4px);box-shadow:0 14px 32px rgba(0,0,0,0.42);border-color:var(--bord2); }
.ks-ico { width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0; }
.ks-val { font-family:var(--fh);font-size:26px;font-weight:900;line-height:1.1; }
.ks-lbl { font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.9px;margin-top:2px; }

/* ══ CARD ══ */
.card {
    background:var(--card);border:1px solid var(--bord);border-radius:16px;
    overflow:hidden;margin-bottom:16px;animation:fadeUp .4s ease;
}
.card-head {
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.05);
    background:rgba(0,0,0,0.2);
}
.card-title {
    font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);
    display:flex;align-items:center;gap:10px;
}
.card-title i { color:var(--neon); }
.card-body { padding:18px 20px; }

/* ══ FILTER BAR ══ */
.filter-bar {
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    padding:12px 20px;border-bottom:1px solid rgba(255,255,255,0.04);
    background:rgba(0,0,0,0.1);
}
.search-wrap {
    flex:1;min-width:220px;position:relative;
}
.search-wrap i { position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px; }
.search-wrap input {
    width:100%;padding:9px 12px 9px 36px;
    background:rgba(0,0,0,0.3);border:1.5px solid var(--bord);border-radius:10px;
    color:var(--text);font-family:var(--fb);font-size:13px;transition:all .28s;
}
.search-wrap input:focus { outline:none;border-color:var(--purple);box-shadow:0 0 12px rgba(168,85,247,0.18); }
.search-wrap input::placeholder { color:var(--muted); }

.fc { /* filter chip */
    padding:7px 14px;border-radius:9px;border:1.5px solid var(--bord);
    background:rgba(50,190,143,0.04);color:var(--muted);font-family:var(--fh);
    font-size:11px;font-weight:900;cursor:pointer;transition:all .24s;white-space:nowrap;
}
.fc:hover  { background:rgba(50,190,143,0.1);color:var(--text2);border-color:var(--bord2); }
.fc.active { background:rgba(50,190,143,0.16);color:var(--neon);border-color:rgba(50,190,143,0.4); }

/* ══ TABLE ══ */
.tbl-wrap { overflow-x:auto;-webkit-overflow-scrolling:touch; }
table { width:100%;border-collapse:collapse;min-width:900px; }
table th {
    font-family:var(--fh);font-size:10px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1px;padding:12px 14px;
    border-bottom:1px solid rgba(255,255,255,0.06);background:rgba(0,0,0,0.18);
    text-align:left;white-space:nowrap;
}
table td {
    font-size:13px;color:var(--text2);padding:13px 14px;
    border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:middle;
}
tbody tr { transition:background .2s; }
tbody tr:hover td { background:rgba(50,190,143,0.03); }
tbody tr:last-child td { border-bottom:none; }

/* ══ BADGES ══ */
.bdg {
    font-family:var(--fb);font-size:10px;font-weight:800;padding:3px 10px;
    border-radius:14px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;
}
.bdg-n  { background:rgba(50,190,143,0.12);color:var(--neon); }
.bdg-r  { background:rgba(255,53,83,0.12);color:var(--red); }
.bdg-g  { background:rgba(255,208,96,0.12);color:var(--gold); }
.bdg-c  { background:rgba(6,182,212,0.12);color:var(--cyan); }
.bdg-p  { background:rgba(168,85,247,0.12);color:var(--purple); }
.bdg-o  { background:rgba(255,145,64,0.12);color:var(--orange); }

/* ══ EMP specifics ══ */
.emp-code { font-family:var(--fh);font-size:13px;font-weight:900;color:var(--cyan);letter-spacing:2px; }
.emp-name { font-family:var(--fh);font-size:15px;font-weight:900;color:var(--text); }
.emp-sub  { font-size:11px;color:var(--muted);margin-top:2px; }
.sal-amt  { font-family:var(--fh);font-size:15px;font-weight:900;color:var(--neon); }
.sal-type { font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px; }

/* Performance bar */
.perf-wrap { display:flex;flex-direction:column;gap:4px; }
.perf-bar  { height:5px;background:rgba(255,255,255,0.07);border-radius:3px;overflow:hidden;width:80px; }
.perf-fill { height:100%;border-radius:3px; }
.perf-score{ font-family:var(--fh);font-size:11px;font-weight:900; }

/* ══ BTN ══ */
.btn {
    display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;
    border:1.5px solid transparent;cursor:pointer;font-family:var(--fh);font-size:11px;
    font-weight:900;letter-spacing:.3px;transition:all .26s;text-decoration:none;
    white-space:nowrap;
}
.btn:active { transform:scale(0.97); }
.btn-n  { background:rgba(50,190,143,0.1);border-color:rgba(50,190,143,0.3);color:var(--neon); }
.btn-n:hover  { background:var(--neon);color:var(--bg);box-shadow:var(--glow); }
.btn-c  { background:rgba(6,182,212,0.1);border-color:rgba(6,182,212,0.3);color:var(--cyan); }
.btn-c:hover  { background:var(--cyan);color:var(--bg); }
.btn-p  { background:rgba(168,85,247,0.1);border-color:rgba(168,85,247,0.3);color:var(--purple); }
.btn-p:hover  { background:var(--purple);color:#fff; }
.btn-g  { background:rgba(255,208,96,0.1);border-color:rgba(255,208,96,0.3);color:var(--gold); }
.btn-g:hover  { background:var(--gold);color:var(--bg); }
.btn-r  { background:rgba(255,53,83,0.1);border-color:rgba(255,53,83,0.3);color:var(--red); }
.btn-r:hover  { background:var(--red);color:#fff;box-shadow:var(--glow-r); }
.btn-b  { background:rgba(61,140,255,0.1);border-color:rgba(61,140,255,0.3);color:var(--blue); }
.btn-b:hover  { background:var(--blue);color:#fff; }
.btn-sm { padding:6px 12px;font-size:10px;border-radius:8px; }
.btn-full { width:100%;justify-content:center;padding:12px; }
/* Solid pour actions primaires */
.btn-solid-n {
    background:linear-gradient(135deg,var(--neon),var(--neon2));color:var(--bg);
    border-color:var(--neon);font-weight:900;box-shadow:0 4px 18px rgba(50,190,143,0.35);
}
.btn-solid-n:hover { box-shadow:0 6px 28px rgba(50,190,143,0.55); }

/* ══ FORMS ══ */
.fg { display:flex;flex-direction:column;gap:6px; }
.fg label {
    font-family:var(--fh);font-size:10px;font-weight:900;color:var(--muted);
    text-transform:uppercase;letter-spacing:1px;
}
.fg input,.fg select,.fg textarea {
    padding:10px 14px;background:rgba(0,0,0,0.35);border:1.5px solid var(--bord);
    border-radius:10px;color:var(--text);font-family:var(--fb);font-size:13px;
    font-weight:600;transition:all .28s;
}
.fg input:focus,.fg select:focus,.fg textarea:focus {
    outline:none;border-color:var(--neon);box-shadow:0 0 14px rgba(50,190,143,0.18);
}
.fg input::placeholder,.fg textarea::placeholder { color:var(--muted); }
.fg select option { background:#0d1e2c;color:var(--text); }
.fg textarea { resize:vertical;min-height:72px; }
input[type="checkbox"] { width:auto;cursor:pointer; }

.form-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:16px; }
.span2 { grid-column:1/-1; }

/* Password box */
.pass-box {
    background:rgba(0,0,0,0.2);border:1.5px solid rgba(168,85,247,0.2);border-radius:12px;
    padding:16px;margin-bottom:16px;
}
.pass-box-title { font-family:var(--fh);font-size:12px;font-weight:900;color:var(--purple);margin-bottom:12px; }
.radio-row { display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;
    font-family:var(--fh);font-size:12px;font-weight:700;color:var(--text2); }
.radio-row input[type="radio"] { width:auto;accent-color:var(--purple); }

/* Progress */
.prog-wrap { padding:14px 20px;border-bottom:1px solid rgba(255,255,255,0.04); }
.prog-lbl { display:flex;justify-content:space-between;font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);margin-bottom:8px; }
.prog-track { height:8px;background:rgba(255,255,255,0.07);border-radius:4px;overflow:hidden; }
.prog-fill  { height:100%;background:linear-gradient(90deg,var(--neon),var(--cyan));border-radius:4px;position:relative;transition:width .8s cubic-bezier(.23,1,.32,1); }
.prog-pct   { position:absolute;right:6px;top:50%;transform:translateY(-50%);font-family:var(--fh);font-size:9px;font-weight:900;color:var(--bg); }

/* ══ LOG ITEMS ══ */
.log-item {
    display:flex;align-items:flex-start;gap:12px;padding:13px 20px;
    border-bottom:1px solid rgba(255,255,255,0.04);transition:background .2s;
}
.log-item:hover { background:rgba(50,190,143,0.03); }
.log-item:last-child { border-bottom:none; }
.log-dot { width:9px;height:9px;border-radius:50%;background:var(--neon);box-shadow:0 0 8px var(--neon);flex-shrink:0;margin-top:5px;animation:pdot 2s infinite; }
.log-action { font-family:var(--fh);font-size:12px;font-weight:900;color:var(--neon); }
.log-detail { font-size:12px;color:var(--text2);margin-top:2px; }
.log-meta   { font-size:10px;color:var(--muted);margin-top:2px; }
.log-time   { font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);white-space:nowrap;flex-shrink:0; }

/* ══ EMPTY STATE ══ */
.empty-st { text-align:center;padding:48px 20px;color:var(--muted); }
.empty-st i { font-size:52px;display:block;margin-bottom:14px;opacity:.1; }
.empty-st h3 { font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text2);margin-bottom:6px; }
.empty-st p  { font-size:12px; }

/* ══ MODAL ══ */
.modal {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,0.88);z-index:1000;
    align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(10px);
}
.modal.show { display:flex; }
.modal-box {
    background:var(--card);border:1px solid var(--bord);border-radius:18px;
    width:100%;max-width:720px;max-height:92vh;overflow-y:auto;
    animation:zoomIn .28s cubic-bezier(.23,1,.32,1);
    box-shadow:0 24px 60px rgba(0,0,0,0.7);
}
.modal-box-sm { max-width:440px; }
.modal-head {
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.06);
    background:rgba(0,0,0,0.2);position:sticky;top:0;z-index:2;
}
.modal-title { font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:10px; }
.modal-title i { color:var(--neon); }
.modal-close {
    width:34px;height:34px;border-radius:50%;background:rgba(255,53,83,0.1);border:1.5px solid rgba(255,53,83,0.25);
    color:var(--red);display:flex;align-items:center;justify-content:center;cursor:pointer;
    font-size:18px;transition:all .25s;
}
.modal-close:hover { background:var(--red);color:#fff;transform:rotate(90deg); }
.modal-body { padding:22px; }
.modal-foot { display:flex;gap:10px;justify-content:flex-end;padding:16px 22px;border-top:1px solid rgba(255,255,255,0.06); }

/* Danger zone */
.danger-zone {
    text-align:center;padding:28px 20px;background:rgba(255,53,83,0.05);
    border:1px solid rgba(255,53,83,0.18);border-radius:13px;margin-bottom:16px;
}
.danger-icon { font-size:52px;display:block;margin-bottom:12px;opacity:.7; }
.danger-name { font-family:var(--fh);font-size:18px;font-weight:900;color:var(--red);margin-bottom:6px; }
.danger-txt  { font-size:12px;color:var(--muted); }
.danger-warn {
    margin-top:12px;padding:10px 14px;border-radius:9px;background:rgba(255,53,83,0.08);
    border:1px solid rgba(255,53,83,0.2);color:var(--red);font-size:11px;font-weight:700;
}

/* Success zone (reset, perf) */
.success-zone {
    text-align:center;padding:20px;background:rgba(50,190,143,0.05);
    border:1px solid rgba(50,190,143,0.15);border-radius:12px;margin-bottom:16px;
}
.success-ico  { font-size:44px;display:block;margin-bottom:10px; }
.success-name { font-family:var(--fh);font-size:16px;font-weight:900;color:var(--neon); }

/* ══ TOAST ══ */
.toast-stack { position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;align-items:flex-end; }
.toast {
    background:var(--card2);border:1px solid rgba(50,190,143,0.25);border-radius:12px;
    padding:12px 17px;min-width:240px;display:flex;align-items:center;gap:11px;
    box-shadow:0 8px 28px rgba(0,0,0,0.55);animation:toast-in .4s cubic-bezier(.23,1,.32,1);
}
.toast.out { animation:toast-out .3s ease forwards; }
.toast-ico { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0; }
.toast-txt strong { font-family:var(--fh);font-size:12px;font-weight:900;display:block; }
.toast-txt span   { font-size:11px;color:var(--muted); }

/* ══ QUICK-ACTION CHIPS ══ */
.quick-chips { display:flex;gap:8px;flex-wrap:wrap; }

/* ══ RESPONSIVE ══ */
@media(max-width:900px) {
    .form-grid { grid-template-columns:1fr; }
    .kpi-strip { grid-template-columns:repeat(2,1fr); }
}
@media(max-width:600px) {
    .topbar { padding:12px 14px; }
    .clock-val { font-size:20px; }
    .tab-lnk { padding:7px 11px;font-size:11px; }
    .kpi-strip { grid-template-columns:1fr 1fr; }
}
</style>
</head>
<body>

<div class="wrap">

<!-- ══════ TOPBAR ══════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico"><i class="fas fa-droplet"></i></div>
        <div class="brand-txt">
            <h1>ESPERANCE H2O</h1>
            <p>Gestion de stock &nbsp;·&nbsp; Resource Huimaine</p>
        </div>
    </div>

    <div style="text-align:center;flex-shrink:0">
        <div class="clock-val" id="clk">--:--:--</div>
        <div class="clock-sub" id="clkd">Chargement…</div>
    </div>

    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
        <div class="user-badge">
            <i class="fas fa-user-shield"></i>
            <?= htmlspecialchars($user_name) ?>
            <span class="user-role">ADMIN</span>
        </div>
        <a href="<?= project_url('dashboard/index.php') ?>" class="btn btn-b btn-sm"><i class="fas fa-arrow-left"></i> Dashboard</a>

    </div>
</div>

<!-- ══════ ALERTS ══════ -->
<?php if ($payment_alert): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle alert-ico"></i>
    <div>
        <div class="alert-main">🚨 Alerte paiement imminent !</div>
        <div class="alert-sub"><?= $unpaid_count ?> employé(s) non payé(s) ce mois — date limite approche !</div>
    </div>
</div>
<?php endif; ?>

<?php if ($success_parts): ?>
<div class="alert alert-success" id="flash-ok">
    <i class="fas fa-check-circle alert-ico"></i>
    <div>
        <div class="alert-main"><?= htmlspecialchars($success_parts[0]) ?></div>
        <?php foreach(array_slice($success_parts, 1) as $part): ?>
        <span class="alert-pill"><?= htmlspecialchars($part) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($error_parts): ?>
<div class="alert alert-error" id="flash-err">
    <i class="fas fa-times-circle alert-ico"></i>
    <div>
        <div class="alert-main"><?= htmlspecialchars($error_parts[0]) ?></div>
    </div>
</div>
<?php endif; ?>

<!-- ══════ TAB NAV ══════ -->
<div class="tab-nav">
    <a href="?view=employees" class="tab-lnk <?= $view==='employees'?'active':'' ?>">
        <i class="fas fa-users"></i> Employés
        <span style="font-family:var(--fh);font-size:10px;font-weight:900;color:var(--muted)"><?= $total_emp ?></span>
    </a>
    <a href="?view=requests" class="tab-lnk <?= $view==='requests'?'active':'' ?>">
        <i class="fas fa-inbox"></i> Demandes
        <?php if($total_pending > 0): ?><span class="tab-badge"><?= $total_pending ?></span><?php endif; ?>
    </a>
    <a href="?view=payroll" class="tab-lnk <?= $view==='payroll'?'active':'' ?>">
        <i class="fas fa-coins"></i> Paie
        <?php if($unpaid_count > 0): ?><span class="tab-badge"><?= $unpaid_count ?></span><?php endif; ?>
    </a>
    <a href="?view=logs" class="tab-lnk <?= $view==='logs'?'active':'' ?>">
        <i class="fas fa-terminal"></i> Logs
    </a>
    <div style="margin-left:auto;display:flex;gap:6px">
        <a href="<?= project_url('dashboard/admin_nasa.php') ?>"              class="tab-lnk"><i class="fas fa-rocket"></i> Admin</a>
        <a href="<?= project_url('admin/admin_notifications.php') ?>"     class="tab-lnk"><i class="fas fa-bell"></i> Notifs</a>
        <a href="<?= project_url('documents/documents_erp_pro.php') ?>"       class="tab-lnk"><i class="fas fa-archive"></i> Docs</a>
        <a href="<?= project_url('finance/cashier_payment_pro.php') ?>"     class="tab-lnk"><i class="fas fa-cash-register"></i> Gisher</a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     VIEW : EMPLOYÉS
══════════════════════════════════════════════════════ -->
<?php if ($view === 'employees'): ?>

<!-- KPI -->
<div class="kpi-strip">
    <div class="ks" style="animation-delay:.04s">
        <div class="ks-ico" style="background:rgba(50,190,143,0.12);color:var(--neon)"><i class="fas fa-users"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $total_emp ?></div><div class="ks-lbl">Total employés</div></div>
    </div>
    <div class="ks" style="animation-delay:.07s">
        <div class="ks-ico" style="background:rgba(6,182,212,0.12);color:var(--cyan)"><i class="fas fa-user-check"></i></div>
        <div><div class="ks-val" style="color:var(--cyan)"><?= $actifs ?></div><div class="ks-lbl">Actifs</div></div>
    </div>
    <div class="ks" style="animation-delay:.10s">
        <div class="ks-ico" style="background:rgba(255,53,83,0.12);color:var(--red)"><i class="fas fa-user-slash"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $suspendus ?></div><div class="ks-lbl">Suspendus</div></div>
    </div>
    <div class="ks" style="animation-delay:.13s">
        <div class="ks-ico" style="background:rgba(168,85,247,0.12);color:var(--purple)"><i class="fas fa-calendar-check"></i></div>
        <div><div class="ks-val" style="color:var(--purple)"><?= $total_present_all ?></div><div class="ks-lbl">Présences/mois</div></div>
    </div>
    <div class="ks" style="animation-delay:.16s">
        <div class="ks-ico" style="background:rgba(255,208,96,0.12);color:var(--gold)"><i class="fas fa-star"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= $avg_perf ?></div><div class="ks-lbl">Perf. moy.</div></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-database"></i> Liste des Employés</div>
        <button class="btn btn-solid-n" onclick="openModal('createModal')">
            <i class="fas fa-plus"></i> Nouvel employé
        </button>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
        <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Rechercher par nom, code, catégorie…" oninput="filterTable()">
        </div>
        <button class="fc active" onclick="setFilter('all',this)">Tous</button>
        <button class="fc" onclick="setFilter('actif',this)">Actifs</button>
        <button class="fc" onclick="setFilter('suspendu',this)">Suspendus</button>
        <button class="fc" onclick="setFilter('journalier',this)">Journaliers</button>
        <button class="fc" onclick="setFilter('mensuel',this)">Mensuels</button>
    </div>

    <div class="tbl-wrap">
    <table id="empTable">
        <thead>
            <tr>
                <th>Code</th><th>Employé</th><th>Catégorie</th><th>Poste</th>
                <th>Contrat</th><th>Salaire</th><th>Perf.</th><th>Début</th>
                <th>✅ Prés.</th><th>❌ Abs.</th><th>⏰ Ret.</th>
                <th>Statut</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($employees as $i => $emp):
            $perf       = (float)($emp['performance_score'] ?? 5.0);
            $perf_pct   = ($perf / 10) * 100;
            $perf_color = $perf >= 7 ? 'var(--neon)' : ($perf >= 4 ? 'var(--gold)' : 'var(--red)');
            $contract   = $emp['contract_type'] ?? 'CDI';
        ?>
        <tr data-status="<?= $emp['status'] ?>" data-salary-type="<?= $emp['salary_type'] ?>"
            style="animation:fadeUp .35s ease <?= $i * 0.04 ?>s backwards">
            <td><span class="emp-code"><?= htmlspecialchars($emp['employee_code']) ?></span></td>
            <td>
                <div class="emp-name"><?= htmlspecialchars($emp['full_name']) ?></div>
                <?php if(!empty($emp['phone'])): ?>
                <div class="emp-sub"><i class="fas fa-phone" style="font-size:9px"></i> <?= htmlspecialchars($emp['phone']) ?></div>
                <?php endif; ?>
            </td>
            <td><span class="bdg bdg-p"><?= htmlspecialchars($emp['category_name']) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($emp['position_title']) ?></td>
            <td><span class="bdg bdg-c"><?= htmlspecialchars($contract) ?></span></td>
            <td>
                <div class="sal-amt"><?= number_format($emp['salary_amount'],0) ?> <small style="font-size:10px;color:var(--muted)">CFA</small></div>
                <div class="sal-type"><?= strtoupper($emp['salary_type']) ?></div>
            </td>
            <td>
                <div class="perf-wrap">
                    <div class="perf-bar">
                        <div class="perf-fill" style="width:<?= $perf_pct ?>%;background:<?= $perf_color ?>"></div>
                    </div>
                    <div class="perf-score" style="color:<?= $perf_color ?>"><?= $perf ?>/10</div>
                </div>
            </td>
            <td>
                <?php if($emp['start_date']): ?>
                <div style="font-size:12px;color:var(--text2)"><?= date('d/m/Y', strtotime($emp['start_date'])) ?></div>
                <?php if($emp['days_since_start'] !== null): ?>
                <div style="font-family:var(--fh);font-size:10px;color:var(--neon)"><?= $emp['days_since_start'] ?>j</div>
                <?php endif; ?>
                <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td><span class="bdg bdg-n"><?= $emp['days_present'] ?></span></td>
            <td><span class="bdg bdg-r"><?= $emp['days_absent'] ?></span></td>
            <td><span class="bdg bdg-g"><?= $emp['days_late'] ?></span></td>
            <td>
                <span class="bdg <?= $emp['status']==='actif'?'bdg-n':'bdg-r' ?>">
                    <?= $emp['status']==='actif'?'Actif':'Suspendu' ?>
                </span>
            </td>
            <td>
                <div style="display:flex;gap:5px;flex-wrap:nowrap">
                    <button class="btn btn-c btn-sm" title="Modifier"
                        onclick='openEditModal(<?= json_encode($emp, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-g btn-sm" title="Performance"
                        onclick="openPerfModal(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['full_name'],ENT_QUOTES) ?>', <?= $perf ?>)">
                        <i class="fas fa-star"></i>
                    </button>
                    <button class="btn btn-p btn-sm" title="Reset mot de passe"
                        onclick="openResetModal(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['full_name'],ENT_QUOTES) ?>')">
                        <i class="fas fa-key"></i>
                    </button>
                    <button class="btn btn-r btn-sm" title="Supprimer"
                        onclick="openDeleteModal(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['full_name'],ENT_QUOTES) ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     VIEW : DEMANDES
══════════════════════════════════════════════════════ -->
<?php elseif ($view === 'requests'): ?>

<div class="kpi-strip">
    <div class="ks" style="animation-delay:.04s">
        <div class="ks-ico" style="background:rgba(255,208,96,0.12);color:var(--gold)"><i class="fas fa-clock"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= $total_pending ?></div><div class="ks-lbl">En attente</div></div>
    </div>
    <div class="ks" style="animation-delay:.07s">
        <div class="ks-ico" style="background:rgba(168,85,247,0.12);color:var(--purple)"><i class="fas fa-file-alt"></i></div>
        <div><div class="ks-val" style="color:var(--purple)"><?= count($pending_permissions) ?></div><div class="ks-lbl">Permissions</div></div>
    </div>
    <div class="ks" style="animation-delay:.10s">
        <div class="ks-ico" style="background:rgba(6,182,212,0.12);color:var(--cyan)"><i class="fas fa-hand-holding-usd"></i></div>
        <div><div class="ks-val" style="color:var(--cyan)"><?= count($pending_advances) ?></div><div class="ks-lbl">Avances</div></div>
    </div>
</div>

<!-- Permissions -->
<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-file-alt"></i> Demandes de Permission</div>
    </div>
    <?php if(count($pending_permissions) > 0): ?>
    <div class="tbl-wrap">
    <table>
        <thead><tr><th>Employé</th><th>Code</th><th>Période</th><th>Durée</th><th>Motif</th><th>Demandé le</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($pending_permissions as $perm):
            $days = (strtotime($perm['end_date']) - strtotime($perm['start_date'])) / 86400 + 1;
        ?>
        <tr>
            <td><div class="emp-name"><?= htmlspecialchars($perm['full_name']) ?></div></td>
            <td><span class="emp-code"><?= htmlspecialchars($perm['employee_code']) ?></span></td>
            <td style="color:var(--cyan);font-size:12px">
                <?= date('d/m/Y', strtotime($perm['start_date'])) ?>
                <i class="fas fa-arrow-right" style="color:var(--muted);margin:0 5px;font-size:9px"></i>
                <?= date('d/m/Y', strtotime($perm['end_date'])) ?>
            </td>
            <td><span class="bdg bdg-g"><?= $days ?>j</span></td>
            <td style="font-size:12px;color:var(--text2);max-width:200px"><?= htmlspecialchars($perm['reason']) ?></td>
            <td style="font-size:11px;color:var(--muted)"><?= date('d/m H:i', strtotime($perm['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:inline;display:flex;gap:6px">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="permission_id" value="<?= $perm['id'] ?>">
                    <button type="submit" name="approve_permission" class="btn btn-n btn-sm"><i class="fas fa-check"></i> Accepter</button>
                    <button type="submit" name="reject_permission"  class="btn btn-r btn-sm"><i class="fas fa-times"></i> Rejeter</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="empty-st"><i class="fas fa-inbox"></i><h3>Aucune demande de permission</h3><p>Toutes les demandes ont été traitées</p></div>
    <?php endif; ?>
</div>

<!-- Avances -->
<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-hand-holding-usd"></i> Demandes d'Avance</div>
    </div>
    <?php if(count($pending_advances) > 0): ?>
    <div class="tbl-wrap">
    <table>
        <thead><tr><th>Employé</th><th>Code</th><th>Montant</th><th>Date souhaitée</th><th>Motif</th><th>Demandé le</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($pending_advances as $adv): ?>
        <tr>
            <td><div class="emp-name"><?= htmlspecialchars($adv['full_name']) ?></div></td>
            <td><span class="emp-code"><?= htmlspecialchars($adv['employee_code']) ?></span></td>
            <td><div class="sal-amt"><?= number_format($adv['amount'],0) ?> <small>CFA</small></div></td>
            <td style="color:var(--cyan);font-size:12px"><?= date('d/m/Y', strtotime($adv['advance_date'])) ?></td>
            <td style="font-size:12px;color:var(--text2)"><?= htmlspecialchars($adv['reason']) ?></td>
            <td style="font-size:11px;color:var(--muted)"><?= date('d/m H:i', strtotime($adv['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:flex;gap:6px">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="advance_id" value="<?= $adv['id'] ?>">
                    <button type="submit" name="approve_advance" class="btn btn-n btn-sm"><i class="fas fa-check"></i> Approuver</button>
                    <button type="submit" name="reject_advance"  class="btn btn-r btn-sm"><i class="fas fa-times"></i> Rejeter</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="empty-st"><i class="fas fa-coins"></i><h3>Aucune demande d'avance</h3><p>Toutes les avances ont été traitées</p></div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     VIEW : PAIE
══════════════════════════════════════════════════════ -->
<?php elseif ($view === 'payroll'): ?>

<div class="kpi-strip">
    <?php $pct_pay = ($paid_count + $unpaid_count) > 0 ? round(($paid_count/($paid_count+$unpaid_count))*100) : 0; ?>
    <div class="ks" style="animation-delay:.04s">
        <div class="ks-ico" style="background:rgba(50,190,143,0.12);color:var(--neon)"><i class="fas fa-coins"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= number_format($total_payroll,0) ?></div><div class="ks-lbl">Total paie CFA</div></div>
    </div>
    <div class="ks" style="animation-delay:.07s">
        <div class="ks-ico" style="background:rgba(255,53,83,0.12);color:var(--red)"><i class="fas fa-exclamation-circle"></i></div>
        <div><div class="ks-val" style="color:var(--red)"><?= $unpaid_count ?></div><div class="ks-lbl">Non payés</div></div>
    </div>
    <div class="ks" style="animation-delay:.10s">
        <div class="ks-ico" style="background:rgba(6,182,212,0.12);color:var(--cyan)"><i class="fas fa-check-circle"></i></div>
        <div><div class="ks-val" style="color:var(--cyan)"><?= $paid_count ?></div><div class="ks-lbl">Payés</div></div>
    </div>
    <div class="ks" style="animation-delay:.13s">
        <div class="ks-ico" style="background:rgba(168,85,247,0.12);color:var(--purple)"><i class="fas fa-percent"></i></div>
        <div><div class="ks-val" style="color:var(--purple)"><?= $pct_pay ?>%</div><div class="ks-lbl">Taux paiement</div></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">
            <i class="fas fa-money-bill-wave"></i>
            Paie — <?= strtoupper(date('F Y', strtotime($current_month.'-01'))) ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="month" value="<?= $current_month ?>">
                <button type="submit" name="generate_payroll" class="btn btn-c"
                    onclick="return confirm('Générer les paies du mois <?= $current_month ?> ?')">
                    <i class="fas fa-cog"></i> Générer paies
                </button>
            </form>
            <a href="?export_payroll=1&month=<?= $current_month ?>" class="btn btn-n">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
        </div>
    </div>

    <?php if(count($current_payroll) > 0): ?>

    <!-- Progress bar -->
    <div class="prog-wrap">
        <div class="prog-lbl">
            <span>Progression paiements</span>
            <span><?= $paid_count ?> / <?= $paid_count+$unpaid_count ?> employés</span>
        </div>
        <div class="prog-track">
            <div class="prog-fill" style="width:<?= $pct_pay ?>%">
                <?php if($pct_pay > 12): ?><span class="prog-pct"><?= $pct_pay ?>%</span><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tbl-wrap">
    <table>
        <thead>
            <tr><th>Employé</th><th>Catégorie</th><th>Type</th><th>Base</th>
                <th>H.Sup</th><th>Avances</th><th>Absences</th><th>Net à payer</th><th>Statut</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach($current_payroll as $pr): ?>
        <tr style="<?= $pr['status']==='paye'?'background:rgba(50,190,143,0.02)':'' ?>">
            <td>
                <div class="emp-name"><?= htmlspecialchars($pr['full_name'] ?? '') ?></div>
                <div class="emp-sub"><?= htmlspecialchars($pr['employee_code'] ?? '') ?></div>
            </td>
            <td><span class="bdg bdg-p"><?= htmlspecialchars($pr['category_name'] ?? '') ?></span></td>
            <td><span class="bdg <?= ($pr['salary_type']??'')==='journalier'?'bdg-c':'bdg-p' ?>">
                <?= ($pr['salary_type']??'')==='journalier'?'Journalier':'Mensuel' ?>
            </span></td>
            <td><span class="sal-amt"><?= number_format((float)($pr['base_salary']??0),0) ?></span> <small style="color:var(--muted)">CFA</small></td>
            <td style="color:var(--neon);font-size:13px">+<?= number_format((float)($pr['overtime_amount']??0),0) ?></td>
            <td style="color:var(--red);font-size:13px">-<?= number_format((float)($pr['advances_deduction']??0),0) ?></td>
            <td style="color:var(--red);font-size:13px">-<?= number_format((float)($pr['absences_deduction']??0),0) ?></td>
            <td>
                <div class="sal-amt" style="font-size:17px"><?= number_format((float)($pr['net_salary']??0),0) ?></div>
                <div style="font-size:10px;color:var(--muted)">CFA</div>
            </td>
            <td>
                <span class="bdg <?= $pr['status']==='paye'?'bdg-n':'bdg-r' ?>">
                    <?= $pr['status']==='paye'?'✅ Payé':'❌ Impayé' ?>
                </span>
                <?php if($pr['status']==='paye' && !empty($pr['payment_date'])): ?>
                <div style="font-size:9px;color:var(--neon);margin-top:3px"><?= date('d/m/Y H:i',strtotime($pr['payment_date'])) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if($pr['status']==='impaye'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="payroll_id" value="<?= $pr['id'] ?>">
                    <button type="submit" name="mark_paid" class="btn btn-solid-n btn-sm"
                        onclick="return confirm('Confirmer le paiement de <?= number_format((float)$pr['net_salary'],0) ?> CFA ?')">
                        <i class="fas fa-check-double"></i> Marquer payé
                    </button>
                </form>
                <?php else: ?>
                <i class="fas fa-check-circle" style="font-size:20px;color:var(--neon)"></i>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php else: ?>
    <div class="empty-st">
        <i class="fas fa-coins"></i>
        <h3>Aucune paie générée</h3>
        <p>Cliquez sur "Générer paies" pour créer les paies du mois</p>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     VIEW : LOGS
══════════════════════════════════════════════════════ -->
<?php elseif ($view === 'logs'): ?>

<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-terminal"></i> Journal d'activité</div>
        <span style="font-family:var(--fh);font-size:10px;color:var(--neon)">Live monitoring</span>
    </div>
    <?php if(count($recent_logs) > 0): ?>
    <?php foreach($recent_logs as $log): ?>
    <div class="log-item">
        <div class="log-dot"></div>
        <div style="flex:1">
            <div class="log-action"><?= htmlspecialchars($log['action']) ?></div>
            <div class="log-detail"><?= htmlspecialchars($log['details']) ?></div>
            <div class="log-meta"><i class="fas fa-map-marker-alt" style="font-size:8px"></i> <?= htmlspecialchars($log['ip_address']) ?></div>
        </div>
        <div class="log-time"><?= date('d/m H:i', strtotime($log['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-st"><i class="fas fa-terminal"></i><h3>Aucun log</h3><p>Les actions apparaîtront ici</p></div>
    <?php endif; ?>
</div>

<?php endif; ?>
</div><!-- /wrap -->

<!-- ══════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════ -->

<!-- CRÉER EMPLOYÉ -->
<div class="modal" id="createModal">
<div class="modal-box">
    <div class="modal-head">
        <div class="modal-title"><i class="fas fa-user-plus"></i> Nouvel employé</div>
        <div class="modal-close" onclick="closeModal('createModal')">×</div>
    </div>
    <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <div class="modal-body">
        <div class="form-grid">
            <div class="fg">
                <label><i class="fas fa-id-badge"></i> Code employé *</label>
                <input type="text" name="employee_code" placeholder="EMP001" required pattern="[A-Z0-9]+" title="Majuscules et chiffres">
            </div>
            <div class="fg">
                <label><i class="fas fa-user"></i> Nom complet *</label>
                <input type="text" name="full_name" placeholder="Jean KOUA" required>
            </div>
            <div class="fg">
                <label><i class="fas fa-layer-group"></i> Catégorie *</label>
                <select name="category_id" required>
                    <option value="">-- Sélectionner --</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fas fa-briefcase"></i> Poste *</label>
                <select name="position_id" required>
                    <option value="">-- Sélectionner --</option>
                    <?php foreach($positions as $pos): ?>
                    <option value="<?= $pos['id'] ?>"><?= htmlspecialchars($pos['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fas fa-file-contract"></i> Type contrat</label>
                <select name="contract_type">
                    <option value="CDI">CDI</option>
                    <option value="CDD">CDD</option>
                    <option value="Stagiaire">Stagiaire</option>
                    <option value="Freelance">Freelance</option>
                    <option value="Temps partiel">Temps partiel</option>
                </select>
            </div>
            <div class="fg">
                <label><i class="fas fa-calendar-alt"></i> Type salaire *</label>
                <select name="salary_type" required>
                    <option value="journalier">📅 Journalier</option>
                    <option value="mensuel">📆 Mensuel</option>
                </select>
            </div>
            <div class="fg">
                <label><i class="fas fa-coins"></i> Montant salaire (CFA) *</label>
                <input type="number" name="salary_amount" step="100" placeholder="75000" required min="0">
            </div>
            <div class="fg">
                <label><i class="fas fa-phone"></i> Téléphone</label>
                <input type="tel" name="phone" placeholder="+225 XX XX XX XX XX">
            </div>
            <div class="fg">
                <label><i class="fas fa-calendar-check"></i> Date d'embauche *</label>
                <input type="date" name="hire_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="fg">
                <label><i class="fas fa-play-circle"></i> Date de début *</label>
                <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="fg">
                <label><i class="fas fa-ambulance"></i> Contact urgence</label>
                <input type="text" name="emergency_contact" placeholder="+225 XX — Prénom NOM">
            </div>
            <div class="fg">
                <label style="color:var(--muted)">Adresse</label>
                <input type="text" name="address" placeholder="Adresse complète">
            </div>
            <div class="fg span2">
                <label><i class="fas fa-sticky-note"></i> Notes privées</label>
                <textarea name="notes" placeholder="Notes internes…"></textarea>
            </div>
        </div>

        <!-- Mot de passe -->
        <div class="pass-box">
            <div class="pass-box-title"><i class="fas fa-lock"></i> Configuration mot de passe</div>
            <label class="radio-row">
                <input type="radio" name="password_option" value="auto" checked
                    onclick="document.getElementById('customPField').style.display='none';document.getElementById('autoFlag').value='1'">
                🎲 Générer automatiquement (recommandé)
            </label>
            <label class="radio-row">
                <input type="radio" name="password_option" value="custom"
                    onclick="document.getElementById('customPField').style.display='block';document.getElementById('autoFlag').value='0'">
                ✏️ Définir manuellement
            </label>
            <div id="customPField" style="display:none;margin-top:10px">
                <input type="text" name="custom_password" placeholder="Mot de passe (min. 6 caractères)" minlength="6">
            </div>
            <input type="hidden" name="auto_password" id="autoFlag" value="1">
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn btn-r" onclick="closeModal('createModal')"><i class="fas fa-times"></i> Annuler</button>
        <button type="submit" name="create_employee" class="btn btn-solid-n"><i class="fas fa-save"></i> Créer l'employé</button>
    </div>
    </form>
</div>
</div>

<!-- MODIFIER EMPLOYÉ -->
<div class="modal" id="editModal">
<div class="modal-box">
    <div class="modal-head">
        <div class="modal-title"><i class="fas fa-edit"></i> Modifier employé</div>
        <div class="modal-close" onclick="closeModal('editModal')">×</div>
    </div>
    <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="employee_id" id="edit_id">
    <div class="modal-body">
        <div class="form-grid">
            <div class="fg">
                <label>Code</label>
                <input type="text" id="edit_code" disabled style="opacity:.35;cursor:not-allowed">
            </div>
            <div class="fg">
                <label>Nom complet *</label>
                <input type="text" name="full_name" id="edit_name" required>
            </div>
            <div class="fg">
                <label>Catégorie *</label>
                <select name="category_id" id="edit_category" required>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Poste *</label>
                <select name="position_id" id="edit_position" required>
                    <?php foreach($positions as $pos): ?>
                    <option value="<?= $pos['id'] ?>"><?= htmlspecialchars($pos['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Type contrat</label>
                <select name="contract_type" id="edit_contract">
                    <option value="CDI">CDI</option><option value="CDD">CDD</option>
                    <option value="Stagiaire">Stagiaire</option><option value="Freelance">Freelance</option>
                    <option value="Temps partiel">Temps partiel</option>
                </select>
            </div>
            <div class="fg">
                <label>Type salaire *</label>
                <select name="salary_type" id="edit_salary_type" required>
                    <option value="journalier">Journalier</option>
                    <option value="mensuel">Mensuel</option>
                </select>
            </div>
            <div class="fg">
                <label>Montant (CFA) *</label>
                <input type="number" name="salary_amount" id="edit_salary_amount" step="100" required min="0">
            </div>
            <div class="fg">
                <label>Téléphone</label>
                <input type="tel" name="phone" id="edit_phone">
            </div>
            <div class="fg">
                <label>Date d'embauche *</label>
                <input type="date" name="hire_date" id="edit_hire_date" required>
            </div>
            <div class="fg">
                <label>Date de début</label>
                <input type="date" name="start_date" id="edit_start_date">
            </div>
            <div class="fg">
                <label>Statut *</label>
                <select name="status" id="edit_status" required>
                    <option value="actif">✅ Actif</option>
                    <option value="suspendu">❌ Suspendu</option>
                </select>
            </div>
            <div class="fg">
                <label>Contact urgence</label>
                <input type="text" name="emergency_contact" id="edit_emergency">
            </div>
            <div class="fg span2">
                <label>Adresse</label>
                <textarea name="address" id="edit_address"></textarea>
            </div>
            <div class="fg span2">
                <label>Notes</label>
                <textarea name="notes" id="edit_notes"></textarea>
            </div>
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn btn-r" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Annuler</button>
        <button type="submit" name="update_employee" class="btn btn-solid-n"><i class="fas fa-save"></i> Enregistrer</button>
    </div>
    </form>
</div>
</div>

<!-- PERFORMANCE -->
<div class="modal" id="perfModal">
<div class="modal-box modal-box-sm">
    <div class="modal-head">
        <div class="modal-title"><i class="fas fa-star"></i> Score de Performance</div>
        <div class="modal-close" onclick="closeModal('perfModal')">×</div>
    </div>
    <form method="POST">
    <input type="hidden" name="employee_id" id="perf_id">
    <div class="modal-body">
        <div class="success-zone">
            <span class="success-ico">⭐</span>
            <div class="success-name" id="perf_name"></div>
        </div>
        <div class="fg" style="margin-bottom:14px">
            <label>Score (0 à 10)</label>
            <input type="number" name="performance_score" id="perf_score" min="0" max="10" step="0.1" placeholder="7.5">
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php foreach([2,4,6,7,8,9,10] as $v): ?>
            <button type="button" class="fc" onclick="document.getElementById('perf_score').value='<?= $v ?>'"><?= $v ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn btn-r" onclick="closeModal('perfModal')"><i class="fas fa-times"></i> Annuler</button>
        <button type="submit" name="update_performance" class="btn btn-g"><i class="fas fa-save"></i> Sauvegarder</button>
    </div>
    </form>
</div>
</div>

<!-- RESET MDP -->
<div class="modal" id="resetModal">
<div class="modal-box modal-box-sm">
    <div class="modal-head">
        <div class="modal-title"><i class="fas fa-key"></i> Reset Mot de Passe</div>
        <div class="modal-close" onclick="closeModal('resetModal')">×</div>
    </div>
    <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="employee_id" id="reset_id">
    <div class="modal-body">
        <div class="success-zone" style="border-color:rgba(168,85,247,0.2);background:rgba(168,85,247,0.05)">
            <span class="success-ico">🔑</span>
            <div class="success-name" id="reset_name" style="color:var(--purple)"></div>
            <div style="font-size:12px;color:var(--muted);margin-top:6px">Un nouveau mot de passe sécurisé sera généré</div>
        </div>
        <div class="danger-warn">⚠️ Il sera affiché une seule fois — notez-le !</div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn btn-b" onclick="closeModal('resetModal')"><i class="fas fa-times"></i> Annuler</button>
        <button type="submit" name="reset_password" class="btn btn-p"><i class="fas fa-key"></i> Réinitialiser</button>
    </div>
    </form>
</div>
</div>

<!-- SUPPRIMER -->
<div class="modal" id="deleteModal">
<div class="modal-box modal-box-sm">
    <div class="modal-head">
        <div class="modal-title" style="color:var(--red)"><i class="fas fa-skull"></i> Suppression</div>
        <div class="modal-close" onclick="closeModal('deleteModal')">×</div>
    </div>
    <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="employee_id" id="delete_id">
    <div class="modal-body">
        <div class="danger-zone">
            <span class="danger-icon">💀</span>
            <div class="danger-name" id="delete_name"></div>
            <div class="danger-txt">Cette action est irréversible</div>
            <div class="danger-warn">⚠️ Toutes les données associées (présences, demandes, paies) seront supprimées</div>
        </div>
    </div>
    <div class="modal-foot">
        <button type="button" class="btn btn-n" onclick="closeModal('deleteModal')"><i class="fas fa-arrow-left"></i> Annuler</button>
        <button type="submit" name="delete_employee" class="btn btn-r"><i class="fas fa-trash"></i> Confirmer suppression</button>
    </div>
    </form>
</div>
</div>

<!-- TOAST STACK -->
<div class="toast-stack" id="toast-stack"></div>

<script>
/* ── HORLOGE ── */
function tick() {
    const n = new Date();
    const e = document.getElementById('clk');
    const d = document.getElementById('clkd');
    if (e) e.textContent = n.toLocaleTimeString('fr-FR', {timeZone:'Africa/Abidjan',hour:'2-digit',minute:'2-digit',second:'2-digit'});
    if (d) d.textContent = n.toLocaleDateString('fr-FR', {timeZone:'Africa/Abidjan',weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
tick(); setInterval(tick, 1000);

/* ── TOAST ── */
function toast(msg, type='info', sub='') {
    const c  = {success:'var(--neon)',error:'var(--red)',info:'var(--cyan)',warn:'var(--gold)'};
    const ic = {success:'fa-check-circle',error:'fa-times-circle',info:'fa-info-circle',warn:'fa-exclamation-triangle'};
    const stack = document.getElementById('toast-stack');
    if (!stack) return;
    const t = document.createElement('div');
    t.className = 'toast';
    t.innerHTML = `<div class="toast-ico" style="background:${c[type]}22;color:${c[type]}"><i class="fas ${ic[type]}"></i></div>
        <div class="toast-txt"><strong style="color:${c[type]}">${msg}</strong>${sub?`<span>${sub}</span>`:''}</div>`;
    stack.appendChild(t);
    setTimeout(()=>{ t.classList.add('out'); setTimeout(()=>t.remove(),350); }, 4200);
}

/* ── MODALS ── */
function openModal(id)  { document.getElementById(id).classList.add('show');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow=''; }

document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal.show').forEach(m => closeModal(m.id));
    if (e.ctrlKey && e.key === 'n') { e.preventDefault(); openModal('createModal'); }
});

/* ── EDIT MODAL ── */
function openEditModal(emp) {
    document.getElementById('edit_id').value           = emp.id;
    document.getElementById('edit_code').value         = emp.employee_code;
    document.getElementById('edit_name').value         = emp.full_name;
    document.getElementById('edit_category').value     = emp.category_id;
    document.getElementById('edit_position').value     = emp.position_id;
    document.getElementById('edit_salary_type').value  = emp.salary_type;
    document.getElementById('edit_salary_amount').value= emp.salary_amount;
    document.getElementById('edit_phone').value        = emp.phone || '';
    document.getElementById('edit_hire_date').value    = emp.hire_date;
    document.getElementById('edit_start_date').value   = emp.start_date || '';
    document.getElementById('edit_status').value       = emp.status;
    document.getElementById('edit_address').value      = emp.address || '';
    document.getElementById('edit_contract').value     = emp.contract_type || 'CDI';
    document.getElementById('edit_emergency').value    = emp.emergency_contact || '';
    document.getElementById('edit_notes').value        = emp.notes || '';
    openModal('editModal');
}

/* ── PERF MODAL ── */
function openPerfModal(id, name, score) {
    document.getElementById('perf_id').value    = id;
    document.getElementById('perf_name').textContent = name;
    document.getElementById('perf_score').value = score;
    openModal('perfModal');
}

/* ── RESET MODAL ── */
function openResetModal(id, name) {
    document.getElementById('reset_id').value         = id;
    document.getElementById('reset_name').textContent = name;
    openModal('resetModal');
}

/* ── DELETE MODAL ── */
function openDeleteModal(id, name) {
    document.getElementById('delete_id').value         = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}

/* ── SEARCH + FILTER ── */
let activeFilter = 'all';
function setFilter(f, btn) {
    activeFilter = f;
    document.querySelectorAll('.fc').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    filterTable();
}
function filterTable() {
    const q = (document.getElementById('searchInput')?.value || '').toLowerCase();
    document.querySelectorAll('#empTable tbody tr').forEach(row => {
        const match  = row.textContent.toLowerCase().includes(q);
        const status = row.dataset.status || '';
        const stype  = row.dataset.salaryType || '';
        const ok     = activeFilter === 'all' || status === activeFilter || stype === activeFilter;
        row.style.display = (match && ok) ? '' : 'none';
    });
}

/* ── AUTO-HIDE ALERTS ── */
setTimeout(() => {
    ['flash-ok','flash-err'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.transition='opacity .5s,transform .5s'; el.style.opacity='0'; el.style.transform='translateX(40px)'; setTimeout(()=>el.remove(),500); }
    });
}, 7000);

/* ── INIT TOASTS ── */
window.addEventListener('DOMContentLoaded', () => {
    <?php if($success_parts): ?>toast("<?= htmlspecialchars(addslashes($success_parts[0])) ?>", "success");<?php endif; ?>
    <?php if($error_parts):   ?>toast("<?= htmlspecialchars(addslashes($error_parts[0]))   ?>", "error");<?php endif; ?>
    if (<?= $payment_alert?'true':'false' ?>) toast("Alerte paiement !", "warn", "<?= $unpaid_count ?> employé(s) non payé(s)");
});

console.log('%c ESPERANCE H2O // RH Dark Neon v3.0 ', 'background:#04090e;color:#32be8f;font-family:monospace;padding:6px;border:1px solid #32be8f');
</script>
</body>
</html>
