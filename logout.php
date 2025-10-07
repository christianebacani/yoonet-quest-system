<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Destroy all session data
$_SESSION = array();
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>