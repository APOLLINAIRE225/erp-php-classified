<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();
require_once APP_ROOT . '/includes/db.php'; // connexion PDO MySQL

$error = '';

// Vérification de clé d'accès + login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $access_key = trim($_POST['access_key']); // clé fournie par admin

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Vérification utilisateur + mot de passe + clé
    if($user && hash('sha256',$password)===$user['password'] && $access_key === $user['access_key']){
        $_SESSION['user'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['company_id'] = $user['company_id']; // si applicable
        header("Location: " . project_url('finance/caisse_complete_enhanced.php'));
        exit;
    } else {
        $error = "⚠ Accès refusé, utilisateur, mot de passe ou clé incorrect !";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Login - ProMax Enterprise</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#0f2027,#2c5364);display:flex;justify-content:center;align-items:center;height:100vh;color:#fff;margin:0;}
.login-container{background:#fff;color:#134e4a;padding:50px 40px 40px 40px;border-radius:16px;width:400px;position:relative;box-shadow:0 10px 30px rgba(0,0,0,0.3);text-align:center;overflow:hidden;}
.login-container i.fa-gear{font-size:70px;color:#14b8a6;margin-bottom:20px;animation:spin 6s linear infinite;}
@keyframes spin{0%{transform:rotate(0deg);}100%{transform:rotate(360deg);}}
h2{margin-bottom:15px;font-weight:bold;}
input[type=text],input[type=password]{width:100%;padding:14px;margin:10px 0 20px 0;border:1px solid #14b8a6;border-radius:10px;font-size:14px;}
button{width:100%;padding:14px;background:#14b8a6;color:white;border:none;border-radius:10px;cursor:pointer;font-size:16px;transition:0.3s;}
button:hover{background:#0d9488;}
.error{background:#fef3c7;color:#92400e;padding:10px;border-radius:8px;margin-bottom:15px;font-weight:bold;}
.marquee-container{background:#e0f2fe;padding:8px 0;margin-bottom:25px;border-radius:8px;overflow:hidden;}
.marquee-container marquee{color:#0369a1;font-weight:bold;}
#clock{position:absolute;top:20px;right:20px;font-weight:bold;color:#14b8a6;font-size:14px;}
</style>
</head>
<body>
<div class="login-container">
    <i class="fa-solid fa-gear"></i>
    <div id="clock"></div>
    <div class="marquee-container">
        <marquee>Bienvenue sur le système ProMax Enterprise. Veuillez entrer votre login et votre clé d'accès.</marquee>
    </div>
    <h2>Connexion Admin</h2>
    <?php if($error) echo "<div class='error'>$error</div>"; ?>
    <form method="post">
        <input type="text" name="username" placeholder="Nom d'utilisateur" required>
        <input type="password" name="password" placeholder="Mot de passe" required>
        <input type="text" name="access_key" placeholder="Clé d'accès" required>
        <button type="submit"><i class="fa-solid fa-right-to-bracket"></i> Se connecter</button>
    </form>
</div>
<script>
function updateClock(){const now=new Date();document.getElementById('clock').textContent=now.toLocaleTimeString('fr-FR',{hour12:false});}
setInterval(updateClock,1000);updateClock();
</script>
</body>
</html>
