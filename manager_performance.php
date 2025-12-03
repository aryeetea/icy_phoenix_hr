<?php
// manager_performance.php
// Managers + CEO can log simple performance reviews for employees.

session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$current_emp_no = $_SESSION["emp_no"];
$current_role   = $_SESSION["role"];
$current_name   = $_SESSION["emp_name"] ?? "";

$is_ceo = ($current_role === 'ceo');

// Only CEO and managers may use this page
if (!$is_ceo && $current_role !== 'manager') {
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------
   1) Which employees can this user review?
   ------------------------------------------------- */

$employees = [];

if ($is_ceo) {
    // CEO: can see everyone who is active
    $stmt = $mysqli->prepare("
        SELECT emp_no, first_name, last_name, role
        FROM employees
        WHERE employment_status = 'active'
        ORDER BY role != 'manager', role, first_name, last_name
    ");
} else {
    // Manager: only employees in their own department
    $dept_stmt = $mysqli->prepare("
        SELECT dept_no
        FROM employees
        WHERE emp_no = ?
        LIMIT 1
    ");
    $dept_stmt->bind_param("s", $current_emp_no);
    $dept_stmt->execute();
    $dept_row = $dept_stmt->get_result()->fetch_assoc();
    $dept_stmt->close();

    $manager_dept = $dept_row["dept_no"] ?? null;

    $stmt = $mysqli->prepare("
        SELECT emp_no, first_name, last_name, role
        FROM employees
        WHERE employment_status = 'active'
          AND dept_no = ?
          AND role = 'employee'
        ORDER BY first_name, last_name
    ");
    $stmt->bind_param("s", $manager_dept);
}

$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $employees[] = $row;
}
$stmt->close();

// Default selected employee in the dropdown
$selected_emp_no = $_GET["emp_no"] ?? ($employees[0]["emp_no"] ?? null);

/* -------------------------------------------------
   2) Handle "Add Review" form
   ------------------------------------------------- */

$feedback_msg = "";
$feedback_ok  = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_review"])) {
    $review_emp_no = $_POST["review_emp_no"] ?? "";
    $rating        = (int)($_POST["rating"] ?? 0);
    $comments      = trim($_POST["comments"] ?? "");

    if ($review_emp_no === "" || $rating < 1 || $rating > 5) {
        $feedback_msg = "Please choose an employee and a rating from 1 to 5.";
    } else {
        // Small safety check: make sure current user is allowed to review this employee
        $allowed = false;

        if ($is_ceo) {
            $allowed = true;
        } else {
            $check = $mysqli->prepare("
                SELECT e.emp_no
                FROM employees e
                JOIN employees m ON m.emp_no = ?
                WHERE e.emp_no = ?
                  AND e.dept_no = m.dept_no
                  AND e.role = 'employee'
            ");
            $check->bind_param("ss", $current_emp_no, $review_emp_no);
            $check->execute();
            $allowed = (bool)$check->get_result()->fetch_assoc();
            $check->close();
        }

        if (!$allowed) {
            $feedback_msg = "You are not allowed to review this person.";
        } else {
            $insert = $mysqli->prepare("
                INSERT INTO performance_reviews
                    (emp_no, reviewer_emp_no, review_date, rating, comments)
                VALUES (?, ?, CURDATE(), ?, ?)
            ");
            if ($insert) {
                $insert->bind_param("ssis", $review_emp_no, $current_emp_no, $rating, $comments);
                if ($insert->execute()) {
                    $feedback_ok  = true;
                    $feedback_msg = "Performance review saved.";
                    $selected_emp_no = $review_emp_no;
                } else {
                    $feedback_msg = "Could not save the review.";
                }
                $insert->close();
            } else {
                $feedback_msg = "Database error while inserting review.";
            }
        }
    }
}

/* -------------------------------------------------
   3) Load review history for selected employee
   ------------------------------------------------- */

$selected_employee = null;
$reviews = [];

if ($selected_emp_no) {
    // Basic info
    $emp_stmt = $mysqli->prepare("
        SELECT emp_no, first_name, last_name, role
        FROM employees
        WHERE emp_no = ?
    ");
    $emp_stmt->bind_param("s", $selected_emp_no);
    $emp_stmt->execute();
    $selected_employee = $emp_stmt->get_result()->fetch_assoc();
    $emp_stmt->close();

    // Reviews
    $rev_stmt = $mysqli->prepare("
        SELECT r.*, 
               CONCAT(e2.first_name, ' ', e2.last_name) AS reviewer_name
        FROM performance_reviews r
        LEFT JOIN employees e2 ON r.reviewer_emp_no = e2.emp_no
        WHERE r.emp_no = ?
        ORDER BY r.review_date DESC, r.id DESC
    ");
    $rev_stmt->bind_param("s", $selected_emp_no);
    $rev_stmt->execute();
    $rev_res = $rev_stmt->get_result();
    while ($row = $rev_res->fetch_assoc()) {
        $reviews[] = $row;
    }
    $rev_stmt->close();
}

$back_link = $is_ceo ? "ceo_dashboard.php" : "manager_dashboard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Performance Reviews</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($current_role); ?>">
<div class="container">
    <header class="top-bar">
        <h1>Performance Reviews</h1>
        <div class="top-actions">
            <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn btn-secondary btn-small">
                Back to Dashboard
            </a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($feedback_msg): ?>
        <div class="alert <?php echo $feedback_ok ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($feedback_msg); ?>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <h2>Select Employee</h2>

            <?php if (empty($employees)): ?>
                <p>No employees available for review.</p>
            <?php else: ?>
                <form method="get" style="max-width:320px;">
                    <label for="emp_no">Employee</label>
                    <select name="emp_no" id="emp_no" onchange="this.form.submit()">
                        <?php foreach ($employees as $e): ?>
                            <option value="<?php echo htmlspecialchars($e["emp_no"]); ?>"
                                <?php if ($e["emp_no"] == $selected_emp_no) echo "selected"; ?>>
                                <?php echo htmlspecialchars(
                                    $e["emp_no"] . " – " . $e["first_name"] . " " . $e["last_name"] .
                                    " (" . ucfirst($e["role"]) . ")"
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($selected_employee): ?>
                    <p style="margin-top:10px;">
                        Reviewing:
                        <strong><?php echo htmlspecialchars($selected_employee["first_name"] . " " . $selected_employee["last_name"]); ?></strong>
                        (<?php echo htmlspecialchars(ucfirst($selected_employee["role"])); ?>)
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Add a Review</h2>
            <?php if (!$selected_emp_no): ?>
                <p>Select an employee first.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="add_review" value="1">
                    <input type="hidden" name="review_emp_no" value="<?php echo htmlspecialchars($selected_emp_no); ?>">

                    <label for="rating">Rating (1–5)</label>
                    <select id="rating" name="rating" required>
                        <option value="">Choose rating</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>

                    <label for="comments" style="margin-top:8px;">Comments</label>
                    <textarea id="comments" name="comments" placeholder="Write a short review..."></textarea>

                    <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                        Save Review
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Review History</h2>
        <?php if (!$selected_emp_no): ?>
            <p>Select an employee to see their history.</p>
        <?php elseif (empty($reviews)): ?>
            <p>No reviews yet for this employee.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Rating</th>
                        <th>Reviewer</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reviews as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r["review_date"]); ?></td>
                        <td><?php echo (int)$r["rating"]; ?>/5</td>
                        <td><?php echo htmlspecialchars($r["reviewer_name"] ?? $r["reviewer_emp_no"]); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($r["comments"])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>