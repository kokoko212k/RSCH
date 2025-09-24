<?php
session_start();

function checkLogin() {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit;
    }
}

function requireRole($roles = []) {
    checkLogin();
    $userRole = $_SESSION['user']['role'];
    if (!in_array($userRole, $roles)) {
        echo "Akses ditolak untuk role: $userRole";
        exit;
    }
}
