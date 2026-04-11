<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/****************************************************************
* SYSTÈME RH - POINTAGE QUOTIDIEN
* Signature de présence journalière
* Check-in / Check-out avec statuts
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

        // MARK ATTENDANCE
        if($_POST['action']=='mark_attendance'){
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $work_date = $_POST['work_date'] ?? date('Y-m-d');
            $status = $_POST['status'] ?? 'present';
            $check_in = $_POST['check_in'] ?? date('H:i:s');
            $check_out = $_POST['check_out'] ?? null;

            if($employee_id<=0) throw new Exception("Employé invalide");

            // Check if already exists
            $stmt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id=? AND work_date=?");
            $stmt->execute([$employee_id, $work_date]);
            
            if($existing = $stmt->fetch()){
                // Update
                $stmt = $pdo->prepare("UPDATE attendance SET check_in=?, check_out=?, status=? WHERE id=?");
                $stmt->execute([$check_in, $check_out, $status, $existing['id']]);
                $response['msg'] = "✓ Pointage mis à jour";
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, work_date, check_in, check_out, status, created_at) VALUES (?,?,?,?,?,NOW())");
                $stmt->execute([$employee_id, $work_date, $check_in, $check_out, $status]);
                $response['msg'] = "✓ Pointage enregistré";
            }

            $response['success'] = true;
        }

        // BULK MARK (all present/absent)
        elseif($_POST['action']=='bulk_mark'){
            $work_date = $_POST['work_date'] ?? date('Y-m-d');
            $status = $_POST['status'] ?? 'present';
            $employee_ids = json_decode($_POST['employee_ids'] ?? '[]', true);

            if(empty($employee_ids)) throw new Exception("Aucun employé sélectionné");

            $count = 0;
            foreach($employee_ids as $emp_id){
                $stmt = $pdo->prepare("
                    INSERT INTO attendance (employee_id, work_date, check_in, status, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE status=?, check_in=?
                ");
                $stmt->execute([$emp_id, $work_date, date('H:i:s'), $status, $status, date('H:i:s')]);
                $count++;
            }

            $response['success'] = true;
            $response['msg'] = "✓ $count employés marqués";
        }

        // DELETE ATTENDANCE
        elseif($_POST['action']=='delete_attendance'){
            $id = (int)($_POST['id'] ?? 0);
            if($id<=0) throw new Exception("ID invalide");

            $stmt = $pdo->prepare("DELETE FROM attendance WHERE id=?");
            $stmt->execute([$id]);

            $response['success'] = true;
            $response['msg'] = "✓ Pointage supprimé";
        }

    }catch(Throwable $e){
        $response['msg'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

/* ================= FILTERS ================= */
$selected_date = $_GET['date'] ?? date('Y-m-d');
$filter_category = (int)($_GET['category'] ?? 0);
$filter_status = $_GET['status'] ?? 'all';

/* ================= GET EMPLOYEES ================= */
$where = ["e.status='actif'"];
$params = [];

if($filter_category > 0){
    $where[] = "e.category_id=?";
    $params[] = $filter_category;
}

$whereSQL = implode(" AND ", $where);

$stmt = $pdo->prepare("
    SELECT e.id, e.employee_code, e.full_name, c.name as category_name, p.title as position_title
    FROM employees e
    JOIN categories c ON e.category_id=c.id
    JOIN positions p ON e.position_id=p.id
    WHERE $whereSQL
    ORDER BY c.name, e.full_name
");
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= GET ATTENDANCE ================= */
$stmt = $pdo->prepare("
    SELECT a.*, e.employee_code, e.full_name
    FROM attendance a
    JOIN employees e ON a.employee_id=e.id
    WHERE a.work_date=?
    ORDER BY a.created_at DESC
");
$stmt->execute([$selected_date]);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map attendance by employee_id
$attendance_map = [];
foreach($attendances as $att){
    $attendance_map[$att['employee_id']] = $att;
}

// Filter by status
if($filter_status !== 'all'){
    $employees = array_filter($employees, function($emp) use ($attendance_map, $filter_status){
        $has_att = isset($attendance_map[$emp['id']]);
        
        if($filter_status === 'present'){
            return $has_att && $attendance_map[$emp['id']]['status'] === 'present';
        } elseif($filter_status === 'absent'){
            return !$has_att || $attendance_map[$emp['id']]['status'] === 'absent';
        } elseif($filter_status === 'retard'){
            return $has_att && $attendance_map[$emp['id']]['status'] === 'retard';
        } elseif($filter_status === 'permission'){
            return $has_att && $attendance_map[$emp['id']]['status'] === 'permission';
        }
        return true;
    });
}

/* ================= STATS ================= */
$total_employees = count($employees);
$present_count = count(array_filter($attendance_map, fn($a) => $a['status']=='present'));
$absent_count = $total_employees - count($attendance_map);
$retard_count = count(array_filter($attendance_map, fn($a) => $a['status']=='retard'));

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pointage Quotidien - RH Pro</title>
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
    max-width: 1600px;
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
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    transition: transform 0.3s;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 15px;
    color: white;
}

.stat-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); }
.stat-success { background: linear-gradient(135deg, #10b981, #059669); }
.stat-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
.stat-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }

.stat-value {
    font-size: 32px;
    font-weight: 800;
    color: var(--dark);
    margin-bottom: 8px;
}

.stat-label {
    font-size: 14px;
    color: var(--gray);
    text-transform: uppercase;
}

.card {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}

.card-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 22px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border);
}

.filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

input, select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--border);
    border-radius: 10px;
    font-size: 15px;
    transition: all 0.3s;
}

input:focus, select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(99,102,241,0.1);
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-success {
    background: linear-gradient(135deg, var(--success), #059669);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, var(--danger), #dc2626);
    color: white;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), #4f46e5);
    color: white;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.2);
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

thead {
    background: linear-gradient(135deg, var(--primary), #4f46e5);
    color: white;
}

thead th {
    padding: 18px 16px;
    text-align: left;
    font-weight: 700;
    font-size: 13px;
    text-transform: uppercase;
}

thead th:first-child { border-radius: 12px 0 0 0; }
thead th:last-child { border-radius: 0 12px 0 0; }

tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background 0.2s;
}

tbody tr:hover {
    background: rgba(99,102,241,0.05);
}

tbody td {
    padding: 16px;
    color: var(--dark);
    font-size: 14px;
}

.badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}

.badge-success { background: #d1fae5; color: #065f46; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-primary { background: #dbeafe; color: #1e40af; }

.status-selector {
    display: flex;
    gap: 8px;
}

.status-btn {
    padding: 8px 16px;
    border: 2px solid transparent;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.status-btn-present {
    background: #d1fae5;
    color: #065f46;
}

.status-btn-absent {
    background: #fee2e2;
    color: #991b1b;
}

.status-btn-retard {
    background: #fef3c7;
    color: #92400e;
}

.status-btn-permission {
    background: #dbeafe;
    color: #1e40af;
}

.status-btn:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
}

.bulk-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    padding: 15px;
    background: linear-gradient(135deg, #f8fafc, #e2e8f0);
    border-radius: 12px;
}

.checkbox-cell {
    text-align: center;
}

input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}
.btn-back {

  display: inline-flex;

  align-items: center;

  gap: 8px;



  padding: 12px 22px;

  border-radius: 30px;



  background: linear-gradient(135deg, #4f46e5, #6366f1);

  color: #fff;

  font-weight: 600;

  text-decoration: none;



  box-shadow: 0 10px 25px rgba(79, 70, 229, 0.35);

  transition: all 0.3s ease;

}
</style>
</head>
<body>
<p>

  <a href="<?= project_url('hr/admin_attendance_viewer_pro.php') ?>" class="btn-back">

    voire avec photo

  </a>

</p>


<div class="container">
    
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-calendar-check"></i>
            Pointage Quotidien
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
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-value"><?= $total_employees ?></div>
            <div class="stat-label">Total Employés</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon stat-success">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stat-value"><?= $present_count ?></div>
            <div class="stat-label">Présents</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon stat-danger">
                <i class="fas fa-user-times"></i>
            </div>
            <div class="stat-value"><?= $absent_count ?></div>
            <div class="stat-label">Absents</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon stat-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-value"><?= $retard_count ?></div>
            <div class="stat-label">En Retard</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card">
        <h3 class="card-title">
            <i class="fas fa-filter"></i>
            Filtres
        </h3>
        
        <form method="get" class="filters">
            <input type="date" name="date" value="<?= $selected_date ?>" onchange="this.form.submit()">
            
            <select name="category" onchange="this.form.submit()">
                <option value="0">📂 Toutes les catégories</option>
                <?php foreach($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $filter_category==$cat['id']?'selected':'' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="status" onchange="this.form.submit()">
                <option value="all">👥 Tous</option>
                <option value="present" <?= $filter_status=='present'?'selected':'' ?>>✅ Présents</option>
                <option value="absent" <?= $filter_status=='absent'?'selected':'' ?>>❌ Absents</option>
                <option value="retard" <?= $filter_status=='retard'?'selected':'' ?>>⏰ En retard</option>
                <option value="permission" <?= $filter_status=='permission'?'selected':'' ?>>📝 Permission</option>
            </select>
        </form>
    </div>

    <!-- Bulk Actions -->
    <div class="card">
        <div class="bulk-actions">
            <button class="btn btn-success" onclick="bulkMark('present')">
                <i class="fas fa-check-double"></i>
                Tout Présent
            </button>
            <button class="btn btn-danger" onclick="bulkMark('absent')">
                <i class="fas fa-times-circle"></i>
                Tout Absent
            </button>
            <button class="btn btn-primary" onclick="bulkMarkSelected('present')">
                <i class="fas fa-check"></i>
                Sélection → Présent
            </button>
        </div>
        
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                        </th>
                        <th>Code</th>
                        <th>Nom</th>
                        <th>Catégorie</th>
                        <th>Poste</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($employees as $emp): 
                        $att = $attendance_map[$emp['id']] ?? null;
                    ?>
                    <tr data-employee-id="<?= $emp['id'] ?>">
                        <td class="checkbox-cell">
                            <input type="checkbox" class="employee-checkbox" value="<?= $emp['id'] ?>">
                        </td>
                        <td><strong><?= htmlspecialchars($emp['employee_code']) ?></strong></td>
                        <td><?= htmlspecialchars($emp['full_name']) ?></td>
                        <td><span class="badge badge-primary"><?= htmlspecialchars($emp['category_name']) ?></span></td>
                        <td><?= htmlspecialchars($emp['position_title']) ?></td>
                        <td>
                            <input type="time" class="check-in-time" value="<?= $att ? substr($att['check_in'], 0, 5) : date('H:i') ?>" style="width: 100px;">
                        </td>
                        <td>
                            <input type="time" class="check-out-time" value="<?= $att && $att['check_out'] ? substr($att['check_out'], 0, 5) : '' ?>" style="width: 100px;">
                        </td>
                        <td>
                            <?php if($att): ?>
                                <span class="badge badge-<?= $att['status']=='present'?'success':($att['status']=='absent'?'danger':($att['status']=='retard'?'warning':'primary')) ?>">
                                    <?= ucfirst($att['status']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-danger">Non marqué</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="status-selector">
                                <button class="status-btn status-btn-present" onclick="markAttendance(<?= $emp['id'] ?>, 'present')" title="Présent">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="status-btn status-btn-absent" onclick="markAttendance(<?= $emp['id'] ?>, 'absent')" title="Absent">
                                    <i class="fas fa-times"></i>
                                </button>
                                <button class="status-btn status-btn-retard" onclick="markAttendance(<?= $emp['id'] ?>, 'retard')" title="Retard">
                                    <i class="fas fa-clock"></i>
                                </button>
                                <button class="status-btn status-btn-permission" onclick="markAttendance(<?= $emp['id'] ?>, 'permission')" title="Permission">
                                    <i class="fas fa-file-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const csrf = "<?= $_SESSION['csrf_token'] ?>";
const selectedDate = "<?= $selected_date ?>";

function showAlert(type, message) {
    const alert = type === 'success' ? document.getElementById('successAlert') : document.getElementById('errorAlert');
    const msg = type === 'success' ? document.getElementById('successMsg') : document.getElementById('errorMsg');
    
    msg.textContent = message;
    alert.classList.add('show');
    setTimeout(() => alert.classList.remove('show'), 3000);
    
    if(type === 'error') {
        document.getElementById('successAlert').classList.remove('show');
    } else {
        document.getElementById('errorAlert').classList.remove('show');
    }
}

async function markAttendance(employeeId, status) {
    const row = document.querySelector(`tr[data-employee-id="${employeeId}"]`);
    const checkIn = row.querySelector('.check-in-time').value || new Date().toTimeString().slice(0,5);
    const checkOut = row.querySelector('.check-out-time').value || null;
    
    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "mark_attendance",
                csrf_token: csrf,
                employee_id: employeeId,
                work_date: selectedDate,
                status: status,
                check_in: checkIn + ':00',
                check_out: checkOut ? checkOut + ':00' : ''
            })
        });
        
        const data = await res.json();
        
        if(data.success) {
            showAlert('success', data.msg);
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('error', data.msg);
        }
    } catch(error) {
        showAlert('error', 'Erreur: ' + error.message);
    }
}

async function bulkMark(status) {
    const employeeIds = Array.from(document.querySelectorAll('tr[data-employee-id]')).map(tr => tr.dataset.employeeId);
    
    if(!confirm(`Marquer tous les employés comme "${status}" ?`)) return;
    
    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "bulk_mark",
                csrf_token: csrf,
                work_date: selectedDate,
                status: status,
                employee_ids: JSON.stringify(employeeIds)
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

async function bulkMarkSelected(status) {
    const selected = Array.from(document.querySelectorAll('.employee-checkbox:checked')).map(cb => cb.value);
    
    if(selected.length === 0) {
        showAlert('error', 'Aucun employé sélectionné');
        return;
    }
    
    if(!confirm(`Marquer ${selected.length} employé(s) comme "${status}" ?`)) return;
    
    try {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "bulk_mark",
                csrf_token: csrf,
                work_date: selectedDate,
                status: status,
                employee_ids: JSON.stringify(selected)
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

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.employee-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
}
</script>

</body>
</html>
