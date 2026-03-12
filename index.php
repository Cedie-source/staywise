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
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --gold: #c9a84c;
            --gold-light: #e2c97e;
            --gold-dim: rgba(201,168,76,.15);
            --blue: #007DFE;
            --teal: #4ED6C1;
            --bg: #080c14;
            --surface: #0e1420;
            --surface-2: #141b28;
            --border: rgba(255,255,255,.07);
            --border-gold: rgba(201,168,76,.25);
            --text: #f0ede8;
            --muted: #6b7280;
            --muted-2: #9ca3af;
        }

        html, body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* ── Atmospheric background ── */
        .bg-scene {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        /* Grid texture */
        .bg-scene::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.015) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.015) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black, transparent);
        }

        /* Glow blobs */
        .bg-scene::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 60% 50% at 15% 20%, rgba(0,125,254,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 85% 80%, rgba(78,214,193,.05) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 50% 50%, rgba(201,168,76,.03) 0%, transparent 70%);
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            animation: float 16s ease-in-out infinite alternate;
        }
        .orb-1 { width: 600px; height: 600px; background: rgba(0,125,254,.05); top: -200px; left: -150px; animation-delay: 0s; }
        .orb-2 { width: 500px; height: 500px; background: rgba(201,168,76,.04); bottom: -150px; right: -100px; animation-delay: -6s; }
        .orb-3 { width: 300px; height: 300px; background: rgba(78,214,193,.04); top: 40%; left: 55%; animation-delay: -12s; }

        @keyframes float {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(25px,15px) scale(1.06); }
        }

        /* ── Split layout ── */
        .login-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 960px;
            min-height: 560px;
            margin: 1rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-radius: 28px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow:
                0 0 0 1px rgba(201,168,76,.08),
                0 40px 80px rgba(0,0,0,.6),
                0 0 120px rgba(0,125,254,.06);
            animation: appear .6s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes appear {
            from { opacity: 0; transform: translateY(32px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Left panel — branding ── */
        .panel-left {
            background: linear-gradient(160deg, #0d1422 0%, #080c14 100%);
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            border-right: 1px solid var(--border);
        }
        .panel-left::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 100% 60% at 0% 0%, rgba(201,168,76,.06) 0%, transparent 60%),
                radial-gradient(ellipse 80% 80% at 100% 100%, rgba(0,125,254,.05) 0%, transparent 60%);
        }

        /* Decorative circles */
        .deco-ring {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(201,168,76,.08);
        }
        .deco-ring-1 { width: 350px; height: 350px; bottom: -120px; right: -120px; }
        .deco-ring-2 { width: 250px; height: 250px; bottom: -70px; right: -70px; border-color: rgba(201,168,76,.12); }
        .deco-ring-3 { width: 150px; height: 150px; bottom: -20px; right: -20px; border-color: rgba(201,168,76,.18); }

        .brand-wrap { position: relative; z-index: 1; }
        .brand-logo {
            display: flex; align-items: center; gap: .75rem;
            margin-bottom: 2.5rem;
        }
        .brand-icon {
            width: 46px; height: 46px;
            background: linear-gradient(135deg, #1a2540, #0e1830);
            border: 1px solid var(--border-gold);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            color: var(--gold);
            box-shadow: 0 0 20px rgba(201,168,76,.12), inset 0 1px 0 rgba(255,255,255,.05);
        }
        .brand-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: .02em;
        }
        .brand-name em { color: var(--gold); font-style: normal; }

        .panel-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.6rem;
            font-weight: 600;
            line-height: 1.15;
            color: var(--text);
            margin-bottom: 1rem;
        }
        .panel-heading span {
            display: block;
            background: linear-gradient(90deg, var(--gold), var(--gold-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .panel-desc {
            font-size: .875rem;
            color: var(--muted-2);
            line-height: 1.7;
            max-width: 260px;
        }

        .panel-footer { position: relative; z-index: 1; }
        .feature-list { list-style: none; display: flex; flex-direction: column; gap: .65rem; }
        .feature-list li {
            display: flex; align-items: center; gap: .65rem;
            font-size: .8rem; color: var(--muted-2);
        }
        .feature-list li i {
            width: 24px; height: 24px;
            background: var(--gold-dim);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            color: var(--gold);
            font-size: .65rem;
            flex-shrink: 0;
        }

        /* ── Right panel — form ── */
        .panel-right {
            background: var(--surface);
            padding: 3rem 2.75rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.9rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: .3rem;
            letter-spacing: -.01em;
        }
        .form-sub {
            font-size: .83rem;
            color: var(--muted);
            margin-bottom: 2rem;
            font-weight: 400;
        }

        /* Alert */
        .login-alert {
            display: flex; align-items: center; gap: .6rem;
            background: rgba(239,68,68,.08);
            border: 1px solid rgba(239,68,68,.2);
            border-radius: 10px;
            padding: .65rem .9rem;
            font-size: .82rem;
            color: #f87171;
            font-weight: 500;
            margin-bottom: 1.25rem;
            animation: shake .4s ease;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%,60%  { transform: translateX(-4px); }
            40%,80%  { transform: translateX(4px); }
        }

        /* Fields */
        .field-wrap { margin-bottom: 1.1rem; }
        .field-label {
            display: block;
            font-size: .72rem;
            font-weight: 600;
            color: var(--muted-2);
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: .5rem;
        }
        .field-inner { position: relative; }
        .field-icon {
            position: absolute;
            left: .9rem; top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: .8rem;
            pointer-events: none;
            transition: color .2s;
            z-index: 1;
        }
        .field-input {
            width: 100%;
            height: 50px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0 1rem 0 2.5rem;
            font-size: .9rem;
            font-family: 'DM Sans', sans-serif;
            font-weight: 400;
            color: var(--text);
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            caret-color: var(--gold);
        }
        .field-input::placeholder { color: #374151; }
        .field-input:focus {
            border-color: var(--border-gold);
            box-shadow: 0 0 0 3px rgba(201,168,76,.08);
        }
        .field-input:focus ~ .field-icon { color: var(--gold); }
        .field-input.is-invalid {
            border-color: rgba(239,68,68,.4);
            box-shadow: 0 0 0 3px rgba(239,68,68,.08);
        }

        /* Autofill */
        .field-input:-webkit-autofill,
        .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text) !important;
            box-shadow: 0 0 0px 1000px var(--surface-2) inset, 0 0 0 3px rgba(201,168,76,.08) !important;
            border-color: var(--border-gold) !important;
        }

        /* Password */
        .pw-wrap { position: relative; padding-bottom: 1.3rem; }
        .pw-toggle {
            position: absolute;
            right: .9rem; top: 50%;
            transform: translateY(-60%);
            background: none; border: none;
            color: var(--muted); cursor: pointer;
            font-size: .85rem; padding: 0; z-index: 2;
            transition: color .2s;
        }
        .pw-toggle:hover { color: var(--gold); }

        #capsLockHint {
            position: absolute; bottom: 0; left: 0;
            font-size: .75rem; color: #d97706; font-weight: 600;
            display: flex; align-items: center; gap: .3rem;
            visibility: hidden; opacity: 0; transition: opacity .2s;
        }
        #capsLockHint.caps-visible { visibility: visible; opacity: 1; }
        .field-error { font-size: .75rem; color: #f87171; font-weight: 500; margin-top: .3rem; display: none; }
        .field-error.show { display: block; }

        /* Submit */
        .btn-signin {
            width: 100%;
            height: 50px;
            background: linear-gradient(135deg, var(--gold) 0%, #a8832e 100%);
            border: none;
            border-radius: 10px;
            color: #0a0d14;
            font-size: .9rem;
            font-weight: 700;
            font-family: 'DM Sans', sans-serif;
            letter-spacing: .04em;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform .15s, box-shadow .15s, opacity .2s;
            box-shadow: 0 4px 20px rgba(201,168,76,.25);
            margin-top: .25rem;
        }
        .btn-signin::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.15), transparent);
            transition: left .4s ease;
        }
        .btn-signin:hover:not(:disabled)::before { left: 100%; }
        .btn-signin:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 8px 28px rgba(201,168,76,.35);
        }
        .btn-signin:active:not(:disabled) { transform: translateY(0); }
        .btn-signin:disabled { opacity: .5; cursor: not-allowed; }
        .btn-signin.loading .btn-text { opacity: 0; }
        .btn-signin .btn-spinner { display: none; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; opacity: 0; }
        .btn-signin.loading .btn-spinner { opacity: 1; }

        /* Links */
        .form-footer {
            margin-top: 1.25rem;
            text-align: center;
            font-size: .78rem;
            color: var(--muted);
        }
        .form-footer a {
            color: var(--gold);
            font-weight: 600;
            text-decoration: none;
            transition: color .2s;
        }
        .form-footer a:hover { color: var(--gold-light); }

        /* Lock hint */
        .lock-hint {
            background: rgba(217,119,6,.08);
            border: 1px solid rgba(217,119,6,.2);
            border-radius: 8px;
            padding: .55rem .85rem;
            font-size: .78rem;
            color: #fbbf24;
            font-weight: 500;
            margin-top: .5rem;
            display: flex; align-items: center; gap: .4rem;
        }

        /* Divider */
        .divider {
            display: flex; align-items: center; gap: .75rem;
            margin: 1.5rem 0 0;
        }
        .divider-line { flex: 1; height: 1px; background: var(--border); }
        .divider-text { font-size: .7rem; color: var(--muted); letter-spacing: .06em; text-transform: uppercase; }

        /* Responsive */
        @media (max-width: 700px) {
            .login-shell { grid-template-columns: 1fr; max-width: 420px; }
            .panel-left { display: none; }
            .panel-right { padding: 2.5rem 2rem; }
        }
    </style>
</head>
<body>

<div class="bg-scene">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>

<div class="login-shell">

    <!-- LEFT: Branding -->
    <div class="panel-left">
        <div class="deco-ring deco-ring-1"></div>
        <div class="deco-ring deco-ring-2"></div>
        <div class="deco-ring deco-ring-3"></div>

        <div class="brand-wrap">
            <div class="brand-logo">
                <div class="brand-icon"><i class="fas fa-building"></i></div>
                <div class="brand-name">Stay<em>Wise</em></div>
            </div>
            <div class="panel-heading">
                Manage your<br>property
                <span>with elegance.</span>
            </div>
            <p class="panel-desc">
                A premium platform for landlords and tenants to handle payments, maintenance, and communication seamlessly.
            </p>
        </div>

        <div class="panel-footer">
            <ul class="feature-list">
                <li><i class="fas fa-shield-alt"></i> Secure & encrypted access</li>
                <li><i class="fas fa-bolt"></i> Real-time payment tracking</li>
                <li><i class="fas fa-bell"></i> Instant notifications</li>
                <li><i class="fas fa-chart-line"></i> Smart financial reports</li>
            </ul>
        </div>
    </div>

    <!-- RIGHT: Form -->
    <div class="panel-right">
        <div class="form-heading">Sign in</div>
        <div class="form-sub">Enter your credentials to access your account</div>

        <?php if (isset($_GET['error'])): ?>
        <div class="login-alert">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($_GET['error']) ?>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" id="loginForm" novalidate>
            <?= csrf_input() ?>

            <div class="field-wrap">
                <label class="field-label" for="username">Username or Email</label>
                <div class="field-inner">
                    <input
                        type="text"
                        class="field-input"
                        id="username"
                        name="username"
                        placeholder="your@email.com"
                        autocapitalize="none"
                        autocomplete="username email"
                        spellcheck="false"
                        value="<?= isset($_SESSION['last_login_identifier']) ? htmlspecialchars($_SESSION['last_login_identifier']) : '' ?>"
                        required
                    >
                    <i class="fas fa-user field-icon"></i>
                </div>
                <div class="field-error" id="usernameError">Enter a valid username or email.</div>
            </div>

            <div class="field-wrap">
                <label class="field-label" for="password">Password</label>
                <div class="pw-wrap">
                    <div class="field-inner">
                        <input
                            type="password"
                            class="field-input"
                            id="password"
                            name="password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            style="padding-right:2.75rem;"
                            required
                        >
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="pw-toggle" tabindex="-1" id="pwToggle" aria-label="Toggle">
                            <i class="fas fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                    <div class="field-error" id="passwordError">Password is required.</div>
                    <div id="capsLockHint"><i class="fas fa-exclamation-triangle"></i> Caps Lock is ON</div>
                </div>
            </div>

            <button type="submit" class="btn-signin" id="submitBtn">
                <span class="btn-text">Sign In</span>
                <span class="btn-spinner"><i class="fas fa-circle-notch fa-spin"></i></span>
            </button>

            <div id="lockHintWrap"></div>
        </form>

        <div class="divider">
            <div class="divider-line"></div>
            <div class="divider-text">Secure access</div>
            <div class="divider-line"></div>
        </div>

        <div class="form-footer">
            <a href="forgot_password.php"><i class="fas fa-key me-1"></i>Forgot your password?</a>
        </div>
    </div>
</div>

<script>
(function () {
    const form      = document.getElementById('loginForm');
    const username  = document.getElementById('username');
    const password  = document.getElementById('password');
    const submitBtn = document.getElementById('submitBtn');
    const pwToggle  = document.getElementById('pwToggle');
    const pwIcon    = document.getElementById('pwIcon');
    const caps      = document.getElementById('capsLockHint');
    const uErr      = document.getElementById('usernameError');
    const pErr      = document.getElementById('passwordError');
    const lockWrap  = document.getElementById('lockHintWrap');

    pwToggle.addEventListener('click', () => {
        const isText = password.type === 'text';
        password.type = isText ? 'password' : 'text';
        pwIcon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
    });

    function updateCaps(e) {
        try { caps.classList.toggle('caps-visible', e.getModifierState('CapsLock')); } catch (_) {}
    }
    password.addEventListener('keydown', updateCaps);
    password.addEventListener('keyup', updateCaps);
    password.addEventListener('focus', updateCaps);
    password.addEventListener('blur', () => caps.classList.remove('caps-visible'));

    const params   = new URLSearchParams(window.location.search);
    let remain     = parseInt(params.get('remain') || '0', 10);
    const isLocked = params.get('lock') === '1' && remain > 0;

    if (isLocked) {
        const alertEl = document.querySelector('.login-alert');
        if (alertEl) alertEl.style.display = 'none';
        password.disabled = true;
        submitBtn.disabled = true;
        startCountdown();
    }

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
        lockWrap.innerHTML = `<div class="lock-hint"><i class="fas fa-clock"></i> Too many attempts — retry in ${s}s</div>`;
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
