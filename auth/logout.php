<?php
/****************************************************************
* LOGOUT - Déconnexion sécurisée
****************************************************************/
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();

// Détruire toutes les données de session
$_SESSION = array();

// Détruire le cookie de session si il existe
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-42000, '/');
}

// Détruire la session
session_destroy();

// Rediriger vers la page de login
header('Location: ' . project_url('auth/login_unified.php'));
exit;
?>
