<?php
require_once 'includes/security.php';
set_secure_session_cookies();
session_start();
require_once 'config/db.php';

// Must have passed OTP verification
if (empty($_SESSION['fp_verified']) || empty($_SESSION['fp_reset_uid'])) {
    header('Location: forgot_password.php');
    exit();
}

$page_title = 'Reset Password';
$errors     = [];
$success    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $new_pass    = $_POST['new_password'] ?? '';
        $confirm     = $_POST['confirm_password'] ?? '';
        $user_id     = (int)$_SESSION['fp_reset_uid'];

        if (strlen($new_pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($new_pass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);

            $conn2 = new mysqli(
                getenv('MYSQLHOST') ?: 'localhost',
                getenv('MYSQLUSER') ?: 'root',
                getenv('MYSQLPASSWORD') ?: '',
                getenv('MYSQLDATABASE') ?: 'staywise',
                (int)(getenv('MYSQLPORT') ?: 3306)
            );

            $stmt = $conn2->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?");
            $stmt->bind_param('si', $hash, $user_id);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();
            $conn2->close();

            if ($ok) {
                // Clear all forgot-password session data
                unset($_SESSION['fp_verified'], $_SESSION['fp_reset_uid'], $_SESSION['fp_email_hint']);
                $success = true;
            } else {
                $errors[] = 'Failed to update password. Please try again.';
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StayWise — Reset Password</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --gold: #c9a84c; --gold-light: #e2c97e; --gold-dim: rgba(201,168,76,.15);
            --blue: #007DFE; --teal: #4ED6C1;
            --bg: #080c14; --surface: #0e1420; --surface-2: #141b28;
            --border: rgba(255,255,255,.07); --border-gold: rgba(201,168,76,.25);
            --text: #f0ede8; --muted: #6b7280; --muted-2: #9ca3af;
        }
        html, body {
            min-height: 100vh; font-family: 'DM Sans', sans-serif;
            background: var(--bg); color: var(--text);
            display: flex; align-items: center; justify-content: center;
        }
        .bg-scene { position: fixed; inset: 0; z-index: 0; }
        .bg-scene::before {
            content: ''; position: absolute; inset: 0;
            background-image: linear-gradient(rgba(255,255,255,.015) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.015) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black, transparent);
        }
        .bg-scene::after {
            content: ''; position: absolute; inset: 0;
            background:
                radial-gradient(ellipse 60% 50% at 20% 20%, rgba(0,125,254,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 80% 80%, rgba(201,168,76,.05) 0%, transparent 60%);
        }
        .wrap {
            position: relative; z-index: 1; width: 100%; max-width: 420px; padding: 1rem;
            animation: appear .5s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes appear { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
        .card {
            background: var(--surface); border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 0 0 1px rgba(201,168,76,.06), 0 40px 80px rgba(0,0,0,.6);
            padding: 2.5rem; position: relative; overflow: hidden;
        }
        .card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, var(--blue), var(--gold), var(--teal));
        }
        .brand { display: flex; align-items: center; gap: .65rem; margin-bottom: 2rem; }
        .brand-icon {
            width: 40px; height: 40px; background: linear-gradient(135deg, #1a2540, #0e1830);
            border: 1px solid var(--border-gold); border-radius: 10px;
            display: flex; align-items: center; justify-content: center; color: var(--gold); font-size: 1rem;
        }
        .brand-name { font-family: 'Cormorant Garamond', serif; font-size: 1.3rem; font-weight: 700; color: var(--text); }
        .brand-name em { color: var(--gold); font-style: normal; }
        .page-title { font-family: 'Cormorant Garamond', serif; font-size: 1.75rem; font-weight: 600; color: var(--text); margin-bottom: .3rem; }
        .page-sub { font-size: .83rem; color: var(--muted); margin-bottom: 1.75rem; line-height: 1.6; }
        .alert-error {
            display: flex; align-items: center; gap: .6rem;
            background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2);
            border-radius: 10px; padding: .65rem .9rem;
            font-size: .82rem; color: #f87171; font-weight: 500; margin-bottom: 1.25rem;
            animation: shake .4s ease;
        }
        @keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-4px)} 40%,80%{transform:translateX(4px)} }
        .alert-success {
            text-align: center; padding: 1.5rem 1rem;
        }
        .success-icon {
            width: 64px; height: 64px; border-radius: 50%;
            background: rgba(78,214,193,.1); border: 2px solid rgba(78,214,193,.3);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; color: var(--teal); margin: 0 auto 1rem;
        }
        .success-title { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 600; color: var(--text); margin-bottom: .4rem; }
        .success-sub { font-size: .83rem; color: var(--muted); margin-bottom: 1.5rem; }
        .field-wrap { margin-bottom: 1.1rem; }
        .field-label { display: block; font-size: .72rem; font-weight: 600; color: var(--muted-2); letter-spacing: .08em; text-transform: uppercase; margin-bottom: .5rem; }
        .field-inner { position: relative; }
        .field-icon { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .8rem; pointer-events: none; transition: color .2s; }
        .field-input {
            width: 100%; height: 50px; background: var(--surface-2);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 0 2.75rem 0 2.5rem; font-size: .9rem; font-family: 'DM Sans', sans-serif;
            color: var(--text); outline: none; caret-color: var(--gold);
            transition: border-color .2s, box-shadow .2s;
        }
        .field-input::placeholder { color: #374151; }
        .field-input:focus { border-color: var(--border-gold); box-shadow: 0 0 0 3px rgba(201,168,76,.08); }
        .field-input:focus ~ .field-icon { color: var(--gold); }
        .field-input.is-invalid { border-color: rgba(239,68,68,.4); }
        .field-input:-webkit-autofill, .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text) !important;
            box-shadow: 0 0 0px 1000px var(--surface-2) inset !important;
            border-color: var(--border-gold) !important;
        }
        .pw-toggle {
            position: absolute; right: .9rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--muted); cursor: pointer;
            font-size: .85rem; padding: 0; transition: color .2s; z-index: 2;
        }
        .pw-toggle:hover { color: var(--gold); }
        /* Strength meter */
        .strength-bar { height: 3px; border-radius: 2px; background: var(--border); margin-top: .5rem; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 2px; width: 0%; transition: width .3s, background .3s; }
        .strength-text { font-size: .72rem; color: var(--muted); margin-top: .3rem; }
        .field-error { font-size: .75rem; color: #f87171; font-weight: 500; margin-top: .3rem; display: none; }
        .field-error.show { display: block; }
        .btn-main {
            width: 100%; height: 50px;
            background: linear-gradient(135deg, var(--gold) 0%, #a8832e 100%);
            border: none; border-radius: 10px;
            color: #0a0d14; font-size: .9rem; font-weight: 700; font-family: 'DM Sans', sans-serif;
            letter-spacing: .04em; text-transform: uppercase; cursor: pointer; position: relative; overflow: hidden;
            transition: transform .15s, box-shadow .15s; box-shadow: 0 4px 20px rgba(201,168,76,.25);
        }
        .btn-main::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.15), transparent); transition: left .4s;
        }
        .btn-main:hover:not(:disabled)::before { left: 100%; }
        .btn-main:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(201,168,76,.35); }
        .btn-main:disabled { opacity: .5; cursor: not-allowed; }
        .btn-signin { display: block; width: 100%; height: 50px; background: linear-gradient(135deg, var(--blue), #0060cc); border: none; border-radius: 10px; color: #fff; font-size: .9rem; font-weight: 700; font-family: 'DM Sans', sans-serif; letter-spacing: .04em; text-transform: uppercase; cursor: pointer; text-decoration: none; line-height: 50px; text-align: center; transition: transform .15s, box-shadow .15s; box-shadow: 0 4px 20px rgba(0,125,254,.25); }
        .btn-signin:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(0,125,254,.35); color: #fff; }
        .back-link { display: block; text-align: center; margin-top: 1.5rem; font-size: .78rem; color: var(--muted); text-decoration: none; }
        .back-link:hover { color: var(--gold); }
    </style>
</head>
<body>
<div class="bg-scene"></div>
<div class="wrap">
    <div class="card">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-building"></i></div>
            <div class="brand-name">Stay<em>Wise</em></div>
        </div>

        <?php if ($success): ?>
        <div class="alert-success">
            <div class="success-icon"><i class="fas fa-check"></i></div>
            <div class="success-title">Password updated!</div>
            <div class="success-sub">Your password has been reset successfully. You can now sign in with your new password.</div>
            <a href="index.php" class="btn-signin">Sign In Now</a>
        </div>

        <?php else: ?>
        <div class="page-title">Set new password</div>
        <div class="page-sub">Choose a strong password for your account.</div>

        <?php if (!empty($errors)): ?>
        <div class="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($errors[0]) ?></div>
        <?php endif; ?>

        <form method="POST" id="resetForm" novalidate>
            <?= csrf_input() ?>

            <div class="field-wrap">
                <label class="field-label" for="new_password">New Password</label>
                <div class="field-inner">
                    <input type="password" class="field-input" id="new_password" name="new_password"
                           placeholder="Min. 8 characters" autocomplete="new-password" required>
                    <i class="fas fa-lock field-icon"></i>
                    <button type="button" class="pw-toggle" id="toggle1" tabindex="-1"><i class="fas fa-eye" id="icon1"></i></button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-text" id="strengthText"></div>
                <div class="field-error" id="pwError"></div>
            </div>

            <div class="field-wrap">
                <label class="field-label" for="confirm_password">Confirm Password</label>
                <div class="field-inner">
                    <input type="password" class="field-input" id="confirm_password" name="confirm_password"
                           placeholder="Repeat your password" autocomplete="new-password" required>
                    <i class="fas fa-lock field-icon"></i>
                    <button type="button" class="pw-toggle" id="toggle2" tabindex="-1"><i class="fas fa-eye" id="icon2"></i></button>
                </div>
                <div class="field-error" id="confirmError"></div>
            </div>

            <button type="submit" class="btn-main" id="submitBtn">Reset Password</button>
        </form>

        <a href="index.php" class="back-link"><i class="fas fa-arrow-left me-1"></i>Back to Sign In</a>
        <?php endif; ?>
    </div>
</div>

<script>
// Toggle password visibility
function makeToggle(toggleId, iconId, inputId) {
    document.getElementById(toggleId).addEventListener('click', function () {
        const inp = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        const isText = inp.type === 'text';
        inp.type = isText ? 'password' : 'text';
        icon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
    });
}
makeToggle('toggle1', 'icon1', 'new_password');
makeToggle('toggle2', 'icon2', 'confirm_password');

// Strength meter
const pwInput   = document.getElementById('new_password');
const fill      = document.getElementById('strengthFill');
const fillText  = document.getElementById('strengthText');
const levels    = [
    { label: 'Too short',  color: '#ef4444', pct: 15 },
    { label: 'Weak',       color: '#f97316', pct: 35 },
    { label: 'Fair',       color: '#eab308', pct: 60 },
    { label: 'Good',       color: '#22c55e', pct: 80 },
    { label: 'Strong',     color: '#4ED6C1', pct: 100 },
];
function getStrength(v) {
    if (v.length < 8) return 0;
    let s = 1;
    if (v.length >= 10) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    return Math.min(s, 4);
}
pwInput.addEventListener('input', function () {
    const v = this.value;
    if (!v) { fill.style.width = '0'; fillText.textContent = ''; return; }
    const lvl = getStrength(v);
    const d = levels[lvl];
    fill.style.width = d.pct + '%';
    fill.style.background = d.color;
    fillText.textContent = d.label;
    fillText.style.color = d.color;
});

// Form validation
const form = document.getElementById('resetForm');
const confirmInput = document.getElementById('confirm_password');
const pwErr = document.getElementById('pwError');
const confErr = document.getElementById('confirmError');
const submitBtn = document.getElementById('submitBtn');

form.addEventListener('submit', function (e) {
    let ok = true;
    if (pwInput.value.length < 8) {
        pwErr.textContent = 'Password must be at least 8 characters.';
        pwErr.classList.add('show');
        pwInput.classList.add('is-invalid');
        ok = false;
    } else {
        pwErr.classList.remove('show');
        pwInput.classList.remove('is-invalid');
    }
    if (confirmInput.value !== pwInput.value) {
        confErr.textContent = 'Passwords do not match.';
        confErr.classList.add('show');
        confirmInput.classList.add('is-invalid');
        ok = false;
    } else {
        confErr.classList.remove('show');
        confirmInput.classList.remove('is-invalid');
    }
    if (!ok) { e.preventDefault(); return; }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Updating...';
});
</script>
</body>
</html>
