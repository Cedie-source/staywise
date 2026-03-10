<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = 'Manage Properties';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }

    // Add property
    if (isset($_POST['add_property'])) {
        $name = trim($_POST['name']);
        $address = trim($_POST['address']);
        $total_units = intval($_POST['total_units']);
        $description = trim($_POST['description']);
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        $errors = [];
        if (empty($name)) $errors[] = 'Property name is required.';
        if ($total_units < 0) $errors[] = 'Total units cannot be negative.';

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO properties (name, address, total_units, description, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiss", $name, $address, $total_units, $description, $status);
            if ($stmt->execute()) {
                $success = "Property added successfully!";
                logAdminAction($conn, $_SESSION['user_id'], 'add_property', "Added property: $name");
            } else {
                $error = "Failed to add property.";
            }
            $stmt->close();
        } else {
            $error = implode(' ', $errors);
        }
    }

    // Update property
    if (isset($_POST['update_property'])) {
        $property_id = intval($_POST['property_id']);
        $name = trim($_POST['name']);
        $address = trim($_POST['address']);
        $total_units = intval($_POST['total_units']);
        $description = trim($_POST['description']);
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        $errors = [];
        if (empty($name)) $errors[] = 'Property name is required.';
        if ($total_units < 0) $errors[] = 'Total units cannot be negative.';

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE properties SET name = ?, address = ?, total_units = ?, description = ?, status = ?, updated_at = NOW() WHERE property_id = ?");
            $stmt->bind_param("ssissi", $name, $address, $total_units, $description, $status, $property_id);
            if ($stmt->execute()) {
                $success = "Property updated successfully!";
                logAdminAction($conn, $_SESSION['user_id'], 'update_property', "Updated property ID: $property_id ($name)");
            } else {
                $error = "Failed to update property.";
            }
            $stmt->close();
        } else {
            $error = implode(' ', $errors);
        }
    }

    // Delete property
    if (isset($_POST['delete_property'])) {
        $property_id = intval($_POST['property_id']);
        $stmt = $conn->prepare("DELETE FROM properties WHERE property_id = ?");
        $stmt->bind_param("i", $property_id);
        if ($stmt->execute()) {
            $success = "Property deleted successfully.";
            logAdminAction($conn, $_SESSION['user_id'], 'delete_property', "Deleted property ID: $property_id");
        } else {
            $error = "Failed to delete property.";
        }
        $stmt->close();
    }
}

// Fetch all properties
$properties = $conn->query("SELECT * FROM properties ORDER BY created_at DESC");

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="dashboard-title"><i class="fas fa-building me-2"></i>Manage Properties</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPropertyModal">
            <i class="fas fa-plus me-2"></i>Add Property
        </button>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Search bar -->
    <div class="mb-3">
        <input type="text" class="form-control" id="propertySearch" placeholder="Search properties by name, address, or status...">
    </div>

    <div class="card flat-card">
        <div class="card-body">
            <?php if ($properties && $properties->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table" id="propertiesTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Address</th>
                                <th>Total Units</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($prop = $properties->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($prop['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($prop['address'] ?: '—'); ?></td>
                                    <td><span class="badge bg-primary"><?php echo (int)$prop['total_units']; ?></span></td>
                                    <td>
                                        <span class="badge <?php echo $prop['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($prop['status']); ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('M d, Y', strtotime($prop['created_at'])); ?></small></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                                onclick='editProperty(<?php echo json_encode($prop); ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this property?')">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="property_id" value="<?php echo $prop['property_id']; ?>">
                                            <button type="submit" name="delete_property" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                    <h5>No Properties Found</h5>
                    <p class="text-muted">Add your first property to get started.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Property Modal -->
<div class="modal fade" id="addPropertyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Property</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Property Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Units</label>
                            <input type="number" class="form-control" name="total_units" min="0" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_property" class="btn btn-primary"><i class="fas fa-save me-2"></i>Add Property</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Property Modal -->
<div class="modal fade" id="editPropertyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Property</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="property_id" id="edit_prop_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Property Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="edit_prop_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" id="edit_prop_address" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Units</label>
                            <input type="number" class="form-control" name="total_units" id="edit_prop_units" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_prop_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_prop_desc" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_property" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Property</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editProperty(p) {
    document.getElementById('edit_prop_id').value = p.property_id;
    document.getElementById('edit_prop_name').value = p.name;
    document.getElementById('edit_prop_address').value = p.address || '';
    document.getElementById('edit_prop_units').value = p.total_units || 0;
    document.getElementById('edit_prop_status').value = p.status || 'active';
    document.getElementById('edit_prop_desc').value = p.description || '';
    new bootstrap.Modal(document.getElementById('editPropertyModal')).show();
}

// Search/filter
document.addEventListener('DOMContentLoaded', function() {
    var search = document.getElementById('propertySearch');
    var table = document.getElementById('propertiesTable');
    if (search && table) {
        search.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
