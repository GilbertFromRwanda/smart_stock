<?php
$allowed = ['en', 'fr', 'rw'];
$lang    = $_GET['lang'] ?? 'en';
if (!in_array($lang, $allowed)) $lang = 'en';

// Persist for 1 year, site-wide, no httponly so JS can also read it if needed
setcookie('lang', $lang, time() + 365 * 24 * 3600, '/', '', false, false);

$back = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
$back = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $back);
$back = rtrim($back, '?&');

header('Location: ' . $back);
exit;
