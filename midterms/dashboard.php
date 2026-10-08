<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

include('db.php');

$checkCol = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
if ($checkCol && $checkCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL");
}

$userId = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$stmt = $conn->prepare("SELECT id, student_id, fullname, course, year_level, section, gpa, enrollment_status, profile_photo FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc() ?? [];
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['drop_subject_id'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['system_message'] = "Invalid request. Please try again.";
        header("Location: dashboard.php");
        exit;
    }

    $subjectId = (int)$_POST['drop_subject_id'];

    $conn->begin_transaction();
    try {
        $dropStmt = $conn->prepare("DELETE FROM student_enrollments WHERE user_id = ? AND subject_id = ?");
        $dropStmt->bind_param("ii", $userId, $subjectId);
        $dropStmt->execute();
        $dropStmt->close();

        $gradeDropStmt = $conn->prepare("DELETE FROM student_grades WHERE user_id = ? AND subject_id = ?");
        $gradeDropStmt->bind_param("ii", $userId, $subjectId);
        $gradeDropStmt->execute();
        $gradeDropStmt->close();

        $recomputeStmt = $conn->prepare(
            "UPDATE users u
             SET u.gpa = (
                 SELECT IFNULL(AVG(sg.grade), 0)
                 FROM student_grades sg
                 JOIN student_enrollments se
                   ON se.user_id = sg.user_id AND se.subject_id = sg.subject_id
                 WHERE sg.user_id = u.id AND sg.grade IS NOT NULL
             )
             WHERE u.id = ?"
        );
        $recomputeStmt->bind_param("i", $userId);
        $recomputeStmt->execute();
        $recomputeStmt->close();

        $conn->commit();
        $_SESSION['system_message'] = "Subject dropped successfully. GPA has been updated.";
    } catch (Throwable $t) {
        $conn->rollback();
        $_SESSION['system_message'] = "Unable to drop subject. Please try again.";
    }

    header("Location: dashboard.php");
    exit;
}

$pendingStmt = $conn->prepare("SELECT COUNT(*) AS total FROM student_enrollments WHERE user_id = ?");
$pendingStmt->bind_param("i", $userId);
$pendingStmt->execute();
$pendingCount = (int)($pendingStmt->get_result()->fetch_assoc()['total'] ?? 0);
$pendingStmt->close();

$subjectsStmt = $conn->prepare(
    "SELECT se.status, se.schedule_day_time, cs.id AS subject_id, cs.subject_name,
            cs.semester, cs.year_level,
            (SELECT COUNT(*) FROM student_enrollments x WHERE x.subject_id = se.subject_id) AS members,
            (SELECT GROUP_CONCAT(UPPER(LEFT(u2.fullname, 1)) SEPARATOR ',')
               FROM student_enrollments x
               JOIN users u2 ON u2.id = x.user_id
              WHERE x.subject_id = se.subject_id AND x.user_id <> se.user_id) AS initials
     FROM student_enrollments se
     JOIN curriculum_subjects cs ON se.subject_id = cs.id
     WHERE se.user_id = ?
     ORDER BY cs.subject_name ASC"
);
$subjectsStmt->bind_param("i", $userId);
$subjectsStmt->execute();
$enrolledSubjects = $subjectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subjectsStmt->close();

$gradesStmt = $conn->prepare(
    "SELECT cs.subject_name, sg.grade
     FROM student_grades sg
     JOIN student_enrollments se
       ON se.user_id = sg.user_id AND se.subject_id = sg.subject_id
     JOIN curriculum_subjects cs ON sg.subject_id = cs.id
     WHERE sg.user_id = ?
     ORDER BY cs.subject_name ASC"
);
$gradesStmt->bind_param("i", $userId);
$gradesStmt->execute();
$grades = $gradesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$gradesStmt->close();

$systemMessage = $_SESSION['system_message'] ?? '';
unset($_SESSION['system_message']);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$fullName   = $student['fullname'] ?? 'Student';
$initial    = strtoupper(mb_substr($fullName, 0, 1));
$gpa        = number_format((float)($student['gpa'] ?? 0), 2);
$todayLabel = date('d F Y, l');

$approvedCount = 0; $waitingCount = 0;
foreach ($enrolledSubjects as $s) { $s['status'] === 'approved' ? $approvedCount++ : $waitingCount++; }
$ungraded = 0;
foreach ($grades as $g) { if ($g['grade'] === null) $ungraded++; }
$subjectCount = count($enrolledSubjects);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Dashboard | LSPU</title>
    <link rel="icon" type="image/webp" href="LSPU%20LOGO.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="dashboard.css?v=6">
</head>
<body>
<div class="app">
    <aside class="panel sidebar">
        <div class="brand">
            <img src="LSPU LOGO.webp" alt="LSPU logo">
            <span>LSPU</span>
        </div>
        <ul class="nav">
            <li><a href="dashboard.php" class="active">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>
                Dashboard</a></li>
            <li><a href="#classes">
                <svg viewBox="0 0 24 24"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M7 9.5V15c0 1.5 2.2 3 5 3s5-1.5 5-3V9.5"/></svg>
                Enrolled Classes</a></li>
            <li><a href="#grades">
                <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="M8 15l3-3 2 2 3-4"/></svg>
                Academic Records</a></li>
            <li><a href="enrollment.php">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
                Enroll</a></li>
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
            <h4>Need help?</h4>
            <p>Do you have any problem while using the LSPU portal?</p>
            <a href="index.php#contact">Contact support</a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <label class="search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="search" id="subjectSearch" placeholder="Search your subjects" aria-label="Search your subjects">
            </label>
            <div class="topbar-right">
                <div class="date-chip"><strong><?= e($todayLabel) ?></strong></div>
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to dark mode">
                    <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
            </div>
        </div>

        <?php if ($systemMessage): ?>
            <div class="flash" role="status"><?= e($systemMessage) ?></div>
        <?php endif; ?>

        <section class="welcome">
            <div class="welcome-text">
                <h1>Welcome back, <?= e($fullName) ?>!</h1>
                <p>
                    <?php if ($subjectCount === 0): ?>
                        You have no subjects yet. Pick your subjects for the semester to get started.
                    <?php elseif ($waitingCount > 0): ?>
                        <?= $waitingCount ?> of your <?= $subjectCount ?> subject<?= $subjectCount === 1 ? '' : 's' ?> still need admin approval.
                    <?php else: ?>
                        All <?= $subjectCount ?> of your subjects are approved. Have a great semester!
                    <?php endif; ?>
                </p>
                <a href="enrollment.php" class="btn">Enroll subjects</a>
            </div>
            <svg class="books" viewBox="0 0 260 190" aria-hidden="true">
                <defs>
                    <linearGradient id="bk1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6d8bff"/><stop offset="1" stop-color="#3b5bdb"/></linearGradient>
                    <linearGradient id="bk2" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c4a2ff"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient>
                    <linearGradient id="bk3" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff9ccb"/><stop offset="1" stop-color="#ec6aa8"/></linearGradient>
                    <linearGradient id="bk4" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffd37a"/><stop offset="1" stop-color="#f5a623"/></linearGradient>
                </defs>
                <ellipse cx="130" cy="176" rx="104" ry="10" fill="#1b2559" opacity=".1"/>
                <rect x="30" y="132" width="190" height="38" rx="7" fill="url(#bk1)"/>
                <rect x="38" y="140" width="174" height="22" rx="4" fill="#fff" opacity=".92"/>
                <rect x="30" y="132" width="16" height="38" rx="6" fill="#2b3f9e"/>
                <rect x="50" y="96" width="170" height="36" rx="7" fill="url(#bk2)"/>
                <rect x="58" y="104" width="154" height="20" rx="4" fill="#fff" opacity=".92"/>
                <rect x="50" y="96" width="16" height="36" rx="6" fill="#6d3fd1"/>
                <rect x="38" y="62" width="160" height="34" rx="7" fill="url(#bk3)"/>
                <rect x="46" y="69" width="144" height="20" rx="4" fill="#fff" opacity=".92"/>
                <rect x="38" y="62" width="16" height="34" rx="6" fill="#c8498a"/>
                <rect x="62" y="30" width="130" height="32" rx="7" fill="url(#bk4)"/>
                <rect x="70" y="37" width="114" height="18" rx="4" fill="#fff" opacity=".92"/>
                <rect x="62" y="30" width="16" height="32" rx="6" fill="#d98a0b"/>
                <path d="M127 6l42 14-42 14-42-14z" fill="#1b2559"/>
                <path d="M104 29v11c0 4 10 8 23 8s23-4 23-8V29l-23 8z" fill="#2c3a85"/>
                <path d="M169 20v18" stroke="#f5a623" stroke-width="2"/>
                <circle cx="169" cy="40" r="3" fill="#f5a623"/>
            </svg>
        </section>

        <section id="classes">
            <div class="section-head">
                <h2>Classes</h2>
                <?php if ($subjectCount > 3): ?>
                    <button type="button" class="btn-ghost" id="viewAllBtn" aria-expanded="false">View all (<?= $subjectCount ?>)</button>
                <?php endif; ?>
            </div>

            <?php if (!$enrolledSubjects): ?>
                <div class="panel table-card empty">No enrolled subjects yet. Use <strong>Enroll subjects</strong> to add some.</div>
            <?php else: ?>
                <div class="class-grid" id="classGrid">
                    <?php foreach ($enrolledSubjects as $i => $subject):
                        $members  = (int)$subject['members'];
                        $mates    = $subject['initials'] ? explode(',', $subject['initials']) : [];
                        $shown    = array_slice($mates, 0, 3);
                        $extra    = max(0, $members - 1 - count($shown));
                    ?>
                        <article class="class-card c<?= $i % 3 ?>"
                                 data-name="<?= e(strtolower($subject['subject_name'])) ?>"
                                 <?= $i >= 3 ? 'data-extra="1" hidden' : '' ?>>
                            <div class="tag">
                                <svg viewBox="0 0 24 24"><path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-2z"/><path d="M8 7h6"/></svg>
                                Year <?= (int)$subject['year_level'] ?> &middot; Sem <?= (int)$subject['semester'] ?>
                            </div>
                            <h3><?= e($subject['subject_name']) ?></h3>
                            <div class="teacher"><?= e($subject['schedule_day_time']) ?></div>
                            <div class="foot">
                                <div class="avatars" title="<?= $members ?> member<?= $members === 1 ? '' : 's' ?>">
                                    <span class="avatar"><?= e($initial) ?></span>
                                    <?php foreach ($shown as $k => $ch): ?>
                                        <span class="avatar a<?= ($k % 3) + 1 ?>"><?= e($ch) ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($extra > 0): ?><span class="avatar more">+<?= $extra ?></span><?php endif; ?>
                                </div>
                                <span class="files">
                                    <svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>
                                    <?= e(ucfirst($subject['status'])) ?>
                                </span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="section-head"><h2>Enrolled subjects</h2></div>
            <div class="panel table-card">
                <div class="table-wrap">
                    <table id="subjectTable">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Instructor</th>
                                <th>Members</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$enrolledSubjects): ?>
                                <tr><td colspan="6" class="empty">No enrolled subjects yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($enrolledSubjects as $subject):
                                $mates = $subject['initials'] ? array_slice(explode(',', $subject['initials']), 0, 3) : [];
                            ?>
                                <tr data-name="<?= e(strtolower($subject['subject_name'])) ?>">
                                    <td>
                                        <span class="name"><?= e($subject['subject_name']) ?></span>
                                        <span class="sub">Year <?= (int)$subject['year_level'] ?>, Semester <?= (int)$subject['semester'] ?></span>
                                    </td>
                                    <td>To be announced</td>
                                    <td>
                                        <div class="avatars">
                                            <span class="avatar"><?= e($initial) ?></span>
                                            <?php foreach ($mates as $k => $ch): ?>
                                                <span class="avatar a<?= ($k % 3) + 1 ?>"><?= e($ch) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><?= e($subject['schedule_day_time']) ?></td>
                                    <td><span class="status <?= e($subject['status']) ?>"><?= e($subject['status']) ?></span></td>
                                    <td>
                                        <form method="post" action="dashboard.php" onsubmit="return confirm('Drop this subject?')">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="drop_subject_id" value="<?= (int)$subject['subject_id'] ?>">
                                            <button type="submit" class="link-drop">Drop</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section id="grades">
            <div class="section-head"><h2>Academic records</h2></div>
            <div class="panel table-card">
                <div class="table-wrap">
                    <table style="min-width:360px">
                        <thead>
                            <tr><th>Subject</th><th>Grade</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$grades): ?>
                                <tr><td colspan="2" class="empty">No grades posted yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($grades as $grade): $has = $grade['grade'] !== null; ?>
                                <tr data-name="<?= e(strtolower($grade['subject_name'])) ?>">
                                    <td><span class="name"><?= e($grade['subject_name']) ?></span></td>
                                    <td><span class="grade-pill <?= $has ? '' : 'none' ?>"><?= $has ? e(number_format((float)$grade['grade'], 2)) : 'Pending' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <aside class="aside">
        <section class="panel profile">
            <div class="photo" aria-hidden="true">
                <?php if (!empty($student['profile_photo'])): ?><img src="<?= e($student['profile_photo']) ?>" alt=""><?php else: ?><?= e($initial) ?><?php endif; ?>
            </div>
            <h3><?= e($fullName) ?></h3>
            <div class="role">Student</div>
            <div class="meta">
                <?= e($student['student_id'] ?? 'ID not assigned') ?><br>
                <?= e($student['course'] ?? 'Course not set') ?>
                <?php if (!empty($student['year_level'])): ?> &middot; Year <?= (int)$student['year_level'] ?><?php endif; ?>
                <?php if (!empty($student['section'])): ?> &middot; Sec <?= e($student['section']) ?><?php endif; ?>
            </div>
            <a href="account_settings.php" class="btn">Profile</a>

            <div class="gpa-strip">
                <div><b><?= e($gpa) ?></b><span>GPA</span></div>
                <div><b><?= $subjectCount ?></b><span>Subjects</span></div>
                <div><b><?= e($student['enrollment_status'] ?? 'Regular') ?></b><span>Status</span></div>
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
            <h4>Reminders</h4>
            <div class="reminder">
                <div class="bell b1"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></div>
                <div>
                    <strong><?= $waitingCount ?> pending approval</strong>
                    <span>Waiting for the admin to confirm</span>
                </div>
            </div>
            <div class="reminder">
                <div class="bell b2"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></div>
                <div>
                    <strong><?= $ungraded ?> grade<?= $ungraded === 1 ? '' : 's' ?> not posted</strong>
                    <span>Check back after your instructors submit</span>
                </div>
            </div>
            <div class="reminder">
                <div class="bell b3"><svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg></div>
                <div>
                    <strong><?= $approvedCount ?> subject<?= $approvedCount === 1 ? '' : 's' ?> approved</strong>
                    <span>Ready for the semester</span>
                </div>
            </div>
        </section>
    </aside>
</div>

<script>
(function () {
    var input = document.getElementById('subjectSearch');
    var items = document.querySelectorAll('[data-name]');
    var viewAll = document.getElementById('viewAllBtn');
    var expanded = false;

    function applyCards() {
        var q = input.value.trim().toLowerCase();
        items.forEach(function (el) {
            var match = el.dataset.name.indexOf(q) !== -1;
            if (el.classList.contains('class-card')) {
                var isExtra = el.dataset.extra === '1';
                el.hidden = !match || (isExtra && !expanded && q === '');
            } else {
                el.style.display = match ? '' : 'none';
            }
        });
    }
    input.addEventListener('input', applyCards);

    if (viewAll) {
        viewAll.addEventListener('click', function () {
            expanded = !expanded;
            viewAll.setAttribute('aria-expanded', expanded);
            viewAll.textContent = expanded ? 'Show less' : 'View all (<?= $subjectCount ?>)';
            applyCards();
        });
    }
})();

(function () {
    var view = new Date(); view.setDate(1);
    var today = new Date();
    var grid = document.getElementById('calGrid');
    var title = document.getElementById('calTitle');
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function render() {
        var y = view.getFullYear(), m = view.getMonth();
        title.textContent = months[m] + ' ' + y;
        var html = ['S','M','T','W','T','F','S'].map(function (d) { return '<div class="dow">' + d + '</div>'; }).join('');
        var first = new Date(y, m, 1).getDay();
        var days = new Date(y, m + 1, 0).getDate();
        for (var i = 0; i < first; i++) html += '<div></div>';
        for (var d = 1; d <= days; d++) {
            var isToday = d === today.getDate() && m === today.getMonth() && y === today.getFullYear();
            html += '<div class="day' + (isToday ? ' today' : '') + '">' + d + '</div>';
        }
        grid.innerHTML = html;
    }
    document.getElementById('calPrev').onclick = function () { view.setMonth(view.getMonth() - 1); render(); };
    document.getElementById('calNext').onclick = function () { view.setMonth(view.getMonth() + 1); render(); };
    render();
})();
</script>
</body>
</html>