<?php
session_start();

function redirectToRoleProfile() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    
    $role = $_SESSION['role'] ?? 'chercheur';
    
    switch ($role) {
        case 'gestionnaire':
            header('Location: admin_profile.php');
            break;
        case 'reviewer':
            header('Location: reviewer_profile.php');
            break;
        case 'chercheur':
        default:
            header('Location: profile.php');
            break;
    }
    exit;
}

// Usage: redirectToRoleProfile();
?>