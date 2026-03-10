<?php
// This page has been merged into Admin Management for a cleaner UX.
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../index.php');
    exit();
}
header('Location: admin_management.php');
exit();
