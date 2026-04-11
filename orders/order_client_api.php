<?php
session_start();
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        case 'set_client_session':
            $_SESSION['client_id'] = (int)$_POST['client_id'];
            $_SESSION['client_name'] = $_POST['client_name'];
            $_SESSION['client_phone'] = $_POST['client_phone'];
            
            echo json_encode(['success' => true]);
            break;
            
        case 'logout':
            unset($_SESSION['client_id']);
            unset($_SESSION['client_name']);
            unset($_SESSION['client_phone']);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
