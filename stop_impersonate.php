<?php
session_start();
if (!empty($_SESSION['impersonating']) && !empty($_SESSION['original_admin'])) {
  $_SESSION['user'] = $_SESSION['original_admin'];
  unset($_SESSION['impersonating'], $_SESSION['original_admin']);
}
header('Location: users.php');
exit;
