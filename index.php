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
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           LIGHT MODE TOKENS
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        :root {
            --teal:         #3ecfb8;
            --teal-2:       #28b09a;
            --teal-3:       #1a9080;
            --teal-fg:      #ffffff;
            --teal-glow:    rgba(62,207,184,.18);

            --bg:           #eef4f3;
            --panel:        #ffffff;
            --panel-bdr:    rgba(62,207,184,.25);
            --input-bg:     #f5faf9;
            --input-bdr:    #cde8e4;
            --input-focus:  #3ecfb8;
            --text-h:       #0a1f1c;
            --text:         #1c3530;
            --text-soft:    #4a7068;
            --text-ghost:   #8fb5b0;
            --divider:      #daeae7;

            --shadow-card:  0 0 0 1px rgba(62,207,184,.12), 0 24px 64px rgba(10,31,28,.10), 0 4px 16px rgba(10,31,28,.06);
            --shadow-btn:   0 4px 20px rgba(62,207,184,.40);

            --err-bg:       #fff1f1;
            --err-bdr:      #fcc;
            --err-text:     #c0392b;
            --warn-bg:      #fffbeb;
            --warn-bdr:     #fde68a;
            --warn-text:    #92400e;
        }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           DARK MODE TOKENS
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        body.dark {
            --bg:           #08100f;
            --panel:        #0d1a18;
            --panel-bdr:    rgba(62,207,184,.12);
            --input-bg:     #111f1d;
            --input-bdr:    rgba(62,207,184,.15);
            --input-focus:  #3ecfb8;
            --text-h:       #e8f5f3;
            --text:         #b8d8d4;
            --text-soft:    #6fa89f;
            --text-ghost:   #3d6860;
            --divider:      rgba(62,207,184,.1);

            --shadow-card:  0 0 0 1px rgba(62,207,184,.08), 0 32px 80px rgba(0,0,0,.6), 0 0 120px rgba(62,207,184,.04);
            --shadow-btn:   0 4px 24px rgba(62,207,184,.28);

            --err-bg:       rgba(192,57,43,.08);
            --err-bdr:      rgba(192,57,43,.25);
            --err-text:     #f1948a;
            --warn-bg:      rgba(146,64,14,.08);
            --warn-bdr:     rgba(253,230,138,.2);
            --warn-text:    #fbbf24;
        }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           BASE
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        html { height: 100%; }
        body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .3s, color .3s;
            overflow: hidden;
        }

        /* ── Animated mesh background ── */
        .bg-mesh {
            position: fixed; inset: 0; z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }
        .bg-mesh::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(var(--divider) 1px, transparent 1px),
                linear-gradient(90deg, var(--divider) 1px, transparent 1px);
            background-size: 52px 52px;
            mask-image: radial-gradient(ellipse 90% 90% at 50% 50%, black 20%, transparent 80%);
            transition: background-image .3s;
        }
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(90px);
            animation: drift 18s ease-in-out infinite alternate;
        }
        .orb-1 {
            width: 700px; height: 700px;
            background: radial-gradient(circle, rgba(62,207,184,.14) 0%, transparent 70%);
            top: -200px; left: -150px;
            animation-delay: 0s;
        }
        .orb-2 {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(62,207,184,.08) 0%, transparent 70%);
            bottom: -150px; right: -100px;
            animation-delay: -7s;
        }
        body.dark .orb-1 { background: radial-gradient(circle, rgba(62,207,184,.09) 0%, transparent 70%); }
        body.dark .orb-2 { background: radial-gradient(circle, rgba(0,180,140,.06) 0%, transparent 70%); }
        @keyframes drift {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(30px,20px) scale(1.08); }
        }

        /* ── Theme toggle ── */
        .theme-btn {
            position: fixed; top: 1.25rem; right: 1.5rem; z-index: 200;
            width: 42px; height: 42px; border-radius: 12px;
            border: 1px solid var(--panel-bdr);
            background: var(--panel);
            color: var(--text-soft);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: .9rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            transition: all .2s;
        }
        .theme-btn:hover {
            color: var(--teal-2);
            border-color: var(--teal);
            box-shadow: 0 4px 20px var(--teal-glow);
            transform: translateY(-1px);
        }
        .theme-btn .i-sun  { display: none; }
        .theme-btn .i-moon { display: block; }
        body.dark .theme-btn .i-sun  { display: block; }
        body.dark .theme-btn .i-moon { display: none; }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           CARD SHELL
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        .card-shell {
            position: relative; z-index: 10;
            width: 100%; max-width: 980px;
            margin: 1.5rem;
            display: grid;
            grid-template-columns: 420px 1fr;
            border-radius: 28px;
            overflow: hidden;
            border: 1px solid var(--panel-bdr);
            box-shadow: var(--shadow-card);
            animation: rise .55s cubic-bezier(.16,1,.3,1) both;
            transition: border-color .3s, box-shadow .3s;
        }
        @keyframes rise {
            from { opacity: 0; transform: translateY(28px) scale(.97); }
            to   { opacity: 1; transform: translateY(0)   scale(1); }
        }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           LEFT PANEL  ─  teal hero
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        .panel-l {
            background: linear-gradient(155deg, #4edfc8 0%, #28b09a 45%, #0d7a6a 100%);
            padding: 3rem 2.75rem;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
            min-height: 580px;
        }

        /* big abstract shapes */
        .shape {
            position: absolute;
            border-radius: 50%;
        }
        .shape-1 {
            width: 440px; height: 440px;
            background: rgba(255,255,255,.07);
            top: -160px; right: -130px;
        }
        .shape-2 {
            width: 280px; height: 280px;
            background: rgba(255,255,255,.05);
            top: -60px; right: -50px;
        }
        .shape-3 {
            width: 180px; height: 180px;
            background: rgba(0,0,0,.08);
            bottom: -60px; left: -50px;
        }

        /* floating ring decoration */
        .ring {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,.15);
        }
        .ring-1 { width: 320px; height: 320px; bottom: 60px; right: -120px; }
        .ring-2 { width: 220px; height: 220px; bottom: 100px; right: -70px; border-color: rgba(255,255,255,.1); }

        /* dot matrix */
        .dots {
            position: absolute; bottom: 2.5rem; left: 2.75rem;
            display: grid; grid-template-columns: repeat(6,1fr); gap: 7px;
        }
        .dots span {
            width: 4px; height: 4px; border-radius: 50%;
            background: rgba(255,255,255,.3); display: block;
        }

        /* brand */
        .logo-row {
            display: flex; align-items: center; gap: .8rem;
            position: relative; z-index: 2;
        }
        .logo-icon {
            width: 46px; height: 46px; border-radius: 14px;
            background: rgba(255,255,255,.2);
            border: 1px solid rgba(255,255,255,.35);
            backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem; color: #fff;
            box-shadow: 0 8px 24px rgba(0,0,0,.15);
        }
        .logo-text {
            font-family: 'Syne', sans-serif;
            font-size: 1.4rem; font-weight: 800;
            color: #fff; letter-spacing: -.02em;
        }

        /* headline */
        .hero-text { position: relative; z-index: 2; }
        .hero-text h1 {
            font-family: 'Syne', sans-serif;
            font-size: 2.55rem; font-weight: 800;
            color: #fff; line-height: 1.15;
            letter-spacing: -.03em;
            margin-bottom: .9rem;
        }
        .hero-text h1 em {
            font-style: normal;
            color: rgba(255,255,255,.55);
        }
        .hero-text p {
            font-size: .875rem;
            color: rgba(255,255,255,.68);
            line-height: 1.8;
            max-width: 260px;
            font-weight: 300;
        }

        /* pills */
        .pills { position: relative; z-index: 2; display: flex; flex-direction: column; gap: .55rem; }
        .pill {
            display: flex; align-items: center; gap: .7rem;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 10px;
            padding: .55rem .9rem;
            font-size: .8rem; font-weight: 500;
            color: rgba(255,255,255,.9);
            backdrop-filter: blur(6px);
            transition: background .2s;
        }
        .pill:hover { background: rgba(255,255,255,.16); }
        .pill i { width: 14px; text-align: center; opacity: .75; font-size: .75rem; }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           RIGHT PANEL  ─  form
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        .panel-r {
            background: var(--panel);
            padding: 3.25rem 3.25rem;
            display: flex; flex-direction: column; justify-content: center;
            transition: background .3s;
        }

        /* staggered entrance for form children */
        .panel-r > * {
            animation: fadein .5s cubic-bezier(.16,1,.3,1) both;
        }
        .panel-r > *:nth-child(1) { animation-delay: .1s; }
        .panel-r > *:nth-child(2) { animation-delay: .15s; }
        .panel-r > *:nth-child(3) { animation-delay: .2s; }
        .panel-r > *:nth-child(4) { animation-delay: .25s; }
        .panel-r > *:nth-child(5) { animation-delay: .3s; }
        @keyframes fadein {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .tag-line {
            display: inline-flex; align-items: center; gap: .45rem;
            font-size: .68rem; font-weight: 700;
            letter-spacing: .12em; text-transform: uppercase;
            color: var(--teal-2);
            margin-bottom: .6rem;
        }
        .tag-line::before {
            content: '';
            width: 18px; height: 2px;
            background: var(--teal);
            border-radius: 2px;
        }

        .form-h {
            font-family: 'Syne', sans-serif;
            font-size: 2rem; font-weight: 800;
            color: var(--text-h);
            letter-spacing: -.035em;
            line-height: 1.15;
            margin-bottom: .3rem;
            transition: color .3s;
        }
        .form-sub {
            font-size: .83rem; font-weight: 300;
            color: var(--text-soft);
            margin-bottom: 2rem;
            transition: color .3s;
        }

        /* alert */
        .alert-err {
            display: flex; align-items: center; gap: .6rem;
            background: var(--err-bg); border: 1px solid var(--err-bdr);
            border-radius: 10px; padding: .65rem .9rem;
            font-size: .81rem; color: var(--err-text); font-weight: 600;
            margin-bottom: 1.25rem;
            animation: shake .4s ease;
        }
        @keyframes shake {
            0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)}
        }

        /* field */
        .field { margin-bottom: 1.1rem; }
        .field-lbl {
            display: block;
            font-size: .72rem; font-weight: 600;
            color: var(--text-soft);
            letter-spacing: .06em; text-transform: uppercase;
            margin-bottom: .45rem;
            transition: color .3s;
        }
        .field-wrap { position: relative; }
        .field-ico {
            position: absolute; left: .95rem; top: 50%;
            transform: translateY(-50%);
            color: var(--text-ghost); font-size: .78rem;
            pointer-events: none;
            transition: color .2s;
        }
        .field-in {
            width: 100%;
            padding: .72rem .95rem .72rem 2.5rem;
            background: var(--input-bg);
            border: 1.5px solid var(--input-bdr);
            border-radius: 11px;
            font-size: .88rem; font-family: 'DM Sans', sans-serif;
            color: var(--text-h);
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .3s, color .3s;
        }
        .field-in::placeholder { color: var(--text-ghost); }
        .field-in:hover { border-color: var(--teal-2); }
        .field-in:focus {
            border-color: var(--input-focus);
            box-shadow: 0 0 0 3.5px var(--teal-glow);
        }
        .field-in:focus + .field-ico { color: var(--teal-2); }
        .field-in.bad { border-color: var(--err-bdr); box-shadow: 0 0 0 3px rgba(192,57,43,.08); }

        /* autofill */
        .field-in:-webkit-autofill { -webkit-text-fill-color: var(--text-h) !important; }
        .field-in:-webkit-autofill { box-shadow: 0 0 0 1000px var(--input-bg) inset, 0 0 0 3.5px var(--teal-glow) !important; border-color: var(--input-focus) !important; }

        /* pw row */
        .pw-row { position: relative; padding-bottom: 1.3rem; }
        .pw-eye {
            position: absolute; right: .9rem; top: 50%;
            transform: translateY(-65%);
            background: none; border: none; padding: 0;
            color: var(--text-ghost); cursor: pointer; font-size: .82rem; z-index: 2;
            transition: color .2s;
        }
        .pw-eye:hover { color: var(--teal-2); }

        #caps {
            position: absolute; bottom: 0; left: 0;
            font-size: .72rem; color: #d97706; font-weight: 600;
            display: flex; align-items: center; gap: .3rem;
            opacity: 0; visibility: hidden; transition: opacity .2s;
        }
        #caps.on { opacity: 1; visibility: visible; }

        .ferr { font-size: .72rem; color: var(--err-text); font-weight: 600; margin-top: .3rem; display: none; }
        .ferr.show { display: block; }

        /* button */
        .btn-go {
            width: 100%;
            padding: .78rem 1rem;
            background: linear-gradient(135deg, var(--teal) 0%, var(--teal-2) 60%, var(--teal-3) 100%);
            border: none; border-radius: 11px;
            color: #fff; font-family: 'Syne', sans-serif;
            font-size: .92rem; font-weight: 700;
            letter-spacing: .02em;
            cursor: pointer;
            position: relative; overflow: hidden;
            box-shadow: var(--shadow-btn);
            transition: transform .15s, box-shadow .2s, opacity .2s;
            margin-top: .3rem;
        }
        /* shimmer */
        .btn-go::after {
            content: '';
            position: absolute; top: 0; left: -100%; width: 60%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.22), transparent);
            transform: skewX(-20deg);
            transition: left .5s ease;
        }
        .btn-go:hover:not(:disabled)::after { left: 150%; }
        .btn-go:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(62,207,184,.45);
        }
        .btn-go:active:not(:disabled) { transform: translateY(0); }
        .btn-go:disabled { opacity: .5; cursor: not-allowed; }
        .btn-go.spin .btn-lbl { opacity: 0; }
        .btn-go .btn-spinner { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; opacity: 0; }
        .btn-go.spin .btn-spinner { opacity: 1; }

        /* lock */
        .lock-box {
            display: flex; align-items: center; gap: .45rem;
            background: var(--warn-bg); border: 1px solid var(--warn-bdr);
            border-radius: 9px; padding: .5rem .8rem;
            font-size: .77rem; color: var(--warn-text); font-weight: 600; margin-top: .5rem;
        }

        /* divider */
        .div-row { display: flex; align-items: center; gap: .75rem; margin: 1.5rem 0 0; }
        .div-line { flex: 1; height: 1px; background: var(--divider); transition: background .3s; }
        .div-txt { font-size: .67rem; color: var(--text-ghost); letter-spacing: .09em; text-transform: uppercase; font-weight: 600; }

        /* footer link */
        .form-foot { margin-top: 1.1rem; text-align: center; font-size: .8rem; color: var(--text-soft); }
        .form-foot a { color: var(--teal-2); font-weight: 600; text-decoration: none; transition: color .15s; }
        .form-foot a:hover { color: var(--teal-3); text-decoration: underline; }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
           RESPONSIVE
        ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
        @media (max-width: 780px) {
            .card-shell { grid-template-columns: 1fr; max-width: 440px; }
            .panel-l    { display: none; }
            .panel-r    { padding: 2.75rem 2rem; }
        }
    </style>
</head>
<body>

<!-- background -->
<div class="bg-mesh">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
</div>

<!-- theme toggle -->
<button class="theme-btn" id="themeBtn" aria-label="Toggle theme">
    <i class="fas fa-sun i-sun"></i>
    <i class="fas fa-moon i-moon"></i>
</button>

<div class="card-shell">

    <!-- ── LEFT ── -->
    <div class="panel-l">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="ring ring-1"></div>
        <div class="ring ring-2"></div>
        <div class="dots"><?php for($i=0;$i<30;$i++) echo '<span></span>'; ?></div>

        <div class="logo-row">
            <div class="logo-icon"><i class="fas fa-building"></i></div>
            <div class="logo-text">StayWise</div>
        </div>

        <div class="hero-text">
            <h1>Property<br>management<br><em>made elegant.</em></h1>
            <p>One platform for landlords and tenants — payments, maintenance requests, and communication unified.</p>
        </div>

        <div class="pills">
            <div class="pill"><i class="fas fa-shield-alt"></i> End-to-end encrypted access</div>
            <div class="pill"><i class="fas fa-bolt"></i> Real-time payment tracking</div>
            <div class="pill"><i class="fas fa-bell"></i> Instant push notifications</div>
            <div class="pill"><i class="fas fa-chart-bar"></i> AI-powered financial reports</div>
        </div>
    </div>

    <!-- ── RIGHT ── -->
    <div class="panel-r">
        <div class="tag-line">Welcome back</div>
        <div class="form-h">Sign in</div>
        <div class="form-sub">Enter your credentials to access your workspace</div>

        <?php if (isset($_GET['error'])): ?>
        <div class="alert-err">
            <i class="fas fa-circle-exclamation"></i>
            <?= htmlspecialchars($_GET['error']) ?>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" id="lf" novalidate>
            <?= csrf_input() ?>

            <div class="field">
                <label class="field-lbl" for="username">Username or Email</label>
                <div class="field-wrap">
                    <input type="text" class="field-in" id="username" name="username"
                        placeholder="you@email.com"
                        autocapitalize="none" autocomplete="username email" spellcheck="false"
                        value="<?= isset($_SESSION['last_login_identifier']) ? htmlspecialchars($_SESSION['last_login_identifier']) : '' ?>"
                        required>
                    <i class="fas fa-user field-ico"></i>
                </div>
                <div class="ferr" id="uErr">Enter a valid username or email.</div>
            </div>

            <div class="field">
                <label class="field-lbl" for="password">Password</label>
                <div class="pw-row">
                    <div class="field-wrap">
                        <input type="password" class="field-in" id="password" name="password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            style="padding-right:2.6rem;"
                            required>
                        <i class="fas fa-lock field-ico"></i>
                        <button type="button" class="pw-eye" id="eyeBtn" tabindex="-1" aria-label="Toggle password">
                            <i class="fas fa-eye" id="eyeIco"></i>
                        </button>
                    </div>
                    <div class="ferr" id="pErr">Password is required.</div>
                    <div id="caps"><i class="fas fa-triangle-exclamation"></i> Caps Lock is ON</div>
                </div>
            </div>

            <button type="submit" class="btn-go" id="subBtn">
                <span class="btn-lbl">Sign In &nbsp;<i class="fas fa-arrow-right" style="font-size:.78rem"></i></span>
                <span class="btn-spinner"><i class="fas fa-circle-notch fa-spin"></i></span>
            </button>

            <div id="lockWrap"></div>
        </form>

        <div class="div-row">
            <div class="div-line"></div>
            <span class="div-txt">Secure access</span>
            <div class="div-line"></div>
        </div>

        <div class="form-foot">
            <a href="forgot_password.php"><i class="fas fa-key me-1"></i>Forgot your password?</a>
        </div>
    </div>
</div>

<script>
(function(){
    /* theme */
    const body = document.body;
    if(localStorage.getItem('sw_theme')==='dark') body.classList.add('dark');
    document.getElementById('themeBtn').addEventListener('click',()=>{
        const d = body.classList.toggle('dark');
        localStorage.setItem('sw_theme', d?'dark':'light');
    });

    /* form */
    const form   = document.getElementById('lf');
    const uInput = document.getElementById('username');
    const pInput = document.getElementById('password');
    const subBtn = document.getElementById('subBtn');
    const eyeBtn = document.getElementById('eyeBtn');
    const eyeIco = document.getElementById('eyeIco');
    const caps   = document.getElementById('caps');
    const uErr   = document.getElementById('uErr');
    const pErr   = document.getElementById('pErr');
    const lockW  = document.getElementById('lockWrap');

    eyeBtn.addEventListener('click',()=>{
        const t = pInput.type==='text';
        pInput.type = t?'password':'text';
        eyeIco.className = t?'fas fa-eye':'fas fa-eye-slash';
    });

    const updCaps = e=>{ try{ caps.classList.toggle('on',e.getModifierState('CapsLock')); }catch(_){} };
    pInput.addEventListener('keydown',updCaps);
    pInput.addEventListener('keyup',updCaps);
    pInput.addEventListener('focus',updCaps);
    pInput.addEventListener('blur',()=>caps.classList.remove('on'));

    const params   = new URLSearchParams(location.search);
    let remain     = parseInt(params.get('remain')||'0',10);
    const isLocked = params.get('lock')==='1' && remain>0;

    if(isLocked){
        const a=document.querySelector('.alert-err'); if(a) a.style.display='none';
        pInput.disabled=true; subBtn.disabled=true; countdown();
    }
    const srvErr = params.get('error');
    if(!isLocked && srvErr){
        const a=document.querySelector('.alert-err'); if(a) a.style.display='none';
        pErr.textContent=srvErr; pErr.classList.add('show');
        pInput.classList.add('bad'); try{pInput.focus();}catch(_){}
    }

    function renderLock(s){
        lockW.innerHTML=`<div class="lock-box"><i class="fas fa-clock"></i> Too many attempts — retry in ${s}s</div>`;
    }
    function countdown(){
        renderLock(remain);
        const iv=setInterval(()=>{
            remain=Math.max(0,remain-1); renderLock(remain);
            if(remain<=0){
                clearInterval(iv); pInput.disabled=false; subBtn.disabled=false; lockW.innerHTML='';
                try{ const u=new URL(location); u.searchParams.delete('lock'); u.searchParams.delete('remain'); history.replaceState({},'',u); }catch(_){}
            }
        },1000);
    }

    const valU=()=>{ const v=(uInput.value||'').trim(); const ok=/^[A-Za-z0-9_]{3,20}$/.test(v)||/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); uInput.classList.toggle('bad',!ok); uErr.classList.toggle('show',!ok); return ok; };
    const valP=()=>{ const ok=pInput.value.length>0; pInput.classList.toggle('bad',!ok); pErr.classList.toggle('show',!ok); return ok; };

    uInput.addEventListener('input',()=>{ uInput.classList.remove('bad'); uErr.classList.remove('show'); });
    pInput.addEventListener('input',()=>{ pInput.classList.remove('bad'); pErr.classList.remove('show'); });

    form.addEventListener('submit',e=>{
        if(isLocked&&remain>0){e.preventDefault();return;}
        if(!(valU()&valP())){e.preventDefault();return;}
        subBtn.classList.add('spin'); subBtn.disabled=true;
    });
})();
</script>
</body>
</html>
