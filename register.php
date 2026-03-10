<?php
session_start();
require_once 'config/db.php';

$page_title = "Register";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Normalize username/email to lowercase for consistent storage and case-insensitive login
    $username_raw = trim($_POST['username']);
    $username = strtolower($username_raw);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $name = trim($_POST['name']);
    $email_raw = trim($_POST['email']);
    $email = strtolower($email_raw);
    $contact = trim($_POST['contact']);
    $unit_number = trim($_POST['unit_number']);
    
    $errors = [];
    
    // Validate input
    if (empty($username)) $errors[] = "Username is required";
    if (empty($password)) $errors[] = "Password is required";
    // Password strength: require uppercase, number, and special character
    $pwErrors = [];
    if (!preg_match('/[A-Z]/', $password)) $pwErrors[] = 'one uppercase letter';
    if (!preg_match('/[0-9]/', $password)) $pwErrors[] = 'one number';
    if (!preg_match('/[!@#$%^&*()_+\-=[\]{};:\"\\|,.<>\/?]/', $password)) $pwErrors[] = 'one special character';
    if (!empty($pwErrors)) {
        $errors[] = 'Password must contain at least ' . implode(', ', $pwErrors) . '.';
    }
    if ($password !== $confirm_password) $errors[] = "Passwords do not match";
    if (empty($name)) $errors[] = "Name is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    if (empty($unit_number)) $errors[] = "Unit number is required";
    
    // Check if username already exists
    if (empty($errors)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = ?");
    $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Username already exists";
        }
        $stmt->close();
    }
    
    // Check if email already exists
    if (empty($errors)) {
    $stmt = $conn->prepare("SELECT tenant_id FROM tenants WHERE LOWER(email) = ?");
    $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Email already exists";
        }
        $stmt->close();
    }
    
    // Check if unit number already exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT tenant_id FROM tenants WHERE LOWER(unit_number) = LOWER(?)");
        $stmt->bind_param("s", $unit_number);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Unit number is already assigned to another tenant";
        }
        $stmt->close();
    }
    
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Wrap user + tenant insert in a transaction
        $conn->begin_transaction();
        try {
            // Insert user (include full_name and email)
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role) VALUES (?, ?, ?, ?, 'tenant')");
            $stmt->bind_param("ssss", $username, $name, $email, $hashed_password);
            $stmt->execute();
            $user_id = $conn->insert_id;
            $stmt->close();
            
            // Insert tenant (with default rent_amount of 5000)
            $rent_amount = 5000.00;
            $tenant_stmt = $conn->prepare("INSERT INTO tenants (user_id, name, email, contact, unit_number, rent_amount) VALUES (?, ?, ?, ?, ?, ?)");
            $tenant_stmt->bind_param("issssd", $user_id, $name, $email, $contact, $unit_number, $rent_amount);
            $tenant_stmt->execute();
            $tenant_stmt->close();
            
            $conn->commit();
            header("Location: index.php?message=Registration successful! Please login.");
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = "Registration failed. Please try again.";
        }
    }
}

include 'includes/header.php';
?>

<div class="login-container">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="login-card">
                    <div class="text-center mb-4">
                        <h1 class="h3 mb-3">
                            <i class="fas fa-user-plus text-primary me-2"></i>
                            Register as Tenant
                        </h1>
                        <p class="text-muted">Create your StayWise account</p>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="needs-validation" novalidate autocomplete="off">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">
                                    <i class="fas fa-user me-1"></i>Username *
                                </label>
                    <input type="text" class="form-control" id="username" name="username" 
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required
                        autocapitalize="none" spellcheck="false" autocomplete="username"
                        oninput="this.value=this.value.toLowerCase()">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">
                                    <i class="fas fa-id-card me-1"></i>Full Name *
                                </label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">
                                <i class="fas fa-envelope me-1"></i>Email Address *
                            </label>
                <input type="email" class="form-control" id="email" name="email" 
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required
                    autocapitalize="none" spellcheck="false" autocomplete="email"
                    oninput="this.value=this.value.toLowerCase()">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="contact" class="form-label">
                                    <i class="fas fa-phone me-1"></i>Contact Number
                                </label>
                                <input type="tel" class="form-control" id="contact" name="contact" 
                                       value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact']) : ''; ?>"
                                       placeholder="9123456789" pattern="^\d{10}$" maxlength="10" title="Enter 10 digit mobile number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="unit_number" class="form-label">
                                    <i class="fas fa-door-open me-1"></i>Unit Number *
                                </label>
                                <input type="text" class="form-control" id="unit_number" name="unit_number" 
                                       value="<?php echo isset($_POST['unit_number']) ? htmlspecialchars($_POST['unit_number']) : ''; ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock me-1"></i>Password *
                                </label>
                        <div class="pw-toggle-wrap">
                        <input type="password" class="form-control" id="password" name="password" required data-lpignore="true" data-1p-ignore="true"
                            autocapitalize="none" autocomplete="new-password" style="padding-right:2.5rem"
                            pattern="^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$"
                            title="Password must include at least one uppercase letter, one number, and one special character">
                        <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                        </div>
                    <div id="passwordHelp" class="form-text mt-1">Must include uppercase, number, and special character.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="confirm_password" class="form-label">
                                    <i class="fas fa-lock me-1"></i>Confirm Password *
                                </label>
                    <div class="pw-toggle-wrap">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required data-lpignore="true" data-1p-ignore="true"
                        autocapitalize="none" autocomplete="new-password" style="padding-right:2.5rem">
                    <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                    </div>
                    <div id="confirmHelp" class="form-text mt-1"></div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-user-plus me-2"></i>Register
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div class="text-center">
                        <p class="mb-0">Already have an account?</p>
                        <a href="index.php" class="btn btn-outline-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Login Here
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
<script>
// Real-time password strength and match validation
(function(){
    const form = document.querySelector('form.needs-validation');
    if (!form) return;
    const pwd = document.getElementById('password');
    const cpw = document.getElementById('confirm_password');
    const pwdHelp = document.getElementById('passwordHelp');
    const cpwHelp = document.getElementById('confirmHelp');
    const submitBtn = form.querySelector('button[type="submit"]');

    function meetsRules(v){
        return /[A-Z]/.test(v) && /[0-9]/.test(v) && /[!@#$%^&*()_+\-=\[\]{};:'"\\|,.<>\/?]/.test(v);
    }
    function update(){
        const v = pwd.value || '';
        const ok = v.length > 0 && meetsRules(v);
        if (!v) {
            pwdHelp.textContent = 'Must include uppercase, number, and special character.';
            pwdHelp.className = 'form-text mt-1';
        } else if (ok) {
            pwdHelp.textContent = 'Strong password.';
            pwdHelp.className = 'form-text mt-1 text-success';
        } else {
            pwdHelp.textContent = 'Weak password. Add uppercase, number, and special character.';
            pwdHelp.className = 'form-text mt-1 text-danger';
        }
        const match = (cpw.value || '') === v;
        if (cpw.value) {
            cpwHelp.textContent = match ? 'Passwords match.' : 'Passwords do not match.';
            cpwHelp.className = 'form-text mt-1 ' + (match ? 'text-success' : 'text-danger');
        } else {
            cpwHelp.textContent = '';
            cpwHelp.className = 'form-text mt-1';
        }
        submitBtn.disabled = !(ok && match);
    }
    submitBtn.disabled = true;
    pwd.addEventListener('input', update);
    cpw.addEventListener('input', update);
    update();
})();
</script>