<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function app_log($channel, $message) {
	$dir = __DIR__ . '/../storage/logs';
	$safeChannel = preg_replace('/[^a-z0-9_\-]/i','_', (string)$channel);
	$file = $dir . '/' . $safeChannel . '.log';
	try {
		if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
		$line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
		@file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
	} catch (Throwable $e) { /* ignore logging errors */ }
}

// Persist an admin action into the admin_logs table when available,
// and fall back to file-based logging if the table is missing or errors occur.
if (!function_exists('logAdminAction')) {
	function logAdminAction($conn, $admin_id, $action, $details) {
		try {
			if (!$conn) { throw new Exception('No DB connection'); }
			$stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, ?, ?)");
			$stmt->bind_param("iss", $admin_id, $action, $details);
			$stmt->execute();
			$stmt->close();
		} catch (Throwable $e) {
			app_log('admin', 'logAdminAction failed: ' . $e->getMessage() . ' | admin_id=' . (int)$admin_id . ' action=' . (string)$action . ' details=' . (string)$details);
		}
	}
}

