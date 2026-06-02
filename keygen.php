<?php
require_once 'config.php';

// ── Developer PIN ─────────────────────────────────────────────────────────────
// This PIN is known ONLY to the developer. It is separate from the admin
// password. Even someone with admin access to the app cannot generate keys
// without this PIN. Change it to something private and keep it secret.
// Store this PIN in a safe place (NOT in the app's database).
define('_KEYGEN_PIN', base64_decode('MTIzNDU2')); // default PIN is "123456" — CHANGE THIS

if (!isLoggedIn() || ($_SESSION['role'] ?? '') !== 'admin') {
    redirect('dashboard.php');
}

$pin_ok       = false;
$pin_error    = false;
$generated    = '';
$expiry_label = '';

// Step 1: verify PIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_pin = $_POST['dev_pin'] ?? '';
    if (!hash_equals(_KEYGEN_PIN, $submitted_pin)) {
        $pin_error = true;
    } else {
        $pin_ok = true;
        // Step 2: generate key if date + machine_id supplied
        if (!empty($_POST['expiry_date']) && !empty($_POST['machine_id'])) {
            $raw_date   = $_POST['expiry_date'];
            $machine_id = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['machine_id']));
            $dt         = DateTime::createFromFormat('Y-m-d', $raw_date);
            if ($dt && $dt > new DateTime('today') && strlen($machine_id) >= 4) {
                $generated    = sub_generate_key($dt->format('Ymd'), $machine_id);
                $expiry_label = $dt->format('d M Y');
            }
        }
    }
}

$this_machine = _sub_machine(); // developer's own machine ID
$sub  = sub_get_latest($conn);
$today = new DateTime();
$presets = [
    ['label' => '1 month',  'date' => (clone $today)->modify('+1 month')->format('Y-m-d')],
    ['label' => '3 months', 'date' => (clone $today)->modify('+3 months')->format('Y-m-d')],
    ['label' => '6 months', 'date' => (clone $today)->modify('+6 months')->format('Y-m-d')],
    ['label' => '1 year',   'date' => (clone $today)->modify('+1 year')->format('Y-m-d')],
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_LANG_CODE); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('keygen_title'); ?> — UO&amp;GN Smart Stock</title>
<link rel="stylesheet" href="css/style.css">
<style>
.keygen-wrap { max-width: 580px; margin: 40px auto; padding: 0 16px; }

.page-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; color: #64748b; text-decoration: none; margin-bottom: 24px;
    transition: color .15s;
}
.page-back:hover { color: #0f172a; }

.keygen-card {
    background: #fff; border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    padding: 36px; margin-bottom: 24px;
}
.keygen-card h2 { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 6px; }
.keygen-card .subtitle { font-size: 13px; color: #64748b; margin-bottom: 24px; }

.security-note {
    background: #fffbeb; border: 1px solid #fcd34d;
    border-radius: 10px; padding: 14px 16px;
    font-size: 13px; color: #78350f; margin-bottom: 24px;
    line-height: 1.55;
}

.field { margin-bottom: 18px; }
.field label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px; }
.field input {
    width:100%; padding:11px 14px;
    border:1.5px solid #e2e8f0; border-radius:10px;
    font-size:14px; color:#0f172a; outline:none;
    transition:border-color .2s, box-shadow .2s;
}
.field input:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.field input.mono { font-family:'Courier New',monospace; letter-spacing:1px; font-weight:600; text-transform:uppercase; }

.preset-row { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px; }
.preset-btn {
    padding:7px 16px; border:1.5px solid #e2e8f0; border-radius:8px;
    background:#f8fafc; color:#374151; font-size:13px; font-weight:600; cursor:pointer;
    transition:border-color .15s, background .15s;
}
.preset-btn:hover { border-color:#2563eb; background:#eff6ff; color:#2563eb; }

.btn-generate {
    width:100%; padding:13px; background:#2563eb; color:#fff;
    border:none; border-radius:10px; font-size:15px; font-weight:600; cursor:pointer;
    box-shadow:0 4px 14px rgba(37,99,235,.3); transition:background .2s;
}
.btn-generate:hover { background:#1d4ed8; }

.alert-error {
    display:flex; align-items:center; gap:10px;
    background:#fef2f2; border:1px solid #fecaca; color:#b91c1c;
    border-radius:10px; padding:12px 14px; font-size:14px; margin-bottom:16px;
}

.key-result {
    margin-top:28px; padding:24px;
    background:#f0fdf4; border:1.5px solid #86efac;
    border-radius:14px; text-align:center;
}
.key-result-label { font-size:12px; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:.8px; margin-bottom:12px; }
.key-value {
    font-family:'Courier New',monospace; font-size:22px; font-weight:800;
    color:#0f172a; letter-spacing:2px; word-break:break-all;
    cursor:pointer; user-select:all; transition:color .15s;
}
.key-value:hover { color:#2563eb; }
.key-expiry { font-size:13px; color:#64748b; margin-top:10px; }
.copy-hint {
    display:inline-block; margin-top:10px;
    font-size:12px; color:#15803d; cursor:pointer;
    padding:5px 14px; border:1px solid #86efac; border-radius:99px;
    transition:background .15s;
}
.copy-hint:hover { background:#dcfce7; }

.machine-box {
    background:#eff6ff; border:1px solid #bfdbfe;
    border-radius:10px; padding:14px 16px; margin-bottom:20px;
}
.machine-box .mlabel { font-size:11px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:.6px; margin-bottom:5px; }
.machine-box .mval {
    font-family:'Courier New',monospace; font-size:20px; font-weight:800;
    color:#0f172a; letter-spacing:2px; cursor:pointer; user-select:all;
}
</style>
</head>
<body>
<div class="keygen-wrap">

    <a href="subscription.php" class="page-back">← <?php echo t('sub_manage'); ?></a>

    <div class="keygen-card">
        <h2>🔑 <?php echo t('keygen_title'); ?></h2>
        <p class="subtitle">Developer-only tool. Generates machine-locked license keys.</p>

        <div class="security-note">
            ⚠️ <strong>Remove this file from production deployments.</strong>
            This page is protected by a developer PIN but should not exist on the
            customer's machine. Delete <code>keygen.php</code> after setup.
        </div>

        <?php if (!$pin_ok): ?>
        <!-- PIN gate -->
        <?php if ($pin_error): ?>
        <div class="alert-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            Incorrect developer PIN.
        </div>
        <?php endif; ?>
        <form method="POST">
            <div class="field">
                <label>Developer PIN</label>
                <input type="password" name="dev_pin" placeholder="Enter developer PIN" autofocus required>
            </div>
            <button type="submit" class="btn-generate">Unlock Key Generator</button>
        </form>

        <?php else: ?>
        <!-- Generator form (PIN verified) -->

        <!-- This machine's ID (developer's own machine) -->
        <div class="machine-box">
            <div class="mlabel">This machine's ID (developer machine)</div>
            <div class="mval" id="devMachineId" onclick="copyToClipboard('devMachineId')" title="Click to copy">
                <?php echo htmlspecialchars($this_machine); ?>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;">📋 click to copy</div>
        </div>

        <form method="POST">
            <input type="hidden" name="dev_pin" value="<?php echo htmlspecialchars($_POST['dev_pin'] ?? ''); ?>">

            <div class="field">
                <label>Target Machine ID
                    <span style="font-weight:400;color:#64748b;">
                        — from the customer's subscription page
                    </span>
                </label>
                <input type="text" name="machine_id" class="mono"
                       placeholder="e.g. ABCD1234"
                       value="<?php echo htmlspecialchars($_POST['machine_id'] ?? $this_machine); ?>"
                       required maxlength="20">
            </div>

            <div class="field">
                <label><?php echo t('keygen_expiry_date'); ?></label>
                <div class="preset-row">
                    <?php foreach ($presets as $p): ?>
                    <button type="button" class="preset-btn" onclick="setDate('<?php echo $p['date']; ?>')">
                        <?php echo $p['label']; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="date" id="expiry_date" name="expiry_date" required
                       min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                       value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>">
            </div>

            <button type="submit" class="btn-generate"><?php echo t('keygen_generate'); ?></button>
        </form>

        <?php if ($generated): ?>
        <div class="key-result">
            <div class="key-result-label"><?php echo t('keygen_result'); ?></div>
            <div class="key-value" id="keyResult" onclick="copyToClipboard('keyResult')"
                 title="<?php echo t('keygen_copy_hint'); ?>">
                <?php echo htmlspecialchars($generated); ?>
            </div>
            <div class="key-expiry">
                <?php echo t('sub_active_until'); ?>:
                <strong><?php echo $expiry_label; ?></strong>
                &nbsp;·&nbsp;
                Machine: <strong><?php echo htmlspecialchars(strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['machine_id'] ?? ''))); ?></strong>
            </div>
            <div class="copy-hint" id="copyBtn" onclick="copyToClipboard('keyResult')">
                📋 <?php echo t('keygen_copy_hint'); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<script>
function setDate(d) { document.getElementById('expiry_date').value = d; }

function copyToClipboard(elId) {
    var el  = document.getElementById(elId);
    var txt = el ? el.textContent.trim() : '';
    if (!txt) return;

    function done() {
        el.style.color = '#22c55e';
        setTimeout(function() { el.style.color = ''; }, 1500);
        var btn = document.getElementById('copyBtn');
        if (btn) {
            var orig = btn.textContent;
            btn.textContent = '✅ <?php echo addslashes(t("keygen_copied")); ?>';
            setTimeout(function() { btn.textContent = orig; }, 2000);
        }
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
</script>
</body>
</html>
