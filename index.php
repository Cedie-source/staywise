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
    <meta name="theme-color" content="#0d6efd"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:      #0d6efd;
            --primary-dark: #0a58ca;
            --primary-glow: rgba(13,110,253,.15);
            --green:        #16a34a;
            --green-dark:   #15803d;
            --teal:         #4ED6C1;
            --teal-dark:    #0f766e;
            --danger:       #dc3545;
            --bg:           #f1f5f9;
            --surface:      #ffffff;
            --border:       #e2e8f0;
            --border-md:    #d1d5db;
            --text:         #1e293b;
            --text-2:       #374151;
            --muted:        #64748b;
            --muted-2:      #94a3b8;
        }

        html, body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        body::before {
            content: '';
            position: fixed; inset: 0; z-index: 0;
            background:
                radial-gradient(ellipse 60% 50% at 5% 10%, rgba(13,110,253,.05) 0%, transparent 55%),
                radial-gradient(ellipse 50% 50% at 95% 90%, rgba(78,214,193,.06) 0%, transparent 55%);
        }
        body::after {
            content: '';
            position: fixed; inset: 0; z-index: 0;
            background-image: radial-gradient(circle, rgba(100,116,139,.15) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 30%, transparent 80%);
        }

        .shell {
            position: relative; z-index: 1;
            width: 100%; max-width: 880px;
            margin: 1.25rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-radius: 20px;
            overflow: hidden;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 8px 24px rgba(0,0,0,.08), 0 32px 64px rgba(0,0,0,.05);
            animation: rise .5s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes rise {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── LEFT PANEL — dark navy ── */
        .panel-left {
            background: linear-gradient(160deg, #0f172a 0%, #1e293b 100%);
            padding: 2.75rem 2.25rem;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
            border-right: 1px solid rgba(255,255,255,.05);
        }

        /* Top-left blue glow */
        .panel-left::before {
            content: '';
            position: absolute; top: -80px; left: -80px;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(13,110,253,.18) 0%, transparent 70%);
            border-radius: 50%;
        }
        /* Bottom-right teal glow */
        .panel-left::after {
            content: '';
            position: absolute; bottom: -60px; right: -60px;
            width: 240px; height: 240px;
            background: radial-gradient(circle, rgba(78,214,193,.12) 0%, transparent 70%);
            border-radius: 50%;
        }

        .left-inner { position: relative; z-index: 1; }

        .brand { display: flex; align-items: center; gap: .65rem; margin-bottom: 2.25rem; }
        .brand-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: .95rem; color: #fff;
            box-shadow: 0 4px 14px rgba(13,110,253,.4);
            flex-shrink: 0;
        }
        .brand-name { font-size: 1.2rem; font-weight: 700; color: #f1f5f9; letter-spacing: -.01em; }
        .brand-name em { color: var(--teal); font-style: normal; }

        .left-headline {
            font-size: 1.75rem; font-weight: 700; line-height: 1.22;
            color: #f1f5f9; margin-bottom: .8rem; letter-spacing: -.02em;
        }
        .left-headline span {
            background: linear-gradient(90deg, var(--primary), var(--teal));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .left-desc {
            font-size: .8rem; color: #94a3b8; line-height: 1.7;
            font-weight: 400; max-width: 235px; opacity: .85;
        }

        .left-footer { position: relative; z-index: 1; }
        .feat-list { list-style: none; display: flex; flex-direction: column; gap: .5rem; }
        .feat-list li {
            display: flex; align-items: center; gap: .6rem;
            font-size: .78rem; color: #94a3b8; font-weight: 400;
        }
        .feat-pip {
            width: 26px; height: 26px; border-radius: 7px;
            display: flex; align-items: center; justify-content: center;
            font-size: .62rem; flex-shrink: 0;
        }
        .feat-pip.teal  { background: rgba(78,214,193,.15);  color: var(--teal); }
        .feat-pip.blue  { background: rgba(13,110,253,.12);  color: var(--primary); }
        .feat-pip.green { background: rgba(22,163,74,.12);   color: var(--green-dark); }

        /* ── RIGHT PANEL ── */
        .panel-right {
            padding: 2.75rem 2.5rem;
            display: flex; flex-direction: column; justify-content: center;
            background: var(--surface);
            border-top: 3px solid var(--primary);
        }

        .form-title { font-size: 1.9rem; font-weight: 700; color: var(--text); margin-bottom: .3rem; letter-spacing: -.03em; }
        .form-sub   { font-size: .75rem; color: var(--muted); margin-bottom: 1.75rem; font-weight: 400; }

        .login-alert {
            display: flex; align-items: center; gap: .55rem;
            background: #fef2f2; border: 1px solid #fecaca;
            border-radius: 9px; padding: .6rem .85rem;
            font-size: .8rem; color: var(--danger); font-weight: 500;
            margin-bottom: 1.2rem; animation: shake .4s ease;
        }
        @keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-3px)} 40%,80%{transform:translateX(3px)} }

        .field { margin-bottom: 1rem; }
        .field-label {
            display: block; font-size: .78rem; font-weight: 600;
            color: var(--muted); margin-bottom: .45rem;
        }
        .field-inner { position: relative; }
        .field-icon {
            position: absolute; left: .85rem; top: 50%; transform: translateY(-50%);
            color: var(--muted-2); font-size: .78rem; pointer-events: none; transition: color .15s;
        }
        .field-input {
            width: 100%; padding: .65rem .9rem .65rem 2.4rem;
            border: 1.5px solid var(--border-md); border-radius: 8px;
            font-size: .88rem; font-family: 'DM Sans', sans-serif; font-weight: 400;
            color: var(--text-2); background: var(--surface); outline: none;
            transition: border-color .15s, box-shadow .15s; caret-color: var(--primary);
        }
        .field-input::placeholder { color: var(--muted-2); }
        .field-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
        .field-input:focus ~ .field-icon { color: var(--primary); }
        .field-input.is-invalid { border-color: var(--danger); box-shadow: 0 0 0 3px rgba(220,53,69,.1); }
        .field-input:-webkit-autofill,
        .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text-2) !important;
            box-shadow: 0 0 0px 1000px #fff inset, 0 0 0 3px var(--primary-glow) !important;
            border-color: var(--primary) !important;
        }

        .pw-wrap { position: relative; padding-bottom: 1.2rem; }
        .pw-toggle {
            position: absolute; right: .85rem; top: 50%; transform: translateY(-60%);
            background: none; border: none; color: var(--muted-2);
            cursor: pointer; font-size: .82rem; padding: 0; z-index: 2; transition: color .15s;
        }
        .pw-toggle:hover { color: var(--primary); }
        #capsLockHint {
            position: absolute; bottom: 0; left: 0;
            font-size: .73rem; color: #d97706; font-weight: 600;
            display: flex; align-items: center; gap: .3rem;
            visibility: hidden; opacity: 0; transition: opacity .15s;
        }
        #capsLockHint.show { visibility: visible; opacity: 1; }
        .field-error { font-size: .74rem; color: var(--danger); font-weight: 500; margin-top: .3rem; display: none; }
        .field-error.show { display: block; }

        .btn-signin {
            width: 100%; background: var(--green); border: none; border-radius: 8px;
            color: #fff; font-size: .88rem; font-weight: 600;
            font-family: 'DM Sans', sans-serif; padding: .7rem 1rem;
            cursor: pointer; position: relative; overflow: hidden;
            transition: background .15s, transform .12s, box-shadow .15s;
            box-shadow: 0 2px 8px rgba(22,163,74,.25); margin-top: .2rem;
        }
        .btn-signin:hover:not(:disabled) { background: var(--green-dark); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(22,163,74,.32); }
        .btn-signin:active:not(:disabled) { transform: translateY(0); }
        .btn-signin:disabled { opacity: .55; cursor: not-allowed; }
        .btn-signin .btn-text { transition: opacity .15s; }
        .btn-signin .btn-spinner { display: none; position: absolute; inset: 0; align-items: center; justify-content: center; }
        .btn-signin.loading .btn-text { opacity: 0; }
        .btn-signin.loading .btn-spinner { display: flex; }

        .lock-hint {
            background: #fffbeb; border: 1px solid #fde68a;
            border-radius: 8px; padding: .5rem .8rem;
            font-size: .78rem; color: #d97706; font-weight: 500;
            margin-top: .5rem; display: flex; align-items: center; gap: .4rem;
        }

        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.4rem 0 0; }
        .divider-line { flex: 1; height: 1px; background: var(--border); }
        .divider-text { font-size: .69rem; color: var(--muted-2); letter-spacing: .06em; text-transform: uppercase; }

        .form-footer { margin-top: .9rem; text-align: center; font-size: .76rem; color: var(--muted); }
        .form-footer a { color: var(--primary); font-weight: 600; text-decoration: none; transition: color .15s; }
        .form-footer a:hover { color: var(--primary-dark); }

        @media (max-width: 640px) {
            .shell { grid-template-columns: 1fr; max-width: 400px; }
            .panel-left { display: none; }
            .panel-right { padding: 2.25rem 1.75rem; border-top: 3px solid var(--primary); }
        }
    </style>
</head>
<body>
<div class="shell">
    <!-- LEFT -->
    <div class="panel-left">
        <div class="left-inner">
            <div class="brand">
                <div class="brand-icon"><i class="fas fa-building"></i></div>
                <div class="brand-name">Stay<em>Wise</em></div>
            </div>
            <div class="left-headline">
                Property management<br>
                <span>made simple.</span>
            </div>
            <p class="left-desc">Track payments, manage tenants, and handle everything from one clean dashboard.</p>
        </div>
        <div class="left-footer">
            <ul class="feat-list">
                <li><div class="feat-pip teal"><i class="fas fa-shield-alt"></i></div> Secure &amp; encrypted access</li>
                <li><div class="feat-pip blue"><i class="fas fa-bolt"></i></div> Real-time payment tracking</li>
                <li><div class="feat-pip green"><i class="fas fa-bell"></i></div> Instant notifications</li>
                <li><div class="feat-pip teal"><i class="fas fa-chart-line"></i></div> Financial reports &amp; history</li>
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
                           placeholder="your@email.com"
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

            <button type="submit" class="btn-signin" id="submitBtn">
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
    const form=document.getElementById('loginForm'),username=document.getElementById('username'),password=document.getElementById('password'),submitBtn=document.getElementById('submitBtn'),pwToggle=document.getElementById('pwToggle'),pwIcon=document.getElementById('pwIcon'),caps=document.getElementById('capsLockHint'),uErr=document.getElementById('usernameError'),pErr=document.getElementById('passwordError'),lockWrap=document.getElementById('lockHintWrap');
    pwToggle.addEventListener('click',()=>{const t=password.type==='text';password.type=t?'password':'text';pwIcon.className=t?'fas fa-eye':'fas fa-eye-slash';});
    function updateCaps(e){try{caps.classList.toggle('show',e.getModifierState('CapsLock'));}catch(_){}}
    password.addEventListener('keydown',updateCaps);password.addEventListener('keyup',updateCaps);password.addEventListener('focus',updateCaps);password.addEventListener('blur',()=>caps.classList.remove('show'));
    const params=new URLSearchParams(window.location.search);let remain=parseInt(params.get('remain')||'0',10);const isLocked=params.get('lock')==='1'&&remain>0;
    if(isLocked){const a=document.querySelector('.login-alert');if(a)a.style.display='none';password.disabled=true;submitBtn.disabled=true;startCountdown();}
    const serverError=params.get('error');
    if(!isLocked&&serverError){const a=document.querySelector('.login-alert');if(a)a.style.display='none';pErr.textContent=serverError;pErr.classList.add('show');password.classList.add('is-invalid');try{password.focus();}catch(_){}}
    function renderLock(s){lockWrap.innerHTML=`<div class="lock-hint"><i class="fas fa-clock"></i> Too many attempts — retry in ${s}s</div>`;}
    function startCountdown(){renderLock(remain);const iv=setInterval(()=>{remain=Math.max(0,remain-1);renderLock(remain);if(remain<=0){clearInterval(iv);password.disabled=false;submitBtn.disabled=false;lockWrap.innerHTML='';try{const u=new URL(window.location);u.searchParams.delete('lock');u.searchParams.delete('remain');window.history.replaceState({},'',u);}catch(_){}}},1000);}
    function vUser(){const v=(username.value||'').trim(),ok=/^[A-Za-z0-9_]{3,20}$/.test(v)||/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);username.classList.toggle('is-invalid',!ok);uErr.classList.toggle('show',!ok);return ok;}
    function vPass(){const ok=password.value.length>0;password.classList.toggle('is-invalid',!ok);pErr.classList.toggle('show',!ok);return ok;}
    username.addEventListener('input',()=>{username.classList.remove('is-invalid');uErr.classList.remove('show');});
    password.addEventListener('input',()=>{password.classList.remove('is-invalid');pErr.classList.remove('show');});
    form.addEventListener('submit',function(e){if(isLocked&&remain>0){e.preventDefault();return;}const ok=vUser()&vPass();if(!ok){e.preventDefault();return;}submitBtn.classList.add('loading');submitBtn.disabled=true;});
})();
</script>
</body>
</html>
