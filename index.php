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
    <meta name="theme-color" content="#c9a84c"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --gold:         #c9a84c;
            --gold-light:   #e2c97e;
            --gold-dim:     rgba(201,168,76,.15);
            --bg:           #080c14;
            --surface:      #0e1420;
            --surface-2:    #141b28;
            --border:       rgba(255,255,255,.07);
            --border-acc:   rgba(201,168,76,.25);
            --text:         #f0ede8;
            --muted:        #6b7280;
            --muted-2:      #9ca3af;
            --placeholder:  #374151;
            --orb1:  rgba(0,125,254,.05);
            --orb2:  rgba(201,168,76,.04);
            --orb3:  rgba(78,214,193,.04);
            --glow1: rgba(0,125,254,.07);
            --glow2: rgba(78,214,193,.05);
            --glow3: rgba(201,168,76,.03);
            --grid:  rgba(255,255,255,.015);
            --panel-l:      linear-gradient(160deg,#0d1422 0%,#080c14 100%);
            --panel-l-glow1: rgba(201,168,76,.06);
            --panel-l-glow2: rgba(0,125,254,.05);
            --ring1: rgba(201,168,76,.08);
            --ring2: rgba(201,168,76,.12);
            --ring3: rgba(201,168,76,.18);
            --brand-icon-bg: linear-gradient(135deg,#1a2540,#0e1830);
            --panel-r:      #0e1420;
            --input-bg:     #141b28;
            --input-bdr:    rgba(255,255,255,.07);
            --focus-bdr:    rgba(201,168,76,.25);
            --focus-ring:   rgba(201,168,76,.08);
            --caret:        #c9a84c;
            --btn-from:     #c9a84c;
            --btn-to:       #a8832e;
            --btn-text:     #0a0d14;
            --btn-shimmer:  rgba(255,255,255,.15);
            --btn-shadow:   rgba(201,168,76,.25);
            --btn-shadow-h: rgba(201,168,76,.35);
            --err-bg:   rgba(239,68,68,.08);
            --err-bdr:  rgba(239,68,68,.2);
            --err-txt:  #f87171;
            --lock-bg:  rgba(217,119,6,.08);
            --lock-bdr: rgba(217,119,6,.2);
            --lock-txt: #fbbf24;
            --shell-shadow:
                0 0 0 1px rgba(201,168,76,.08),
                0 40px 80px rgba(0,0,0,.6),
                0 0 120px rgba(0,125,254,.06);
        }

        body.light {
            --gold:         #0284c7;
            --gold-light:   #38bdf8;
            --gold-dim:     rgba(2,132,199,.12);
            --bg:           #f0f9ff;
            --surface:      #ffffff;
            --surface-2:    #e0f2fe;
            --border:       rgba(2,132,199,.12);
            --border-acc:   rgba(2,132,199,.35);
            --text:         #0c1a2e;
            --muted:        #0369a1;
            --muted-2:      #0284c7;
            --placeholder:  #7dd3fc;
            --glow1: rgba(2,132,199,.1);
            --glow2: rgba(20,184,166,.08);
            --glow3: rgba(56,189,248,.06);
            --grid:  rgba(2,132,199,.05);
            --panel-l:       linear-gradient(160deg,#0ea5e9 0%,#0284c7 45%,#0d9488 100%);
            --panel-l-glow1: rgba(255,255,255,.06);
            --panel-l-glow2: rgba(20,184,166,.08);
            --ring1: rgba(255,255,255,.1);
            --ring2: rgba(255,255,255,.15);
            --ring3: rgba(255,255,255,.22);
            --brand-icon-bg: linear-gradient(135deg,rgba(255,255,255,.25),rgba(255,255,255,.12));
            --panel-r:      #ffffff;
            --input-bg:     #f0f9ff;
            --input-bdr:    rgba(2,132,199,.18);
            --focus-bdr:    rgba(2,132,199,.5);
            --focus-ring:   rgba(2,132,199,.1);
            --caret:        #0284c7;
            --btn-from:     #0284c7;
            --btn-to:       #0d9488;
            --btn-text:     #ffffff;
            --btn-shimmer:  rgba(255,255,255,.22);
            --btn-shadow:   rgba(2,132,199,.32);
            --btn-shadow-h: rgba(2,132,199,.48);
            --err-bg:   rgba(220,38,38,.06);
            --err-bdr:  rgba(220,38,38,.2);
            --err-txt:  #dc2626;
            --lock-bg:  rgba(2,132,199,.06);
            --lock-bdr: rgba(2,132,199,.2);
            --lock-txt: #0369a1;
            --shell-shadow:
                0 0 0 1px rgba(2,132,199,.12),
                0 24px 60px rgba(2,132,199,.1),
                0 4px 16px rgba(2,132,199,.07);
        }

        html, body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            transition: background .5s, color .35s;
        }

        .bg-scene { position: fixed; inset: 0; z-index: 0; background: var(--bg); transition: background .5s; }
        .bg-scene::before {
            content: ''; position: absolute; inset: 0;
            background-image: linear-gradient(var(--grid) 1px, transparent 1px), linear-gradient(90deg, var(--grid) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black, transparent);
        }
        .bg-scene::after { content: ''; position: absolute; inset: 0; transition: opacity .5s; }
        body:not(.light) .bg-scene::after {
            background:
                radial-gradient(ellipse 60% 50% at 15% 20%, rgba(0,125,254,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 85% 80%, rgba(78,214,193,.05) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 50% 50%, rgba(201,168,76,.03) 0%, transparent 70%);
        }
        body.light .bg-scene::after {
            background:
                radial-gradient(ellipse 60% 50% at 15% 20%, rgba(2,132,199,.1) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 85% 80%, rgba(20,184,166,.08) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 50% 50%, rgba(56,189,248,.06) 0%, transparent 70%);
        }

        .orb { position: absolute; border-radius: 50%; filter: blur(80px); animation: float 16s ease-in-out infinite alternate; transition: background .5s; }
        body:not(.light) .orb-1 { background: rgba(0,125,254,.05); }
        body:not(.light) .orb-2 { background: rgba(201,168,76,.04); }
        body:not(.light) .orb-3 { background: rgba(78,214,193,.04); }
        body.light .orb-1 { background: rgba(2,132,199,.1); }
        body.light .orb-2 { background: rgba(13,148,136,.08); }
        body.light .orb-3 { background: rgba(56,189,248,.07); }
        .orb-1 { width: 600px; height: 600px; top: -200px; left: -150px; animation-delay: 0s; }
        .orb-2 { width: 500px; height: 500px; bottom: -150px; right: -100px; animation-delay: -6s; }
        .orb-3 { width: 300px; height: 300px; top: 40%; left: 55%; animation-delay: -12s; }
        @keyframes float { from { transform: translate(0,0) scale(1); } to { transform: translate(25px,15px) scale(1.06); } }

        .theme-toggle {
            position: fixed; top: 1.25rem; right: 1.5rem; z-index: 200;
            display: flex; align-items: center; gap: .5rem;
            padding: .4rem .75rem .4rem .55rem;
            border-radius: 999px; border: 1px solid var(--border-acc);
            background: var(--surface); color: var(--gold); cursor: pointer;
            font-size: .78rem; font-family: 'DM Sans', sans-serif; font-weight: 600;
            letter-spacing: .01em;
            box-shadow: 0 2px 12px var(--gold-dim), 0 1px 3px rgba(0,0,0,.08);
            transition: all .25s; white-space: nowrap;
        }
        .theme-toggle:hover { box-shadow: 0 4px 20px var(--btn-shadow); transform: translateY(-1px); border-color: var(--gold); }
        .toggle-track { width: 32px; height: 18px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--border-acc); position: relative; transition: background .3s, border-color .3s; flex-shrink: 0; }
        body.light .toggle-track { background: var(--gold); border-color: var(--gold); }
        .toggle-track::after { content: ''; position: absolute; width: 12px; height: 12px; border-radius: 50%; background: var(--gold); top: 2px; left: 2px; transition: transform .3s, background .3s; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
        body.light .toggle-track::after { transform: translateX(14px); background: #fff; }
        .toggle-label-dark { display: flex; align-items: center; gap: .3rem; }
        .toggle-label-light { display: none; align-items: center; gap: .3rem; }
        body.light .toggle-label-dark  { display: none; }
        body.light .toggle-label-light { display: flex; }

        .login-shell {
            position: relative; z-index: 1;
            width: 100%; max-width: 960px; min-height: 560px;
            margin: 1rem;
            display: grid; grid-template-columns: 1fr 1fr;
            border-radius: 28px; overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: var(--shell-shadow);
            animation: appear .6s cubic-bezier(.22,1,.36,1) both;
            transition: border-color .35s, box-shadow .35s;
        }
        @keyframes appear { from { opacity: 0; transform: translateY(32px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .panel-left {
            background: var(--panel-l);
            padding: 3rem 2.5rem;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
            border-right: 1px solid var(--border);
            transition: background .35s, border-color .35s;
        }
        .panel-left::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(ellipse 100% 60% at 0% 0%, var(--panel-l-glow1) 0%, transparent 60%), radial-gradient(ellipse 80% 80% at 100% 100%, var(--panel-l-glow2) 0%, transparent 60%);
            transition: background .35s;
        }
        .deco-ring { position: absolute; border-radius: 50%; border: 1px solid var(--ring1); transition: border-color .35s; }
        .deco-ring-1 { width: 350px; height: 350px; bottom: -120px; right: -120px; }
        .deco-ring-2 { width: 250px; height: 250px; bottom: -70px;  right: -70px;  border-color: var(--ring2); }
        .deco-ring-3 { width: 150px; height: 150px; bottom: -20px;  right: -20px;  border-color: var(--ring3); }

        .brand-wrap { position: relative; z-index: 1; }
        .brand-logo { display: flex; align-items: center; gap: .75rem; margin-bottom: 2.5rem; }
        .brand-icon { width: 46px; height: 46px; background: var(--brand-icon-bg); border: 1px solid var(--border-acc); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: var(--gold); box-shadow: 0 0 20px var(--gold-dim), inset 0 1px 0 rgba(255,255,255,.1); transition: all .35s; }
        .brand-name { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 700; color: var(--text); letter-spacing: .02em; transition: color .35s; }
        .brand-name em { color: var(--gold); font-style: normal; }

        /* ── Panel text blocks — each locked to same fixed dimensions ── */
        .panel-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.6rem; font-weight: 600; line-height: 1.15;
            color: var(--text); margin-bottom: 1rem; transition: color .35s;
            /* lock height so both modes take identical space */
            min-height: 3.45em;
            display: flex; flex-direction: column; justify-content: flex-start;
        }
        .panel-heading span {
            display: block;
            background: linear-gradient(90deg, var(--gold), var(--gold-light));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .panel-desc {
            font-size: .875rem; color: var(--muted-2); line-height: 1.7;
            /* lock to same height as tallest desc */
            min-height: 5.1em;
            max-width: 260px;
            transition: color .35s;
        }

        .panel-text-dark { display: block; }
        .panel-text-light { display: none; }
        body.light .panel-text-dark  { display: none; }
        body.light .panel-text-light { display: block; }

        /* light panel colours */
        body.light .brand-name,
        body.light .panel-heading { color: rgba(255,255,255,.95) !important; -webkit-text-fill-color: unset; }
        body.light .brand-name em { color: #7dd3fc !important; }
        body.light .panel-heading-light span {
            background: linear-gradient(90deg,#7dd3fc,#5eead4);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        body.light .panel-desc { color: rgba(255,255,255,.75) !important; }
        body.light .brand-icon { color: #fff; border-color: rgba(255,255,255,.35); }
        body.light .feature-list li { color: rgba(255,255,255,.92) !important; }
        body.light .feature-list li i { background: rgba(255,255,255,.15); color: #fff; }

        .panel-footer { position: relative; z-index: 1; }
        .feature-list { list-style: none; display: flex; flex-direction: column; gap: .65rem; }
        .feature-list li { display: flex; align-items: center; gap: .65rem; font-size: .8rem; color: var(--muted-2); transition: color .35s; }
        .feature-list li i { width: 24px; height: 24px; background: var(--gold-dim); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--gold); font-size: .65rem; flex-shrink: 0; transition: all .35s; }

        .panel-right { background: var(--panel-r); padding: 3rem 2.75rem; display: flex; flex-direction: column; justify-content: center; transition: background .35s; }
        .form-heading { font-family: 'Cormorant Garamond', serif; font-size: 1.9rem; font-weight: 600; color: var(--text); margin-bottom: .3rem; letter-spacing: -.01em; transition: color .35s; }
        .form-sub { font-size: .83rem; color: var(--muted); margin-bottom: 2rem; font-weight: 400; transition: color .35s; }

        .login-alert { display: flex; align-items: center; gap: .6rem; background: var(--err-bg); border: 1px solid var(--err-bdr); border-radius: 10px; padding: .65rem .9rem; font-size: .82rem; color: var(--err-txt); font-weight: 500; margin-bottom: 1.25rem; animation: shake .4s ease; }
        @keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-4px)} 40%,80%{transform:translateX(4px)} }

        .field-wrap { margin-bottom: 1.1rem; }
        .field-label { display: block; font-size: .72rem; font-weight: 600; color: var(--muted-2); letter-spacing: .08em; text-transform: uppercase; margin-bottom: .5rem; transition: color .35s; }
        .field-inner { position: relative; }
        .field-icon { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .8rem; pointer-events: none; transition: color .2s; z-index: 1; }
        .field-input { width: 100%; height: 50px; background: var(--input-bg); border: 1px solid var(--input-bdr); border-radius: 10px; padding: 0 1rem 0 2.5rem; font-size: .9rem; font-family: 'DM Sans', sans-serif; font-weight: 400; color: var(--text); outline: none; caret-color: var(--caret); transition: border-color .2s, box-shadow .2s, background .35s, color .35s; }
        .field-input::placeholder { color: var(--placeholder); }
        .field-input:focus { border-color: var(--focus-bdr); box-shadow: 0 0 0 3px var(--focus-ring); }
        .field-input:focus ~ .field-icon { color: var(--gold); }
        .field-input.is-invalid { border-color: var(--err-bdr); box-shadow: 0 0 0 3px var(--err-bg); }
        .field-input:-webkit-autofill { -webkit-text-fill-color: var(--text) !important; }
        .field-input:-webkit-autofill, .field-input:-webkit-autofill:focus { box-shadow: 0 0 0 1000px var(--input-bg) inset, 0 0 0 3px var(--focus-ring) !important; border-color: var(--focus-bdr) !important; }

        .pw-wrap { position: relative; padding-bottom: 1.3rem; }
        .pw-toggle { position: absolute; right: .9rem; top: 50%; transform: translateY(-60%); background: none; border: none; color: var(--muted); cursor: pointer; font-size: .85rem; padding: 0; z-index: 2; transition: color .2s; }
        .pw-toggle:hover { color: var(--gold); }
        #capsLockHint { position: absolute; bottom: 0; left: 0; font-size: .75rem; color: #d97706; font-weight: 600; display: flex; align-items: center; gap: .3rem; visibility: hidden; opacity: 0; transition: opacity .2s; }
        #capsLockHint.caps-visible { visibility: visible; opacity: 1; }
        .field-error { font-size: .75rem; color: var(--err-txt); font-weight: 500; margin-top: .3rem; display: none; }
        .field-error.show { display: block; }

        .btn-signin { width: 100%; height: 50px; background: linear-gradient(135deg, var(--btn-from) 0%, var(--btn-to) 100%); border: none; border-radius: 10px; color: var(--btn-text); font-size: .9rem; font-weight: 700; font-family: 'DM Sans', sans-serif; letter-spacing: .04em; text-transform: uppercase; cursor: pointer; position: relative; overflow: hidden; transition: transform .15s, box-shadow .15s, opacity .2s; box-shadow: 0 4px 20px var(--btn-shadow); margin-top: .25rem; }
        .btn-signin::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, var(--btn-shimmer), transparent); transition: left .4s ease; }
        .btn-signin:hover:not(:disabled)::before { left: 100%; }
        .btn-signin:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 28px var(--btn-shadow-h); }
        .btn-signin:active:not(:disabled) { transform: translateY(0); }
        .btn-signin:disabled { opacity: .5; cursor: not-allowed; }
        .btn-signin.loading .btn-text { opacity: 0; }
        .btn-signin .btn-spinner { display: none; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; opacity: 0; }
        .btn-signin.loading .btn-spinner { opacity: 1; }

        .form-footer { margin-top: 1.25rem; text-align: center; font-size: .78rem; color: var(--muted); transition: color .35s; }
        .form-footer a { color: var(--gold); font-weight: 600; text-decoration: none; transition: color .25s; border-bottom: 1px solid transparent; }
        .form-footer a:hover { color: var(--gold-light); border-bottom-color: var(--gold-light); }

        .lock-hint { background: var(--lock-bg); border: 1px solid var(--lock-bdr); border-radius: 8px; padding: .55rem .85rem; font-size: .78rem; color: var(--lock-txt); font-weight: 500; margin-top: .5rem; display: flex; align-items: center; gap: .4rem; }
        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.5rem 0 0; }
        .divider-line { flex: 1; height: 1px; background: var(--border); transition: background .35s; }
        .divider-text { font-size: .7rem; color: var(--muted); letter-spacing: .06em; text-transform: uppercase; transition: color .35s; }

        .login-shell, .panel-left, .panel-right, .brand-icon, .brand-name, .panel-heading, .panel-desc,
        .feature-list li, .feature-list li i, .field-input, .field-label, .field-icon,
        .form-heading, .form-sub, .form-footer, .form-footer a,
        .divider-line, .divider-text, .deco-ring, .login-alert, .lock-hint,
        .btn-signin, .theme-toggle, .toggle-track {
            transition: background .5s, color .4s, border-color .4s, box-shadow .4s, opacity .4s;
        }

        @media (max-width: 700px) {
            .login-shell { grid-template-columns: 1fr; max-width: 420px; }
            .panel-left  { display: none; }
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

<button class="theme-toggle" id="themeBtn" aria-label="Toggle theme">
    <div class="toggle-track"></div>
    <span class="toggle-label-dark"><i class="fas fa-moon"></i> Dark</span>
    <span class="toggle-label-light"><i class="fas fa-sun"></i> Light</span>
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

            <!-- Dark mode text (original) -->
            <div class="panel-text-dark">
                <div class="panel-heading">
                    Manage your<br>property
                    <span>with elegance.</span>
                </div>
                <p class="panel-desc">
                    A premium platform for landlords and tenants to handle payments, maintenance, and communication seamlessly.
                </p>
            </div>

            <!-- Light mode text (different copy, same size) -->
            <div class="panel-text-light">
                <div class="panel-heading panel-heading-light">
                    Made for landlords<br>who do it
                    <span>all themselves.</span>
                </div>
                <p class="panel-desc">
                    StayWise helps small apartment owners manage rent, tenants, and requests — without the overwhelm.
                </p>
            </div>
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
    const body = document.body;
    if (localStorage.getItem('sw_theme') === 'light') body.classList.add('light');

    function setTheme(isLight) {
        body.classList.toggle('light', isLight);
        localStorage.setItem('sw_theme', isLight ? 'light' : 'dark');
        document.cookie = 'darkMode=' + (isLight ? 'false' : 'true') + '; path=/; max-age=31536000';
    }

    document.getElementById('themeBtn').addEventListener('click', () => {
        setTheme(!body.classList.contains('light'));
    });

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

    const updateCaps = e => { try { caps.classList.toggle('caps-visible', e.getModifierState('CapsLock')); } catch (_) {} };
    password.addEventListener('keydown', updateCaps);
    password.addEventListener('keyup',   updateCaps);
    password.addEventListener('focus',   updateCaps);
    password.addEventListener('blur', () => caps.classList.remove('caps-visible'));

    const params   = new URLSearchParams(window.location.search);
    let remain     = parseInt(params.get('remain') || '0', 10);
    const isLocked = params.get('lock') === '1' && remain > 0;

    if (isLocked) {
        const a = document.querySelector('.login-alert'); if (a) a.style.display = 'none';
        password.disabled = true; submitBtn.disabled = true; startCountdown();
    }
    const serverError = params.get('error');
    if (!isLocked && serverError) {
        const a = document.querySelector('.login-alert'); if (a) a.style.display = 'none';
        pErr.textContent = serverError; pErr.classList.add('show');
        password.classList.add('is-invalid'); try { password.focus(); } catch (_) {}
    }

    function renderLock(s) {
        lockWrap.innerHTML = `<div class="lock-hint"><i class="fas fa-clock"></i> Too many attempts — retry in ${s}s</div>`;
    }
    function startCountdown() {
        renderLock(remain);
        const iv = setInterval(() => {
            remain = Math.max(0, remain - 1); renderLock(remain);
            if (remain <= 0) {
                clearInterval(iv); password.disabled = false; submitBtn.disabled = false; lockWrap.innerHTML = '';
                try { const u = new URL(window.location); u.searchParams.delete('lock'); u.searchParams.delete('remain'); window.history.replaceState({}, '', u); } catch (_) {}
            }
        }, 1000);
    }

    const valU = () => { const v = (username.value||'').trim(); const ok = /^[A-Za-z0-9_]{3,20}$/.test(v)||/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); username.classList.toggle('is-invalid',!ok); uErr.classList.toggle('show',!ok); return ok; };
    const valP = () => { const ok = password.value.length > 0; password.classList.toggle('is-invalid',!ok); pErr.classList.toggle('show',!ok); return ok; };

    username.addEventListener('input', () => { username.classList.remove('is-invalid'); uErr.classList.remove('show'); });
    password.addEventListener('input', () => { password.classList.remove('is-invalid'); pErr.classList.remove('show'); });

    form.addEventListener('submit', e => {
        if (isLocked && remain > 0) { e.preventDefault(); return; }
        if (!(valU() & valP())) { e.preventDefault(); return; }
        submitBtn.classList.add('loading'); submitBtn.disabled = true;
    });
})();
</script>
</body>
</html>
