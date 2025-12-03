<?php
// projects.php
session_start();
require_once __DIR__ . "/db_connect.php";
require_once __DIR__ . "/rank_helpers.php";

if (!isset($_SESSION["emp_no"], $_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

$emp_no   = $_SESSION["emp_no"];
$emp_name = $_SESSION["emp_name"];
$role     = $_SESSION["role"];

$create_error   = "";
$create_success = "";
$claim_msg      = "";
$claim_ok       = false;
$complete_msg   = "";
$complete_ok    = false;

/* ---------------------------
   CEO creates a new project
   --------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["new_project"])) {
    if ($role !== "ceo") {
        $create_error = "Only the CEO can post projects.";
    } else {
        $title = trim($_POST["title"] ?? "");
        $desc  = trim($_POST["description"] ?? "");
        $rank  = $_POST["difficulty_rank"] ?? "Bronze";

        if ($title === "" || $desc === "") {
            $create_error = "Please fill in all fields.";
        } else {
            // rank → reward points
            $reward = 10;
            switch ($rank) {
                case "Silver":   $reward = 20; break;
                case "Gold":     $reward = 40; break;
                case "Platinum": $reward = 70; break;
                case "Mythic":   $reward = 100; break;
            }

            $stmt = $mysqli->prepare("
                INSERT INTO projects (title, description, difficulty_rank, created_by, reward_points)
                VALUES (?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param("ssssi", $title, $desc, $rank, $emp_no, $reward);
                if ($stmt->execute()) {
                    $create_success = "Project posted successfully.";
                } else {
                    $create_error = "Could not post project.";
                }
                $stmt->close();
            } else {
                $create_error = "Database error while posting project.";
            }
        }
    }
}

/* ---------------------------
   Claim a project
   --------------------------- */
if (isset($_GET["claim"])) {
    $pid = (int)$_GET["claim"];

    $check = $mysqli->prepare("SELECT status FROM projects WHERE id = ?");
    if ($check) {
        $check->bind_param("i", $pid);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$row) {
            $claim_msg = "Project not found.";
        } elseif ($row["status"] !== "available") {
            $claim_msg = "This project is already claimed.";
        } else {
            $stmt = $mysqli->prepare("
                UPDATE projects
                SET claimed_by = ?, claimed_at = NOW(), status = 'in_progress'
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param("si", $emp_no, $pid);
                if ($stmt->execute()) {
                    $claim_ok = true;
                    $claim_msg = "You pinned this project to yourself.";
                } else {
                    $claim_msg = "Could not claim the project.";
                }
                $stmt->close();
            } else {
                $claim_msg = "Database error while claiming.";
            }
        }
    }
}

/* ---------------------------
   Complete a project (+rank)
   --------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["complete_project"])) {
    $pid = (int)$_POST["project_id"];

    $stmt = $mysqli->prepare("
        SELECT id, title, status, claimed_by, reward_points
        FROM projects
        WHERE id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $proj = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $proj = null;
    }

    if (!$proj) {
        $complete_msg = "Project not found.";
    } elseif ($proj["status"] !== "in_progress") {
        $complete_msg = "This project is not in progress.";
    } elseif ($proj["claimed_by"] !== $emp_no) {
        $complete_msg = "You can only complete projects you claimed.";
    } else {
        $stmt = $mysqli->prepare("
            UPDATE projects
            SET status = 'completed', completed_at = NOW()
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("i", $pid);
            if ($stmt->execute()) {
                $reward = (int)$proj["reward_points"];
                $rank_result = ipx_award_rank_points($mysqli, $emp_no, $reward);

                if ($rank_result) {
                    $complete_ok = true;
                    $complete_msg = "Quest complete! You earned {$reward} rank points. " .
                        "New rank: {$rank_result['tier']} ({$rank_result['points']} pts).";
                } else {
                    $complete_ok = true;
                    $complete_msg = "Quest complete! (Rank points saved, but rank refresh failed.)";
                }
            } else {
                $complete_msg = "Could not complete the project.";
            }
            $stmt->close();
        } else {
            $complete_msg = "Database error while completing project.";
        }
    }
}

/* ---------------------------
   Load all projects
   --------------------------- */
$projects = $mysqli->query("
    SELECT p.*,
           CONCAT(e.first_name, ' ', e.last_name) AS creator_name,
           (SELECT CONCAT(first_name, ' ', last_name)
              FROM employees
              WHERE emp_no = p.claimed_by) AS claimer_name
    FROM projects p
    LEFT JOIN employees e ON p.created_by = e.emp_no
    ORDER BY 
      FIELD(p.difficulty_rank, 'Bronze','Silver','Gold','Platinum','Mythic'),
      p.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Icy Phoenix – Project Board</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo htmlspecialchars($role); ?>">
<div class="container">
    <header class="top-bar">
        <h1>Project Board</h1>
        <div class="top-actions">
            <?php if ($role === "ceo"): ?>
                <a href="ceo_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <?php elseif ($role === "manager"): ?>
                <a href="manager_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <?php else: ?>
                <a href="employee_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>
    </header>

    <?php if ($create_error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($create_error); ?></div>
    <?php endif; ?>
    <?php if ($create_success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($create_success); ?></div>
    <?php endif; ?>
    <?php if ($claim_msg): ?>
        <div class="alert <?php echo $claim_ok ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($claim_msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($complete_msg): ?>
        <div class="alert <?php echo $complete_ok ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($complete_msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($role === "ceo"): ?>
        <div class="card">
            <h2>Post a New Quest</h2>
            <form method="post">
                <input type="hidden" name="new_project" value="1">

                <label for="title">Project Title</label>
                <input type="text" id="title" name="title" required>

                <label for="description">Description</label>
                <textarea id="description" name="description" required></textarea>

                <label for="difficulty_rank">Difficulty Rank</label>
                <select id="difficulty_rank" name="difficulty_rank" required>
                    <option value="Bronze">Bronze</option>
                    <option value="Silver">Silver</option>
                    <option value="Gold">Gold</option>
                    <option value="Platinum">Platinum</option>
                    <option value="Mythic">Mythic</option>
                </select>

                <button class="btn btn-primary" style="margin-top:12px;">Post Project</button>
            </form>
            <p class="hint-text" style="margin-top:8px;">
                Bronze: 10 pts • Silver: 20 pts • Gold: 40 pts • Platinum: 70 pts • Mythic: 100 pts
            </p>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Quest Wall</h2>
        <p class="hint-text">
            Pick a project, pin it to yourself, and complete it to earn rank points.
        </p>

        <div class="project-wall">
            <?php while ($p = $projects->fetch_assoc()): ?>
                <?php
                    $rank = $p["difficulty_rank"]; // Bronze, Silver...
                    $rank_class = "rank-" . strtolower($rank);
                ?>
                <div class="project-card <?php echo htmlspecialchars($rank_class); ?>">
                    <div class="project-pin"></div>

                    <div class="project-card-header">
                        <span class="project-rank-badge">
                            <?php echo htmlspecialchars($rank); ?>
                        </span>
                        <span class="project-reward">
                            <?php echo (int)$p["reward_points"]; ?> pts
                        </span>
                    </div>

                    <h3 class="project-title">
                        <?php echo htmlspecialchars($p["title"]); ?>
                    </h3>

                    <p class="project-description">
                        <?php echo nl2br(htmlspecialchars($p["description"])); ?>
                    </p>

                    <p class="project-meta">
                        Posted by <?php echo htmlspecialchars($p["creator_name"] ?? "Icy Phoenix"); ?>
                    </p>

                    <div class="project-status">
                        <?php if ($p["status"] === "available"): ?>
                            <span class="task-status pending">Available</span>
                        <?php elseif ($p["status"] === "in_progress"): ?>
                            <span class="task-status in_progress">
                                In progress by <?php echo htmlspecialchars($p["claimer_name"] ?? "someone"); ?>
                            </span>
                        <?php else: ?>
                            <span class="task-status done">Completed</span>
                        <?php endif; ?>
                    </div>

                    <div class="project-actions">
                        <?php if ($p["status"] === "available"): ?>
                            <a class="btn btn-primary btn-small"
                               href="projects.php?claim=<?php echo (int)$p["id"]; ?>">
                                Pin this project
                            </a>
                        <?php elseif ($p["status"] === "in_progress" && $p["claimed_by"] === $emp_no): ?>
                            <form method="post">
                                <input type="hidden" name="project_id" value="<?php echo (int)$p["id"]; ?>">
                                <button type="submit" name="complete_project"
                                        class="btn btn-secondary btn-small">
                                    Mark complete
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="project-no-action">—</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>
</body>
</html>