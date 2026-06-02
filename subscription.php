<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$error   = '';
$success = '';

// Handle key activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key'])) {
    $raw = trim($_POST['license_key']);
    $dt  = sub_validate_key($raw);

    if (!$dt) {
        $error = t('sub_invalid_key');
    } elseif ($dt->setTime(23, 59, 59)->getTimestamp() < time()) {
        $error = t('sub_key_expired');
    } else {
        $clean   = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $raw));
        $expires = $dt->format('Y-m-d');
        $uid     = (int)$_SESSION['user_id'];
        $sig     = mysqli_real_escape_string($conn, sub_row_sig($clean, $expires));
        mysqli_query($conn, "INSERT INTO subscription (license_key, expires_at, signature, activated_by)
                             VALUES ('$clean', '$expires', '$sig', $uid)");
        $_SESSION['flash_success'] = t('sub_activated_ok');
        redirect('dashboard.php');
    }
}

$sub_status = sub_status($conn);
$reason     = $_GET['reason'] ?? '';               // 'expired' | 'tampered' | 'none' | ''
$sub        = sub_get_latest($conn);
$is_active  = $sub_status === 'active';
$days_left  = $sub ? (int)$sub['days_left'] : -1;

// History
$history = mysqli_query($conn,
    "SELECT s.*, u.full_name, u.username
     FROM subscription s
     LEFT JOIN users u ON u.id = s.activated_by
     ORDER BY s.activated_at DESC LIMIT 20");
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_LANG_CODE); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('sub_title'); ?> — UO&amp;GN Smart Stock</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    min-height: 100vh;
    background: #f1f5f9;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
}

.sub-card {
    width: 100%;
    max-width: 480px;
    background: #fff;
    border-radius: 20px;
    padding: 40px;
    box-shadow: 0 4px 32px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.04);
    border: 1px solid #e2e8f0;
}

.sub-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 28px;
}
.sub-logo-icon {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.sub-logo-text { font-size: 20px; font-weight: 700; color: #0f172a; }

.sub-status {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 20px;
    border-radius: 14px;
    margin-bottom: 28px;
    border: 1.5px solid;
}
.sub-status.active   { background: #f0fdf4; border-color: #86efac; }
.sub-status.expired  { background: #fef2f2; border-color: #fca5a5; }
.sub-status.warning  { background: #fffbeb; border-color: #fcd34d; }
.sub-status-icon     { font-size: 28px; flex-shrink: 0; }
.sub-status-label    { font-size: 13px; font-weight: 700; text-transform: uppercase;
                        letter-spacing: .5px; margin-bottom: 3px; }
.sub-status.active  .sub-status-label  { color: #15803d; }
.sub-status.expired .sub-status-label  { color: #b91c1c; }
.sub-status.warning .sub-status-label  { color: #92400e; }
.sub-status-detail  { font-size: 14px; color: #374151; }
.sub-status-days    { font-size: 24px; font-weight: 800; }

.sub-divider { border: none; border-top: 1px solid #e2e8f0; margin: 24px 0; }

h2 { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
.sub-hint { font-size: 13px; color: #64748b; margin-bottom: 20px; line-height: 1.5; }

.key-input-wrap { position: relative; margin-bottom: 14px; }
.key-input {
    width: 100%;
    padding: 13px 16px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 15px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #0f172a;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.key-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}
.key-input::placeholder { color: #cbd5e1; font-weight: 400; letter-spacing: 0; text-transform: none; }

.btn-activate {
    width: 100%;
    padding: 13px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s;
    box-shadow: 0 4px 14px rgba(37,99,235,.3);
}
.btn-activate:hover { background: #1d4ed8; }
.btn-activate:active { transform: scale(.98); }

.alert-error {
    display: flex; align-items: center; gap: 10px;
    background: #fef2f2; border: 1px solid #fecaca;
    color: #b91c1c; border-radius: 10px;
    padding: 12px 14px; font-size: 14px; margin-bottom: 16px;
}
.alert-success {
    display: flex; align-items: center; gap: 10px;
    background: #f0fdf4; border: 1px solid #86efac;
    color: #15803d; border-radius: 10px;
    padding: 12px 14px; font-size: 14px; margin-bottom: 16px;
}

.contact-note {
    font-size: 12px; color: #94a3b8;
    text-align: center; margin-top: 18px; line-height: 1.5;
}
.contact-note a { color: #3b82f6; text-decoration: none; }

/* History table */
.history-section { margin-top: 32px; }
.history-section h3 { font-size: 15px; font-weight: 700; color: #374151; margin-bottom: 12px; }
.history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.history-table th {
    text-align: left; padding: 8px 10px;
    font-size: 11px; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: .5px;
    border-bottom: 1px solid #e2e8f0;
}
.history-table td {
    padding: 9px 10px;
    border-bottom: 1px solid #f1f5f9;
    color: #374151;
    font-family: 'Courier New', monospace;
}
.history-table td.normal { font-family: inherit; }
.badge-active  { display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:700; background:#f0fdf4; color:#15803d; }
.badge-expired { display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:700; background:#fef2f2; color:#b91c1c; }

/* Lang switcher */
.sub-lang { display:flex; gap:6px; justify-content:center; margin-top:16px; }
.sub-lang a {
    font-size: 11px; font-weight: 700; color: #94a3b8;
    text-decoration: none; padding: 4px 9px; border-radius: 6px;
    border: 1px solid #e2e8f0;
    transition: color .15s, border-color .15s;
    letter-spacing: .5px;
}
.sub-lang a:hover { color: #0f172a; border-color: #2563eb; }
.sub-lang a.lang-active { color: #2563eb; border-color: #2563eb; background: #eff6ff; }

/* Logout link */
.sub-logout {
    display: block; text-align: center; margin-top: 12px;
    font-size: 12px; color: #94a3b8; text-decoration: none;
}
.sub-logout:hover { color: #ef4444; }

@media (max-width: 520px) {
    .sub-card { padding: 24px 20px; }
}
</style>
</head>
<body>

<div class="sub-card">
    <div class="sub-logo">
        <div class="sub-logo-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
        </div>
        <span class="sub-logo-text">UO&amp;GN Smart Stock</span>
    </div>

    <!-- Tamper alert (shown when DB date was edited without a valid signature) -->
    <?php if ($reason === 'tampered'): ?>
    <div style="background:#fef2f2;border:2px solid #f87171;border-radius:14px;
                padding:18px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;">
        <div style="font-size:26px;flex-shrink:0;">🚨</div>
        <div>
            <div style="font-size:15px;font-weight:800;color:#b91c1c;margin-bottom:4px;">
                <?php echo t('sub_tampered_title'); ?>
            </div>
            <div style="font-size:13px;color:#7f1d1d;line-height:1.55;">
                <?php echo t('sub_tampered_msg'); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Current subscription status -->
    <?php if ($is_active): ?>
        <?php $cls = $days_left <= 7 ? 'warning' : 'active'; ?>
        <div class="sub-status <?php echo $cls; ?>">
            <div class="sub-status-icon"><?php echo $days_left <= 7 ? '⚠️' : '✅'; ?></div>
            <div>
                <div class="sub-status-label">
                    <?php echo $days_left <= 7 ? t('sub_expires_soon') : t('sub_title'); ?>
                </div>
                <div class="sub-status-detail">
                    <?php echo t('sub_active_until'); ?>:
                    <strong><?php echo date('d M Y', strtotime($sub['expires_at'])); ?></strong>
                </div>
                <div style="font-size:13px;color:#64748b;margin-top:3px;">
                    <span class="sub-status-days"><?php echo max(0, $days_left); ?></span>
                    <?php echo t('sub_days_left'); ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="sub-status expired">
            <div class="sub-status-icon">🔒</div>
            <div>
                <div class="sub-status-label"><?php echo t('sub_required'); ?></div>
                <div class="sub-status-detail">
                    <?php echo $sub ? t('sub_expired_msg') : t('sub_no_sub_msg'); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <hr class="sub-divider">

    <!-- Machine ID (needed by developer to generate a valid key) -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;
                padding:14px 16px;margin-bottom:20px;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;
                    letter-spacing:.6px;margin-bottom:6px;">Machine ID
            <span style="font-weight:400;text-transform:none;font-size:11px;">
                — give this to the developer to get a key
            </span>
        </div>
        <div style="font-family:'Courier New',monospace;font-size:18px;font-weight:800;
                    color:#0f172a;letter-spacing:2px;cursor:pointer;user-select:all;"
             id="machineId"
             onclick="copyMachineId()"
             title="Click to copy"><?php echo htmlspecialchars(_sub_machine()); ?></div>
        <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
            📋 click to copy
        </div>
    </div>

    <!-- Key entry form -->
    <h2><?php echo t('sub_enter_key'); ?></h2>
    <p class="sub-hint"><?php echo t('sub_contact'); ?></p>

    <?php if ($error): ?>
    <div class="alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert-success">✓ <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="POST" id="subForm">
        <div class="key-input-wrap">
            <input type="text" name="license_key" class="key-input" id="keyInput"
                   placeholder="<?php echo t('sub_key_placeholder'); ?>"
                   maxlength="35" autocomplete="off" spellcheck="false"
                   autofocus required>
        </div>
        <button type="submit" class="btn-activate" id="activateBtn">
            <?php echo t('sub_activate'); ?>
        </button>
    </form>

    <?php if ($is_active): ?>
    <div class="contact-note">
        <a href="dashboard.php">← <?php echo t('nav_dashboard'); ?></a>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        &nbsp;·&nbsp; <a href="keygen.php"><?php echo t('keygen_title'); ?></a>
        <?php endif; ?>
    </div>
    <?php elseif (($_SESSION['role'] ?? '') === 'admin'): ?>
    <div class="contact-note">
        <a href="keygen.php"><?php echo t('keygen_title'); ?></a>
    </div>
    <?php endif; ?>

    <!-- Activation history -->
    <?php if (mysqli_num_rows($history) > 0): ?>
    <div class="history-section">
        <h3><?php echo t('sub_history'); ?></h3>
        <table class="history-table">
            <thead>
                <tr>
                    <th><?php echo t('sub_col_key'); ?></th>
                    <th><?php echo t('sub_col_expires'); ?></th>
                    <th class="normal"><?php echo t('sub_col_by'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($history)):
                    $exp_ts = strtotime($row['expires_at'] . ' 23:59:59');
                    $still_active = $exp_ts >= time();
                ?>
                <tr>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($row['license_key']); ?></td>
                    <td class="normal">
                        <?php echo date('d M Y', strtotime($row['expires_at'])); ?>
                        <?php if ($still_active): ?>
                            <span class="badge-active"><?php echo t('sub_nav_label'); ?></span>
                        <?php else: ?>
                            <span class="badge-expired"><?php echo t('sub_expired_label'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="normal" style="color:#64748b;">
                        <?php echo htmlspecialchars($row['full_name'] ?? $row['username'] ?? '—'); ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <a href="logout.php" class="sub-logout">⏻ Logout</a>
</div>

<!-- Language switcher -->
<div class="sub-lang">
    <?php foreach (['en' => 'EN', 'fr' => 'FR', 'rw' => 'RW'] as $code => $label): ?>
    <a href="set_lang.php?lang=<?php echo $code; ?>"
       class="<?php echo $_LANG_CODE === $code ? 'lang-active' : ''; ?>">
        <?php echo $label; ?>
    </a>
    <?php endforeach; ?>
</div>

<script>
(function () {
    var btn  = document.getElementById('activateBtn');
    var form = document.getElementById('subForm');
    var inp  = document.getElementById('keyInput');

    // Auto-format input as user types: insert dashes at positions 8 and 17
    inp.addEventListener('input', function () {
        var raw = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        var out = '';
        for (var i = 0; i < raw.length && i < 24; i++) {
            if (i === 8 || i === 16) out += '-';
            out += raw[i];
        }
        this.value = out;
    });

    function copyMachineId() {
        var el  = document.getElementById('machineId');
        var txt = el ? el.textContent.trim() : '';
        if (!txt) return;

        function done() {
            el.style.color = '#22c55e';
            setTimeout(function() { el.style.color = ''; }, 1500);
        }

        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = txt;
            ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
            document.body.appendChild(ta);
            ta.focus(); ta.select();
            try { document.execCommand('copy'); } catch(e) {}
            document.body.removeChild(ta);
            done();
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(done).catch(fallback);
        } else {
            fallback();
        }
    }
    window.copyMachineId = copyMachineId;

    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.textContent = '<?php echo addslashes(t("sub_activating")); ?>';
    });
})();
</script>
</body>
</html>
