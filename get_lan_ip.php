<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(403); exit; }

header('Content-Type: application/json');

try {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_connect($sock, '8.8.8.8', 80);
    socket_getsockname($sock, $ip);
    socket_close($sock);
} catch (\Throwable $e) {
    $ip = gethostbyname(gethostname());
}

$port     = $_SERVER['SERVER_PORT'] ?? 80;
$port_str = ($port == 80 || $port == 443) ? '' : ":$port";
$path     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$url      = 'http://' . $ip . $port_str . $path . '/';

echo json_encode(['ip' => $ip, 'url' => $url]);
