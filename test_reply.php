<?php
// TEMPORARY DEBUG FILE - DELETE AFTER TESTING
// Upload to root of your project and visit: yourdomain.com/test_reply.php
require_once 'config/db.php';

// Auto-create table
$conn->query("SET foreign_key_checks = 0");
$conn->query("CREATE TABLE IF NOT EXISTS complaint_replies (
    reply_id     INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    user_id      INT NOT NULL,
    role         ENUM('admin','tenant') NOT NULL DEFAULT 'admin',
    message      TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_complaint (complaint_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("SET foreign_key_checks = 1");

// Show table status
$tableCheck = $conn->query("SHOW TABLES LIKE 'complaint_replies'");
echo "<h3>complaint_replies table exists: " . ($tableCheck->num_rows > 0 ? 'YES ✅' : 'NO ❌') . "</h3>";

// Show complaints
$complaints = $conn->query("SELECT complaint_id, title FROM complaints LIMIT 5");
echo "<h3>Complaints in DB:</h3><ul>";
$first_id = null;
while ($r = $complaints->fetch_assoc()) {
    echo "<li>ID: {$r['complaint_id']} - {$r['title']}</li>";
    if (!$first_id) $first_id = $r['complaint_id'];
}
echo "</ul>";

// Show users
$users = $conn->query("SELECT id, username FROM users LIMIT 5");
echo "<h3>Users in DB:</h3><ul>";
$first_uid = null;
while ($r = $users->fetch_assoc()) {
    echo "<li>ID: {$r['id']} - {$r['username']}</li>";
    if (!$first_uid) $first_uid = $r['id'];
}
echo "</ul>";

// Try insert
if ($first_id && $first_uid) {
    $ins = $conn->prepare("INSERT INTO complaint_replies (complaint_id, user_id, role, message) VALUES (?, ?, 'admin', 'TEST MESSAGE')");
    $ins->bind_param("ii", $first_id, $first_uid);
    $ok = $ins->execute();
    $rid = $ins->insert_id;
    $err = $ins->error;
    $ins->close();
    
    if ($ok && $rid) {
        echo "<h3 style='color:green'>✅ INSERT WORKED! reply_id = $rid</h3>";
        // Show all replies
        $replies = $conn->query("SELECT * FROM complaint_replies ORDER BY reply_id DESC LIMIT 5");
        echo "<h3>Latest replies:</h3><ul>";
        while ($r = $replies->fetch_assoc()) {
            echo "<li>ID:{$r['reply_id']} complaint:{$r['complaint_id']} role:{$r['role']} msg:{$r['message']}</li>";
        }
        echo "</ul>";
    } else {
        echo "<h3 style='color:red'>❌ INSERT FAILED: $err</h3>";
        echo "<p>MySQL error: " . $conn->error . "</p>";
    }
} else {
    echo "<h3 style='color:orange'>⚠️ No complaints or users found to test with</h3>";
}

echo "<p><strong>DELETE THIS FILE after testing!</strong></p>";
?>
