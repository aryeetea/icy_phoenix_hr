<?php
// employee_leave.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'employee') {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"];

$errors  = [];
$success = "";

// Handle new leave request
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $leave_type = $_POST["leave_type"] ?? "";
    $start_date = $_POST["start_date"] ?? "";
    $end_date   = $_POST["end_date"] ?? "";
    $reason     = trim($_POST["reason"] ?? "");

    if ($leave_type === "" || $start_date === "" || $end_date === "") {
        $errors[] = "Please choose a leave type and dates.";
    } elseif ($end_date < $start_date) {
        $errors[] = "End date cannot be before start date.";
    }

    if (empty($errors)) {
        $stmt = $mysqli->prepare("
            INSERT INTO leave_requests (emp_no, leave_type, start_date, end_date, reason, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        if ($stmt) {
            $stmt->bind_param("sssss", $emp_no, $leave_type, $start_date, $end_date, $reason);
            if ($stmt->execute()) {
                $success = "Leave request submitted for approval.";
            } else {
                $errors[] = "Could not submit leave request. Please try again.";
            }
            $stmt->close();
        } else {
            $errors[] = "Error preparing statement.";
        }
    }
}

// Load this employee's leave requests
$stmt = $mysqli->prepare("
    SELECT id, leave_type, start_date, end_date, status, reason, approved_by, approved_at
    FROM leave_requests
    WHERE emp_no = ?
    ORDER BY start_date DESC
");
$stmt->bind_param("s", $emp_no);
$stmt->execute();
$leaves = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – My Leave</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="employee">
<div class="container">
    <header class="top-bar">
        <h1>Leave Requests – <?php echo htmlspecialchars($emp_name); ?></h1>
        <div class="top-actions">
            <a href="employee_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <div class="card">
        <h2>Request Leave</h2>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="post" style="max-width:480px;">
            <label for="leave_type">Leave Type</label>
            <select id="leave_type" name="leave_type" required>
                <option value="">Select type</option>
                <option value="vacation">Vacation</option>
                <option value="sick">Sick</option>
                <option value="personal">Personal</option>
                <option value="unpaid">Unpaid</option>
                <option value="other">Other</option>
            </select>

            <label for="start_date" style="margin-top:10px;">Start Date</label>
            <input type="date" id="start_date" name="start_date" required>

            <label for="end_date" style="margin-top:10px;">End Date</label>
            <input type="date" id="end_date" name="end_date" required>

            <label for="reason" style="margin-top:10px;">Reason (optional)</label>
            <textarea id="reason" name="reason" rows="3"></textarea>

            <button class="btn btn-primary" style="margin-top:12px;">Submit Request</button>
        </form>
    </div>

    <div class="card">
        <h2>Your Leave History</h2>
        <?php if ($leaves->num_rows === 0): ?>
            <p>You have not requested any leave yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>Approved By</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($l = $leaves->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(ucfirst($l["leave_type"])); ?></td>
                        <td><?php echo htmlspecialchars($l["start_date"]); ?></td>
                        <td><?php echo htmlspecialchars($l["end_date"]); ?></td>
                        <td><?php echo htmlspecialchars($l["status"]); ?></td>
                        <td>
                            <?php
                            if ($l["approved_by"]) {
                                echo htmlspecialchars($l["approved_by"]);
                            } else {
                                echo "—";
                            }
                            ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>