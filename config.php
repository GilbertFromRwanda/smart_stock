<?php
session_start();

// ── Language / i18n ───────────────────────────────────────────────────────────
$_LANG_CODE = (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['en', 'fr', 'rw']))
    ? $_COOKIE['lang']
    : 'en';
$_LANG      = require __DIR__ . '/lang/' . $_LANG_CODE . '.php';

function t(string $key, ...$args): string {
    global $_LANG;
    $str = $_LANG[$key] ?? $key;
    return $args ? vsprintf($str, $args) : $str;
}
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/db_config.php';

// Set timezone
date_default_timezone_set('Africa/Kigali');

// ── Subscription / licensing ──────────────────────────────────────────────────
//
// SECURITY LAYERS
//   1. Machine-locked keys  — key encodes the C: drive serial number; a key
//      generated for machine A is cryptographically invalid on machine B.
//   2. Secret outside web root — the HMAC secret lives at one level above
//      htdocs (e.g. C:\xampp\.ss_lic). Reading all PHP files is not enough
//      to forge a key.
//   3. Keygen PIN — keygen.php requires a separate developer password that is
//      NOT stored in this file, so admin-role access alone is not sufficient.
//   4. Row signature — each DB row stores an HMAC that binds the license_key
//      to its expires_at. Editing the date in phpMyAdmin breaks the signature
//      and the subscription is treated as invalid.
//
// ─────────────────────────────────────────────────────────────────────────────

// Return stable machine identifier (Windows C: volume serial, or hostname hash)
function _sub_machine(): string {
    static $id = null;
    if ($id !== null) return $id;
    if (PHP_OS_FAMILY === 'Windows') {
        $vol = @shell_exec('vol C: 2>NUL');
        if ($vol && preg_match('/[A-F0-9]{4}-[A-F0-9]{4}/i', $vol, $m)) {
            $id = strtoupper(str_replace('-', '', $m[0])); // e.g. "ABCD1234"
            return $id;
        }
    }
    $id = strtoupper(substr(hash('sha256', gethostname() ?: php_uname('n') ?: 'local'), 0, 8));
    return $id;
}

// Load (or auto-generate) the HMAC secret stored OUTSIDE the web root.
// Path: one level above DOCUMENT_ROOT, e.g. C:\xampp\.ss_lic on XAMPP.
// Deleting this file invalidates all existing keys (next key must be re-issued).
function _sub_secret(): string {
    static $s = null;
    if ($s !== null) return $s;
    $file = dirname($_SERVER['DOCUMENT_ROOT']) . DIRECTORY_SEPARATOR . '.ss_lic';
    if (!is_file($file)) {
        @file_put_contents($file, bin2hex(random_bytes(32)));
    }
    $raw = trim((string)@file_get_contents($file));
    // If file unreadable (permissions), keys will simply never validate
    $s = hash_hmac('sha256', 'UOGN_BASE_2024', $raw ?: 'unreadable');
    return $s;
}

// Key format: YYYYMMDD-XXXXXXXX-XXXXXXXX (24 alphanum chars + 2 dashes)
// Payload = expiry date + machine ID; authenticated with the file-based secret.
function sub_generate_key(string $expiry_ymd, string $machine_id = ''): string {
    if ($machine_id === '') $machine_id = _sub_machine();
    $payload = $expiry_ymd . '|' . $machine_id;
    $hmac    = strtoupper(substr(hash_hmac('sha256', $payload, _sub_secret()), 0, 16));
    return $expiry_ymd . '-' . substr($hmac, 0, 8) . '-' . substr($hmac, 8, 8);
}

function sub_validate_key(string $key): ?DateTimeImmutable {
    $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $key));
    if (strlen($clean) !== 24) return null;
    $ymd   = substr($clean, 0, 8);
    $given = substr($clean, 8);
    $y = (int)substr($ymd, 0, 4);
    $m = (int)substr($ymd, 4, 2);
    $d = (int)substr($ymd, 6, 2);
    if (!checkdate($m, $d, $y)) return null;
    $expected = strtoupper(substr(
        hash_hmac('sha256', $ymd . '|' . _sub_machine(), _sub_secret()), 0, 16));
    if (!hash_equals($expected, $given)) return null;
    $dt = DateTimeImmutable::createFromFormat('Ymd', $ymd);
    if (!$dt) return null;
    // Reject keys whose expiry date has already passed
    if ($dt->setTime(23, 59, 59)->getTimestamp() < time()) return null;
    return $dt;
}

// Compute the row signature that binds a license key to its expiry date.
// Any change to expires_at in the DB produces a different hash → invalid.
function sub_row_sig(string $license_key, string $expires_ymd): string {
    $clean_key  = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $license_key));
    $clean_date = str_replace('-', '', $expires_ymd); // normalize to YYYYMMDD
    return hash_hmac('sha256', $clean_key . '|' . $clean_date . '|' . _sub_machine(), _sub_secret());
}

// Returns: 'active' | 'expired' | 'tampered' | 'none'
function sub_status(mysqli $conn): string {
    $r = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT license_key, expires_at, signature
         FROM subscription ORDER BY expires_at DESC LIMIT 1"));
    if (!$r) return 'none';
    $sig_ok = hash_equals(sub_row_sig($r['license_key'], $r['expires_at']), (string)$r['signature']);
    if (!$sig_ok) return 'tampered';
    if (strtotime($r['expires_at'] . ' 23:59:59') < time()) return 'expired';
    return 'active';
}

function sub_is_active(mysqli $conn): bool {
    return sub_status($conn) === 'active';
}

function sub_get_latest(mysqli $conn): ?array {
    return mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT *, DATEDIFF(expires_at, CURDATE()) AS days_left
         FROM subscription ORDER BY expires_at DESC LIMIT 1")) ?: null;
}

// Auto-create table on first run
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS subscription (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    license_key  VARCHAR(35)  NOT NULL,
    expires_at   DATE         NOT NULL,
    signature    VARCHAR(64)  NULL,
    activated_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    activated_by INT,
    notes        VARCHAR(255)
)");

// Add signature column to existing tables (one-time migration)
$_has_sig = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription' AND COLUMN_NAME = 'signature'"));
if ((int)$_has_sig['c'] === 0) {
    mysqli_query($conn, "ALTER TABLE subscription ADD COLUMN signature VARCHAR(64) NULL AFTER expires_at");
}

// Back-fill signatures for rows that were activated before this feature existed.
// Rows whose stored key still validates cryptographically get a signature added;
// rows with a tampered or unknown key are left NULL (and will fail the active check).
$_unsigned = mysqli_query($conn, "SELECT id, license_key, expires_at FROM subscription WHERE signature IS NULL");
while ($_row = mysqli_fetch_assoc($_unsigned)) {
    if (sub_validate_key($_row['license_key'])) {
        $_sig = mysqli_real_escape_string($conn, sub_row_sig($_row['license_key'], $_row['expires_at']));
        mysqli_query($conn, "UPDATE subscription SET signature='$_sig' WHERE id={$_row['id']}");
    }
}
unset($_unsigned, $_row, $_has_sig);

// Subscription guard — redirect to subscription.php when not active
$_sub_bypass = ['login.php', 'logout.php', 'subscription.php', 'set_lang.php', 'keygen.php'];
if (isset($_SESSION['user_id']) && !in_array(basename($_SERVER['PHP_SELF']), $_sub_bypass)) {
    $_sub_status = sub_status($conn);
    if ($_sub_status !== 'active') {
        header('Location: subscription.php?reason=' . $_sub_status);
        exit();
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

// ── Audit log ─────────────────────────────────────────────────────────────────
function audit_log(mysqli $conn, string $action, string $module, int $record_id, string $description, array $old = [], array $new = []): void {
    $user_id  = (int)($_SESSION['user_id'] ?? 0);
    $username = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'system');
    $action_e = mysqli_real_escape_string($conn, $action);
    $module_e = mysqli_real_escape_string($conn, $module);
    $desc_e   = mysqli_real_escape_string($conn, $description);
    $ip       = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '');
    $old_sql  = $old ? "'" . mysqli_real_escape_string($conn, json_encode($old, JSON_UNESCAPED_UNICODE)) . "'" : 'NULL';
    $new_sql  = $new ? "'" . mysqli_real_escape_string($conn, json_encode($new, JSON_UNESCAPED_UNICODE)) . "'" : 'NULL';
    mysqli_query($conn, "INSERT INTO audit_log (user_id, username, action, module, record_id, description, old_value, new_value, ip_address)
        VALUES ($user_id, '$username', '$action_e', '$module_e', $record_id, '$desc_e', $old_sql, $new_sql, '$ip')");
}
// ─────────────────────────────────────────────────────────────────────────────
?>