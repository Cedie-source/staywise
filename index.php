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
    <meta name="theme-color" content="#4ED6C1"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── LIGHT MODE (default) ── */
        :root {
            --teal:        #4ED6C1;
            --teal-dark:   #2cb8a3;
            --teal-deeper: #1a9b87;
            --teal-glow:   rgba(78,214,193,.14);

            --bg:          #f8fafc;
            --card-right:  #ffffff;
            --border:      #e2e8f0;
            --input-bg:    #ffffff;
            --input-bdr:   #d1d5db;

            --text:        #111827;
            --text-2:      #374151;
            --muted:       #6b7280;
            --muted-2:     #94a3b8;

            --shadow:      0 1px 3px rgba(15,23,42,.06), 0 12px 40px rgba(15,23,42,.09);
            --shell-bdr:   #d1faf4;

            --lock-bg:     #fffbeb;
            --lock-bdr:    #fde68a;
            --lock-text:   #92400e;
        }

        /* ── DARK MODE ── */
        body.dark {
            --bg:         #080c14;
            --card-right: #0e1420;
            --border:     rgba(255,255,255,.07);
            --input-bg:   #141b28;
            --input-bdr:  rgba(255,255,255,.1);

            --text:       #f0ede8;
            --text-2:     #d1d5db;
            --muted:      #6b7280;
            --muted-2:    #9ca3af;

            --shadow:     0 40px 80px rgba(0,0,0,.55), 0 4px 16px rgba(0,0,0,.3);
            --shell-bdr:  rgba(78,214,193,.15);

            --lock-bg:    rgba(217,119,6,.08);
            --lock-bdr:   rgba(217,119,6,.25);
            --lock-text:  #fbbf24;
        }

        html, body {
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .25s, color .25s;
        }

        /* page background glows */
        body::before {
            content: '';
            position: fixed; inset: 0; z-index: 0;
            pointer-events: none;
            transition: background .25s;
        }
        body:not(.dark)::before {
            background:
                radial-gradient(ellipse 65% 55% at 100% 0%,   rgba(78,214,193,.13) 0%, transparent 65%),
                radial-gradient(ellipse 45% 45% at 0%   100%, rgba(78,214,193,.09) 0%, transparent 65%);
        }
        body.dark::before {
            background:
                radial-gradient(ellipse 60% 50% at 15% 20%, rgba(0,125,254,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 85% 80%, rgba(78,214,193,.05) 0%, transparent 60%);
        }

        /* ── Theme toggle button ── */
        .theme-toggle {
            position: fixed;
            top: 1.1rem; right: 1.25rem;
            z-index: 100;
            width: 38px; height: 38px;
            border-radius: 50%;
            border: 1.5px solid var(--border);
            background: var(--card-right);
            color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: .85rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            transition: background .2s, border-color .2s, color .2s, transform .15s, box-shadow .2s;
        }
        .theme-toggle:hover {
            color: var(--teal-dark);
            border-color: var(--teal);
            transform: scale(1.08);
            box-shadow: 0 4px 14px rgba(78,214,193,.2);
        }
        .theme-toggle .icon-sun  { display: none; }
        .theme-toggle .icon-moon { display: block; }
        body.dark .theme-toggle .icon-sun  { display: block; }
        body.dark .theme-toggle .icon-moon { display: none; }

        /* ── Card shell ── */
        .login-shell {
            position: relative; z-index: 1;
            width: 100%; max-width: 960px; min-height: 560px;
            margin: 1.5rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-radius: 20px;
            overflow: hidden;
            border: 1.5px solid var(--shell-bdr);
            box-shadow: var(--shadow);
            animation: appear .45s cubic-bezier(.22,1,.36,1) both;
            transition: border-color .25s, box-shadow .25s;
        }
        @keyframes appear {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ══ LEFT — teal branding panel (same in both modes) ══ */
        .panel-left {
            background: linear-gradient(150deg, var(--teal) 0%, var(--teal-dark) 50%, var(--teal-deeper) 100%);
            padding: 2.75rem 2.5rem;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
        }
        .panel-left::before,
        .panel-left::after {
            content: ''; position: absolute; border-radius: 50%;
            background: rgba(255,255,255,.08);
        }
        .panel-left::before { width: 380px; height: 380px; top: -140px; right: -110px; }
        .panel-left::after  { width: 220px; height: 220px; bottom: -80px; left: -60px; }

        .dot-grid {
            position: absolute; bottom: 2.25rem; right: 2.25rem;
            display: grid; grid-template-columns: repeat(5,1fr); gap: 6px; z-index: 1;
        }
        .dot-grid span { width: 4px; height: 4px; border-radius: 50%; background: rgba(255,255,255,.3); display: block; }

        .brand-row { display: flex; align-items: center; gap: .75rem; position: relative; z-index: 1; }
        .brand-icon-box {
            width: 42px; height: 42px;
            background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.35);
            border-radius: 11px; display: flex; align-items: center; justify-content: center;
            font-size: .95rem; color: #fff; backdrop-filter: blur(6px);
        }
        .brand-name { font-size: 1.3rem; font-weight: 800; color: #fff; letter-spacing: -.01em; }

        .panel-headline { position: relative; z-index: 1; }
        .panel-headline h2 {
            font-size: 2.2rem; font-weight: 800; color: #fff;
            line-height: 1.2; letter-spacing: -.025em; margin-bottom: .75rem;
        }
        .panel-headline h2 span { color: rgba(255,255,255,.65); font-weight: 600; font-size: 1.8rem; }
        .panel-headline p { font-size: .85rem; color: rgba(255,255,255,.72); line-height: 1.75; max-width: 250px; }

        .panel-features { position: relative; z-index: 1; display: flex; flex-direction: column; gap: .55rem; }
        .feat-pill {
            display: flex; align-items: center; gap: .65rem;
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18);
            border-radius: 9px; padding: .5rem .8rem;
            font-size: .79rem; color: rgba(255,255,255,.92); font-weight: 500;
        }
        .feat-pill i { width: 13px; text-align: center; color: rgba(255,255,255,.7); font-size: .75rem; }

        /* ══ RIGHT — form panel ══ */
        .panel-right {
            background: var(--card-right);
            padding: 2.75rem 3rem;
            display: flex; flex-direction: column; justify-content: center;
            transition: background .25s;
        }

        .form-eyebrow {
            font-size: .7rem; font-weight: 700; letter-spacing: .1em;
            text-transform: uppercase; color: var(--teal-dark); margin-bottom: .4rem;
        }
        .form-title {
            font-size: 1.75rem; font-weight: 800; color: var(--text);
            letter-spacing: -.03em; margin-bottom: .25rem;
            transition: color .25s;
        }
        .form-sub { font-size: .82rem; color: var(--muted); margin-bottom: 1.85rem; transition: color .25s; }

        /* error alert */
        .login-alert {
            display: flex; align-items: center; gap: .55rem;
            background: #fef2f2; border: 1px solid #fecaca;
            border-radius: 9px; padding: .6rem .85rem;
            font-size: .81rem; color: #dc2626; font-weight: 600;
            margin-bottom: 1.2rem; animation: shake .4s ease;
        }
        body.dark .login-alert {
            background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.2); color: #f87171;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%,60%  { transform: translateX(-4px); }
            40%,80%  { transform: translateX(4px); }
        }

        /* fields */
        .field-wrap { margin-bottom: 1rem; }
        .field-label {
            display: block; font-size: .76rem; font-weight: 600;
            color: var(--muted); margin-bottom: 5px; transition: color .25s;
        }
        .field-inner { position: relative; }
        .field-icon {
            position: absolute; left: .9rem; top: 50%;
            transform: translateY(-50%); color: var(--muted-2);
            font-size: .78rem; pointer-events: none; transition: color .15s;
        }
        .field-input {
            width: 100%; padding: .65rem .9rem .65rem 2.4rem;
            border: 1.5px solid var(--input-bdr); border-radius: 8px;
            font-size: .88rem; color: var(--text); background: var(--input-bg);
            outline: none; font-family: 'Inter', sans-serif;
            transition: border-color .15s, box-shadow .15s, background .25s, color .25s;
        }
        .field-input::placeholder { color: var(--muted-2); }
        .field-input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px var(--teal-glow); }
        .field-input:focus ~ .field-icon { color: var(--teal-dark); }
        .field-input.is-invalid { border-color: #fca5a5; box-shadow: 0 0 0 3px rgba(220,38,38,.07); }
        body.dark .field-input.is-invalid { border-color: rgba(239,68,68,.4); }

        /* autofill — light */
        .field-input:-webkit-autofill,
        .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text) !important;
            box-shadow: 0 0 0 1000px #fff inset, 0 0 0 3px var(--teal-glow) !important;
            border-color: var(--teal) !important;
        }
        /* autofill — dark */
        body.dark .field-input:-webkit-autofill,
        body.dark .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: #f0ede8 !important;
            box-shadow: 0 0 0 1000px #141b28 inset, 0 0 0 3px var(--teal-glow) !important;
            border-color: var(--teal) !important;
        }

        /* password */
        .pw-wrap { position: relative; padding-bottom: 1.2rem; }
        .pw-toggle {
            position: absolute; right: .85rem; top: 50%; transform: translateY(-60%);
            background: none; border: none; color: var(--muted-2); cursor: pointer;
            font-size: .82rem; padding: 0; z-index: 2; transition: color .15s;
        }
        .pw-toggle:hover { color: var(--teal-dark); }

        #capsLockHint {
            position: absolute; bottom: 0; left: 0;
            font-size: .73rem; color: #d97706; font-weight: 600;
            display: flex; align-items: center; gap: .3rem;
            visibility: hidden; opacity: 0; transition: opacity .2s;
        }
        #capsLockHint.caps-visible { visibility: visible; opacity: 1; }

        .field-error { font-size: .73rem; color: #dc2626; font-weight: 600; margin-top: .3rem; display: none; }
        body.dark .field-error { color: #f87171; }
        .field-error.show { display: block; }

        /* submit */
        .btn-signin {
            width: 100%; padding: .68rem 1rem;
            background: var(--teal-dark); border: none; border-radius: 8px;
            color: #fff; font-size: .88rem; font-weight: 600;
            font-family: 'Inter', sans-serif; cursor: pointer;
            position: relative; overflow: hidden;
            transition: background .15s, box-shadow .15s, transform .1s;
            box-shadow: 0 2px 10px rgba(44,184,163,.3); margin-top: .2rem;
        }
        .btn-signin::after {
            content: ''; position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.18), transparent);
            transition: left .4s;
        }
        .btn-signin:hover:not(:disabled)::after { left: 100%; }
        .btn-signin:hover:not(:disabled) {
            background: var(--teal-deeper);
            box-shadow: 0 4px 18px rgba(44,184,163,.38);
            transform: translateY(-1px);
        }
        .btn-signin:active:not(:disabled) { transform: translateY(0); }
        .btn-signin:disabled { opacity: .55; cursor: not-allowed; }
        .btn-signin.loading .btn-text { opacity: 0; }
        .btn-signin .btn-spinner { display: none; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; opacity: 0; }
        .btn-signin.loading .btn-spinner { opacity: 1; }

        /* lock hint */
        .lock-hint {
            display: flex; align-items: center; gap: .4rem;
            background: var(--lock-bg); border: 1px solid var(--lock-bdr);
            border-radius: 8px; padding: .5rem .8rem;
            font-size: .77rem; color: var(--lock-text); font-weight: 600; margin-top: .5rem;
        }

        /* divider */
        .divider { display: flex; align-items: center; gap: .7rem; margin-top: 1.4rem; }
        .divider-line { flex: 1; height: 1px; background: var(--border); transition: background .25s; }
        .divider-text { font-size: .68rem; color: var(--muted-2); letter-spacing: .07em; text-transform: uppercase; font-weight: 600; }

        /* forgot link */
        .form-footer { margin-top: 1.1rem; text-align: center; font-size: .79rem; color: var(--muted); }
        .form-footer a { color: var(--teal-dark); font-weight: 600; text-decoration: none; transition: color .15s; }
        .form-footer a:hover { color: var(--teal-deeper); text-decoration: underline; }

        /* responsive */
        @media (max-width: 680px) {
            .login-shell { grid-template-columns: 1fr; max-width: 420px; }
            .panel-left  { display: none; }
            .panel-right { padding: 2.5rem 1.75rem; }
        }
    </style>
</head>
<body>

<!-- Dark / Light toggle -->
<button class="theme-toggle" id="themeToggle" title="Toggle dark/light mode" aria-label="Toggle theme">
    <i class="fas fa-sun icon-sun"></i>
    <i class="fas fa-moon icon-moon"></i>
</button>

<div class="login-shell">

    <!-- LEFT: teal branding -->
    <div class="panel-left">
        <div class="dot-grid"><?php for($i=0;$i<25;$i++) echo '<span></span>'; ?></div>

        <div class="brand-row">
            <div class="brand-icon-box"><i class="fas fa-building"></i></div>
            <div class="brand-name">StayWise</div>
        </div>

        <div class="panel-headline">
            <h2>Manage your<br>property<span><br>effortlessly.</span></h2>
            <p>A modern platform for landlords and tenants — payments, maintenance, and communication in one place.</p>
        </div>

        <div class="panel-features">
            <div class="feat-pill"><i class="fas fa-shield-alt"></i> Secure &amp; encrypted access</div>
            <div class="feat-pill"><i class="fas fa-bolt"></i> Real-time payment tracking</div>
            <div class="feat-pill"><i class="fas fa-bell"></i> Instant notifications</div>
            <div class="feat-pill"><i class="fas fa-chart-line"></i> Smart financial reports</div>
        </div>
    </div>

    <!-- RIGHT: form -->
    <div class="panel-right">
        <div class="form-eyebrow">Welcome back</div>
        <div class="form-title">Sign in</div>
        <div class="form-sub">Enter your credentials to continue</div>

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
                    <input type="text" class="field-input" id="username" name="username"
                        placeholder="your@email.com"
                        autocapitalize="none" autocomplete="username email" spellcheck="false"
                        value="<?= isset($_SESSION['last_login_identifier']) ? htmlspecialchars($_SESSION['last_login_identifier']) : '' ?>"
                        required>
                    <i class="fas fa-user field-icon"></i>
                </div>
                <div class="field-error" id="usernameError">Enter a valid username or email.</div>
            </div>

            <div class="field-wrap">
                <label class="field-label" for="password">Password</label>
                <div class="pw-wrap">
                    <div class="field-inner">
                        <input type="password" class="field-input" id="password" name="password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            style="padding-right:2.6rem;"
                            required>
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="pw-toggle" tabindex="-1" id="pwToggle" aria-label="Toggle password">
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
    /* ── Theme toggle ── */
    const body   = document.body;
    const toggle = document.getElementById('themeToggle');

    // Load saved preference; default = light
    if (localStorage.getItem('sw_theme') === 'dark') {
        body.classList.add('dark');
    }

    toggle.addEventListener('click', () => {
        const isDark = body.classList.toggle('dark');
        localStorage.setItem('sw_theme', isDark ? 'dark' : 'light');
    });

    /* ── Form logic ── */
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
        const v  = (username.value || '').trim();
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
