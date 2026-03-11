<?php
require_once 'includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
// If user is already logged in, redirect to appropriate dashboard
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
    <title>StayWise Login</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"/>
    <link rel="alternate icon" type="image/png" href="/assets/favicon-32.png"/>
    <link rel="apple-touch-icon" href="/assets/icon-192.png"/>
    <meta name="theme-color" content="#4ED6C1"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- style.css intentionally excluded: login page uses self-contained inline styles -->
    <style>
        /* ===== Dark mode login ===== */
        html, body {
            background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%) !important;
            background-attachment: fixed !important;
            color: #E5E7EB;
            font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            height: 100%;
            margin: 0;
        }
        .login-card {
            background: #181B2A;
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.55);
            padding: 2.5rem;
            width: 100%;
            max-width: 450px;
            color: #E5E7EB;
        }
        @media (max-width: 480px) {
            .login-card { max-width: 360px; }
        }
        .login-card h1 {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            text-align: center;
            background: linear-gradient(90deg, #60A5FA, #3B82F6);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .login-card .form-label { color: #E5E7EB; font-weight: 600; letter-spacing: 0.2px; margin-bottom: 0.3rem; font-size: 1.1rem; }
        .login-card .form-text { margin-top: 0.15rem; margin-bottom: 0; color: #A3A7B3; }
        /* Caps Lock hint: always reserve space, use visibility+opacity so no layout shift */
        .password-wrapper {
            position: relative;
            padding-bottom: 1.5rem; /* always reserve space for the hint */
        }
        .pw-toggle-btn {
            position: absolute;
            top: calc(0.375rem + 0.75em + 10px);
            right: 14px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
            padding: 0;
            font-size: 1.1rem;
            z-index: 5;
            line-height: 1;
        }
        .pw-toggle-btn:hover { color: #60A5FA; }
        #capsLockHint {
            position: absolute;
            left: 0;
            bottom: 0;
            z-index: 10;
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.2s ease;
            pointer-events: none;
            margin: 0 !important;
            font-size: 0.85rem;
        }
        #capsLockHint.caps-visible {
            visibility: visible;
            opacity: 1;
        }
        .login-card .form-control {
            background: #232636;
            border: 1px solid #3B82F6;
            color: #E5E7EB;
            caret-color: #E5E7EB;
            height: 56px;
            padding: 0.7rem 1rem;
            border-radius: 14px;
            font-size: 1.1rem;
        }
        .login-card .form-control::placeholder { color: #94A3B8; }
        .login-card .form-control:focus {
            border-color: #60A5FA;
            box-shadow: 0 0 0 0.2rem rgba(96, 165, 250, 0.25);
        }
        /* Reduce vertical gaps between fields and button */
        .login-card .mb-0 { margin-bottom: 0 !important; }
        .login-card .invalid-feedback { margin-top: 0.25rem; }
        .login-card .btn-primary {
            background: linear-gradient(90deg, #3B82F6 0%, #2563EB 100%);
            border: none;
            width: 100%;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 700;
            color: #E5E7EB;
            border-radius: 14px;
            transition: background 0.3s;
            margin-top: 0.5rem;
        }
        .login-card .btn-primary:hover { background: linear-gradient(90deg, #2563EB 0%, #3B82F6 100%); }
        /* Keep dark background on browser autofill (Chrome/Edge/Safari) */
        .login-card .form-control:-webkit-autofill,
        .login-card .form-control:-webkit-autofill:hover,
        .login-card .form-control:-webkit-autofill:focus {
            -webkit-text-fill-color: #E5E7EB !important;
            box-shadow: 0 0 0px 1000px #232636 inset !important;
            border: 1px solid #3B82F6 !important;
        }
        /* Firefox autofill */
        .login-card .form-control:-moz-autofill {
            background-color: #232636 !important;
            color: #E5E7EB !important;
        }

    </style>
</head>
<body>
    <div class="login-card">
        <h1>Welcome to StayWise</h1>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        <form action="login.php" method="POST" class="needs-validation" novalidate id="loginForm">
            <?php echo csrf_input(); ?>
                        <div class="mb-0">
                                <label for="username" class="form-label">Username or Email</label>
                                    <input type="text" class="form-control" id="username" name="username" required autocapitalize="none" autocomplete="username email" spellcheck="false" value="<?php echo isset($_SESSION['last_login_identifier']) ? htmlspecialchars($_SESSION['last_login_identifier']) : ''; ?>">
                        </div>
            <div class="mb-0">
                <label for="password" class="form-label">Password</label>
                <div class="password-wrapper">
                    <input type="password" class="form-control" id="password" name="password" required autocapitalize="none" autocomplete="current-password" style="padding-right:2.8rem">
                    <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                    <div class="invalid-feedback">Password is required.</div>
                    <div id="capsLockHint" class="form-text text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Caps Lock is ON</div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Login</button>
        </form>
        
        <script>
        (function(){
            const form = document.getElementById('loginForm');
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            const submitBtn = document.querySelector('.btn.btn-primary');
            const caps = document.getElementById('capsLockHint');
            // Lock handling: read remaining seconds from URL and disable password if locked
            const params = new URLSearchParams(window.location.search);
            let remain = parseInt(params.get('remain') || '0', 10);
            const isLocked = params.get('lock') === '1' && remain > 0;
            const serverError = params.get('error');
            let lockInterval = null;
            // Hide static error alert when locked; rely on realtime hint instead
            if (isLocked) {
                const alertEl = document.querySelector('.alert.alert-danger');
                if (alertEl) { alertEl.style.display = 'none'; }
            }
            // Option 1: Show server error inline under password (and hide alert)
            if (!isLocked && serverError) {
                const alertEl = document.querySelector('.alert.alert-danger');
                if (alertEl) { alertEl.style.display = 'none'; }
                let err = document.getElementById('serverError');
                if (!err) {
                    err = document.createElement('div');
                    err.id = 'serverError';
                    err.className = 'form-text text-danger mt-1';
                    password.parentElement.appendChild(err);
                }
                err.textContent = serverError;
                // Mark password invalid and focus it for easy retry
                password.classList.add('is-invalid');
                try { password.focus(); } catch(_) {}
            }
            function renderLockHint(seconds){
                let hint = document.getElementById('lockHint');
                if (!hint) {
                    hint = document.createElement('div');
                    hint.id = 'lockHint';
                    hint.className = 'form-text text-danger mt-1';
                    password.parentElement.appendChild(hint);
                }
                hint.textContent = `Login locked. Please try again after ${seconds} second${seconds === 1 ? '' : 's'}.`;
            }
            function startLockCountdown(){
                if (!isLocked) return;
                // Disable password input and submit during lock
                password.setAttribute('disabled', 'disabled');
                if (submitBtn) submitBtn.setAttribute('disabled', 'disabled');
                renderLockHint(remain);
                lockInterval = setInterval(() => {
                    remain = Math.max(0, remain - 1);
                    renderLockHint(remain);
                    if (remain <= 0) {
                        clearInterval(lockInterval);
                        // Re-enable fields
                        password.removeAttribute('disabled');
                        if (submitBtn) submitBtn.removeAttribute('disabled');
                        // Remove lock hint and clean URL params
                        const hint = document.getElementById('lockHint');
                        if (hint) hint.remove();
                        try {
                            const url = new URL(window.location);
                            url.searchParams.delete('lock');
                            url.searchParams.delete('remain');
                            window.history.replaceState({}, '', url);
                        } catch(_) {}
                    }
                }, 1000);
            }
            startLockCountdown();
            function validateUsername() {
                const v = (username.value || '').trim();
                // Allow either username (3-20 alnum/underscore) OR a valid-looking email
                const isUsername = /^[A-Za-z0-9_]{3,20}$/.test(v);
                const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
                const ok = isUsername || isEmail;
                if (!ok) {
                    username.classList.add('is-invalid');
                } else {
                    username.classList.remove('is-invalid');
                }
                return ok;
            }
            function validatePassword() {
                const v = password.value || '';
                const ok = v.length > 0; // do not enforce complexity at login, just non-empty
                if (!ok) {
                    password.classList.add('is-invalid');
                } else {
                    password.classList.remove('is-invalid');
                }
                return ok;
            }
            function updateCapsLock(e){
                if (!caps) return;
                let on = false;
                try { on = e.getModifierState && e.getModifierState('CapsLock'); } catch(_) {}
                if (on) {
                    caps.classList.add('caps-visible');
                } else {
                    caps.classList.remove('caps-visible');
                }
            }
            if (form) {
                form.addEventListener('submit', function(e){
                    if (isLocked && remain > 0) {
                        e.preventDefault();
                        e.stopPropagation();
                        renderLockHint(remain);
                        return;
                    }
                    // Native validity check + custom guards
                    const uOk = validateUsername();
                    const pOk = validatePassword();
                    if (!uOk || !pOk || !form.checkValidity()) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    form.classList.add('was-validated');
                });
            }
            username.addEventListener('input', function(){ username.classList.remove('is-invalid'); });
            password.addEventListener('input', function(e){ password.classList.remove('is-invalid'); updateCapsLock(e); });
            password.addEventListener('keydown', updateCapsLock);
            password.addEventListener('keyup', updateCapsLock);
            password.addEventListener('focus', function(e){ updateCapsLock(e); });
            password.addEventListener('blur', function(){ if (caps) caps.classList.remove('caps-visible'); });
        })();
        </script>
    </div>
</body>
</html>
