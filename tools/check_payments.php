<?php
require_once '../config/db.php';
// Delete duplicate payments (keep 7, delete 8-14)
$conn->query("DELETE FROM payments WHERE payment_id IN (8,9,10,11,12,13,14)");
echo 'Deleted duplicates: ' . $conn->affected_rows . PHP_EOL;
// Fix remaining empty status
$conn->query("UPDATE payments SET status='pending' WHERE status='' OR status IS NULL");
echo 'Fixed empty status: ' . $conn->affected_rows . PHP_EOL;
// Show remaining
$r = $conn->query("SELECT payment_id, tenant_id, amount, for_month, status, created_at FROM payments ORDER BY payment_id DESC");
while ($row = $r->fetch_assoc()) {
    echo $row['payment_id'] . ' | t' . $row['tenant_id'] . ' | ' . $row['amount'] . ' | ' . $row['for_month'] . ' | [' . $row['status'] . '] | ' . $row['created_at'] . PHP_EOL;
}
