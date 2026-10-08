<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Redirect to login page if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/db.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Load logged-in user role from DB (so we don't depend on client/session tampering)
$meStmt = $conn->prepare("SELECT id, fullname, role FROM users WHERE id = ?");
$meStmt->bind_param("i", $_SESSION['user_id']);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();
$meStmt->close();

$profRole = $me['role'] ?? null;

// Trim whitespace and force lowercase to support consistent admin role checks.
$profRole = is_string($profRole) ? strtolower(trim($profRole)) : $profRole;
if ($profRole !== 'admin') {
    echo "<script>alert('Access denied. Admin account required.'); window.location.href='dashboard.php';</script>";
    exit;
}

if (isset($_GET['ajax_fetch_student_subjects'])) {
    header('Content-Type: application/json');
    $targetStudentId = (int)($_GET['student_id'] ?? 0);

    $stmt = $conn->prepare(
        "SELECT cs.id AS subject_id, cs.subject_name, sg.grade
         FROM student_enrollments se
         JOIN curriculum_subjects cs ON se.subject_id = cs.id
         LEFT JOIN student_grades sg ON sg.subject_id = cs.id AND sg.user_id = se.user_id
         WHERE se.user_id = ? AND se.status = 'approved'
         ORDER BY cs.subject_name ASC"
    );
    $stmt->bind_param("i", $targetStudentId);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_process_enrollment'], $_POST['student_id'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['admin_flash'] = 'Invalid request. Please try again.';
        header('Location: prof_dashboard.php');
        exit;
    }

    $action = $_POST['action_process_enrollment'];
    $targetStudentId = (int)$_POST['student_id'];

    if ($action === 'approve') {
        $updateEnr = $conn->prepare("UPDATE student_enrollments SET status = 'approved' WHERE user_id = ?");
        $updateEnr->bind_param("i", $targetStudentId);
        $updateEnr->execute();
        $updateEnr->close();
        $_SESSION['admin_flash'] = 'Enrollment approved.';
        header('Location: prof_dashboard.php');
        exit;
    }

    if ($action === 'reject') {
        $rejectEnr = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ?");
        $rejectEnr->bind_param("i", $targetStudentId);
        $rejectEnr->execute();
        $rejectEnr->close();
        $_SESSION['admin_flash'] = 'Enrollment request rejected.';
        header('Location: prof_dashboard.php');
        exit;
    }
}

// Handle grade update
$success = null;
$error = null;

// Curriculum subject CRUD (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['curriculum_subject_action'])) {
    $action = $_POST['curriculum_subject_action'] ?? '';
    $course = trim($_POST['subject_course'] ?? '');
    $yearLevel = isset($_POST['subject_year_level']) ? (int)$_POST['subject_year_level'] : 0;
    $semester = isset($_POST['subject_semester']) ? (int)$_POST['subject_semester'] : 0;
    $subjectName = trim($_POST['subject_name'] ?? '');

    if ($action === 'add') {
        if ($course === '' || $yearLevel < 1 || $yearLevel > 4 || $semester < 1 || $semester > 2 || $subjectName === '') {
            $error = 'Please provide valid curriculum subject details.';
        } else {
            $ins = $conn->prepare(
                "INSERT INTO curriculum_subjects (course, year_level, semester, subject_name)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name)"
            );
            $ins->bind_param('siis', $course, $yearLevel, $semester, $subjectName);
            $ins->execute();
            $ins->close();

            $success = 'Curriculum subject saved.';
        }
    }

    if ($action === 'delete') {
        $subjectId = isset($_POST['curriculum_subject_id']) ? (int)$_POST['curriculum_subject_id'] : 0;
        if ($subjectId <= 0) {
            $error = 'Invalid curriculum subject id.';
        } else {
            $del = $conn->prepare('DELETE FROM curriculum_subjects WHERE id = ?');
            $del->bind_param('i', $subjectId);
            $del->execute();
            $del->close();

            $success = 'Curriculum subject deleted.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grades'])) {
    $targetStudentId = (int)($_POST['id'] ?? 0);
    $studentCode = trim($_POST['student_id'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $yearLevel = isset($_POST['year_level']) ? (int)$_POST['year_level'] : 0;
    $gpa = isset($_POST['gpa']) ? (float)$_POST['gpa'] : -1;

    if ($targetStudentId <= 0 || $studentCode === '' || $course === '' || $yearLevel < 1 || $yearLevel > 10 || $gpa < 0 || $gpa > 4) {
        $error = 'Please select a student and provide valid student record details.';
    } else {
        $updateStudent = $conn->prepare(
            'UPDATE users
             SET course = ?, section = ?, year_level = ?, gpa = ?
             WHERE id = ? AND student_id = ?'
        );
        $updateStudent->bind_param('ssidis', $course, $section, $yearLevel, $gpa, $targetStudentId, $studentCode);
        $saved = $updateStudent->execute();
        $updateStudent->close();

        if ($saved) {
            $success = 'Student record updated successfully.';
        } else {
            $error = 'Student record update failed. Please try again.';
        }
    }
}

// List curriculum subjects for admin UI
$curriculumSubjectsStmt = $conn->prepare(
    "SELECT id, course, year_level, semester, subject_name
     FROM curriculum_subjects
     ORDER BY course ASC, year_level ASC, semester ASC, subject_name ASC"
);
$curriculumSubjectsStmt->execute();
$curriculumSubjects = $curriculumSubjectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$curriculumSubjectsStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_submit_student_evaluations'])) {

    $targetStudentId = (int)($_POST['student_user_id'] ?? 0);
    $assignedSection = trim($_POST['override_section'] ?? '');
    $standingStatus = trim($_POST['academic_status'] ?? 'Regular');
    $allowedStatuses = ['Regular', 'Irregular', 'Dropped'];

    if ($targetStudentId > 0 && $assignedSection !== '' && in_array($standingStatus, $allowedStatuses, true)) {
        $updateUserMain = $conn->prepare("UPDATE users SET section = ?, enrollment_status = ? WHERE id = ?");
        $updateUserMain->bind_param("ssi", $assignedSection, $standingStatus, $targetStudentId);
        $updateUserMain->execute();
        $updateUserMain->close();

        if (isset($_POST['grades']) && is_array($_POST['grades'])) {
            foreach ($_POST['grades'] as $subjectId => $scoreVal) {
                if ($scoreVal === '' || $scoreVal === null) {
                    continue;
                }

                $subjectId = (int)$subjectId;
                $scoreFloat = (float)$scoreVal;

                $gradeStmt = $conn->prepare(
                    "INSERT INTO student_grades (user_id, subject_id, grade)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE grade = VALUES(grade)"
                );
                $gradeStmt->bind_param("iid", $targetStudentId, $subjectId, $scoreFloat);
                $gradeStmt->execute();
                $gradeStmt->close();
            }
        }

        $gpaStmt = $conn->prepare(
            "UPDATE users u
             SET u.gpa = (
                SELECT IFNULL(AVG(sg.grade), 0)
                FROM student_grades sg
                JOIN student_enrollments se
                  ON se.user_id = sg.user_id
                 AND se.subject_id = sg.subject_id
                WHERE sg.user_id = u.id
                  AND sg.grade IS NOT NULL
             )
             WHERE u.id = ?"
        );
        $gpaStmt->bind_param("i", $targetStudentId);
        $gpaStmt->execute();
        $gpaStmt->close();

        $success = "Student evaluation saved successfully. GPA updated.";

    } else {
        $error = "Please select a valid student, section, and status.";
    }
}

$pendingStmt = $conn->prepare(
    "SELECT DISTINCT u.id, u.fullname, u.email, u.course, u.year_level, u.section
     FROM users u
     JOIN student_enrollments se ON u.id = se.user_id
     WHERE se.status = 'pending'
     ORDER BY u.fullname ASC"
);
$pendingStmt->execute();
$pendingEnrollees = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pendingStmt->close();

// Load all students for display
$studentsStmt = $conn->prepare("SELECT id, fullname, student_id, course, section, year_level, gpa, enrollment_status FROM users WHERE student_id IS NOT NULL AND (role IS NULL OR LOWER(TRIM(role)) != 'admin') ORDER BY fullname ASC");
$studentsStmt->execute();
$students = $studentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$studentsStmt->close();

// ---------- View data ----------
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if ($success === null && isset($_GET['status']) && $_GET['status'] === 'student_deleted') {
    $success = 'Student deleted successfully.';
}
if ($success === null && !empty($_SESSION['admin_flash'])) {
    $success = $_SESSION['admin_flash'];
}
unset($_SESSION['admin_flash']);

$adminName  = $me['fullname'] ?? 'Admin';
$adminInit  = strtoupper(mb_substr($adminName, 0, 1));
$todayLabel = date('d F Y, l');

$totalStudents = count($students);
$pendingTotal  = count($pendingEnrollees);
$gpaSum = 0.0; $gpaN = 0; $atRisk = 0; $unassigned = 0;
foreach ($students as $s) {
    if ((float)($s['gpa'] ?? 0) > 0) { $gpaSum += (float)$s['gpa']; $gpaN++; }
    $st = strtolower((string)($s['enrollment_status'] ?? 'Regular'));
    if ($st === 'irregular' || $st === 'dropped') { $atRisk++; }
    if (trim((string)($s['section'] ?? '')) === '') { $unassigned++; }
}
$avgGpa = $gpaN ? number_format($gpaSum / $gpaN, 2) : '0.00';

// Group students by section
$studentsBySection = [];
foreach ($students as $s) {
    $sec = trim((string)($s['section'] ?? 'Unassigned'));
    if ($sec === '') { $sec = 'Unassigned'; }
    $studentsBySection[$sec][] = $s;
}
$sectionOrder = ['A','B','C','D','E','F','G','H'];
$sections = array_keys($studentsBySection);
usort($sections, function ($a, $b) use ($sectionOrder) {
    $aIdx = array_search(strtoupper($a), $sectionOrder, true);
    $bIdx = array_search(strtoupper($b), $sectionOrder, true);
    $aRank = ($aIdx === false) ? 999 : $aIdx;
    $bRank = ($bIdx === false) ? 999 : $bIdx;
    if ($aRank === $bRank) { return strcasecmp($a, $b); }
    return $aRank <=> $bRank;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | LSPU</title>
    <link rel="icon" type="image/webp" href="LSPU%20LOGO.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="dashboard.css?v=6">
</head>
<body id="top">
<div class="app">

    <!-- ============ LEFT SIDEBAR ============ -->
    <aside class="panel sidebar">
        <div class="brand">
            <img src="LSPU LOGO.webp" alt="LSPU logo">
            <span>LSPU</span>
        </div>

        <ul class="nav">
            <li><a href="#top" class="active">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>
                Dashboard</a></li>
            <li><a href="#pending">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                Pending Requests</a></li>
            <li><a href="#roster">
                <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.3c2 .8 3.5 2.8 3.5 5.7"/></svg>
                Student Roster</a></li>
            <li><a href="#edit-panel">
                <svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>
                Update Records</a></li>
            <li><a href="account_settings.php">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                Account Settings</a></li>
            <li><a href="logout.php">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
                Logout</a></li>
        </ul>

        <div class="help-card">
            <svg viewBox="0 0 96 80" aria-hidden="true">
                <circle cx="48" cy="40" r="32" fill="#fff" opacity=".7"/>
                <rect x="26" y="30" width="44" height="30" rx="6" fill="#5b7cfa"/>
                <rect x="32" y="36" width="32" height="18" rx="3" fill="#dfe7ff"/>
                <circle cx="48" cy="22" r="9" fill="#ffd0a8"/>
                <path d="M39 20c1-8 17-8 18 0-4-3-14-3-18 0z" fill="#1b2559"/>
                <text x="48" y="50" font-size="14" font-weight="700" text-anchor="middle" fill="#5b7cfa" font-family="Poppins,sans-serif">?</text>
            </svg>
            <h4>Admin tips</h4>
            <p>Use Edit to fix a record, or Evaluate to post grades and set standing.</p>
        </div>
    </aside>

    <!-- ============ CENTER ============ -->
    <main class="main">
        <div class="topbar">
            <label class="search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="search" id="adminSearch" placeholder="Search students by name, ID or course" aria-label="Search students">
            </label>
            <div class="topbar-right">
                <div class="date-chip"><strong><?= e($todayLabel) ?></strong></div>
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to dark mode">
                    <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
            </div>
        </div>

        <?php if ($success): ?><div class="alert success" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="welcome compact">
            <div class="welcome-text">
                <h1>Welcome back, <?= e($adminName) ?>!</h1>
                <p>
                    <?php if ($pendingTotal > 0): ?>
                        <?= $pendingTotal ?> enrollment request<?= $pendingTotal === 1 ? ' is' : 's are' ?> waiting for your review.
                    <?php else: ?>
                        No pending enrollment requests. Everything is up to date.
                    <?php endif; ?>
                </p>
                <a href="#pending" class="btn">Review requests</a>
            </div>
            <svg class="books" viewBox="0 0 260 190" aria-hidden="true">
                <defs>
                    <linearGradient id="bk1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6d8bff"/><stop offset="1" stop-color="#3b5bdb"/></linearGradient>
                    <linearGradient id="bk2" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c4a2ff"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient>
                    <linearGradient id="bk3" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff9ccb"/><stop offset="1" stop-color="#ec6aa8"/></linearGradient>
                    <linearGradient id="bk4" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffd37a"/><stop offset="1" stop-color="#f5a623"/></linearGradient>
                </defs>
                <ellipse cx="130" cy="176" rx="104" ry="10" fill="#1b2559" opacity=".1"/>
                <rect x="30" y="132" width="190" height="38" rx="7" fill="url(#bk1)"/><rect x="38" y="140" width="174" height="22" rx="4" fill="#fff" opacity=".92"/><rect x="30" y="132" width="16" height="38" rx="6" fill="#2b3f9e"/>
                <rect x="50" y="96" width="170" height="36" rx="7" fill="url(#bk2)"/><rect x="58" y="104" width="154" height="20" rx="4" fill="#fff" opacity=".92"/><rect x="50" y="96" width="16" height="36" rx="6" fill="#6d3fd1"/>
                <rect x="38" y="62" width="160" height="34" rx="7" fill="url(#bk3)"/><rect x="46" y="69" width="144" height="20" rx="4" fill="#fff" opacity=".92"/><rect x="38" y="62" width="16" height="34" rx="6" fill="#c8498a"/>
                <rect x="62" y="30" width="130" height="32" rx="7" fill="url(#bk4)"/><rect x="70" y="37" width="114" height="18" rx="4" fill="#fff" opacity=".92"/><rect x="62" y="30" width="16" height="32" rx="6" fill="#d98a0b"/>
                <path d="M127 6l42 14-42 14-42-14z" fill="#1b2559"/><path d="M104 29v11c0 4 10 8 23 8s23-4 23-8V29l-23 8z" fill="#2c3a85"/>
                <path d="M169 20v18" stroke="#f5a623" stroke-width="2"/><circle cx="169" cy="40" r="3" fill="#f5a623"/>
            </svg>
        </section>

        <!-- Stats -->
        <section class="stat-grid">
            <article class="class-card stat-card c0">
                <div class="tag">Total students</div>
                <div class="big"><?= $totalStudents ?></div>
                <div class="label"><?= $unassigned ?> without a section</div>
            </article>
            <article class="class-card stat-card c1">
                <div class="tag">Pending requests</div>
                <div class="big"><?= $pendingTotal ?></div>
                <div class="label">Awaiting approval</div>
            </article>
            <article class="class-card stat-card c2">
                <div class="tag">Average GPA</div>
                <div class="big"><?= e($avgGpa) ?></div>
                <div class="label">Across graded students</div>
            </article>
        </section>

        <!-- Pending requests -->
        <section id="pending">
            <div class="section-head"><h2>Pending enrollment requests</h2></div>
            <div class="panel table-card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$pendingEnrollees): ?>
                                <tr><td colspan="6" class="empty">No pending enrollment requests.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($pendingEnrollees as $i => $p): ?>
                                <tr>
                                    <td>
                                        <div class="person">
                                            <span class="person-dot d<?= $i % 4 ?>"><?= e(strtoupper(mb_substr($p['fullname'], 0, 1))) ?></span>
                                            <div>
                                                <span class="name"><?= e($p['fullname']) ?></span>
                                                <span class="sub"><?= e($p['email']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= e($p['course'] ?? '') ?></td>
                                    <td><?= e($p['year_level'] ?? '') ?></td>
                                    <td><?= e(($p['section'] ?? '') !== '' ? $p['section'] : 'Unassigned') ?></td>
                                    <td><span class="status pending">pending</span></td>
                                    <td>
                                        <div class="actions">
                                            <form method="post" action="prof_dashboard.php">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="student_id" value="<?= (int)$p['id'] ?>">
                                                <input type="hidden" name="action_process_enrollment" value="approve">
                                                <button type="submit" class="btn-sm btn-approve">Approve</button>
                                            </form>
                                            <form method="post" action="prof_dashboard.php" onsubmit="return confirm('Reject this enrollment request?')">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="student_id" value="<?= (int)$p['id'] ?>">
                                                <input type="hidden" name="action_process_enrollment" value="reject">
                                                <button type="submit" class="btn-sm btn-reject">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Roster -->
        <section id="roster">
            <div class="section-head"><h2>Student roster</h2></div>
            <div class="panel table-card">
                <?php if (!$students): ?>
                    <p class="empty">No students found.</p>
                <?php endif; ?>
                <?php foreach ($sections as $sec): ?>
                    <div class="roster-group">
                        <h4>Section: <?= e($sec) ?> <span class="count-chip"><?= count($studentsBySection[$sec]) ?></span></h4>
                        <div class="table-wrap">
                            <table style="min-width:760px">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Course</th>
                                        <th>Year</th>
                                        <th>GPA</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studentsBySection[$sec] as $i => $s):
                                        $stKey = strtolower((string)($s['enrollment_status'] ?? 'Regular'));
                                        if (!in_array($stKey, ['regular', 'irregular', 'dropped'], true)) { $stKey = 'regular'; }
                                    ?>
                                        <tr class="student-row"
                                            data-search="<?= e(strtolower($s['fullname'] . ' ' . $s['student_id'] . ' ' . ($s['course'] ?? ''))) ?>"
                                            data-id="<?= (int)$s['id'] ?>"
                                            data-name="<?= e($s['fullname']) ?>"
                                            data-sid="<?= e($s['student_id']) ?>"
                                            data-course="<?= e($s['course'] ?? '') ?>"
                                            data-section="<?= e($s['section'] ?? '') ?>"
                                            data-year="<?= e($s['year_level'] ?? '') ?>"
                                            data-gpa="<?= e($s['gpa'] ?? '0.00') ?>"
                                            data-status="<?= e($s['enrollment_status'] ?? 'Regular') ?>">
                                            <td>
                                                <div class="person">
                                                    <span class="person-dot d<?= $i % 4 ?>"><?= e(strtoupper(mb_substr($s['fullname'], 0, 1))) ?></span>
                                                    <div>
                                                        <span class="name"><?= e($s['fullname']) ?></span>
                                                        <span class="sub"><?= e($s['student_id']) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= e($s['course'] ?? '') ?></td>
                                            <td><?= e($s['year_level'] ?? '') ?></td>
                                            <td><span class="grade-pill"><?= e(number_format((float)($s['gpa'] ?? 0), 2)) ?></span></td>
                                            <td><span class="status <?= $stKey ?>"><?= e($s['enrollment_status'] ?? 'Regular') ?></span></td>
                                            <td>
                                                <div class="actions">
                                                    <button type="button" class="btn-sm btn-edit" onclick="editRow(this)">Edit</button>
                                                    <button type="button" class="btn-sm btn-eval" onclick="evalRow(this)">Evaluate</button>
                                                    <form method="POST" action="delete.php" onsubmit="return confirm('Delete this student and all related records? This cannot be undone.');">
                                                        <input type="hidden" name="student_user_id" value="<?= (int)$s['id'] ?>">
                                                        <button type="submit" class="btn-sm btn-del">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Edit form -->
        <section id="edit-panel">
            <div class="section-head"><h2>Update student record</h2></div>
            <form method="POST" action="prof_dashboard.php" class="panel form-panel">
                <input type="hidden" name="update_grades" value="1">
                <input type="hidden" id="id" name="id" value="0">

                <p class="edit-hint" id="editHint">Pick a student with the <strong>Edit</strong> button in the roster to fill this form.</p>

                <div class="field-grid g3">
                    <div class="field">
                        <label for="student_id">Student ID</label>
                        <input id="student_id" name="student_id" type="text" readonly required>
                    </div>
                    <div class="field">
                        <label for="course">Course</label>
                        <input id="course" name="course" type="text" required>
                    </div>
                    <div class="field">
                        <label for="section">Section</label>
                        <input id="section" name="section" type="text" placeholder="e.g. BSIT-1A">
                    </div>
                </div>
                <div class="field-grid g3">
                    <div class="field">
                        <label for="year_level">Year level</label>
                        <input id="year_level" name="year_level" type="number" min="1" max="10" required>
                    </div>
                    <div class="field">
                        <label for="gpa">GPA</label>
                        <input id="gpa" name="gpa" type="number" step="0.01" min="0" max="4" required>
                    </div>
                    <div class="field" style="display:flex; align-items:flex-end;">
                        <button class="btn" type="submit" style="width:100%;">Save changes</button>
                    </div>
                </div>
            </form>
        </section>
    </main>

    <!-- ============ RIGHT SIDEBAR ============ -->
    <aside class="aside">
        <section class="panel profile">
            <div class="photo" aria-hidden="true"><?= e($adminInit) ?></div>
            <h3><?= e($adminName) ?></h3>
            <div class="role">Administrator</div>
            <div class="meta">Campus Portal</div>
            <a href="account_settings.php" class="btn">Profile</a>

            <div class="gpa-strip">
                <div><b><?= $totalStudents ?></b><span>Students</span></div>
                <div><b><?= $pendingTotal ?></b><span>Pending</span></div>
                <div><b><?= $atRisk ?></b><span>At risk</span></div>
            </div>
        </section>

        <section class="panel calendar">
            <div class="cal-head">
                <h4 id="calTitle"></h4>
                <div class="cal-nav">
                    <button type="button" id="calPrev" aria-label="Previous month">&#8249;</button>
                    <button type="button" id="calNext" aria-label="Next month">&#8250;</button>
                </div>
            </div>
            <div class="cal-grid" id="calGrid"></div>
        </section>

        <section class="panel reminders">
            <h4>Needs attention</h4>
            <div class="reminder">
                <div class="bell b1"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></div>
                <div>
                    <strong><?= $pendingTotal ?> pending request<?= $pendingTotal === 1 ? '' : 's' ?></strong>
                    <span>Approve or reject to move them forward</span>
                </div>
            </div>
            <div class="reminder">
                <div class="bell b2"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></div>
                <div>
                    <strong><?= $atRisk ?> irregular or dropped</strong>
                    <span>Monitor students who need academic intervention</span>
                </div>
            </div>
        </section>
    </aside>

</div>
</body>
</html>