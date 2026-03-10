<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Admin Logs";

// Date filter handling (GET: start, end as YYYY-MM-DD)
$dateFilter = isset($_GET['date']) ? trim($_GET['date']) : '';
$period = isset($_GET['period']) ? trim($_GET['period']) : '';
$actionFilter = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';

// Validate simple date format YYYY-MM-DD
function is_valid_ymd($s) {
    if (!$s) return false;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

$where = '';
$params = [];
$types = '';

// Predefined period filters take precedence over single date
function compute_period($periodKey) {
    $today = new DateTime('today');
    $start = null; $end = null;
    switch ($periodKey) {
        case 'today':
            $start = clone $today; $end = clone $today; break;
        case 'yesterday':
            $y = (clone $today)->modify('-1 day');
            $start = $y; $end = $y; break;
        case 'last7':
            $start = (clone $today)->modify('-6 days');
            $end = clone $today; break;
        case 'last30':
            $start = (clone $today)->modify('-29 days');
            $end = clone $today; break;
        case 'thisMonth':
            $start = new DateTime($today->format('Y-m-01'));
            $end = new DateTime($today->format('Y-m-t'));
            break;
        default:
            return null;
    }
    return [ $start->format('Y-m-d'), $end->format('Y-m-d') ];
}

$periodRange = compute_period($period);
if ($periodRange) {
    $where = 'WHERE DATE(l.created_at) BETWEEN ? AND ?';
    $params = [$periodRange[0], $periodRange[1]];
    $types = 'ss';
} elseif (is_valid_ymd($dateFilter)) {
    $where = 'WHERE DATE(l.created_at) = ?';
    $params = [$dateFilter];
    $types = 's';
}

// Action type filter (e.g., password_change)
if (!empty($actionFilter) && preg_match('/^[a-z_]+$/i', $actionFilter)) {
    $where .= ($where ? ' AND' : 'WHERE') . ' l.action = ?';
    $params[] = $actionFilter;
    $types .= 's';
}

$sql = "SELECT l.*, u.username, u.role AS user_role FROM admin_logs l JOIN users u ON l.admin_id = u.id $where ORDER BY l.created_at DESC";
if ($where) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query($sql);
}

include '../includes/header.php';
?>
<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title d-flex align-items-center justify-content-between">
        <span><i class="fas fa-clipboard-list me-2"></i>Admin Action Logs</span>
    </h2>

    <div class="card flat-card mb-3">
        <div class="card flat-card mb-3">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3">
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
                            <a href="admin_logs.php?period=<?php echo $key; ?>" class="btn btn-outline-primary <?php echo ($period === $key ? 'active' : ''); ?>"><?php echo $label; ?></a>
                        <?php endforeach; ?>
                        <a href="admin_logs.php" class="btn btn-outline-secondary">All</a>
                    </div>
                    <div class="mt-2">
                        <span class="me-2">Action type:</span>
                        <div class="btn-group" role="group" aria-label="Action type filter">
                            <a href="admin_logs.php?action_type=password_change<?php echo $period ? '&period=' . urlencode($period) : ''; ?>" class="btn btn-outline-danger btn-sm <?php echo ($actionFilter === 'password_change' ? 'active' : ''); ?>"><i class="fas fa-key me-1"></i>Password Changes</a>
                            <a href="admin_logs.php<?php echo $period ? '?period=' . urlencode($period) : ''; ?>" class="btn btn-outline-secondary btn-sm <?php echo (empty($actionFilter) ? 'active' : ''); ?>">All Actions</a>
                        </div>
                    </div>
                </div>
                <form id="logFilterForm" method="get" class="row g-3 align-items-end">
                    <div class="col-auto">
                        <label for="date" class="form-label">Or pick a date</label>
                        <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($dateFilter); ?>" />
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary mt-auto"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                    <div class="col-auto">
                        <a href="admin_logs.php" class="btn btn-outline-secondary mt-auto">Clear</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="card flat-card">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
                <div class="d-flex align-items-center gap-2">
                    <label for="logSearch" class="form-label mb-0 me-2">Search</label>
                    <input type="text" id="logSearch" class="form-control" placeholder="Search activity or user..." style="max-width: 280px;" />
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label for="rowsPerPage" class="form-label mb-0 me-2">Rows</label>
                    <select id="rowsPerPage" class="form-select" style="width: 100px;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="exportCsv"><i class="fas fa-file-csv me-2"></i>Export CSV</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="adminLogsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Activity</th>
                            <th>User</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php 
                                    $ts = strtotime($row['created_at']);
                                    $dateFull = date('F d, Y g:i A', $ts);
                                    $dayName = date('l', $ts);
                                    $activity = ucfirst($row['action']);
                                    if (!empty($row['details'])) { $activity .= ' – ' . $row['details']; }
                                    $isPasswordChange = ($row['action'] === 'password_change');
                                    $roleLabel = ucfirst($row['user_role'] ?? 'unknown');
                                    $roleBadge = ($row['user_role'] === 'tenant') 
                                        ? '<span class="badge bg-info text-dark">Tenant</span>' 
                                        : '<span class="badge bg-primary">Admin</span>';
                                ?>
                                <tr<?php echo $isPasswordChange ? ' class="table-warning"' : ''; ?>>
                                    <td><?php echo htmlspecialchars($dateFull); ?></td>
                                    <td><?php echo htmlspecialchars($dayName); ?></td>
                                    <td>
                                        <?php if ($isPasswordChange): ?>
                                            <i class="fas fa-key text-warning me-1"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($activity); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td><?php echo $roleBadge; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center">No logs found for the selected dates.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav>
                <ul class="pagination justify-content-end mt-3" id="logPagination"></ul>
            </nav>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
<script>
// Modern client-side enhancements: search, pagination, export CSV
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('adminLogsTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const searchInput = document.getElementById('logSearch');
    const rowsSelect = document.getElementById('rowsPerPage');
    const pager = document.getElementById('logPagination');
    const exportBtn = document.getElementById('exportCsv');

    let filtered = rows.slice();
    let pageSize = parseInt(rowsSelect.value, 10) || 10;
    let currentPage = 1;

    function normalize(str) { return (str || '').toLowerCase(); }

    function applySearch() {
        const q = normalize(searchInput.value);
        if (!q) {
            filtered = rows.slice();
        } else {
            filtered = rows.filter(tr => {
                const tds = tr.querySelectorAll('td');
                const dateText = normalize(tds[0]?.textContent);
                const dayText = normalize(tds[1]?.textContent);
                const actText = normalize(tds[2]?.textContent);
                const userText = normalize(tds[3]?.textContent);
                return dateText.includes(q) || dayText.includes(q) || actText.includes(q) || userText.includes(q);
            });
        }
        currentPage = 1;
        render();
    }

    function buildPager(total, page, size) {
        pager.innerHTML = '';
        const totalPages = Math.max(1, Math.ceil(total / size));
        const createItem = (label, disabled, active, onClick) => {
            const li = document.createElement('li');
            li.className = `page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}`;
            const a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.textContent = label;
            a.onclick = (e) => { e.preventDefault(); if (!disabled && onClick) onClick(); };
            li.appendChild(a);
            return li;
        };
        const prev = createItem('«', page === 1, false, () => { currentPage = Math.max(1, page - 1); render(); });
        pager.appendChild(prev);
        // Show up to 7 pages window
        let start = Math.max(1, page - 3);
        let end = Math.min(totalPages, start + 6);
        if (end - start < 6) start = Math.max(1, end - 6);
        for (let p = start; p <= end; p++) {
            pager.appendChild(createItem(String(p), false, p === page, () => { currentPage = p; render(); }));
        }
        const next = createItem('»', page === totalPages, false, () => { currentPage = Math.min(totalPages, page + 1); render(); });
        pager.appendChild(next);
    }

    function render() {
        // Hide all
        rows.forEach(tr => tr.style.display = 'none');
        // Paginate filtered
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * pageSize;
        const end = Math.min(total, start + pageSize);
        for (let i = start; i < end; i++) {
            filtered[i].style.display = '';
        }
        buildPager(total, currentPage, pageSize);
    }

    function exportVisibleToCSV() {
        const visible = filtered.filter(tr => tr.style.display !== 'none');
        const header = ['Date','Day','Activity','User','Role'];
        const rowsCsv = visible.map(tr => Array.from(tr.children).slice(0,5).map(td => '"' + (td.textContent||'').replace(/"/g,'""') + '"').join(','));
        const csv = [header.join(',')].concat(rowsCsv).join('\r\n');
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const today = new Date();
        const ymd = today.toISOString().slice(0,10);
        a.download = `admin-logs-${ymd}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    searchInput.addEventListener('input', applySearch);
    rowsSelect.addEventListener('change', function(){ pageSize = parseInt(rowsSelect.value, 10) || 10; currentPage = 1; render(); });
    exportBtn.addEventListener('click', exportVisibleToCSV);

    // Auto-submit on date change for a single-click experience
    const dateInput = document.getElementById('date');
    const filterForm = document.getElementById('logFilterForm');
    if (dateInput && filterForm) {
        dateInput.addEventListener('change', function(){ filterForm.submit(); });
    }

    // Initial render
    applySearch();
});
</script>
