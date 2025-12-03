<?php
// import_employees.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/notify_helpers.php";   // ipx_send_email()

if (!isset($_SESSION["emp_no"], $_SESSION["role"]) || $_SESSION["role"] !== 'ceo') {
    header("Location: login.php");
    exit;
}

// Get CEO email for summary
$ceo_email = $mysqli->query("
    SELECT email
    FROM employees
    WHERE emp_no = '{$_SESSION["emp_no"]}'
    LIMIT 1
")->fetch_assoc()["email"] ?? null;

$msg = "";
$ok  = false;

$report = [];   // summary for CEO email
$skipped = [];  // skipped rows

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["csv_file"])) {

    if ($_FILES["csv_file"]["error"] !== UPLOAD_ERR_OK) {
        $msg = "Upload failed.";
    } else {
        $fh = fopen($_FILES["csv_file"]["tmp_name"], 'r');
        if (!$fh) {
            $msg = "Could not open uploaded file.";
        } else {

            $header = fgetcsv($fh);  // read header
            $count = 0;

            while (($row = fgetcsv($fh)) !== false) {

                $data = @array_combine($header, $row);
                if (!$data) {
                    $skipped[] = "Bad row: " . implode(",", $row);
                    continue;
                }

                $emp_no      = $data["emp_no"]      ?? null;
                $first_name  = $data["first_name"]  ?? "";
                $last_name   = $data["last_name"]   ?? "";
                $email       = $data["email"]       ?? "";
                $birth_date  = $data["birth_date"]  ?? null;
                $hire_date   = $data["hire_date"]   ?? null;
                $role        = $data["role"]        ?? "employee";
                $dept_no     = $data["dept_no"]     ?? null;
                $title_id    = (int)($data["title_id"] ?? 0);
                $access_code = $data["access_code"] ?? null;

                if (!$emp_no) {
                    $skipped[] = "Missing emp_no: " . implode(",", $row);
                    continue;
                }

                // Auto-generate email + password if missing
                if (empty($email)) {
                    $email = ipx_make_login_email($emp_no, $first_name, $last_name);
                }

                $plain_password = ipx_make_login_password($emp_no, $first_name);
                $password_hash  = password_hash($plain_password, PASSWORD_DEFAULT);

                // Check if employee exists already
                $exists = $mysqli->query("SELECT emp_no FROM employees WHERE emp_no='$emp_no'")->num_rows > 0;

                // Insert or update
                $stmt = $mysqli->prepare("
                    INSERT INTO employees (
                        emp_no, first_name, last_name, email, birth_date, hire_date,
                        role, dept_no, title_id, access_code, password_hash, employment_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                    ON DUPLICATE KEY UPDATE
                        first_name = VALUES(first_name),
                        last_name  = VALUES(last_name),
                        email      = VALUES(email),
                        birth_date = VALUES(birth_date),
                        hire_date  = VALUES(hire_date),
                        role       = VALUES(role),
                        dept_no    = VALUES(dept_no),
                        title_id   = VALUES(title_id),
                        access_code= VALUES(access_code),
                        password_hash = VALUES(password_hash)
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        "sssssssisss",
                        $emp_no,
                        $first_name,
                        $last_name,
                        $email,
                        $birth_date,
                        $hire_date,
                        $role,
                        $dept_no,
                        $title_id,
                        $access_code,
                        $password_hash
                    );
                    $stmt->execute();
                    $stmt->close();

                    $count++;

                    // Add to CEO summary report
                    $report[] = [
                        "emp_no" => $emp_no,
                        "name"   => "$first_name $last_name",
                        "email"  => $email,
                        "type"   => $exists ? "UPDATED" : "CREATED"
                    ];

                    // Send welcome email
                    if (!empty($email)) {
                        $subject = "Welcome to Icy Phoenix – Your Login Details ❄️";
                        $body = "Hi {$first_name} {$last_name},\n\n"
                              . "Welcome to Icy Phoenix!\n\n"
                              . "Here are your login details:\n"
                              . "Login email: {$email}\n"
                              . "Password:   {$plain_password}\n\n"
                              . "- Icy Phoenix HR ❄️\n";
                        @ipx_send_email($email, $subject, $body);
                    }

                } else {
                    $skipped[] = "DB error for employee {$emp_no}";
                }
            }

            fclose($fh);

            // Send CEO summary email
            if ($ceo_email) {
                $subject = "Icy Phoenix – Import Summary Report ❄️";

                $body = "Hello CEO,\n\n";
                $body .= "Your recent employee CSV import has completed.\n\n";
                $body .= "Total processed: {$count}\n";

                $body .= "\n=== Employees ===\n";
                foreach ($report as $r) {
                    $body .= "{$r['emp_no']} – {$r['name']} ({$r['type']})\n";
                    $body .= "   Login: {$r['email']}\n\n";
                }

                if (!empty($skipped)) {
                    $body .= "\n=== SKIPPED ROWS ===\n";
                    foreach ($skipped as $s) {
                        $body .= "- {$s}\n";
                    }
                }

                $body .= "\n- Icy Phoenix Bot ❄️\n";

                @ipx_send_email($ceo_email, $subject, $body);
            }

            $ok  = true;
            $msg = "Imported / updated {$count} employees. Summary sent to CEO.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Import Employees</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="ceo">
<div class="container">
    <header class="top-bar">
        <h1>Import Employees (CSV)</h1>
        <div class="top-actions">
            <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($msg): ?>
        <div class="alert <?php echo $ok ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width:500px;">
        <h2>Upload CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <label>CSV File</label>
            <input type="file" name="csv_file" accept=".csv" required>
            <button class="btn btn-primary" style="margin-top:12px;">Import</button>
        </form>
    </div>
</div>
</body>
</html>