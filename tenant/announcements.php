<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Check if user is tenant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php");
    exit();
}

$page_title = "All Announcements";

// Quick date filter: period or specific date
require_once '../includes/date_helpers.php';
$dateFilter = isset($_GET['date']) ? trim($_GET['date']) : '';
$period = isset($_GET['period']) ? trim($_GET['period']) : '';

// Determine date column (prefer created_at)
$hasCreatedAt = function_exists('db_column_exists') ? db_column_exists($conn, 'announcements', 'created_at') : true;
$dateColumn = $hasCreatedAt ? 'created_at' : 'announcement_date';

// Build SQL with optional filters; tenants see published/visible announcements (assume all for now)
$where = '';
$params = [];
$types = '';
$periodRange = compute_period($period);
if ($periodRange) {
    $where = "WHERE DATE($dateColumn) BETWEEN ? AND ?";
    $params = [$periodRange[0], $periodRange[1]];
    $types = 'ss';
} elseif (is_valid_ymd($dateFilter)) {
    $where = "WHERE DATE($dateColumn) = ?";
    $params = [$dateFilter];
    $types = 's';
}

$order = $hasCreatedAt ? 'created_at DESC' : 'announcement_date DESC';
$sql = "SELECT * FROM announcements $where ORDER BY $order";
if ($where) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $announcements = $stmt->get_result();
    $stmt->close();
} else {
    $announcements = $conn->query($sql);
}

include '../includes/header.php';
?>
<div class="container mt-4 tenant-ui">
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="fas fa-bullhorn me-2"></i>All Announcements</span>
        </div>
        <div class="card-body announcement-body">
            <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-3">
                <div>
                    <div class="mb-2">Quick filters</div>
                    <?php 
                        $periods = [
                            'today' => 'Today',
                            'yesterday' => 'Yesterday',
                            'last7' => 'Last 7 days',
                            'last30' => 'Last 30 days',
                            'thisMonth' => 'This month'
                        ];
                    ?>
                    <div class="btn-group" role="group" aria-label="Quick filters">
                        <?php foreach ($periods as $key => $label): ?>
                            <a href="announcements.php?period=<?php echo $key; ?>" class="btn btn-outline-primary <?php echo ($period === $key ? 'active' : ''); ?>"><?php echo $label; ?></a>
                        <?php endforeach; ?>
                        <a href="announcements.php" class="btn btn-outline-secondary">All</a>
                    </div>
                </div>
                <form id="tenantAnnFilterForm" method="get" class="row g-3 align-items-end">
                    <div class="col-auto">
                        <label for="tenantAnnDate" class="form-label">Or pick a date</label>
                        <input type="date" name="date" id="tenantAnnDate" class="form-control" value="<?php echo htmlspecialchars($dateFilter); ?>" />
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary mt-auto"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                    <div class="col-auto">
                        <a href="announcements.php" class="btn btn-outline-secondary mt-auto">Clear</a>
                    </div>
                </form>
            </div>
            <?php if ($announcements->num_rows > 0): ?>
                <?php while ($announcement = $announcements->fetch_assoc()): ?>
                    <div class="mb-4 pb-3 border-bottom">
                        <h5 class="mb-1"><?php echo htmlspecialchars($announcement['title']); ?></h5>
                        <p class="mb-1 small">
                            <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                        </p>
                        <?php
                            $rawPosted = !empty($announcement['created_at']) ? $announcement['created_at'] : ($announcement['announcement_date'] ?? null);
                            $ts = $rawPosted ? strtotime($rawPosted) : false;
                            $postedText = $ts ? date('M d, Y g:i A', $ts) : '';
                            $dayName = $ts ? date('l', $ts) : '';
                        ?>
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i>
                            <?php echo $postedText ? ('Posted: ' . $postedText) : 'Posted: N/A'; ?>
                            <?php if (!empty($dayName)): ?>
                                <span class="ms-2"><i class="far fa-calendar-alt me-1"></i>Day: <?php echo htmlspecialchars($dayName); ?></span>
                            <?php endif; ?>
                        </small>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="text-muted text-center">No announcements available</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('tenantAnnDate');
    const filterForm = document.getElementById('tenantAnnFilterForm');
    if (dateInput && filterForm) {
        dateInput.addEventListener('change', function(){ filterForm.submit(); });
    }
});
</script>
<?php include '../includes/footer.php'; ?>
