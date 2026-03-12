<?php
require_once 'includes/security.php';
set_secure_session_cookies();
session_start();
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $adminRole = isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : '';
        if ($adminRole === 'super_admin') {
            header("Location: admin/super_dashboard.php");
        } else {
            header("Location: admin/dashboard.php");
        }
    } else {
        header("Location: tenant/dashboard.php");
    }
    exit();
}
$page_title = "Welcome";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StayWise — Sign In</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"/>
    <link rel="alternate icon" type="image/png" href="/assets/favicon-32.png"/>
    <link rel="apple-touch-icon" href="/assets/icon-192.png"/>
    <meta name="theme-color" content="#007DFE"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue: #007DFE;
            --blue-dark: #0060cc;
            --blue-light: #e8f1ff;
            --teal: #4ED6C1;
            --bg: #f0f4ff;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --input-bg: #f8faff;
            --shadow: 0 20px 60px rgba(0,125,254,.12), 0 4px 16px rgba(0,0,0,.06);
        }

        html, body {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* ── Animated background ── */
        .bg-scene {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
        }
        .bg-scene::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(0,125,254,.13) 0%, transparent 60%),
                radial-gradient(ellipse 60% 80% at 80% 90%, rgba(78,214,193,.10) 0%, transparent 60%),
                radial-gradient(ellipse 50% 50% at 50% 50%, rgba(0,125,254,.05) 0%, transparent 70%);
        }
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            animation: drift 12s ease-in-out infinite alternate;
        }
        .orb-1 {
            width: 500px; height: 500px;
            background: rgba(0,125,254,.08);
            top: -150px; left: -100px;
            animation-delay: 0s;
        }
        .orb-2 {
            width: 400px; height: 400px;
            background: rgba(78,214,193,.07);
            bottom: -120px; right: -80px;
            animation-delay: -4s;
        }
        .orb-3 {
            width: 300px; height: 300px;
            background: rgba(0,125,254,.05);
            top: 50%; left: 60%;
            animation-delay: -8s;
        }
        @keyframes drift {
            from { transform: translate(0, 0) scale(1); }
            to   { transform: translate(30px, 20px) scale(1.08); }
        }

        /* ── Card ── */
        .login-wrap {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 1rem;
            animation: slideUp .5s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .login-card {
            background: var(--card);
            border-radius: 24px;
            border: 1px solid rgba(0,125,254,.1);
            box-shadow: var(--shadow);
            padding: 2.5rem 2.5rem 2rem;
            position: relative;
            overflow: hidden;
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--blue), var(--teal));
        }

        /* ── Logo / Brand ── */
        .brand {
            display: flex;
            align-items: center;
            gap: .7rem;
            margin-bottom: 2rem;
        }
        .brand-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--blue), var(--teal));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; color: #fff;
            box-shadow: 0 4px 12px rgba(0,125,254,.3);
            flex-shrink: 0;
        }
        .brand-name {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -.02em;
        }
        .brand-name span { color: var(--blue); }

        /* ── Heading ── */
        .login-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -.03em;
            margin-bottom: .35rem;
        }
        .login-sub {
            font-size: .875rem;
            color: var(--muted);
            margin-bottom: 1.75rem;
            font-weight: 500;
        }

        /* ── Alert ── */
        .login-alert {
            display: flex; align-items: center; gap: .6rem;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: .7rem 1rem;
            font-size: .85rem;
            color: #dc2626;
            font-weight: 500;
            margin-bottom: 1.25rem;
            animation: shake .4s ease;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%,60%  { transform: translateX(-4px); }
            40%,80%  { transform: translateX(4px); }
        }

        /* ── Form fields ── */
        .field-group { margin-bottom: 1.1rem; }
        .field-label {
            display: block;
            font-size: .8rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: .45rem;
        }
        .field-input-wrap { position: relative; }
        .field-icon {
            position: absolute;
            left: 1rem; top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: .9rem;
            pointer-events: none;
            transition: color .2s;
        }
        .field-input {
            width: 100%;
            height: 52px;
            background: var(--input-bg);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 0 1rem 0 2.75rem;
            font-size: .95rem;
            font-family: inherit;
            font-weight: 500;
            color: var(--text);
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
            caret-color: var(--blue);
        }
        .field-input::placeholder { color: #cbd5e1; font-weight: 400; }
        .field-input:focus {
            border-color: var(--blue);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(0,125,254,.1);
        }
        .field-input:focus + .field-icon,
        .field-input-wrap:focus-within .field-icon { color: var(--blue); }
        .field-input.is-invalid {
            border-color: #ef4444;
            box-shadow: 0 0 0 4px rgba(239,68,68,.1);
        }

        /* Password wrapper */
        .pw-wrap { position: relative; padding-bottom: 1.4rem; }
        .pw-toggle {
            position: absolute;
            right: 1rem; top: 50%;
            transform: translateY(-60%);
            background: none; border: none;
            color: #94a3b8; cursor: pointer;
            font-size: .95rem; padding: 0;
            transition: color .2s;
        }
        .pw-toggle:hover { color: var(--blue); }
        #capsLockHint {
            position: absolute; bottom: 0; left: 0;
            font-size: .78rem; color: #d97706; font-weight: 600;
            display: flex; align-items: center; gap: .3rem;
            visibility: hidden; opacity: 0;
            transition: opacity .2s;
        }
        #capsLockHint.caps-visible { visibility: visible; opacity: 1; }

        /* Error hint below field */
        .field-error {
            font-size: .78rem;
            color: #ef4444;
            font-weight: 600;
            margin-top: .35rem;
            display: none;
        }
        .field-error.show { display: block; }

        /* ── Submit button ── */
        .btn-login {
            width: 100%;
            height: 52px;
            background: linear-gradient(135deg, var(--blue) 0%, #0060cc 100%);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: .95rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            letter-spacing: .01em;
            position: relative;
            overflow: hidden;
            transition: transform .15s, box-shadow .15s;
            box-shadow: 0 4px 14px rgba(0,125,254,.35);
            margin-top: .5rem;
        }
        .btn-login:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,125,254,.45);
        }
        .btn-login:active:not(:disabled) { transform: translateY(0); }
        .btn-login:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .btn-login .btn-text { transition: opacity .2s; }
        .btn-login .btn-spinner { display: none; }
        .btn-login.loading .btn-text { opacity: 0; }
        .btn-login.loading .btn-spinner { display: inline-block; }

        /* ── Links ── */
        .login-footer {
            margin-top: 1.5rem;
            text-align: center;
            font-size: .8rem;
            color: var(--muted);
        }
        .login-footer a {
            color: var(--blue);
            font-weight: 600;
            text-decoration: none;
        }
        .login-footer a:hover { text-decoration: underline; }

        /* ── Lock hint ── */
        .lock-hint {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 10px;
            padding: .6rem .9rem;
            font-size: .82rem;
            color: #c2410c;
            font-weight: 600;
            margin-top: .5rem;
            display: flex; align-items: center; gap: .4rem;
        }

        /* Autofill fix */
        .field-input:-webkit-autofill,
        .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text) !important;
            box-shadow: 0 0 0px 1000px var(--input-bg) inset !important;
            border-color: var(--blue) !important;
        }

        @media (max-width: 480px) {
            .login-card { padding: 2rem 1.5rem 1.75rem; border-radius: 20px; }
            .login-title { font-size: 1.4rem; }
        }
    </style>
</head>
<body>

<!-- Background -->
<div class="bg-scene">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>

<!-- Card -->
<div class="login-wrap">
    <div class="login-card">

        <!-- Brand -->
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-building"></i></div>
            <div class="brand-name">Stay<span>Wise</span></div>
        </div>

        <!-- Heading -->
        <div class="login-title">Welcome back 👋</div>
        <div class="login-sub">Sign in to your account to continue</div>

        <!-- Error alert -->
        <?php if (isset($_GET['error'])): ?>
        <div class="login-alert">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($_GET['error']) ?>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form action="login.php" method="POST" id="loginForm" novalidate>
            <?= csrf_input() ?>

            <!-- Username -->
            <div class="field-group">
                <label class="field-label" for="username">Username or Email</label>
                <div class="field-input-wrap">
                    <input
                        type="text"
                        class="field-input"
                        id="username"
                        name="username"
                        placeholder="Enter your username or email"
                        autocapitalize="none"
                        autocomplete="username email"
                        spellcheck="false"
                        value="<?= isset($_SESSION['last_login_identifier']) ? htmlspecialchars($_SESSION['last_login_identifier']) : '' ?>"
                        required
                    >
                    <i class="fas fa-user field-icon"></i>
                </div>
                <div class="field-error" id="usernameError">Please enter a valid username or email.</div>
            </div>

            <!-- Password -->
            <div class="field-group">
                <label class="field-label" for="password">Password</label>
                <div class="pw-wrap">
                    <div class="field-input-wrap">
                        <input
                            type="password"
                            class="field-input"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            style="padding-right:3rem;"
                            required
                        >
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="pw-toggle" tabindex="-1" aria-label="Toggle password visibility" id="pwToggle">
                            <i class="fas fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                    <div class="field-error" id="passwordError">Password is required.</div>
                    <div id="capsLockHint"><i class="fas fa-exclamation-triangle"></i> Caps Lock is ON</div>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-login" id="submitBtn">
                <span class="btn-text">Sign In</span>
                <span class="btn-spinner"><i class="fas fa-circle-notch fa-spin"></i></span>
            </button>

            <div id="lockHintWrap"></div>
        </form>

        <!-- Footer -->
        <div class="login-footer">
            <a href="forgot_password.php"><i class="fas fa-key me-1"></i>Forgot password?</a>
        </div>

    </div>
</div>

<script>
(function () {
    const form       = document.getElementById('loginForm');
    const username   = document.getElementById('username');
    const password   = document.getElementById('password');
    const submitBtn  = document.getElementById('submitBtn');
    const pwToggle   = document.getElementById('pwToggle');
    const pwIcon     = document.getElementById('pwIcon');
    const caps       = document.getElementById('capsLockHint');
    const uErr       = document.getElementById('usernameError');
    const pErr       = document.getElementById('passwordError');
    const lockWrap   = document.getElementById('lockHintWrap');

    // Password toggle
    pwToggle.addEventListener('click', function () {
        const isText = password.type === 'text';
        password.type = isText ? 'password' : 'text';
        pwIcon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
    });

    // Caps lock
    function updateCaps(e) {
        try {
            const on = e.getModifierState && e.getModifierState('CapsLock');
            caps.classList.toggle('caps-visible', on);
        } catch (_) {}
    }
    password.addEventListener('keydown', updateCaps);
    password.addEventListener('keyup', updateCaps);
    password.addEventListener('focus', updateCaps);
    password.addEventListener('blur', () => caps.classList.remove('caps-visible'));

    // Lock countdown
    const params   = new URLSearchParams(window.location.search);
    let remain     = parseInt(params.get('remain') || '0', 10);
    const isLocked = params.get('lock') === '1' && remain > 0;

    if (isLocked) {
        // Hide error alert if locked
        const alertEl = document.querySelector('.login-alert');
        if (alertEl) alertEl.style.display = 'none';
        password.disabled = true;
        submitBtn.disabled = true;
        startCountdown();
    }

    // Show server error inline
    const serverError = params.get('error');
    if (!isLocked && serverError) {
        const alertEl = document.querySelector('.login-alert');
        if (alertEl) alertEl.style.display = 'none';
        pErr.textContent = serverError;
        pErr.classList.add('show');
        password.classList.add('is-invalid');
        try { password.focus(); } catch (_) {}
    }

    function renderLock(s) {
        lockWrap.innerHTML = `<div class="lock-hint"><i class="fas fa-clock"></i> Too many attempts — try again in ${s} second${s === 1 ? '' : 's'}.</div>`;
    }

    function startCountdown() {
        renderLock(remain);
        const iv = setInterval(() => {
            remain = Math.max(0, remain - 1);
            renderLock(remain);
            if (remain <= 0) {
                clearInterval(iv);
                password.disabled = false;
                submitBtn.disabled = false;
                lockWrap.innerHTML = '';
                try {
                    const url = new URL(window.location);
                    url.searchParams.delete('lock');
                    url.searchParams.delete('remain');
                    window.history.replaceState({}, '', url);
                } catch (_) {}
            }
        }, 1000);
    }

    // Validation
    function validateUsername() {
        const v = (username.value || '').trim();
        const ok = /^[A-Za-z0-9_]{3,20}$/.test(v) || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
        username.classList.toggle('is-invalid', !ok);
        uErr.classList.toggle('show', !ok);
        return ok;
    }
    function validatePassword() {
        const ok = password.value.length > 0;
        password.classList.toggle('is-invalid', !ok);
        pErr.classList.toggle('show', !ok);
        return ok;
    }

    username.addEventListener('input', () => { username.classList.remove('is-invalid'); uErr.classList.remove('show'); });
    password.addEventListener('input', () => { password.classList.remove('is-invalid'); pErr.classList.remove('show'); });

    form.addEventListener('submit', function (e) {
        if (isLocked && remain > 0) { e.preventDefault(); return; }
        const ok = validateUsername() & validatePassword();
        if (!ok) { e.preventDefault(); return; }
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
    });
})();
</script>
</body>
</html>
