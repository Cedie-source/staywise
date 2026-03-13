<?php
require_once 'includes/security.php';
set_secure_session_cookies();
session_start();
require_once 'config/db.php';
require_once 'includes/email_helper.php';

// Already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$page_title   = 'Forgot Password';
$errors       = [];
$success      = null;
$email_sent   = !empty($_SESSION['fp_otp_code']) && !empty($_SESSION['fp_otp_expires']) && !empty($_SESSION['fp_user_id']);

function _fp_generate_otp() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'send_otp';

        // ── STEP 1: Send OTP ─────────────────────────────────────────
        if ($action === 'send_otp') {
            $email_input = strtolower(trim($_POST['email'] ?? ''));

            if (empty($email_input) || !filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                // Look up user by email
                $conn2 = new mysqli(
                    getenv('MYSQLHOST') ?: 'localhost',
                    getenv('MYSQLUSER') ?: 'root',
                    getenv('MYSQLPASSWORD') ?: '',
                    getenv('MYSQLDATABASE') ?: 'staywise',
                    (int)(getenv('MYSQLPORT') ?: 3306)
                );
                $stmt = $conn2->prepare("SELECT id, username, full_name, email FROM users WHERE LOWER(email) = ? LIMIT 1");
                $stmt->bind_param('s', $email_input);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $conn2->close();

                // Always show success to prevent email enumeration
                if ($user) {
                    $otp  = _fp_generate_otp();
                    $name = !empty($user['full_name']) ? $user['full_name'] : $user['username'];

                    $_SESSION['fp_otp_code']    = $otp;
                    $_SESSION['fp_otp_expires'] = time() + 600; // 10 min
                    $_SESSION['fp_user_id']     = $user['id'];
                    $_SESSION['fp_email_hint']  = substr($email_input, 0, 3) . str_repeat('*', max(0, strpos($email_input, '@') - 3)) . substr($email_input, strpos($email_input, '@'));
                    $_SESSION['fp_otp_attempts'] = 0;

                    // Send email via Brevo API
                    $subject = 'StayWise — Password Reset Code';
                    $body    = "Your password reset code is: <strong style='font-size:1.5rem;letter-spacing:.2em;color:#007DFE;'>{$otp}</strong><br><br>This code expires in 10 minutes. Do not share it with anyone.";

                    try {
                        send_email(
                            $email_input,
                            $name,
                            $subject,
                            $body
                        );
                    } catch (Throwable $e) {
                        // Silently continue — OTP is in session
                    }
                }

                $email_sent = true;
                $_SESSION['fp_email_sent_display'] = true;
            }

        // ── STEP 2: Verify OTP ────────────────────────────────────────
        } elseif ($action === 'verify_otp') {
            $entered = trim($_POST['otp_code'] ?? '');

            if (empty($_SESSION['fp_otp_code']) || empty($_SESSION['fp_otp_expires']) || empty($_SESSION['fp_user_id'])) {
                $errors[] = 'Session expired. Please start over.';
                $email_sent = false;
                unset($_SESSION['fp_otp_code'], $_SESSION['fp_otp_expires'], $_SESSION['fp_user_id'], $_SESSION['fp_otp_attempts']);
            } elseif (time() > $_SESSION['fp_otp_expires']) {
                $errors[] = 'Code has expired. Please request a new one.';
                unset($_SESSION['fp_otp_code'], $_SESSION['fp_otp_expires'], $_SESSION['fp_user_id'], $_SESSION['fp_otp_attempts']);
                $email_sent = false;
            } elseif (empty($entered) || !preg_match('/^\d{6}$/', $entered)) {
                $errors[] = 'Please enter the 6-digit code.';
                $email_sent = true;
            } elseif (!hash_equals($_SESSION['fp_otp_code'], $entered)) {
                $_SESSION['fp_otp_attempts'] = ($_SESSION['fp_otp_attempts'] ?? 0) + 1;
                $remaining = max(0, 5 - $_SESSION['fp_otp_attempts']);
                if ($remaining === 0) {
                    $errors[] = 'Too many incorrect attempts. Please start over.';
                    unset($_SESSION['fp_otp_code'], $_SESSION['fp_otp_expires'], $_SESSION['fp_user_id'], $_SESSION['fp_otp_attempts']);
                    $email_sent = false;
                } else {
                    $errors[] = "Incorrect code. {$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining.";
                    $email_sent = true;
                }
            } else {
                // OTP correct — store verified flag and redirect to reset
                $_SESSION['fp_verified']  = true;
                $_SESSION['fp_reset_uid'] = $_SESSION['fp_user_id'];
                unset($_SESSION['fp_otp_code'], $_SESSION['fp_otp_expires'], $_SESSION['fp_user_id'], $_SESSION['fp_otp_attempts']);
                header('Location: reset_password.php');
                exit();
            }

        // ── Resend OTP ────────────────────────────────────────────────
        } elseif ($action === 'resend_otp') {
            // Restart — clear session and reload
            unset($_SESSION['fp_otp_code'], $_SESSION['fp_otp_expires'], $_SESSION['fp_user_id'], $_SESSION['fp_otp_attempts'], $_SESSION['fp_email_hint']);
            $email_sent = false;
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
    <title>StayWise — Forgot Password</title>
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
            background: var(--surface);
            border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 0 0 1px rgba(201,168,76,.06), 0 40px 80px rgba(0,0,0,.6);
            padding: 2.5rem;
            position: relative; overflow: hidden;
        }
        .card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, var(--blue), var(--gold), var(--teal));
        }

        .brand { display: flex; align-items: center; gap: .65rem; margin-bottom: 2rem; }
        .brand-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #1a2540, #0e1830);
            border: 1px solid var(--border-gold); border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: var(--gold); font-size: 1rem;
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
            display: flex; align-items: flex-start; gap: .6rem;
            background: rgba(78,214,193,.08); border: 1px solid rgba(78,214,193,.2);
            border-radius: 10px; padding: .75rem .9rem;
            font-size: .82rem; color: #4ED6C1; font-weight: 500; margin-bottom: 1.25rem;
        }

        .field-wrap { margin-bottom: 1.1rem; }
        .field-label { display: block; font-size: .72rem; font-weight: 600; color: var(--muted-2); letter-spacing: .08em; text-transform: uppercase; margin-bottom: .5rem; }
        .field-inner { position: relative; }
        .field-icon { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .8rem; pointer-events: none; transition: color .2s; }
        .field-input {
            width: 100%; height: 50px; background: var(--surface-2);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 0 1rem 0 2.5rem; font-size: .9rem; font-family: 'DM Sans', sans-serif;
            color: var(--text); outline: none;
            transition: border-color .2s, box-shadow .2s; caret-color: var(--gold);
        }
        .field-input::placeholder { color: #374151; }
        .field-input:focus { border-color: var(--border-gold); box-shadow: 0 0 0 3px rgba(201,168,76,.08); }
        .field-input:focus ~ .field-icon { color: var(--gold); }
        .field-input:-webkit-autofill, .field-input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text) !important;
            box-shadow: 0 0 0px 1000px var(--surface-2) inset, 0 0 0 3px rgba(201,168,76,.08) !important;
            border-color: var(--border-gold) !important;
        }

        /* OTP input */
        .otp-input {
            width: 100%; height: 58px; background: var(--surface-2);
            border: 1px solid var(--border-gold); border-radius: 12px;
            text-align: center; font-size: 1.6rem; font-weight: 700; letter-spacing: .4em;
            font-family: 'DM Sans', sans-serif; color: var(--gold); outline: none;
            transition: border-color .2s, box-shadow .2s; caret-color: var(--gold);
        }
        .otp-input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(201,168,76,.12); }

        .btn-main {
            width: 100%; height: 50px;
            background: linear-gradient(135deg, var(--gold) 0%, #a8832e 100%);
            border: none; border-radius: 10px;
            color: #0a0d14; font-size: .9rem; font-weight: 700; font-family: 'DM Sans', sans-serif;
            letter-spacing: .04em; text-transform: uppercase; cursor: pointer;
            position: relative; overflow: hidden;
            transition: transform .15s, box-shadow .15s;
            box-shadow: 0 4px 20px rgba(201,168,76,.25);
        }
        .btn-main::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.15), transparent);
            transition: left .4s;
        }
        .btn-main:hover:not(:disabled)::before { left: 100%; }
        .btn-main:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(201,168,76,.35); }
        .btn-main:disabled { opacity: .5; cursor: not-allowed; }

        .btn-ghost {
            width: 100%; height: 44px; background: transparent;
            border: 1px solid var(--border); border-radius: 10px;
            color: var(--muted-2); font-size: .82rem; font-weight: 500; font-family: 'DM Sans', sans-serif;
            cursor: pointer; transition: border-color .2s, color .2s; margin-top: .6rem;
        }
        .btn-ghost:hover { border-color: var(--border-gold); color: var(--gold); }

        .back-link { display: block; text-align: center; margin-top: 1.5rem; font-size: .78rem; color: var(--muted); text-decoration: none; }
        .back-link:hover { color: var(--gold); }
        .back-link i { margin-right: .3rem; }

        .timer { font-size: .75rem; color: var(--muted); text-align: center; margin-top: .6rem; }
        .timer span { color: var(--gold); font-weight: 600; }

        .email-hint {
            font-size: .8rem; color: var(--muted-2);
            background: var(--surface-2); border: 1px solid var(--border);
            border-radius: 8px; padding: .5rem .8rem;
            margin-bottom: 1.1rem; display: flex; align-items: center; gap: .5rem;
        }

        /* ── Light mode ── */
        body.light {
            --gold: #0e7490; --gold-light: #0284c7; --gold-dim: rgba(14,116,144,.12);
            --bg: #dce9ef; --surface: #eaf3f7; --surface-2: #d0e5ed;
            --border: rgba(14,116,144,.13); --border-gold: rgba(14,116,144,.35);
            --text: #091520; --muted: #155e75; --muted-2: #0e7490;
        }
        html, body { transition: background .4s, color .3s; }
        .bg-scene { background: var(--bg); transition: background .4s; }
        body:not(.light) .bg-scene::after {
            background:
                radial-gradient(ellipse 60% 50% at 20% 20%, rgba(0,125,254,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 80% 80%, rgba(201,168,76,.05) 0%, transparent 60%);
        }
        body.light .bg-scene::after {
            background:
                radial-gradient(ellipse 60% 50% at 20% 20%, rgba(12,95,115,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 80% 80%, rgba(11,99,88,.06) 0%, transparent 60%);
        }
        body.light .card {
            background: var(--surface);
            border-color: rgba(14,116,144,.15);
            box-shadow: 0 0 0 1px rgba(14,116,144,.08), 0 24px 60px rgba(8,40,55,.12);
        }
        body.light .card::before { background: linear-gradient(90deg,#0c5f73,#0b6358,#06b6d4); }
        body.light .brand-icon { background: linear-gradient(135deg,#d0e5ed,#bdd8e3); border-color: rgba(14,116,144,.3); }
        body.light .field-input {
            background: #d0e5ed; border-color: rgba(14,116,144,.2);
            color: var(--text);
        }
        body.light .field-input::placeholder { color: #5aacbf; }
        body.light .field-input:focus { border-color: rgba(14,116,144,.5); box-shadow: 0 0 0 3px rgba(14,116,144,.1); }
        body.light .field-input:-webkit-autofill {
            -webkit-text-fill-color: #091520 !important;
            box-shadow: 0 0 0 1000px #d0e5ed inset !important;
        }
        body.light .otp-input { background: #d0e5ed; border-color: rgba(14,116,144,.35); color: #0e7490; }
        body.light .btn-main {
            background: linear-gradient(135deg,#0c5f73,#0b6358);
            color: #fff; box-shadow: 0 4px 20px rgba(14,116,144,.28);
        }
        body.light .btn-main:hover:not(:disabled) { box-shadow: 0 8px 28px rgba(14,116,144,.42); }
        body.light .btn-ghost { border-color: rgba(14,116,144,.2); color: #0e7490; }
        body.light .btn-ghost:hover { border-color: rgba(14,116,144,.4); color: #0c5f73; }
        body.light .alert-success { background: rgba(14,116,144,.08); border-color: rgba(14,116,144,.25); color: #0e7490; }
        body.light .back-link { color: #155e75; }
        body.light .back-link:hover { color: #0e7490; }
        body.light .email-hint { background: #d0e5ed; border-color: rgba(14,116,144,.15); }
    </style>
</head>
<body>
<script>
    // Apply saved theme instantly before paint
    if (localStorage.getItem('sw_theme') === 'light') document.body.classList.add('light');
</script>
<div class="bg-scene"></div>
<div class="wrap">
    <div class="card">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-building"></i></div>
            <div class="brand-name">Stay<em>Wise</em></div>
        </div>

        <?php if (!$email_sent): ?>
        <!-- STEP 1: Enter email -->
        <div class="page-title">Forgot password?</div>
        <div class="page-sub">Enter your email address and we'll send you a 6-digit reset code.</div>

        <?php if (!empty($errors)): ?>
        <div class="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($errors[0]) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="send_otp">
            <div class="field-wrap">
                <label class="field-label" for="email">Email Address</label>
                <div class="field-inner">
                    <input type="email" class="field-input" id="email" name="email" placeholder="your@email.com" autocomplete="email" required>
                    <i class="fas fa-envelope field-icon"></i>
                </div>
            </div>
            <button type="submit" class="btn-main">Send Reset Code</button>
        </form>

        <?php else: ?>
        <!-- STEP 2: Enter OTP -->
        <div class="page-title">Check your email</div>
        <div class="page-sub">We sent a 6-digit code to your email. It expires in 10 minutes.</div>

        <?php if (!empty($_SESSION['fp_email_hint'])): ?>
        <div class="email-hint">
            <i class="fas fa-envelope" style="color:var(--gold);"></i>
            Code sent to <strong style="color:var(--text);"><?= htmlspecialchars($_SESSION['fp_email_hint']) ?></strong>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($errors[0]) ?></div>
        <?php endif; ?>

        <form method="POST" id="otpForm">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="verify_otp">
            <div class="field-wrap">
                <label class="field-label" for="otp_code">Verification Code</label>
                <input type="text" class="otp-input" id="otp_code" name="otp_code"
                       placeholder="000000" maxlength="6" inputmode="numeric"
                       autocomplete="one-time-code" autofocus>
            </div>
            <button type="submit" class="btn-main" id="verifyBtn">Verify Code</button>
        </form>

        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="resend_otp">
            <button type="submit" class="btn-ghost"><i class="fas fa-redo me-2"></i>Start Over</button>
        </form>

        <div class="timer" id="timerWrap">Code expires in <span id="timerCount">10:00</span></div>
        <?php endif; ?>

        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i>Back to Sign In</a>
    </div>
</div>

<script>
// OTP only digits
const otpInput = document.getElementById('otp_code');
if (otpInput) {
    otpInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });
    otpInput.addEventListener('paste', function (e) {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        this.value = text;
    });
}

// Countdown timer
const timerEl = document.getElementById('timerCount');
if (timerEl) {
    let total = 600;
    const iv = setInterval(() => {
        total--;
        if (total <= 0) { clearInterval(iv); timerEl.textContent = 'Expired'; timerEl.style.color = '#f87171'; return; }
        const m = Math.floor(total / 60);
        const s = total % 60;
        timerEl.textContent = m + ':' + String(s).padStart(2, '0');
    }, 1000);
}

// Loading state
const verifyBtn = document.getElementById('verifyBtn');
const otpForm   = document.getElementById('otpForm');
if (otpForm && verifyBtn) {
    otpForm.addEventListener('submit', function () {
        verifyBtn.disabled = true;
        verifyBtn.textContent = 'Verifying...';
    });
}
</script>
</body>
</html>
