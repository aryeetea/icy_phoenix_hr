<?php
// export_employees.php
session_start();
require_once __DIR__ . "/db_connect.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    exit("Not allowed");
}

$role = $_SESSION["role"];
if (!in_array($role, ['ceo', 'manager'], true)) {
    exit("Not allowed");
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=employees_export.csv');

$output = fopen('php://output', 'w');

// header row
fputcsv($output, [
    'emp_no', 'first_name', 'last_name', 'email',
    'birth_date', 'hire_date', 'role', 'dept_no', 'title_id', 'access_code'
]);

$sql = "
SELECT emp_no, first_name, last_name, email, birth_date, hire_date,
       role, dept_no, title_id, access_code
FROM employees
ORDER BY CAST(emp_no AS UNSIGNED)
";
$res = $mysqli->query($sql);
while ($row = $res->fetch_assoc()) {
    fputcsv($output, $row);
}
fclose($output);
exit;