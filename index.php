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

        /* ═══════════════════════════════════
           DARK MODE (default — your original)
        ═══════════════════════════════════ */
        :root {
            --gold:        #c9a84c;
            --gold-light:  #e2c97e;
            --gold-dim:    rgba(201,168,76,.15);
            --blue:        #007DFE;
            --teal:        #4ED6C1;

            --bg:          #080c14;
            --surface:     #0e1420;
            --surface-2:   #141b28;
            --border:      rgba(255,255,255,.07);
            --border-gold: rgba(201,168,76,.25);
            --text:        #f0ede8;
            --muted:       #6b7280;
            --muted-2:     #9ca3af;

            --orb1-bg:     rgba(0,125,254,.05);
            --orb2-bg:     rgba(201,168,76,.04);
            --orb3-bg:     rgba(78,214,193,.04);

            --panel-l-bg:  linear-gradient(160deg, #0d1422 0%, #080c14 100%);
            --panel-r-bg:  #0e1420;
            --ring-color:  rgba(201,168,76,.08);
            --ring-color2: rgba(201,168,76,.12);
            --ring-color3: rgba(201,168,76,.18);

            --grid-line:   rgba(255,255,255,.015);
            --btn-color:   #0a0d14;
            --placeholder: #374151;

            --err-bg:      rgba(239,68,68,.08);
            --err-bdr:     rgba(239,68,68,.2);
            --err-text:    #f87171;
            --lock-bg:     rgba(217,119,6,.08);
            --lock-bdr:    rgba(217,119,6,.2);
            --lock-text:   #fbbf24;

            --accent:      var(--gold);
            --accent-glow: rgba(201,168,76,.08);
            --accent-shimmer: rgba(255,255,255,.15);
            --btn-shadow:  rgba(201,168,76,.25);
            --btn-hover-shadow: rgba(201,168,76,.35);
            --caret:       var(--gold);
            --focus-ring:  rgba(201,168,76,.08);
            --focus-bdr:   var(--border-gold);
        }

        /* ═══════════════════════════════════
           LIGHT MODE overrides
        ═══════════════════════════════════ */
        body.light {
            --bg:          #f0f4ff;
            --surface:     #ffffff;
            --surface-2:   #f5f7ff;
            --border:      rgba(99,102,241,.1);
            --border-gold: rgba(99,102,241,.35);
            --text:        #1e1b4b;
            --muted:       #6366f1;
            --muted-2:     #818cf8;

            --orb1-bg:     rgba(99,102,241,.07);
            --orb2-bg:     rgba(139,92,246,.05);
            --orb3-bg:     rgba(78,214,193,.04);

            --panel-l-bg:  linear-gradient(160deg, #ede9fe 0%, #e0e7ff 100%);
            --panel-r-bg:  #ffffff;
            --ring-color:  rgba(99,102,241,.1);
            --ring-color2: rgba(99,102,241,.15);
            --ring-color3: rgba(99,102,241,.22);

            --grid-line:   rgba(99,102,241,.04);
            --btn-color:   #ffffff;
            --placeholder: #a5b4fc;

            --err-bg:      rgba(239,68,68,.06);
            --err-bdr:     rgba(239,68,68,.18);
            --err-text:    #dc2626;
            --lock-bg:     rgba(217,119,6,.06);
            --lock-bdr:    rgba(217,119,6,.18);
            --lock-text:   #92400e;

            --gold:        #6366f1;
            --gold-light:  #818cf8;
            --gold-dim:    rgba(99,102,241,.12);

            --accent:      #6366f1;
            --accent-glow: rgba(99,102,241,.08);
            --accent-shimmer: rgba(255,255,255,.25);
            --btn-shadow:  rgba(99,102,241,.28);
            --btn-hover-shadow: rgba(99,102,241,.4);
            --caret:       #6366f1;
            --focus-ring:  rgba(99,102,241,.1);
            --focus-bdr:   rgba(99,102,241,.4);
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
            transition: background .3s, color .3s;
        }

        /* ── Background scene ── */
        .bg-scene { position: fixed; inset: 0; z-index: 0; }
        .bg-scene::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(var(--grid-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black, transparent);
        }
        .bg-scene::after {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(ellipse 60% 50% at 15% 20%, rgba(0,125,254,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 85% 80%, rgba(78,214,193,.05) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 50% 50%, rgba(201,168,76,.03) 0%, transparent 70%);
        }
        body.light .bg-scene::after {
            background:
                radial-gradient(ellipse 60% 50% at 15% 20%, rgba(99,102,241,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 85% 80%, rgba(139,92,246,.05) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 50% 50%, rgba(99,102,241,.04) 0%, transparent 70%);
        }

        .orb { position: absolute; border-radius: 50%; filter: blur(80px); animation: float 16s ease-in-out infinite alternate; }
        .orb-1 { width: 600px; height: 600px; background: var(--orb1-bg); top: -200px; left: -150px; animation-delay: 0s; }
        .orb-2 { width: 500px; height: 500px; background: var(--orb2-bg); bottom: -150px; right: -100px; animation-delay: -6s; }
        .orb-3 { width: 300px; height: 300px; background: var(--orb3-bg); top: 40%; left: 55%; animation-delay: -12s; }
        @keyframes float {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(25px,15px) scale(1.06); }
        }

        /* ── Theme toggle ── */
        .theme-toggle {
            position: fixed; top: 1.25rem; right: 1.5rem; z-index: 200;
            width: 40px; height: 40px; border-radius: 12px;
            border: 1px solid var(--border-gold);
            background: var(--surface-2);
            color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: .88rem;
            box-shadow: 0 2px 12px var(--accent-glow);
            transition: all .25s;
        }
        .theme-toggle:hover {
            box-shadow: 0 4px 20px var(--btn-shadow);
            transform: translateY(-1px) scale(1.05);
        }
        .theme-toggle .i-sun  { display: none; }
        .theme-toggle .i-moon { display: block; }
        body.light .theme-toggle .i-sun  { display: block; }
        body.light .theme-toggle .i-moon { display: none; }

        /* ── Shell ── */
        .login-shell {
            position: relative; z-index: 1;
            width: 100%; max-width: 960px; min-height: 560px;
            margin: 1rem;
            display: grid; grid-template-columns: 1fr 1fr;
            border-radius: 28px; overflow: hidden;
            border: 1px solid var(--border);
            box-shadow:
                0 0 0 1px var(--accent-glow),
                0 40px 80px rgba(0,0,0,.18),
                0 0 120px var(--accent-glow);
            animation: appear .6s cubic-bezier(.22,1,.36,1) both;
            transition: border-color .3s, box-shadow .3s;
        }
        body.light .login-shell {
            box-shadow:
                0 0 0 1px rgba(99,102,241,.1),
                0 24px 60px rgba(99,102,241,.1),
                0 4px 16px rgba(99,102,241,.06);
        }
        @keyframes appear {
            from { opacity: 0; transform: translateY(32px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Left panel ── */
        .panel-left {
            background: var(--panel-l-bg);
            padding: 3rem 2.5rem;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
            border-right: 1px solid var(--border);
            transition: background .3s, border-color .3s;
        }
        .panel-left::before {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(ellipse 100% 60% at 0% 0%, var(--gold-dim) 0%, transparent 60%),
                radial-gradient(ellipse 80% 80% at 100% 100%, rgba(0,125,254,.05) 0%, transparent 60%);
        }
        body.light .panel-left::before {
            background:
                radial-gradient(ellipse 100% 60% at 0% 0%, rgba(99,102,241,.08) 0%, transparent 60%),
                radial-gradient(ellipse 80% 80% at 100% 100%, rgba(139,92,246,.06) 0%, transparent 60%);
        }

        .deco-ring { position: absolute; border-radius: 50%; border: 1px solid var(--ring-color); }
        .deco-ring-1 { width: 350px; height: 350px; bottom: -120px; right: -120px; }
        .deco-ring-2 { width: 250px; height: 250px; bottom: -70px; right: -70px; border-color: var(--ring-color2); }
        .deco-ring-3 { width: 150px; height: 150px; bottom: -20px; right: -20px; border-color: var(--ring-color3); }

        .brand-wrap { position: relative; z-index: 1; }
        .brand-logo { display: flex; align-items: center; gap: .75rem; margin-bottom: 2.5rem; }
        .brand-icon {
            width: 46px; height: 46px;
            background: linear-gradient(135deg, #1a2540, #0e1830);
            border: 1px solid var(--border-gold);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: var(--gold);
            box-shadow: 0 0 20px var(--accent-glow), inset 0 1px 0 rgba(255,255,255,.05);
            transition: all .3s;
        }
        body.light .brand-icon {
            background: linear-gradient(135deg, #ede9fe, #ddd6fe);
        }
        .brand-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem; font-weight: 700;
            color: var(--text); letter-spacing: .02em;
            transition: color .3s;
        }
        .brand-name em { color: var(--gold); font-style: normal; }

        .panel-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.6rem; font-weight: 600; line-height: 1.15;
            color: var(--text); margin-bottom: 1rem;
            transition: color .3s;
        }
        .panel-heading span {
            display: block;
            background: linear-gradient(90deg, var(--gold), var(--gold-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .panel-desc { font-size: .875rem; color: var(--muted-2); line-height: 1.7; max-width: 260px; transition: color .3s; }

        .panel-footer { position: relative; z-index: 1; }
        .feature-list { list-style: none; display: flex; flex-direction: column; gap: .65rem; }
        .feature-list li { display: flex; align-items: center; gap: .65rem; font-size: .8rem; color: var(--muted-2); transition: color .3s; }
        .feature-list li i {
            width: 24px; height: 24px;
            background: var(--gold-dim);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            color: var(--gold); font-size: .65rem; flex-shrink: 0;
            transition: all .3s;
        }

        /* ── Right panel ── */
        .panel-right {
            background: var(--panel-r-bg);
            padding: 3rem 2.75rem;
            display: flex; flex-direction: column; justify-content: center;
            transition: background .3s;
        }

        .form-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.9rem; font-weight: 600;
            color: var(--text); margin-bottom: .3rem; letter-spacing: -.01em;
            transition: color .3s;
        }
        .form-sub { font-size: .83rem; color: var(--muted); margin-bottom: 2rem; font-weight: 400; transition: color .3s; }

        /* Alert */
        .login-alert {
            display: flex; align-items: center; gap: .6rem;
            background: var(--err-bg); border: 1px solid var(--err-bdr);
            border-radius: 10px; padding: .65rem .9rem;
            font-size: .82rem; color: var(--err-text); font-weight: 500;
            margin-bottom: 1.25rem; animation: shake .4s ease;
        }
        @keyframes shake {
            0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-4px)} 40%,80%{transform:translateX(4px)}
        }

        /* Fields */
        .field-wrap { margin-bottom: 1.1rem; }
        .field-label {
            display: block; font-size: .72rem; font-weight: 600;
            color: var(--muted-2); letter-spacing: .08em; text-transform: uppercase;
            margin-bottom: .5rem; transition: color .3s;
        }
        .field-inner { position: relative; }
        .field-icon {
            position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
            color: var(--muted); font-size: .8rem; pointer-events: none;
            transition: color .2s; z-index: 1;
        }
        .field-input {
            width: 100%; height: 50px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0 1rem 0 2.5rem;
            font-size: .9rem; font-family: 'DM Sans', sans-serif; font-weight: 400;
            color: var(--text); outline: none;
            transition: border-color .2s, box-shadow .2s, background .3s, color .3s;
            caret-color: var(--caret);
        }
        .field-input::placeholder { color: var(--placeholder); }
        .field-input:focus { border-color: var(--focus-bdr); box-shadow: 0 0 0 3px var(--focus-ring); }
        .field-input:focus ~ .field-icon { color: var(--gold); }
        .field-input.is-invalid { border-color: var(--err-bdr); box-shadow: 0 0 0 3px var(--err-bg); }

        .field-input:-webkit-autofill { -webkit-text-fill-color: var(--text) !important; }
        .field-input:-webkit-autofill,
        .field-input:-webkit-autofill:focus {
            box-shadow: 0 0 0 1000px var(--surface-2) inset, 0 0 0 3px var(--focus-ring) !important;
            border-color: var(--focus-bdr) !important;
        }

        /* Password */
        .pw-wrap { position: relative; padding-bottom: 1.3rem; }
        .pw-toggle {
            position: absolute; right: .9rem; top: 50%; transform: translateY(-60%);
            background: none; border: none; color: var(--muted); cursor: pointer;
            font-size: .85rem; padding: 0; z-index: 2; transition: color .2s;
        }
        .pw-toggle:hover { color: var(--gold); }

        #capsLockHint {
            position: absolute; bottom: 0; left: 0;
            font-size: .75rem; color: #d97706; font-weight: 600;
            display: flex; align-items: center; gap: .3rem;
            visibility: hidden; opacity: 0; transition: opacity .2s;
        }
        #capsLockHint.caps-visible { visibility: visible; opacity: 1; }
        .field-error { font-size: .75rem; color: var(--err-text); font-weight: 500; margin-top: .3rem; display: none; }
        .field-error.show { display: block; }

        /* Submit */
        .btn-signin {
            width: 100%; height: 50px;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            border: none; border-radius: 10px;
            color: var(--btn-color);
            font-size: .9rem; font-weight: 700; font-family: 'DM Sans', sans-serif;
            letter-spacing: .04em; text-transform: uppercase;
            cursor: pointer; position: relative; overflow: hidden;
            transition: transform .15s, box-shadow .15s, opacity .2s;
            box-shadow: 0 4px 20px var(--btn-shadow);
            margin-top: .25rem;
        }
        body.light .btn-signin {
            background: linear-gradient(135deg, #6366f1 0%, #818cf8 100%);
        }
        .btn-signin::before {
            content: '';
            position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, var(--accent-shimmer), transparent);
            transition: left .4s ease;
        }
        .btn-signin:hover:not(:disabled)::before { left: 100%; }
        .btn-signin:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 28px var(--btn-hover-shadow); }
        .btn-signin:active:not(:disabled) { transform: translateY(0); }
        .btn-signin:disabled { opacity: .5; cursor: not-allowed; }
        .btn-signin.loading .btn-text { opacity: 0; }
        .btn-signin .btn-spinner { display: none; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; opacity: 0; }
        .btn-signin.loading .btn-spinner { opacity: 1; }

        /* Footer */
        .form-footer { margin-top: 1.25rem; text-align: center; font-size: .78rem; color: var(--muted); }
        .form-footer a { color: var(--gold); font-weight: 600; text-decoration: none; transition: color .2s; }
        .form-footer a:hover { color: var(--gold-light); }

        /* Lock */
        .lock-hint {
            background: var(--lock-bg); border: 1px solid var(--lock-bdr);
            border-radius: 8px; padding: .55rem .85rem;
            font-size: .78rem; color: var(--lock-text); font-weight: 500;
            margin-top: .5rem; display: flex; align-items: center; gap: .4rem;
        }

        /* Divider */
        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.5rem 0 0; }
        .divider-line { flex: 1; height: 1px; background: var(--border); transition: background .3s; }
        .divider-text { font-size: .7rem; color: var(--muted); letter-spacing: .06em; text-transform: uppercase; }

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

<!-- Theme toggle -->
<button class="theme-toggle" id="themeBtn" aria-label="Toggle theme">
    <i class="fas fa-sun i-sun"></i>
    <i class="fas fa-moon i-moon"></i>
</button>

<div class="login-shell">

    <!-- LEFT -->
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
                <li><i class="fas fa-shield-alt"></i> Secure &amp; encrypted access</li>
                <li><i class="fas fa-bolt"></i> Real-time payment tracking</li>
                <li><i class="fas fa-bell"></i> Instant notifications</li>
                <li><i class="fas fa-chart-line"></i> Smart financial reports</li>
            </ul>
        </div>
    </div>

    <!-- RIGHT -->
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
                            style="padding-right:2.75rem;"
                            required>
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
    /* ── Theme ── */
    const body = document.body;
    // Default = dark (your original design). Light saved to localStorage.
    if (localStorage.getItem('sw_theme') === 'light') body.classList.add('light');

    document.getElementById('themeBtn').addEventListener('click', () => {
        const isLight = body.classList.toggle('light');
        localStorage.setItem('sw_theme', isLight ? 'light' : 'dark');
    });

    /* ── Form ── */
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
