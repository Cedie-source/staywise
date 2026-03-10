<?php
/**
 * Migration: Create complaint_replies table + fix complaints status enum
 * Date: 2026-02-19
 */
require_once __DIR__ . '/../../config/db.php';

$queries = [
    // Create complaint_replies table
    "CREATE TABLE IF NOT EXISTS complaint_replies (
        reply_id INT AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT NOT NULL,
        user_id INT NOT NULL,
        role ENUM('admin','tenant') NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )",

    // Fix complaints status enum: change in_progress -> ongoing
    "ALTER TABLE complaints MODIFY COLUMN status ENUM('pending','ongoing','resolved') NOT NULL DEFAULT 'pending'",

    // Backfill any existing in_progress to ongoing (in case old data)
    "UPDATE complaints SET status = 'ongoing' WHERE status = 'in_progress'",

    // Add urgent column if missing
    "ALTER TABLE complaints ADD COLUMN IF NOT EXISTS urgent TINYINT(1) NOT NULL DEFAULT 0",
];

$ok = 0;
$fail = 0;
foreach ($queries as $sql) {
    try {
        $conn->query($sql);
        echo "OK: " . substr($sql, 0, 60) . "...\n";
        $ok++;
    } catch (Exception $e) {
        echo "SKIP/FAIL: " . $e->getMessage() . "\n";
        $fail++;
    }
}
echo "\nDone: $ok succeeded, $fail skipped/failed.\n";
