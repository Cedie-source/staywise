# StayWise — Security Features Documentation

## Table of Contents

1. [Introduction](#1-introduction)
2. [Authentication Security](#2-authentication-security)
   - 2.1 [Password Hashing (Bcrypt/Argon2)](#21-password-hashing-bcryptargon2)
   - 2.2 [Legacy Hash Detection and Auto-Upgrade](#22-legacy-hash-detection-and-auto-upgrade)
   - 2.3 [Password Strength Enforcement](#23-password-strength-enforcement)
   - 2.4 [Brute-Force Login Protection (Rate Limiting)](#24-brute-force-login-protection-rate-limiting)
   - 2.5 [Forced Password Change on First Login](#25-forced-password-change-on-first-login)
   - 2.6 [Password Rehashing on Algorithm Upgrade](#26-password-rehashing-on-algorithm-upgrade)
3. [Session Security](#3-session-security)
   - 3.1 [Secure Session Cookie Configuration](#31-secure-session-cookie-configuration)
   - 3.2 [Session-Based Access Control](#32-session-based-access-control)
4. [Cross-Site Request Forgery (CSRF) Protection](#4-cross-site-request-forgery-csrf-protection)
   - 4.1 [Token Generation](#41-token-generation)
   - 4.2 [Token Verification and Rotation](#42-token-verification-and-rotation)
5. [Input Validation and Output Encoding](#5-input-validation-and-output-encoding)
   - 5.1 [Server-Side Input Validation](#51-server-side-input-validation)
   - 5.2 [Output Encoding (XSS Prevention)](#52-output-encoding-xss-prevention)
   - 5.3 [Client-Side Validation](#53-client-side-validation)
6. [SQL Injection Prevention](#6-sql-injection-prevention)
7. [Role-Based Access Control (RBAC)](#7-role-based-access-control-rbac)
   - 7.1 [Role Hierarchy](#71-role-hierarchy)
   - 7.2 [Page-Level Access Guards](#72-page-level-access-guards)
   - 7.3 [Self-Demotion Prevention](#73-self-demotion-prevention)
8. [Account Security](#8-account-security)
   - 8.1 [Account Active/Inactive Flag](#81-account-activeinactive-flag)
   - 8.2 [Soft Deletion](#82-soft-deletion)
   - 8.3 [Generic Error Messages](#83-generic-error-messages)
9. [File Upload Security](#9-file-upload-security)
10. [Audit Logging](#10-audit-logging)
11. [Database Security](#11-database-security)
    - 11.1 [Transaction Integrity](#111-transaction-integrity)
    - 11.2 [UTF-8 Character Encoding](#112-utf-8-character-encoding)
12. [Summary Table](#12-summary-table)

---

## 1. Introduction

**StayWise** is a web-based Rental Management System built with PHP and MySQL that serves two user roles — **Admins** (including Super Admins) and **Tenants**. Because the system handles sensitive data such as login credentials, payment records, personal information, and complaint details, a robust set of security measures is integrated at every layer of the application.

This document describes each security feature, explains **how** it works at a technical level, and discusses **why** it is important for the safety and reliability of the system.

---

## 2. Authentication Security

### 2.1 Password Hashing (Bcrypt/Argon2)

**How it works:**
All user passwords are hashed using PHP's `password_hash()` function with the `PASSWORD_DEFAULT` algorithm, which currently maps to **bcrypt** (`$2y$` prefix). The system also recognises Argon2 hashes (`$argon2` prefix). During login, `password_verify()` is used to compare the entered password against the stored hash.

```php
// Registration — hashing
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Login — verification
if (password_verify($password, $stored)) { ... }
```

**Why it is important:**
Plain-text or weakly hashed passwords are the most common source of credential theft. Bcrypt is a slow, salted, adaptive hashing algorithm specifically designed for passwords. Even if the database is compromised, attackers cannot feasibly reverse bcrypt hashes to recover original passwords.

---

### 2.2 Legacy Hash Detection and Auto-Upgrade

**How it works:**
The login system detects older hash formats that may exist in the database from earlier development stages:

| Format     | Detection Method                              |
|------------|-----------------------------------------------|
| MD5        | Regex match for 32-character hex string       |
| SHA-1      | Regex match for 40-character hex string       |
| Plain text | Anything that is not a recognised hash format |

When a user logs in successfully with a legacy hash, the system **automatically upgrades** the stored password to a modern bcrypt hash:

```php
// Legacy matched → upgrade to modern hash
if ($login_ok) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $upd->bind_param("si", $newHash, $user['id']);
    $upd->execute();
}
```

**Why it is important:**
This provides a seamless migration path from insecure legacy hashes (MD5, SHA-1, plaintext) to modern cryptographic standards without requiring all users to reset their passwords at once. It hardens the system incrementally with each successful login.

---

### 2.3 Password Strength Enforcement

**How it works:**
Both during **registration** and **password change**, the system enforces password complexity rules on the server side using regex checks:

- At least **one uppercase letter** (`/[A-Z]/`)
- At least **one numeric digit** (`/[0-9]/`)
- At least **one special character** (`/[!@#$%^&*()_+\-=[\]{};:\"\\|,.<>\/?]/`)
- Minimum **8 characters** (enforced on password change)

```php
if (!preg_match('/[A-Z]/', $password)) $pwErrors[] = 'one uppercase letter';
if (!preg_match('/[0-9]/', $password)) $pwErrors[] = 'one number';
if (!preg_match('/[!@#$%^&*()_+\-=[\]{};:\"\\|,.<>\/?]/', $password)) $pwErrors[] = 'one special character';
```

Additionally, **client-side real-time validation** provides immediate visual feedback in the registration form, disabling the submit button until criteria are met.

**Why it is important:**
Weak passwords are vulnerable to dictionary attacks and brute-force attacks. Enforcing complexity rules at both the client and server level ensures that passwords meet a minimum strength threshold, significantly reducing the risk of account compromise.

---

### 2.4 Brute-Force Login Protection (Rate Limiting)

**How it works:**
The login system tracks failed attempts **per username/email identifier** using session variables:

1. Each failed login increments a per-identifier attempt counter.
2. After **5 consecutive failed attempts**, the account is **locked for 30 seconds**.
3. During lockout, subsequent login attempts are rejected and the user sees a real-time countdown timer.
4. Once the lockout period expires, the counter and lock are cleared automatically.

```php
$attempts++;
$_SESSION['login_attempts'][$identifier] = $attempts;
if ($attempts >= 5) {
    $_SESSION['login_lock_until'][$identifier] = time() + 30;
    header("Location: index.php?lock=1&remain=$remaining");
}
```

**Why it is important:**
Without rate limiting, an attacker could attempt unlimited password combinations against an account. The lockout mechanism dramatically slows down brute-force and credential-stuffing attacks, making them impractical while still allowing legitimate users to retry after a short wait.

---

### 2.5 Forced Password Change on First Login

**How it works:**
When new admin accounts are created by a Super Admin, the `force_password_change` flag is set to `1` in the database. On login, the system checks this flag and if set:

1. The session variable `$_SESSION['must_change_password']` is set to `true`.
2. The user is immediately redirected to `change_password.php`.
3. A **global guard** in the header template prevents navigation to any other page until the password is changed.
4. Once changed, the flag is cleared in the database and session.

```php
// On login
$mustChange = $hasForceCol ? (!empty($user['force_password_change'])) : false;
if ($mustChange) {
    $_SESSION['must_change_password'] = true;
    header("Location: change_password.php");
    exit();
}

// Global guard in header.php
if (!empty($_SESSION['must_change_password'])) {
    if ($self !== 'change_password.php' && $self !== 'logout.php' && ...) {
        header('Location: ' . $base_url . 'change_password.php');
        exit();
    }
}
```

**Why it is important:**
Admin accounts created by another administrator use a temporary password known to the creator. Forcing a password change on first login ensures that only the account owner knows the final password, eliminating shared-secret risks and maintaining the principle of individual accountability.

---

### 2.6 Password Rehashing on Algorithm Upgrade

**How it works:**
After a successful login with a modern hash, the system checks whether the stored hash needs rehashing (e.g., if PHP's default algorithm or cost factor has been updated):

```php
if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $upd->bind_param("si", $newHash, $user['id']);
    $upd->execute();
}
```

**Why it is important:**
Cryptographic best practices evolve over time. As PHP updates its default hashing algorithm or cost parameters, this mechanism ensures all user passwords are transparently re-encrypted to the latest standard without user intervention.

---

## 3. Session Security

### 3.1 Secure Session Cookie Configuration

**How it works:**
The `set_secure_session_cookies()` function (defined in `includes/security.php`) hardens session cookies with multiple directives:

| Directive                    | Value      | Purpose                                                 |
|-----------------------------|------------|---------------------------------------------------------|
| `session.cookie_httponly`   | `1`        | Prevents JavaScript from accessing the session cookie   |
| `session.use_strict_mode`  | `1`        | Rejects uninitialized session IDs                       |
| `session.cookie_samesite`  | `Strict`   | Prevents the cookie from being sent in cross-site requests |
| `session.cookie_secure`    | `1` (HTTPS)| Cookie only transmitted over encrypted connections      |

```php
function set_secure_session_cookies() {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        @ini_set('session.cookie_secure', '1');
    }
}
```

This function is called at the top of every entry-point page (`index.php`, `login.php`, admin pages, etc.).

**Why it is important:**
- **HttpOnly** mitigates Cross-Site Scripting (XSS) session hijacking by making the cookie inaccessible to client-side scripts.
- **Strict mode** prevents session fixation attacks where an attacker pre-sets a session ID.
- **SameSite=Strict** defends against CSRF by ensuring the browser only sends session cookies with same-origin requests.
- **Secure flag** prevents session cookies from being intercepted over unencrypted HTTP connections.

---

### 3.2 Session-Based Access Control

**How it works:**
After successful authentication, the system stores user identity and role information in the server-side session:

```php
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['role']      = $role;         // 'admin' or 'tenant'
$_SESSION['admin_role'] = $adminRole;   // 'super_admin' or null
```

Every protected page verifies these session variables before rendering content.

**Why it is important:**
Server-side sessions ensure that authentication state cannot be tampered with by the client. The session data lives on the server; the client only holds an opaque session ID cookie, which by itself reveals nothing about the user's role or permissions.

---

## 4. Cross-Site Request Forgery (CSRF) Protection

### 4.1 Token Generation

**How it works:**
A unique, cryptographically random 32-byte (64-character hex) token is generated per session and embedded as a hidden field in every form:

```php
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input() {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $t . '">';
}
```

Usage in forms:
```php
<form method="POST">
    <?php echo csrf_input(); ?>
    <!-- form fields -->
</form>
```

### 4.2 Token Verification and Rotation

**How it works:**
On every POST request, the submitted token is verified using **timing-safe comparison** (`hash_equals`) to prevent timing attacks. After successful verification, the token is **rotated** (regenerated) to limit replay attacks:

```php
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Rotate
    }
    return $valid;
}
```

CSRF protection is enforced on all state-changing operations: login, password change, complaint submission, payment status updates, admin management actions, and role promotions/demotions.

**Why it is important:**
CSRF attacks trick authenticated users into unknowingly submitting malicious requests. Without CSRF tokens, an attacker's crafted page could perform actions like changing passwords or approving payments on behalf of a logged-in user. The per-request token rotation further limits the window of opportunity for token replay.

---

## 5. Input Validation and Output Encoding

### 5.1 Server-Side Input Validation

**How it works:**
All user inputs are validated on the server before processing:

| Input         | Validation Rule                                             |
|---------------|-------------------------------------------------------------|
| Username      | Regex: `^[a-z0-9_]{3,20}$` (letters, numbers, underscores) |
| Email         | PHP `filter_var($email, FILTER_VALIDATE_EMAIL)`             |
| Password      | Complexity regex (uppercase, number, special character)     |
| Contact       | Pattern `^\d{10}$` — exactly 10 digits                     |
| Numeric IDs   | Cast with `intval()` or `(int)`                             |
| Free text     | `trim()` to remove leading/trailing whitespace              |

```php
// Username format validation
if (!preg_match('/^[a-z0-9_]{3,20}$/', $identifier)) { ... }

// Email validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { ... }
```

**Why it is important:**
Server-side validation is the authoritative defense against malformed or malicious input. Client-side validation can be bypassed by any attacker using browser developer tools or automated scripts. Every input that touches the database or application logic must be validated on the server.

---

### 5.2 Output Encoding (XSS Prevention)

**How it works:**
All dynamic content rendered in HTML pages is escaped with `htmlspecialchars()` using `ENT_QUOTES` encoding to neutralize HTML/JavaScript injection:

```php
<?php echo htmlspecialchars($error); ?>
<?php echo htmlspecialchars($payment['name']); ?>
<?php echo htmlspecialchars($complaint['title']); ?>
```

The CSRF token itself is also encoded before being placed in a form:
```php
$t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
```

**Why it is important:**
Cross-Site Scripting (XSS) attacks allow attackers to inject malicious JavaScript into pages viewed by other users. By encoding all output, the system ensures that special characters like `<`, `>`, `"`, and `'` are rendered as harmless text rather than executable code. This protects against stored XSS (e.g., injecting scripts via complaint descriptions) and reflected XSS (e.g., injecting via URL parameters).

---

### 5.3 Client-Side Validation

**How it works:**
Registration and password-change forms include real-time client-side validation that:

- Shows **password strength indicators** ("Weak password" / "Strong password").
- Shows **password match/mismatch feedback** for the confirm field.
- **Disables the submit button** until all criteria are met.
- Detects and warns about **Caps Lock** being active during password entry.

```javascript
function meetsRules(v) {
    return /[A-Z]/.test(v) && /[0-9]/.test(v) && /[!@#$%^&*()_+\-=\[\]{};:'"\\|,.<>\/?]/.test(v);
}
```

**Why it is important:**
While not a security boundary (server validation is authoritative), client-side validation improves user experience by providing immediate feedback, reducing form submission errors, and helping users create stronger passwords. The Caps Lock warning prevents accidental password entry errors.

---

## 6. SQL Injection Prevention

**How it works:**
The system uses **prepared statements with parameterised queries** for all database interactions involving user input. Parameters are bound using typed placeholders (`?`) and `bind_param()`:

```php
// Login query — parameterised
$stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE LOWER(username) = ?");
$stmt->bind_param("s", $identifier);
$stmt->execute();

// Payment update — parameterised
$stmt = $conn->prepare("UPDATE payments SET status = ? WHERE payment_id = ?");
$stmt->bind_param("si", $status, $payment_id);
$stmt->execute();

// Registration — parameterised insert
$stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role) VALUES (?, ?, ?, ?, 'tenant')");
$stmt->bind_param("ssss", $username, $name, $email, $hashed_password);
$stmt->execute();
```

This pattern is consistently applied across login, registration, payment processing, complaint handling, admin management, and all other database operations throughout the application.

**Why it is important:**
SQL injection is one of the most critical and commonly exploited web vulnerabilities (OWASP Top 10 #1 for many years). Prepared statements completely separate SQL logic from data, making it impossible for user input to alter the structure of queries. This protects against data theft, data modification, and even full server compromise through database-level attacks.

---

## 7. Role-Based Access Control (RBAC)

### 7.1 Role Hierarchy

The system implements a three-tier role hierarchy:

| Role          | Access Level                                                     |
|---------------|------------------------------------------------------------------|
| **Super Admin** | Full system control: admin management, role promotions/demotions, system settings |
| **Admin**       | Property management: tenants, payments, complaints, announcements, logs |
| **Tenant**      | Personal dashboard: own payments, complaints, profile, announcements |

### 7.2 Page-Level Access Guards

**How it works:**
Every protected page begins with a session and role check. Unauthorised users are immediately redirected:

```php
// Admin pages
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

// Super Admin-only pages
require_super_admin(); // Checks role='admin' AND admin_role='super_admin'

// Tenant pages
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php");
    exit();
}
```

The `require_admin_role()` and `require_super_admin()` helper functions centralise access control logic in `includes/security.php`.

### 7.3 Self-Demotion Prevention

**How it works:**
The Role Manager prevents a Super Admin from demoting their own account, which would cause an administrative lockout:

```php
if ($targetId === (int)($_SESSION['user_id'] ?? 0)) {
    $error = 'You cannot demote your own account.';
}
```

**Why it is important:**
RBAC ensures the **principle of least privilege** — each user can only access the functionality and data appropriate to their role. Without proper access controls, a tenant could potentially access admin functions or another tenant's data. The self-demotion guard prevents accidental loss of administrative access, which could require manual database intervention to recover.

---

## 8. Account Security

### 8.1 Account Active/Inactive Flag

**How it works:**
The `users` table includes an `is_active` column. During login, if a user's account is inactive, the system:

1. Rejects the login silently (does not reveal account status).
2. Clears any partial session data.
3. Displays a **generic "Invalid username or password"** message.

```php
if ($hasActiveCol && isset($user['is_active']) && intval($user['is_active']) !== 1) {
    session_unset();
    session_destroy();
    header("Location: index.php?error=Invalid username or password");
    exit();
}
```

**Why it is important:**
Account deactivation allows administrators to disable user access without deleting data. The generic error message prevents **account enumeration** — attackers cannot determine whether a specific username exists or whether the account is disabled.

---

### 8.2 Soft Deletion

**How it works:**
User and tenant records support soft deletion via a `deleted_at` timestamp column. Login queries exclude soft-deleted accounts:

```php
if ($hasDeletedAt) { $sql .= " AND deleted_at IS NULL"; }
```

Dashboard queries similarly filter out soft-deleted tenants:
```sql
SELECT COUNT(*) as count FROM tenants WHERE deleted_at IS NULL
```

**Why it is important:**
Soft deletion preserves data integrity and audit trails. Historical records (payments, complaints) linked to deleted accounts remain intact for reporting purposes, while the user is effectively barred from the system.

---

### 8.3 Generic Error Messages

**How it works:**
The login system returns the same error message — **"Invalid username or password"** — for all authentication failures, whether the username does not exist, the password is wrong, or the account is deactivated.

**Why it is important:**
Distinct error messages (e.g., "Username not found" vs. "Wrong password") allow attackers to enumerate valid usernames. By using a single generic message, StayWise prevents this reconnaissance technique.

---

## 9. File Upload Security

**How it works:**
Payment proof files uploaded by tenants are handled with the following safeguards:

1. **Dedicated upload directory** (`uploads/payments/`) with controlled permissions.
2. **Unique filenames** generated using format: `payment_{tenantId}_{timestamp}.{extension}` — preventing filename collisions and making filenames unpredictable.
3. **Upload error checking** — the file is only processed if `$_FILES['proof_file']['error'] == 0`.
4. **Server-side path construction** — the upload path is constructed server-side, preventing path traversal.

```php
$file_extension = pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION);
$proof_file = 'payment_' . $tenant_id . '_' . time() . '.' . $file_extension;
$upload_path = $upload_dir . $proof_file;
move_uploaded_file($_FILES['proof_file']['tmp_name'], $upload_path);
```

**Why it is important:**
Unrestricted file uploads can be exploited to upload malicious scripts (e.g., PHP web shells). Unique naming prevents overwriting existing files, and server-controlled path construction prevents directory traversal attacks that could place files outside the intended directory.

---

## 10. Audit Logging

**How it works:**
All significant administrative actions are recorded in the `admin_logs` database table via the `logAdminAction()` function:

```php
function logAdminAction($conn, $admin_id, $action, $details) {
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $admin_id, $action, $details);
    $stmt->execute();
}
```

Logged actions include:
- Payment status updates (approve/reject)
- Complaint status changes and admin responses
- Admin account creation
- Role promotions and demotions
- Admin account deletions

A secondary **file-based logging** fallback (`storage/logs/`) ensures events are captured even if the database is unavailable:

```php
function app_log($channel, $message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
```

The Admin Logs page provides a filterable, searchable interface for reviewing audit trails with quick filters (Today, Yesterday, Last 7 days, Last 30 days, This Month) and date-based filtering.

**Why it is important:**
Audit logs provide **accountability, traceability, and forensic capability**. They allow administrators to review who performed what action and when, which is essential for investigating security incidents, resolving disputes, and maintaining compliance. The dual logging strategy (database + file) ensures reliability even during partial system failures.

---

## 11. Database Security

### 11.1 Transaction Integrity

**How it works:**
Multi-step database operations (e.g., user registration, which inserts into both `users` and `tenants` tables) are wrapped in transactions to ensure atomicity:

```php
$conn->begin_transaction();
try {
    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (...) VALUES (...)");
    $stmt->execute();
    $user_id = $conn->insert_id;

    // Insert tenant
    $tenant_stmt = $conn->prepare("INSERT INTO tenants (...) VALUES (...)");
    $tenant_stmt->execute();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $errors[] = "Registration failed. Please try again.";
}
```

**Why it is important:**
Without transactions, a failure midway through a multi-table operation could leave the database in an inconsistent state (e.g., a user record without a corresponding tenant record). Transactions guarantee that either all changes succeed or none take effect, maintaining data integrity.

---

### 11.2 UTF-8 Character Encoding

**How it works:**
The database connection is configured to use UTF-8 encoding:

```php
$conn->set_charset("utf8");
```

**Why it is important:**
Consistent character encoding prevents **multi-byte character injection attacks** where attackers exploit encoding mismatches to bypass security filters. UTF-8 is the web standard and ensures that internationalised text (names, descriptions) is stored and displayed correctly.

---

## 12. Summary Table

| Security Feature                    | Threat Mitigated                      | Implementation Location           |
|-------------------------------------|---------------------------------------|-----------------------------------|
| Bcrypt/Argon2 Password Hashing      | Credential theft from database breach | `login.php`, `register.php`, `change_password.php` |
| Legacy Hash Auto-Upgrade            | Weak legacy password storage          | `login.php`, `change_password.php` |
| Password Strength Enforcement       | Weak/guessable passwords              | `register.php`, `change_password.php`, `admin_management.php` |
| Brute-Force Rate Limiting           | Password brute-force attacks          | `login.php`                       |
| Forced Password Change              | Shared temporary credentials          | `login.php`, `header.php`, `change_password.php` |
| Secure Session Cookies              | Session hijacking, fixation, CSRF     | `includes/security.php`           |
| CSRF Token Protection               | Cross-site request forgery            | `includes/security.php`, all forms |
| CSRF Token Rotation                 | Token replay attacks                  | `includes/security.php`           |
| Prepared Statements                 | SQL injection                         | All database queries              |
| Server-Side Input Validation        | Malformed/malicious input             | `login.php`, `register.php`, all POST handlers |
| Output Encoding (`htmlspecialchars`)| Cross-site scripting (XSS)            | All rendered templates            |
| Role-Based Access Control           | Privilege escalation                  | All admin/tenant pages, `includes/security.php` |
| Generic Error Messages              | Account enumeration                   | `login.php`                       |
| Account Active Flag                 | Unauthorized access by disabled users | `login.php`                       |
| Soft Deletion                       | Data loss, audit trail destruction    | `login.php`, `admin/dashboard.php` |
| Secure File Uploads                 | Malicious file upload, path traversal | `tenant/payments.php`             |
| Audit Logging (DB + File)           | Lack of accountability/traceability   | `includes/logger.php`, admin pages |
| Database Transactions               | Data inconsistency                    | `register.php`                    |
| UTF-8 Encoding                      | Multi-byte injection attacks          | `config/db.php`                   |
| Password Rehash on Algo Upgrade     | Outdated hash algorithms              | `login.php`                       |
| Self-Demotion Prevention            | Administrative lockout                | `admin/role_manager.php`          |

---

*Document generated for StayWise Rental Management System — February 2026*
