<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Fetch all events for the filter dropdown
$eventsList = $pdo->query("SELECT id, name FROM events ORDER BY created_at DESC")->fetchAll();

$limit = 15;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$eventFilter = $_GET['event_id'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(el.recipient_email LIKE ? OR el.certificate_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter !== '') {
    $whereClauses[] = "el.status = ?";
    $params[] = $statusFilter;
}
if ($eventFilter !== '') {
    $whereClauses[] = "ep.event_id = ?";
    $params[] = $eventFilter;
}
if ($startDate !== '') {
    $whereClauses[] = "DATE(el.created_at) >= ?";
    $params[] = $startDate;
}
if ($endDate !== '') {
    $whereClauses[] = "DATE(el.created_at) <= ?";
    $params[] = $endDate;
}

$whereSql = count($whereClauses) > 0 ? " WHERE " . implode(" AND ", $whereClauses) : "";

$baseQuery = "
    FROM email_logs el
    LEFT JOIN event_participants ep ON el.certificate_id = ep.certificate_id
    LEFT JOIN events e ON ep.event_id = e.id
" . $whereSql;

$stmtCount = $pdo->prepare("SELECT COUNT(el.id) " . $baseQuery);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$stmt = $pdo->prepare("SELECT el.*, e.name as event_name " . $baseQuery . " ORDER BY el.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Build query string for pagination links
$queryParams = $_GET;
unset($queryParams['page']);
$queryString = http_build_query($queryParams);
$searchParam = $queryString ? '&' . $queryString : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://dcwwiki.org/images/5/56/DCW_logo.png">
    <meta charset="UTF-8">
    <title>Email Logs - Admin Panel</title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        .filter-form {
            background: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }
        .filter-group input, .filter-group select {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            min-width: 150px;
        }
        .filter-group input[type="text"] {
            min-width: 250px;
        }
        .btn-clear {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn-clear:hover {
            background-color: #e2e8f0;
        }
    </style>
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;">Admin Panel - Email Logs</span>
    </div>
    <div>
        <a href="dashboard.php" style="margin-right: 15px;">Back to Dashboard</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="container" style="max-width: 1300px;">
    <h2 style="margin-top: 0; margin-bottom: 20px;">Universal Email Delivery Logs</h2>
    
    <form method="GET" class="filter-form">
        <div class="filter-group">
            <label>Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search email or Cert ID...">
        </div>
        <div class="filter-group">
            <label>Event</label>
            <select name="event_id">
                <option value="">All Events</option>
                <?php foreach($eventsList as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $eventFilter == $ev['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ev['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="Success" <?= $statusFilter === 'Success' ? 'selected' : '' ?>>Success</option>
                <option value="Failed" <?= $statusFilter === 'Failed' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Start Date</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
        </div>
        <div class="filter-group">
            <label>End Date</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
        </div>
        <div class="filter-group" style="flex-direction: row; gap: 10px;">
            <button type="submit" class="btn">Apply Filters</button>
            <a href="email_logs.php" class="btn btn-clear">Clear</a>
        </div>
    </form>

    <?php if (count($logs) > 0): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Cert ID</th>
                        <th>Event Name</th>
                        <th>Recipient Email</th>
                        <th>Status</th>
                        <th>Error Message</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['id']) ?></td>
                            <td style="font-family: monospace; font-size: 13px;"><?= htmlspecialchars($log['certificate_id']) ?></td>
                            <td style="font-size: 14px;"><?= htmlspecialchars($log['event_name'] ?? 'Unknown Event') ?></td>
                            <td><?= htmlspecialchars($log['recipient_email']) ?></td>
                            <td>
                                <?php if ($log['status'] === 'Success'): ?>
                                    <span style="background-color: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">Success</span>
                                <?php else: ?>
                                    <span style="background-color: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: #64748b; font-size: 13px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($log['error_message']) ?>">
                                <?= $log['error_message'] ? htmlspecialchars($log['error_message']) : '—' ?>
                            </td>
                            <td style="font-size: 13px;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="?page=<?= max(1, $page - 1) . $searchParam ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">Prev</a>
            
            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i . $searchParam ?>" class="page-btn <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <a href="?page=<?= min($totalPages, $page + 1) . $searchParam ?>" class="page-btn <?= ($page >= $totalPages) ? 'disabled' : '' ?>">Next</a>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <div style="text-align: center; padding: 40px; background: white; border: 1px solid #e2e8f0; border-radius: 8px;">
            <svg viewBox="0 0 24 24" width="48" height="48" style="color: #cbd5e1; margin-bottom: 15px;"><path fill="currentColor" d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
            <p style="color: #64748b; margin: 0; font-size: 16px;">No email logs found matching your criteria.</p>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
