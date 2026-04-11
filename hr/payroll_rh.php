<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/****************************************************************
* SYSTÈME RH - GÉNÉRATION AUTOMATIQUE DE PAIE
* Calcul salaires : jours travaillés + heures sup + avances + pénalités
* Export PDF bulletin de paie
****************************************************************/

ini_set('display_errors',1);
error_reporting(E_ALL);

if(session_status() === PHP_SESSION_NONE) session_start();

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer','admin','manager']);

$pdo = DB::getConnection();

if(empty($_SESSION['csrf_token'])){
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ================= AJAX HANDLERS ================= */
if(isset($_POST['action'])){
    header('Content-Type: application/json');
    $response = ['success'=>false,'msg'=>''];

    try{
        if(!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])){
            throw new Exception("CSRF TOKEN INVALID");
        }

        // GENERATE PAYROLL FOR MONTH
        if($_POST['action']=='generate_payroll'){
            $month = $_POST['month'] ?? date('Y-m');
            
            // Get all active employees
            $stmt = $pdo->query("SELECT * FROM employees WHERE status='actif'");
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $generated = 0;
            $errors = [];
            
            foreach($employees as $emp){
                try {
                    // Check if already exists
                    $stmt = $pdo->prepare("SELECT id FROM payroll WHERE employee_id=? AND month=?");
                    $stmt->execute([$emp['id'], $month]);
                    if($stmt->fetchColumn()){
                        continue; // Skip if already exists
                    }
                    
                    // 1. Count days worked
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND work_date LIKE ? AND status='present'");
                    $stmt->execute([$emp['id'], $month.'%']);
                    $days_worked = $stmt->fetchColumn();
                    
                    // 2. Calculate base salary
                    $base_salary = 0;
                    if($emp['salary_type'] == 'mensuel'){
                        $base_salary = $emp['salary_amount'];
                    } else {
                        // journalier
                        $base_salary = $days_worked * $emp['salary_amount'];
                    }
                    
                    // 3. Get overtime total
                    $stmt = $pdo->prepare("SELECT SUM(hours * rate_per_hour) FROM overtime WHERE employee_id=? AND work_date LIKE ?");
                    $stmt->execute([$emp['id'], $month.'%']);
                    $overtime_total = $stmt->fetchColumn() ?? 0;
                    
                    // 4. Bonus (can be added manually later)
                    $bonus_total = 0;
                    
                    // 5. Penalties
                    $stmt = $pdo->prepare("SELECT SUM(amount) FROM penalties WHERE employee_id=? AND penalty_date LIKE ?");
                    $stmt->execute([$emp['id'], $month.'%']);
                    $penalty_total = $stmt->fetchColumn() ?? 0;
                    
                    // 6. Advances
                    $stmt = $pdo->prepare("SELECT SUM(amount) FROM advances WHERE employee_id=? AND advance_date LIKE ? AND status='approuve'");
                    $stmt->execute([$emp['id'], $month.'%']);
                    $advance_total = $stmt->fetchColumn() ?? 0;
                    
                    // 7. Calculate net salary
                    $net_salary = $base_salary + $overtime_total + $bonus_total - $penalty_total - $advance_total;
                    
                    // Insert payroll
                    $stmt = $pdo->prepare("
                        INSERT INTO payroll 
                        (employee_id, month, days_worked, base_salary, overtime_total, bonus_total, penalty_total, advance_total, net_salary, status, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    $stmt->execute([
                        $emp['id'],
                        $month,
                        $days_worked,
                        $base_salary,
                        $overtime_total,
                        $bonus_total,
                        $penalty_total,
                        $advance_total,
                        $net_salary,
                        'non_paye'
                    ]);
                    
                    $generated++;
                    
                } catch(Exception $e){
                    $errors[] = $emp['full_name'] . ": " . $e->getMessage();
                }
            }
            
            $response['success'] = true;
            $response['msg'] = "✓ $generated paies générées";
            if(count($errors) > 0){
                $response['msg'] .= " | Erreurs: " . implode(", ", $errors);
            }
        }

        // MARK AS PAID
        elseif($_POST['action']=='mark_paid'){
            $id = (int)($_POST['id'] ?? 0);
            if($id<=0) throw new Exception("ID invalide");

            $stmt = $pdo->prepare("UPDATE payroll SET status='paye' WHERE id=?");
            $stmt->execute([$id]);

            $response['success'] = true;
            $response['msg'] = "✓ Marqué comme payé";
        }

        // DELETE PAYROLL
        elseif($_POST['action']=='delete_payroll'){
            $id = (int)($_POST['id'] ?? 0);
            if($id<=0) throw new Exception("ID invalide");

            $stmt = $pdo->prepare("DELETE FROM payroll WHERE id=?");
            $stmt->execute([$id]);

            $response['success'] = true;
            $response['msg'] = "✓ Paie supprimée";
        }

    }catch(Throwable $e){
        $response['msg'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

/* ================= FILTERS ================= */
$selected_month = $_GET['month'] ?? date('Y-m');

/* ================= GET PAYROLLS ================= */
$stmt = $pdo->prepare("
    SELECT p.*, e.employee_code, e.full_name, c.name as category_name, pos.title as position_title
    FROM payroll p
    JOIN employees e ON p.employee_id=e.id
    JOIN categories c ON e.category_id=c.id
    JOIN positions pos ON e.position_id=pos.id
    WHERE p.month=?
    ORDER BY e.full_name
");
$stmt->execute([$selected_month]);
$payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= STATS ================= */
$total_payrolls = count($payrolls);
$paid_count = count(array_filter($payrolls, fn($p) => $p['status']=='paye'));
$unpaid_count = $total_payrolls - $paid_count;
$total_to_pay = array_sum(array_filter(array_column($payrolls, 'net_salary'), fn($s, $k) => $payrolls[$k]['status']=='non_paye', ARRAY_FILTER_USE_BOTH));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Génération Paie - RH Pro</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #6366f1;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --dark: #1e293b;
    --gray: #64748b;
    --border: #e2e8f0;
}

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
}

.container {
    max-width: 1800px;
    margin: 0 auto;
}

.page-header {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
}

.page-title {
    display: flex;
    align-items: center;
    gap: 15px;
    font-size: 32px;
    font-weight: 800;
    color: var(--dark);
}

.page-title i {
    font-size: 36px;
    color: var(--primary);
}

.alert {
    padding: 16px 24px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: none;
    align-items: center;
    gap: 12px;
    font-weight: 600;
}

.alert.show { display: flex; }

.alert-success {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}

.alert-error {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    transition: transform 0.3s;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-icon {
    width: 70px;
    height: 70px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    margin-bottom: 20px;
    color: white;
}

.stat-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); }
.stat-success { background: linear-gradient(135deg, #10b981, #059669); }
.stat-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
.stat-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }

.stat-value {
    font-size: 36px;
    font-weight: 900;
    color: var(--dark);
    margin-bottom: 10px;
}

.stat-label {
    font-size: 15px;
    color: var(--gray);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

.card {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 3px solid var(--border);
}

.card-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 24px;
    font-weight: 800;
    color: var(--dark);
}

.card-title i {
    color: var(--primary);
}

input, select {
    padding: 14px 18px;
    border: 2px solid var(--border);
    border-radius: 12px;
    font-size: 16px;
    font-weight: 500;
    transition: all 0.3s;
}

input:focus, select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(99,102,241,0.1);
}

.btn {
    padding: 14px 28px;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.btn-success {
    background: linear-gradient(135deg, var(--success), #059669);
    color: white;
    box-shadow: 0 4px 15px rgba(16,185,129,0.3);
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), #4f46e5);
    color: white;
    box-shadow: 0 4px 15px rgba(99,102,241,0.3);
}

.btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 14px;
}

thead {
    background: linear-gradient(135deg, var(--primary), #4f46e5);
    color: white;
}

thead th {
    padding: 20px 14px;
    text-align: left;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

thead th:first-child { border-radius: 12px 0 0 0; }
thead th:last-child { border-radius: 0 12px 0 0; }

tbody tr {
    border-bottom: 1px solid var(--border);
    transition: all 0.2s;
}

tbody tr:hover {
    background: rgba(99,102,241,0.05);
}

tbody td {
    padding: 18px 14px;
    color: var(--dark);
    font-weight: 500;
}

.badge {
    display: inline-block;
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-success { background: #d1fae5; color: #065f46; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-primary { background: #dbeafe; color: #1e40af; }

.action-btns {
    display: flex;
    gap: 8px;
}

.action-btn {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 14px;
}

.action-btn-success {
    background: #d1fae5;
    color: #065f46;
}

.action-btn-danger {
    background: #fee2e2;
    color: #991b1b;
}

.action-btn:hover {
    transform: scale(1.1);
}

.money {
    font-weight: 800;
    font-size: 15px;
}

.money-positive {
    color: var(--success);
}

.money-negative {
    color: var(--danger);
}

.filters {
    display: flex;
    gap: 15px;
    align-items: center;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--gray);
}

.empty-state i {
    font-size: 80px;
    opacity: 0.3;
    margin-bottom: 25px;
}

.empty-state h3 {
    font-size: 24px;
    margin-bottom: 10px;
}
</style>
</head>
<body>

<div class="container">
    
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-money-check-alt"></i>
            Génération de Paie
        </h1>
    </div>

    <div class="alert alert-success" id="successAlert">
        <i class="fas fa-check-circle"></i>
        <span id="successMsg"></span>
    </div>
    <div class="alert alert-error" id="errorAlert">
        <i class="fas fa-times-circle"></i>
        <span id="errorMsg"></span>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-primary">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="stat-value"><?= $total_payrolls ?></div>
            <div class="stat-label">Total Paies</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon stat-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-value"><?= $paid_count ?></div>
            <div class="stat-label">Payés</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon stat-danger">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-value"><?= $unpaid_count ?></div>
            <div class="stat-label">Non Payés</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon stat-warning">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="stat-value"><?= number_format($total_to_pay, 0, ',', ' ') ?></div>
            <div class="stat-label">Total à Payer (FCFA)</div>
        </div>
    </div>

    <!-- Actions -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-cog"></i>
                Actions
            </h3>
        </div>
        
        <div class="filters">
            <input type="month" id="monthSelect" value="<?= $selected_month ?>" onchange="location.href='?month='+this.value">
            
            <button class="btn btn-success" onclick="generatePayroll()">
                <i class="fas fa-calculator"></i>
                Générer Paies du Mois
            </button>
            
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i>
                Imprimer
            </button>
        </div>
    </div>

    <!-- Payroll Table -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-list"></i>
                Paies - <?= date('F Y', strtotime($selected_month.'-01')) ?>
            </h3>
        </div>
        
        <?php if(count($payrolls) > 0): ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Employé</th>
                        <th>Catégorie</th>
                        <th>Poste</th>
                        <th>Jrs</th>
                        <th>Base</th>
                        <th>H.Sup</th>
                        <th>Bonus</th>
                        <th>Pénalités</th>
                        <th>Avances</th>
                        <th>Net</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($payrolls as $p): ?>
                    <tr data-id="<?= $p['id'] ?>">
                        <td><strong><?= htmlspecialchars($p['employee_code']) ?></strong></td>
                        <td><?= htmlspecialchars($p['full_name']) ?></td>
                        <td><span class="badge badge-primary"><?= htmlspecialchars($p['category_name']) ?></span></td>
                        <td><?= htmlspecialchars($p['position_title']) ?></td>
                        <td><strong><?= $p['days_worked'] ?></strong></td>
                        <td class="money money-positive"><?= number_format($p['base_salary'], 0, ',', ' ') ?></td>
                        <td class="money money-positive">+<?= number_format($p['overtime_total'], 0, ',', ' ') ?></td>
                        <td class="money money-positive">+<?= number_format($p['bonus_total'], 0, ',', ' ') ?></td>
                        <td class="money money-negative">-<?= number_format($p['penalty_total'], 0, ',', ' ') ?></td>
                        <td class="money money-negative">-<?= number_format($p['advance_total'], 0, ',', ' ') ?></td>
                        <td class="money" style="font-size: 16px; color: var(--dark);">
                            <strong><?= number_format($p['net_salary'], 0, ',', ' ') ?> F</strong>
                        </td>
                        <td>
                            <span class="badge <?= $p['status']=='paye'?'badge-success':'badge-warning' ?>">
                                <?= $p['status']=='paye'?'Payé':'Non Payé' ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <?php if($p['status']=='non_paye'): ?>
                                <button class="action-btn action-btn-success" onclick="markPaid(<?= $p['id'] ?>)" title="Marquer payé">
                                    <i class="fas fa-check"></i>
                                </button>
                                <?php endif; ?>
                                <button class="action-btn action-btn-danger" onclick="deletePayroll(<?= $p['id'] ?>)" title="Supprimer">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f8fafc; font-weight: 800; font-size: 16px;">
                        <td colspan="5" style="text-align: right;">TOTAL:</td>
                        <td class="money money-positive"><?= number_format(array_sum(array_column($payrolls, 'base_salary')), 0, ',', ' ') ?></td>
                        <td class="money money-positive"><?= number_format(array_sum(array_column($payrolls, 'overtime_total')), 0, ',', ' ') ?></td>
                        <td class="money money-positive"><?= number_format(array_sum(array_column($payrolls, 'bonus_total')), 0, ',', ' ') ?></td>
                        <td class="money money-negative"><?= number_format(array_sum(array_column($payrolls, 'penalty_total')), 0, ',', ' ') ?></td>
                        <td class="money money-negative"><?= number_format(array_sum(array_column($payrolls, 'advance_total')), 0, ',', ' ') ?></td>
                        <td class="money" style="color: var(--primary);"><?= number_format(array_sum(array_column($payrolls, 'net_salary')), 0, ',', ' ') ?> F</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-file-invoice"></i>
            <h3>Aucune paie générée pour ce mois</h3>
            <p>Cliquez sur "Générer Paies du Mois" pour commencer</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
const csrf = "<?= $_SESSION['csrf_token'] ?>";

function showAlert(type, message) {
    const alert = type === 'success' ? document.getElementById('successAlert') : document.getElementById('errorAlert');
    const msg = type === 'success' ? document.getElementById('successMsg') : document.getElementById('errorMsg');
    
    msg.textContent = message;
    alert.classList.add('show');
    setTimeout(() => alert.classList.remove('show'), 5000);
    
    if(type === 'error') {
        document.getElementById('successAlert').classList.remove('show');
    } else {
        document.getElementById('errorAlert').classList.remove('show');
    }
}

async function generatePayroll() {
    const month = document.getElementById('monthSelect').value;
    
    if(!confirm(`Générer les paies pour ${month} ?\n\nCeci calculera automatiquement:\n- Jours travaillés\n- Heures supplémentaires\n- Pénalités\n- Avances\n\nContinuer ?`)) return;
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Génération...';
    
    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "generate_payroll",
                csrf_token: csrf,
                month: month
            })
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            setTimeout(() => location.reload(), 2000);
        } else {
            showAlert('error', data.msg);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-calculator"></i> Générer Paies du Mois';
        }
    } catch(error) {
        showAlert('error', 'Erreur: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-calculator"></i> Générer Paies du Mois';
    }
}

async function markPaid(id) {
    if(!confirm('Marquer cette paie comme payée ?')) return;
    
    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "mark_paid",
                csrf_token: csrf,
                id: id
            })
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert('error', data.msg);
        }
    } catch(error) {
        showAlert('error', 'Erreur: ' + error.message);
    }
}

async function deletePayroll(id) {
    if(!confirm('⚠️ Supprimer cette paie ?')) return;
    
    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "delete_payroll",
                csrf_token: csrf,
                id: id
            })
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert('error', data.msg);
        }
    } catch(error) {
        showAlert('error', 'Erreur: ' + error.message);
    }
}
</script>

</body>
</html>
