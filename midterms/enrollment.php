<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/db.php';

$checkCol = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
if ($checkCol && $checkCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL");
}

$studentId = $_SESSION['user_id'];

if (isset($_GET['ajax_query'])) {
    header('Content-Type: application/json');

    $course = trim($_GET['course'] ?? '');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $semester = isset($_GET['sem']) ? (int)$_GET['sem'] : 0;

    $stmt = $conn->prepare('SELECT id, subject_name FROM curriculum_subjects WHERE course = ? AND year_level = ? AND semester = ? ORDER BY id ASC');
    $stmt->bind_param('sii', $course, $year, $semester);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    exit;
}

// Load student
$studentStmt = $conn->prepare('SELECT id, student_id, fullname, course, year_level, profile_photo FROM users WHERE id = ?');
$studentStmt->bind_param('i', $studentId);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

if (!$student || empty($student['student_id'])) {
    die('Student record missing or student_id not assigned.');
}

$studentCode = $student['student_id'];

$profileStmt = $conn->prepare('SELECT * FROM student_profiles WHERE user_id = ? LIMIT 1');
$profileStmt->bind_param('i', $studentId);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

$enrollmentStatusStmt = $conn->prepare('SELECT COUNT(*) AS total FROM student_enrollments WHERE user_id = ?');
$enrollmentStatusStmt->bind_param('i', $studentId);
$enrollmentStatusStmt->execute();
$hasSubjectEnrollment = ((int)$enrollmentStatusStmt->get_result()->fetch_assoc()['total']) > 0;
$enrollmentStatusStmt->close();

$courses = [
    'BS Information Technology',
    'BS Computer Science',
    'BS Computer Engineering',
    'BS Data Science',
    'BS Cybersecurity',
    'BS Business Administration',
    'BS Accountancy',
    'BS Entrepreneurship',
    'BS Marketing Management',
    'BS Financial Management',
    'BS Human Resource Management',
    'BS Office Administration',
    'BS Civil Engineering',
    'BS Mechanical Engineering',
    'BS Electrical Engineering',
    'BS Electronics Engineering',
    'BS Industrial Engineering',
    'BS Chemical Engineering',
    'BS Aeronautical Engineering',
    'BS Nursing',
    'BS Medical Technology',
    'BS Pharmacy',
    'BS Physical Therapy',
    'BS Psychology',
];

function makeSectionCode(string $course, int $yearLevel): string
{
    $courseCodes = [
        'BS Information Technology' => 'BSIT',
        'BS Computer Science' => 'BSCS',
        'BS Computer Engineering' => 'BSCPE',
        'BS Data Science' => 'BSDS',
        'BS Cybersecurity' => 'BSCY',
        'BS Business Administration' => 'BSBA',
        'BS Accountancy' => 'BSACT',
        'BS Entrepreneurship' => 'BSENT',
        'BS Marketing Management' => 'BSMM',
        'BS Financial Management' => 'BSFM',
        'BS Human Resource Management' => 'BSHRM',
        'BS Office Administration' => 'BSOA',
        'BS Civil Engineering' => 'BSCE',
        'BS Mechanical Engineering' => 'BSME',
        'BS Electrical Engineering' => 'BSEE',
        'BS Electronics Engineering' => 'BSECE',
        'BS Industrial Engineering' => 'BSIE',
        'BS Chemical Engineering' => 'BSCHE',
        'BS Aeronautical Engineering' => 'BSAE',
        'BS Nursing' => 'BSN',
        'BS Medical Technology' => 'BSMT',
        'BS Pharmacy' => 'BSPHAR',
        'BS Physical Therapy' => 'BSPT',
        'BS Psychology' => 'BSPSY',
    ];

    return ($courseCodes[$course] ?? 'BS') . '-' . $yearLevel . 'A';
}

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course = trim($_POST['course'] ?? '');
    $yearLevel = isset($_POST['year_level']) ? (int)$_POST['year_level'] : 0;
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;
    $subjects = isset($_POST['subjects']) && is_array($_POST['subjects']) ? array_map('intval', $_POST['subjects']) : [];

    if (!in_array($course, $courses, true) || $yearLevel < 1 || $yearLevel > 4) {
        $error = 'Please provide a valid course and year level.';
    } elseif (isset($_POST['action_enroll']) && ($semester < 1 || $semester > 2 || !$subjects)) {
        $error = 'Please select a semester and at least one subject.';
    } else {
        $existingStmt = $conn->prepare('SELECT id FROM enrollments WHERE student_id = ? AND course = ? AND year_level = ? LIMIT 1');
        $existingStmt->bind_param('ssi', $studentCode, $course, $yearLevel);
        $existingStmt->execute();
        $existingExists = $existingStmt->get_result()->num_rows > 0;
        $existingStmt->close();

        if ($existingExists) {
            $error = 'You are already enrolled for that course and year level.';
        } else {
            $sectionCode = makeSectionCode($course, $yearLevel);

            $seedStmt = $conn->prepare(
                'INSERT INTO sections (section_code, course, year_level, capacity, enrolled_count) '
                . 'SELECT ?, ?, ?, 40, 0 '
                . 'WHERE NOT EXISTS (SELECT 1 FROM sections WHERE course = ? AND year_level = ?)'
            );
            $seedStmt->bind_param('ssisi', $sectionCode, $course, $yearLevel, $course, $yearLevel);
            $seedStmt->execute();
            $seedStmt->close();

            $pickStmt = $conn->prepare(
                'SELECT id, section_code, capacity, enrolled_count '
                . 'FROM sections '
                . 'WHERE course = ? AND year_level = ? AND enrolled_count < capacity '
                . 'ORDER BY (capacity - enrolled_count) ASC, id ASC '
                . 'LIMIT 1'
            );
            $pickStmt->bind_param('si', $course, $yearLevel);
            $pickStmt->execute();
            $section = $pickStmt->get_result()->fetch_assoc();
            $pickStmt->close();

            if (!$section) {
                $error = 'No available sections found for the selected course/year.';
            } else {
                $conn->begin_transaction();
                try {
                    if (isset($_POST['action_enroll'])) {
                        $firstName = trim($_POST['first_name'] ?? '');
                        $middleName = trim($_POST['middle_name'] ?? '');
                        $lastName = trim($_POST['last_name'] ?? '');
                        $birthDate = trim($_POST['birth_date'] ?? '');
                        $age = isset($_POST['age']) ? (int)$_POST['age'] : 0;
                        $elementary = trim($_POST['graduated_elementary'] ?? '');
                        $jhs = trim($_POST['graduated_jhs'] ?? '');
                        $english = isset($_POST['grade_english']) ? (float)$_POST['grade_english'] : 0;
                        $math = isset($_POST['grade_math']) ? (float)$_POST['grade_math'] : 0;
                        $science = isset($_POST['grade_science']) ? (float)$_POST['grade_science'] : 0;
                        $filipino = isset($_POST['grade_filipino']) ? (float)$_POST['grade_filipino'] : 0;

                        if ($firstName === '' || $lastName === '' || $birthDate === '' || $age < 15 || $elementary === '' || $jhs === '') {
                            throw new RuntimeException('Profile details are incomplete.');
                        }

                        if ($profile) {
                            $profStmt = $conn->prepare(
                                'UPDATE student_profiles SET first_name = ?, middle_name = ?, last_name = ?, birth_date = ?, age = ?, '
                                . 'graduated_elementary = ?, graduated_jhs = ?, grade_english = ?, grade_math = ?, grade_science = ?, grade_filipino = ? '
                                . 'WHERE user_id = ?'
                            );
                            $profStmt->bind_param('ssssissddddi', $firstName, $middleName, $lastName, $birthDate, $age, $elementary, $jhs, $english, $math, $science, $filipino, $studentId);
                        } else {
                            $profStmt = $conn->prepare(
                                'INSERT INTO student_profiles '
                                . '(user_id, first_name, middle_name, last_name, birth_date, age, graduated_elementary, graduated_jhs, grade_english, grade_math, grade_science, grade_filipino) '
                                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                            );
                            $profStmt->bind_param('issssissdddd', $studentId, $firstName, $middleName, $lastName, $birthDate, $age, $elementary, $jhs, $english, $math, $science, $filipino);
                        }

                        $profStmt->execute();
                        $profStmt->close();
                    }

                    $ins = $conn->prepare('INSERT INTO enrollments (student_id, course, year_level, section_id, section_code) VALUES (?, ?, ?, ?, ?)');
                    $ins->bind_param('sssis', $studentCode, $course, $yearLevel, $section['id'], $section['section_code']);
                    $ins->execute();
                    $ins->close();

                    $upd = $conn->prepare('UPDATE sections SET enrolled_count = enrolled_count + 1 WHERE id = ?');
                    $upd->bind_param('i', $section['id']);
                    $upd->execute();
                    $upd->close();

                    $studentUpd = $conn->prepare('UPDATE users SET course = ?, year_level = ?, section = ? WHERE id = ?');
                    $studentUpd->bind_param('sisi', $course, $yearLevel, $section['section_code'], $studentId);
                    $studentUpd->execute();
                    $studentUpd->close();

                    if (isset($_POST['action_enroll'])) {
                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                        foreach ($subjects as $subjectId) {
                            $checkSubject = $conn->prepare('SELECT id FROM curriculum_subjects WHERE id = ? AND course = ? AND year_level = ? AND semester = ? LIMIT 1');
                            $checkSubject->bind_param('isii', $subjectId, $course, $yearLevel, $semester);
                            $checkSubject->execute();
                            $validSubject = $checkSubject->get_result()->num_rows === 1;
                            $checkSubject->close();

                            if (!$validSubject) {
                                continue;
                            }

                            $day = $days[array_rand($days)];
                            $startHour = random_int(8, 15);
                            $endHour = $startHour + 2;
                            $schedule =$day . ' ' . $startHour . ':00 - ' .$endHour . ':00';

                            $subjectEnroll =$conn->prepare(
                                'INSERT INTO student_enrollments (user_id, subject_id, semester, year_level, status, schedule_day_time) '
                                . "VALUES (?, ?, ?, ?, 'pending', ?) "
                                . 'ON DUPLICATE KEY UPDATE semester = VALUES(semester), year_level = VALUES(year_level), status = VALUES(status), schedule_day_time = VALUES(schedule_day_time)'
                            );
                            $subjectEnroll->bind_param('iiiis',$studentId, $subjectId,$semester, $yearLevel,$schedule);
                            $subjectEnroll->execute();$subjectEnroll->close();
                        }
                    }

                    $conn->commit();

                    $success = isset($_POST['action_enroll'])
                        ? 'Enrollment submitted successfully. Your subjects are pending admin approval.'
                        : 'Enrolled successfully in section ' . htmlspecialchars($section['section_code']) . '.';
                } catch (Throwable $t) {
                    $conn->rollback();$error = 'Enrollment failed. Please complete all required fields and try again.';
                }
            }
        }
    }
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$fullName   = $student['fullname'] ?? 'Student';$initial    = strtoupper(mb_substr($fullName, 0, 1));$todayLabel = date('d F Y, l');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment | LSPU</title>
    <link rel="icon" type="image/webp" href="LSPU%20LOGO.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="dashboard.css?v=6">
</head>
<body>
<div class="app">

    <!-- ============ LEFT SIDEBAR ============ -->
    <aside class="panel sidebar">
        <div class="brand">
            <img src="LSPU LOGO.webp" alt="LSPU logo">
            <span>LSPU</span>
        </div>

        <ul class="nav">
            <li><a href="dashboard.php">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>
                Dashboard</a></li>
            <li><a href="dashboard.php#classes">
                <svg viewBox="0 0 24 24"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M7 9.5V15c0 1.5 2.2 3 5 3s5-1.5 5-3V9.5"/></svg>
                Enrolled Classes</a></li>
            <li><a href="dashboard.php#grades">
                <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="M8 15l3-3 2 2 3-4"/></svg>
                Academic Records</a></li>
            <li><a href="enrollment.php" class="active">
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

    <!-- ============ CENTER ============ -->
    <main class="main">
        <div class="topbar">
            <div class="date-chip" style="font-size:15px"><strong>Enrollment System</strong></div>
            <div class="topbar-right">
                <div class="date-chip"><strong><?= e($todayLabel) ?></strong></div>
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to dark mode">
                    <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
            </div>
        </div>

        <section class="welcome compact">
            <div class="welcome-text">
                <h1>Enroll for the semester</h1>
                <p>Fill in your background, choose your course, then pick your subjects. A section is assigned automatically.</p>
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

        <?php if ($success): ?><div class="alert success" role="status"><?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($hasSubjectEnrollment): ?>
            <div class="alert info">Your subject enrollment request is already in the system. Check your dashboard for status updates.</div>
        <?php endif; ?>

        <form method="POST" action="enrollment.php" style="display:grid; gap:22px;">
            <input type="hidden" name="action_enroll" value="1">

            <section class="panel form-panel">
                <h3>Student background</h3>
                <p class="hint">Your name, birth date and previous schools.</p>

                <div class="field-grid g3">
                    <div class="field">
                        <label for="first_name">First name</label>
                        <input id="first_name" type="text" name="first_name" required value="<?= e($profile['first_name'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="middle_name">Middle name</label>
                        <input id="middle_name" type="text" name="middle_name" value="<?= e($profile['middle_name'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="last_name">Last name</label>
                        <input id="last_name" type="text" name="last_name" required value="<?= e($profile['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="field-grid g2">
                    <div class="field">
                        <label for="birth_date">Birth date</label>
                        <input id="birth_date" type="date" name="birth_date" required value="<?= e($profile['birth_date'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="age">Age</label>
                        <input id="age" type="number" name="age" min="15" max="100" required value="<?= e($profile['age'] ?? '') ?>">
                    </div>
                </div>

                <div class="field-grid g2">
                    <div class="field">
                        <label for="graduated_elementary">Elementary school graduated</label>
                        <input id="graduated_elementary" type="text" name="graduated_elementary" required value="<?= e($profile['graduated_elementary'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="graduated_jhs">Junior high school graduated</label>
                        <input id="graduated_jhs" type="text" name="graduated_jhs" required value="<?= e($profile['graduated_jhs'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="panel form-panel">
                <h3>Grade 12 scores</h3>
                <p class="hint">Enter a grade from 70 to 100 for each subject.</p>
                <div class="field-grid g4">
                    <div class="field">
                        <label for="grade_english">English</label>
                        <input id="grade_english" type="number" step="0.01" name="grade_english" min="70" max="100" required value="<?= e($profile['grade_english'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="grade_math">Mathematics</label>
                        <input id="grade_math" type="number" step="0.01" name="grade_math" min="70" max="100" required value="<?= e($profile['grade_math'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="grade_science">Science</label>
                        <input id="grade_science" type="number" step="0.01" name="grade_science" min="70" max="100" required value="<?= e($profile['grade_science'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="grade_filipino">Filipino</label>
                        <input id="grade_filipino" type="number" step="0.01" name="grade_filipino" min="70" max="100" required value="<?= e($profile['grade_filipino'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="panel form-panel">
                <h3>Program selection</h3>
                <p class="hint">Subjects load from the curriculum for your choices.</p>

                <div class="field-grid g3">
                    <div class="field">
                        <label for="course_opt">Course</label>
                        <select name="course" id="course_opt" onchange="fetchCurriculum()" required>
                            <option value="">Select course</option>
                            <?php foreach ($courses as$courseOption): ?>
                                <option value="<?= e($courseOption) ?>" <?= (($student['course'] ?? '') === $courseOption) ? 'selected' : '' ?>><?= e($courseOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="year_opt">Year level</label>
                        <select name="year_level" id="year_opt" onchange="fetchCurriculum()" required>
                            <?php for ($year = 1; $year <= 4; $year++): ?>
                                <option value="<?= $year ?>" <?= ((int)($student['year_level'] ?? 1) === $year) ? 'selected' : '' ?>><?=$year ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="sem_opt">Semester</label>
                        <select name="semester" id="sem_opt" onchange="fetchCurriculum()" required>
                            <option value="1">1st Semester</option>
                            <option value="2">2nd Semester</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="panel form-panel">
                <div class="subjects-head">
                    <div>
                        <h3>Available subjects</h3>
                        <p class="hint" style="margin-bottom:0">Untick any subject you don't want to take.</p>
                    </div>
                    <button type="button" class="btn-ghost" id="toggleAll" hidden>Clear all</button>
                </div>
                <div id="curriculum-checklist-box">
                    <p class="placeholder">Select a course, year level, and semester to load available subjects.</p>
                </div>
            </section>

            <div class="submit-row">
                <p>Your student ID: <strong><?= e($studentCode) ?></strong></p>
                <button type="submit" class="btn">Submit enrollment</button>
            </div>
        </form>
    </main>

    <!-- ============ RIGHT SIDEBAR ============ -->
    <aside class="aside">
        <section class="panel profile">
            <div class="photo" aria-hidden="true">
                <?php if (!empty($student['profile_photo'])): ?><img src="<?= e($student['profile_photo']) ?>" alt=""><?php else: ?><?= e($initial) ?><?php endif; ?>
            </div>
            <h3><?= e($fullName) ?></h3>
            <div class="role">Student</div>
            <div class="meta"><?= e($studentCode) ?></div>
            <a href="dashboard.php" class="btn">Back to dashboard</a>
        </section>

        <section class="panel summary">
            <h4>Your selection</h4>
            <dl>
                <div><dt>Course</dt><dd id="sumCourse">&mdash;</dd></div>
                <div><dt>Year level</dt><dd id="sumYear">&mdash;</dd></div>
                <div><dt>Semester</dt><dd id="sumSem">&mdash;</dd></div>
                <div><dt>Subjects</dt><dd id="sumCount">0</dd></div>
            </dl>
        </section>

        <section class="panel reminders">
            <h4>Enrollment steps</h4>
            <ul class="steps">
                <li class="<?= $profile ? 'done' : 'now' ?>"><span class="dot"><?= $profile ? '&#10003;' : '1' ?></span>Fill in your background</li>
                <li class="<?= $hasSubjectEnrollment ? 'done' : ($profile ? 'now' : '') ?>"><span class="dot"><?= $hasSubjectEnrollment ? '&#10003;' : '2' ?></span>Choose course and subjects</li>
                <li class="<?= $hasSubjectEnrollment ? 'now' : '' ?>"><span class="dot">3</span>Wait for admin approval</li>
            </ul>
        </section>
    </aside>
</div>

<script>
const box = document.getElementById('curriculum-checklist-box');
const toggleAll = document.getElementById('toggleAll');

function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function updateSummary() {
    const course = document.getElementById('course_opt');
    const sem = document.getElementById('sem_opt');
    document.getElementById('sumCourse').textContent = course.value || '\u2014';
    document.getElementById('sumYear').textContent = document.getElementById('year_opt').value || '\u2014';
    document.getElementById('sumSem').textContent = sem.options[sem.selectedIndex].text;
    const boxes = box.querySelectorAll('input[type=checkbox]');
    const checked = box.querySelectorAll('input[type=checkbox]:checked').length;
    document.getElementById('sumCount').textContent = checked;
    toggleAll.hidden = boxes.length === 0;
    toggleAll.textContent = checked === boxes.length ? 'Clear all' : 'Select all';
}

toggleAll.addEventListener('click', () => {
    const boxes = box.querySelectorAll('input[type=checkbox]');
    const all = [...boxes].every(b => b.checked);
    boxes.forEach(b => b.checked = !all);
    updateSummary();
});
box.addEventListener('change', updateSummary);

function fetchCurriculum() {
    const course = document.getElementById('course_opt').value;
    const year = document.getElementById('year_opt').value;
    const sem = document.getElementById('sem_opt').value;

    if (!course || !year || !sem) {
        box.innerHTML = '<p class="placeholder">Select a course, year level, and semester to load available subjects.</p>';
        updateSummary();
        return;
    }

    box.innerHTML = '<p class="placeholder">Loading subjects...</p>';

    fetch(`enrollment.php?ajax_query=1&course=${encodeURIComponent(course)}&year=${encodeURIComponent(year)}&sem=${encodeURIComponent(sem)}`)
        .then(response => response.json())
        .then(subjects => {
            if (!subjects.length) {
                box.innerHTML = '<p class="placeholder">No curriculum subjects found for this course, year and semester yet.</p>';
                updateSummary();
                return;
            }

            box.innerHTML = '<div class="subject-list">' + subjects.map(subject => `
                <label class="subject-pick">
                    <input type="checkbox" name="subjects[]" value="${esc(subject.id)}" checked>
                    <span>${esc(subject.subject_name)}</span>
                </label>
            `).join('') + '</div>';
            updateSummary();
        })
        .catch(() => {
            box.innerHTML = '<p class="placeholder">Unable to load subjects. Please try again.</p>';
            updateSummary();
        });
}

fetchCurriculum();
</script>
</body>
</html>