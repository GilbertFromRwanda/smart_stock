<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    $username = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
    $result   = mysqli_query($conn, "SELECT * FROM users WHERE username = '$username'");

    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        if (password_verify($_POST['password'] ?? '', $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            mysqli_query($conn, "UPDATE users SET last_login = NOW() WHERE id = " . $user['id']);
            echo json_encode(['ok' => true, 'redirect' => 'dashboard.php']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Invalid username or password']);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid username or password']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UO&amp;GN — Login</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            background: #f1f5f9;
        }

        /* ── Left brand panel ── */
        .brand-panel {
            display: none;
            flex: 1;
            background: #0f172a;
            position: relative;
            overflow: hidden;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 48px;
            color: #fff;
        }

        @media (min-width: 900px) { .brand-panel { display: flex; flex: 0 0 560px; } }

        .brand-panel::before {
            content: '';
            position: absolute;
            width: 500px; height: 500px;
            border-radius: 50%;
            background: rgba(59,130,246,0.07);
            top: -120px; right: -140px;
        }
        .brand-panel::after {
            content: '';
            position: absolute;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: rgba(99,102,241,0.06);
            bottom: -80px; left: -80px;
        }

        .brand-icon {
            width: 72px; height: 72px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 28px;
        }

        .brand-panel h1 {
            font-size: 36px;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.15;
            margin-bottom: 16px;
            text-align: center;
        }

        .brand-panel p {
            font-size: 15px;
            color: #94a3b8;
            text-align: center;
            max-width: 300px;
            line-height: 1.6;
        }

        .brand-stats {
            display: flex;
            gap: 32px;
            margin-top: 48px;
        }

        .stat {
            text-align: center;
        }
        .stat-value {
            font-size: 26px;
            font-weight: 700;
        }
        .stat-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }

        /* ── Right form panel ── */
        .form-panel {
            flex: 0 0 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            background: #f1f5f9;
        }

        @media (min-width: 900px) { .form-panel { flex: 0 0 460px; } }

        .login-card {
            width: 100%;
            max-width: 400px;
            background: #ffffff;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }

        .login-card .logo-sm {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
        }

        .logo-sm-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
        }

        .logo-sm-text {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .login-card h2 {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .login-card .subtitle {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 32px;
        }

        /* Error alert */
        .alert-error {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* Form */
        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            display: flex;
            pointer-events: none;
        }

        .field input {
            width: 100%;
            padding: 11px 14px 11px 42px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #0f172a;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }

        .field input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        .field input::placeholder { color: #cbd5e1; }

        .toggle-pw {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            display: flex;
            padding: 2px;
        }
        .toggle-pw:hover { color: #475569; }

        /* Submit button */
        .btn-login {
            width: 100%;
            padding: 13px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
            margin-top: 6px;
            box-shadow: 0 4px 14px rgba(37,99,235,0.35);
        }

        .btn-login:hover {
            background: #1d4ed8;
            box-shadow: 0 6px 20px rgba(37,99,235,0.4);
        }
        .btn-login:active { transform: scale(0.98); }

        .footer-note {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 28px;
        }
    </style>
</head>
<body>

    <!-- Brand panel (visible on wide screens) -->
    <div class="brand-panel">
        <div class="brand-icon">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
        </div>
        <h1>UO&amp;GN<br>Smart Stock</h1>
        <p>Manage your inventory, track sales, and grow your business with confidence.</p>
        <div class="brand-stats">
            <div class="stat">
                <div class="stat-value">100%</div>
                <div class="stat-label">Accurate</div>
            </div>
            <div class="stat">
                <div class="stat-value">Real‑time</div>
                <div class="stat-label">Stock Updates</div>
            </div>
            <div class="stat">
                <div class="stat-value">Fast</div>
                <div class="stat-label">& Simple</div>
            </div>
        </div>
    </div>

    <!-- Form panel -->
    <div class="form-panel">
        <div class="login-card">

            <div class="logo-sm">
                <div class="logo-sm-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                </div>
                <span class="logo-sm-text">UO&amp;GN System</span>
            </div>

            <h2>Welcome back</h2>
            <p class="subtitle">Sign in to your account to continue</p>

            <div class="alert-error" id="login-error" style="display:none">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span id="login-error-msg"></span>
            </div>

            <form method="POST" action="">
                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <button type="button" class="toggle-pw" onclick="togglePassword()" title="Show/hide password">
                            <svg id="eye-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="login-btn">Sign In</button>
            </form>

            <p class="footer-note">&copy; <?php echo date('Y'); ?> UO&amp;GN Smart Stock &mdash; All rights reserved</p>
        </div>
    </div>

    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('login-btn');
            var errorBox = document.getElementById('login-error');
            var errorMsg = document.getElementById('login-error-msg');

            btn.disabled = true;
            btn.textContent = 'Signing in…';
            errorBox.style.display = 'none';

            var body = new FormData(this);

            fetch('login.php', { method: 'POST', body: body })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        btn.textContent = 'Redirecting…';
                        window.location.href = data.redirect;
                    } else {
                        errorMsg.textContent = data.error;
                        errorBox.style.display = 'flex';
                        btn.disabled = false;
                        btn.textContent = 'Sign In';
                    }
                })
                .catch(function() {
                    errorMsg.textContent = 'Network error. Please try again.';
                    errorBox.style.display = 'flex';
                    btn.disabled = false;
                    btn.textContent = 'Sign In';
                });
        });

        function togglePassword() {
            var input = document.getElementById('password');
            var icon  = document.getElementById('eye-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>' +
                    '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>' +
                    '<line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        }
    </script>
</body>
</html>
