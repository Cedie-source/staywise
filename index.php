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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:      #007DFE;
            --blue-dark: #0060cc;
            --blue-glow: rgba(0,125,254,.18);
            --teal:      #4ED6C1;
            --teal-dim:  rgba(78,214,193,.12);
            --bg:        #111418;
            --surface:   #1a1f26;
            --surface-2: #1f2530;
            --border:    rgba(255,255,255,.07);
            --border-hi: rgba(0,125,254,.3);
            --text:      #e7e7ee;
            --muted:     #6b7280;
            --muted-2:   #9ca3af;
        }

        html, body {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* ── Background ── */
        .bg-scene { position: fixed; inset: 0; z-index: 0; overflow: hidden; }

        /* Subtle grid */
        .bg-scene::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.018) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.018) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: radial-gradient(ellipse 90% 90% at 50% 50%, black 20%, transparent 80%);
        }

        /* Ambient glow */
        .bg-scene::after {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(ellipse 55% 55% at 10% 15%, rgba(0,125,254,.09) 0%, transparent 65%),
                radial-gradient(ellipse 45% 55% at 90% 85%, rgba(78,214,193,.07) 0%, transparent 65%),
                radial-gradient(ellipse 35% 35% at 55% 45%, rgba(0,125,254,.04) 0%, transparent 70%);
        }

        /* Floating orbs */
        .orb {
            position: absolute; border-radius: 50%;
            filter: blur(90px); animation: drift 18s ease-in-out infinite alternate;
        }
        .orb-1 { width: 550px; height: 550px; background: rgba(0,125,254,.06); top: -180px; left: -130px; animation-delay: 0s; }
        .orb-2 { width: 450px; height: 450px; background: rgba(78,214,193,.05); bottom: -140px; right: -100px; animation-delay: -7s; }
        .orb-3 { width: 280px; height: 280px; background: rgba(0,125,254,.04); top: 45%; left: 58%; animation-delay: -14s; }
        @keyframes drift {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(20px,14px) scale(1.05); }
        }

        /* ── Split layout ── */
        .shell {
            position: relative; z-index: 1;
            width: 100%; max-width: 900px;
            margin: 1rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow:
                0 0 0 1px rgba(0,125,254,.08),
                0 32px 80px rgba(0,0,0,.7),
                0 0 100px rgba(0,125,254,.05);
            animation: appear .55s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes appear {
            from { opacity: 0; transform: translateY(28px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Left panel ── */
        .panel-left {
            background: linear-gradient(160deg, #151b24 0%, #111418 100%);
            padding: 2.75rem 2.25rem;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
            border-right: 1px solid var(--border);
        }
        .panel-left::before {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(ellipse 90% 50% at 0% 0%, rgba(0,125,254,.06) 0%, transparent 60%),
                radial-gradient(ellipse 70% 70% at 100% 100%, rgba(78,214,193,.04) 0%, transparent 60%);
        }

        /* Decorative rings */
        .ring {
            position: absolute; border-radius: 50%;
            border: 1px solid rgba(0,125,254,.08);
        }
        .ring-1 { width: 320px; height: 320px; bottom: -110px; right: -110px; }
        .ring-2 { width: 220px; height: 220px; bottom: -60px; right: -60px; border-color: rgba(78,214,193,.08); }
        .ring-3 { width: 120px; height: 120px; bottom: -10px; right: -10px; border-color: rgba(78,214,193,.14); }

        .left-top { position: relative; z-index: 1; }

        /* Brand */
        .brand { display: flex; align-items: center; gap: .7rem; margin-bottom: 2.25rem; }
        .brand-icon {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--blue), #0050bb);
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: #fff;
            box-shadow: 0 4px 16px rgba(0,125,254,.35);
        }
        .brand-name { font-size: 1.25rem; font-weight: 800; color: var(--text); letter-spacing: -.02em; }
        .brand-name em { color: var(--teal); font-style: normal; }

        .panel-headline {
            font-size: 2rem; font-weight: 800; line-height: 1.2;
            color: var(--text); margin-bottom: .85rem; letter-spacing: -.03em;
        }
        .panel-headline span {
            display: block;
            background: linear-gradient(90deg, var(--blue), var(--teal));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .panel-desc { font-size: .82rem; color: var(--muted-2); line-height: 1.7; max-width: 240px; font-weight: 300; }

        /* Feature list */
        .left-bottom { position: relative; z-index: 1; }
        .features { list-style: none; display: flex; flex-direction: column; gap: .55rem; }
        .features li {
            display: flex; align-items: center; gap: .6rem;
            font-size: .78rem; color: var(--muted-2); font-weight: 400;
        }
        .feat-icon {
            width: 26px; height: 26px; border-radius: 7px;
            display: flex; align-items: center; justify-content: center;
            font-size: .65rem; flex-shrink: 0;
        }
        .feat-icon.blue { background: rgba(0,125,254,.15); color: var(--blue); }
        .feat-icon.teal { background: rgba(78,214,193,.12); color: var(--teal); }

        /* ── Right panel (form) ── */
        .panel-right {
            background: var(--surface);
            padding: 2.75rem 2.5rem;
            display: flex; flex-direction: column; justify-content: center;
        }

        /* Top accent bar */
        .panel-right::before {
            content: '';
            position: absolute; /* not used, using card::before instead */
        }

        .form-title { font-size: 1.5rem; font-weight: 800; color: var(--text); margin-bottom: .25rem; letter-spacing: -.025em; }
        .form-sub { font-size: .8rem; color: var(--muted); margin-bottom: 1.75rem; font-weight: 400; }

        /* Alert */
        .login-alert {
            display: flex; align-items: center; gap: .6rem;
            background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2);
            border-radius: 10px; padding: .6rem .85rem;
            font-size: .8rem; color: #f87171; font-weight: 500;
            margin-bottom: 1.2rem; animation: shake .4s ease;
        }
        @keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-4px)} 40%,80%{transform:translateX(4px)} }

        /* Fields */
        .field { margin-bottom: 1rem; }
        .field-label {
            display: block; font-size: .7rem; font-weight: 700;
            color: var(--muted-2); letter-spacing: .08em; text-transform: uppercase; margin-bottom: .45rem;
        }
        .field-inner { position: relative; }
        .field-icon {
            position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
            color: var(--muted); font-size: .78rem; pointer-events: none; transition: color .2s;
        }
        .field-input {
            width: 100%; height: 48px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0 1rem 0 2.4rem;
            font-size: .88rem; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 400;
            color: var(--text); outline: none; caret-color: var(--teal);
            transition: border-color .2s, box-shadow .2s;
        }
        .field-input::placeholder { color: #2e3542; }
        .field-input:focus {
            border-color: var(--border-hi);
            box-shadow: 0 0 0 3px var(--blue-glow);
            background: #212936;
        }
        .field-input:focus ~ .field-icon { color: var(--blue); }
        .field-input.is-invalid { border-color: rgba(239,68,68,.4); box-shadow: 0 0 0 3px rgba(239,68,68,.08); }

        /* Autofill */
        .field-input:-webkit-autofill,
        .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text) !important;
            box-shadow: 0 0 0px 1000px #1f2530 inset, 0 0 0 3px var(--blue-glow) !important;
            border-color: var(--border-hi) !important;
        }

        /* Password */
        .pw-wrap { position: relative; padding-bottom: 1.3rem; }
        .pw-toggle {
            position: absolute; right: .85rem; top: 50%; transform: translateY(-60%);
            background: none; border: none; color: var(--muted);
            cursor: pointer; font-size: .82rem; padding: 0; z-index: 2; transition: color .2s;
        }
        .pw-toggle:hover { color: var(--teal); }
        #capsLockHint {
            position: absolute; bottom: 0; left: 0;
            font-size: .72rem; color: #f59e0b; font-weight: 600;
            display: flex; align-items: center; gap: .3rem;
            visibility: hidden; opacity: 0; transition: opacity .2s;
        }
        #capsLockHint.caps-visible { visibility: visible; opacity: 1; }
        .field-error { font-size: .72rem; color: #f87171; font-weight: 500; margin-top: .3rem; display: none; }
        .field-error.show { display: block; }

        /* Button */
        .btn-login {
            width: 100%; height: 48px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
            border: none; border-radius: 10px;
            color: #fff; font-size: .88rem; font-weight: 700;
            font-family: 'Plus Jakarta Sans', sans-serif;
            cursor: pointer; letter-spacing: .02em; position: relative; overflow: hidden;
            transition: transform .15s, box-shadow .15s;
            box-shadow: 0 4px 18px rgba(0,125,254,.3);
            margin-top: .25rem;
        }
        .btn-login::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.1), transparent);
            transition: left .45s;
        }
        .btn-login:hover:not(:disabled)::before { left: 100%; }
        .btn-login:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(0,125,254,.4);
        }
        .btn-login:active:not(:disabled) { transform: translateY(0); }
        .btn-login:disabled { opacity: .5; cursor: not-allowed; }
        .btn-login.loading .btn-text { opacity: 0; }
        .btn-login .btn-spinner { display: none; position: absolute; inset: 0; align-items: center; justify-content: center; }
        .btn-login.loading .btn-spinner { display: flex; }

        /* Divider */
        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.4rem 0 0; }
        .divider-line { flex: 1; height: 1px; background: var(--border); }
        .divider-text { font-size: .68rem; color: var(--muted); letter-spacing: .06em; text-transform: uppercase; white-space: nowrap; }

        /* Footer */
        .form-footer { margin-top: .9rem; text-align: center; font-size: .75rem; color: var(--muted); }
        .form-footer a { color: var(--teal); font-weight: 600; text-decoration: none; transition: color .2s; }
        .form-footer a:hover { color: #7ee8db; }

        /* Lock hint */
        .lock-hint {
            display: flex; align-items: center; gap: .4rem;
            background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.2);
            border-radius: 8px; padding: .5rem .8rem;
            font-size: .76rem; color: #fbbf24; font-weight: 500; margin-top: .5rem;
        }

        @media (max-width: 680px) {
            .shell { grid-template-columns: 1fr; max-width: 400px; }
            .panel-left { display: none; }
            .panel-right { padding: 2.25rem 1.75rem; }
        }
    </style>
</head>
<body>

<div class="bg-scene">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>

<div class="shell">

    <!-- LEFT -->
    <div class="panel-left">
        <div class="ring ring-1"></div>
        <div class="ring ring-2"></div>
        <div class="ring ring-3"></div>

        <div class="left-top">
            <div class="brand">
                <div class="brand-icon"><i class="fas fa-building"></i></div>
                <div class="brand-name">Stay<em>Wise</em></div>
            </div>
            <div class="panel-headline">
                Smart property<br>management
                <span>made simple.</span>
            </div>
            <p class="panel-desc">Track payments, manage tenants and handle everything from one clean dashboard.</p>
        </div>

        <div class="left-bottom">
            <ul class="features">
                <li><div class="feat-icon blue"><i class="fas fa-shield-alt"></i></div> Secure & encrypted access</li>
                <li><div class="feat-icon teal"><i class="fas fa-bolt"></i></div> Real-time payment tracking</li>
                <li><div class="feat-icon blue"><i class="fas fa-bell"></i></div> Instant notifications</li>
                <li><div class="feat-icon teal"><i class="fas fa-chart-line"></i></div> Financial reports & history</li>
            </ul>
        </div>
    </div>

    <!-- RIGHT -->
    <div class="panel-right">
        <div class="form-title">Welcome back 👋</div>
        <div class="form-sub">Sign in to your StayWise account</div>

        <?php if (isset($_GET['error'])): ?>
        <div class="login-alert">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($_GET['error']) ?>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" id="loginForm" novalidate>
            <?= csrf_input() ?>

            <div class="field">
                <label class="field-label" for="username">Username or Email</label>
                <div class="field-inner">
                    <input type="text" class="field-input" id="username" name="username"
                           placeholder="you@email.com"
                           autocapitalize="none" autocomplete="username email" spellcheck="false"
                           value="<?= isset($_SESSION['last_login_identifier']) ? htmlspecialchars($_SESSION['last_login_identifier']) : '' ?>"
                           required>
                    <i class="fas fa-user field-icon"></i>
                </div>
                <div class="field-error" id="usernameError">Enter a valid username or email.</div>
            </div>

            <div class="field">
                <label class="field-label" for="password">Password</label>
                <div class="pw-wrap">
                    <div class="field-inner">
                        <input type="password" class="field-input" id="password" name="password"
                               placeholder="••••••••" autocomplete="current-password"
                               style="padding-right:2.75rem;" required>
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="pw-toggle" tabindex="-1" id="pwToggle">
                            <i class="fas fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                    <div class="field-error" id="passwordError">Password is required.</div>
                    <div id="capsLockHint"><i class="fas fa-exclamation-triangle"></i> Caps Lock is ON</div>
                </div>
            </div>

            <button type="submit" class="btn-login" id="submitBtn">
                <span class="btn-text">Sign In</span>
                <span class="btn-spinner"><i class="fas fa-circle-notch fa-spin"></i></span>
            </button>

            <div id="lockHintWrap"></div>
        </form>

        <div class="divider">
            <div class="divider-line"></div>
            <div class="divider-text">secure portal</div>
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
    password.addEventListener('keyup',   updateCaps);
    password.addEventListener('focus',   updateCaps);
    password.addEventListener('blur',    () => caps.classList.remove('caps-visible'));

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
