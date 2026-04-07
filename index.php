<?php
/**
 * Subdivision Name Checker - PHP + SQLite
 *
 * Drop this file on your county PHP server as index.php.
 * Optional seed file: subdivision_names.csv in the same folder.
 * Database file created automatically: subdivision_names.sqlite
 */

declare(strict_types=1);

$dbFile = __DIR__ . '/subdivision_names.sqlite';
$csvFile = __DIR__ . '/subdivision_names.csv';

function db(): PDO
{
    static $pdo = null;
    global $dbFile;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS subdivision_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            normalized_name TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT "Issued",
            date_value TEXT,
            submitted_by TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subdivision_name ON subdivision_names(name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subdivision_status ON subdivision_names(status)');

    return $pdo;
}

function normalize_name(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return trim($value);
}

function json_response(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function table_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM subdivision_names')->fetchColumn();
}

function import_csv_if_empty(PDO $pdo, string $csvFile): void
{
    if (!file_exists($csvFile) || table_count($pdo) > 0) {
        return;
    }

    $handle = fopen($csvFile, 'r');
    if ($handle === false) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO subdivision_names
        (name, normalized_name, status, date_value, submitted_by, created_at, updated_at)
        VALUES (:name, :normalized_name, :status, :date_value, :submitted_by, :created_at, :updated_at)'
    );

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return;
    }

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === 0) {
            continue;
        }

        $name = trim((string) ($row[0] ?? ''));
        $dateValue = trim((string) ($row[1] ?? ''));

        if ($name === '' || strtolower($name) === 'subdivisionname') {
            continue;
        }

        $normalized = normalize_name($name);
        if ($normalized === '') {
            continue;
        }

        $status = strtolower($dateValue) === 'reserved' ? 'Reserved' : 'Issued';

        $insert->execute([
            ':name' => $name,
            ':normalized_name' => $normalized,
            ':status' => $status,
            ':date_value' => $dateValue,
            ':submitted_by' => '',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    fclose($handle);
}

function get_exact_match(PDO $pdo, string $name): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM subdivision_names WHERE normalized_name = :normalized_name LIMIT 1');
    $stmt->execute([':normalized_name' => normalize_name($name)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_similar_matches(PDO $pdo, string $name, int $limit = 5): array
{
    $normalized = normalize_name($name);
    if ($normalized === '') {
        return [];
    }

    $words = array_values(array_filter(explode(' ', $normalized)));
    if ($words === []) {
        return [];
    }

    $conditions = [];
    $params = [];
    foreach ($words as $i => $word) {
        $key = ':w' . $i;
        $conditions[] = 'normalized_name LIKE ' . $key;
        $params[$key] = '%' . $word . '%';
    }

    $sql = 'SELECT id, name, status, date_value, submitted_by FROM subdivision_names WHERE ' . implode(' OR ', $conditions) . ' ORDER BY name ASC LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function reserve_name(PDO $pdo, string $name, string $submittedBy): array
{
    $trimmedName = trim($name);
    $trimmedSubmittedBy = trim($submittedBy);

    if ($trimmedName === '') {
        return ['ok' => false, 'message' => 'Enter a subdivision name.'];
    }

    if (get_exact_match($pdo, $trimmedName)) {
        return ['ok' => false, 'message' => 'That subdivision name already exists or is reserved.'];
    }

    $now = date('Y-m-d H:i:s');
    $dateValue = date('Y-m-d');

    $stmt = $pdo->prepare(
        'INSERT INTO subdivision_names
        (name, normalized_name, status, date_value, submitted_by, created_at, updated_at)
        VALUES (:name, :normalized_name, :status, :date_value, :submitted_by, :created_at, :updated_at)'
    );

    try {
        $stmt->execute([
            ':name' => $trimmedName,
            ':normalized_name' => normalize_name($trimmedName),
            ':status' => 'Reserved',
            ':date_value' => $dateValue,
            ':submitted_by' => $trimmedSubmittedBy,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'Unable to reserve that name. It may have just been taken by another user.'];
    }

    return ['ok' => true, 'message' => 'Subdivision name reserved successfully.'];
}

function fetch_rows(PDO $pdo, string $search = ''): array
{
    if ($search !== '') {
        $stmt = $pdo->prepare(
            'SELECT * FROM subdivision_names
             WHERE name LIKE :search OR normalized_name LIKE :normalized
             ORDER BY name ASC
             LIMIT 500'
        );
        $stmt->execute([
            ':search' => '%' . $search . '%',
            ':normalized' => '%' . normalize_name($search) . '%',
        ]);
        return $stmt->fetchAll();
    }

    return $pdo->query('SELECT * FROM subdivision_names ORDER BY name ASC LIMIT 500')->fetchAll();
}

function export_csv(PDO $pdo): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subdivision_names_export.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['SubdivisionName', 'Status', 'Date', 'SubmittedBy']);

    $stmt = $pdo->query('SELECT name, status, date_value, submitted_by FROM subdivision_names ORDER BY name ASC');
    foreach ($stmt as $row) {
        fputcsv($out, [$row['name'], $row['status'], $row['date_value'], $row['submitted_by']]);
    }

    fclose($out);
    exit;
}

$pdo = db();
import_csv_if_empty($pdo, $csvFile);

if (isset($_GET['action']) && $_GET['action'] === 'export') {
    export_csv($pdo);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'check') {
    $name = trim((string) ($_GET['name'] ?? ''));
    if ($name === '') {
        json_response(['ok' => true, 'available' => null, 'matches' => []]);
    }

    $exact = get_exact_match($pdo, $name);
    $similar = get_similar_matches($pdo, $name);

    json_response([
        'ok' => true,
        'available' => $exact === null,
        'exactMatch' => $exact,
        'matches' => $similar,
    ]);
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'reserve') {
    $result = reserve_name(
        $pdo,
        (string) ($_POST['candidate_name'] ?? ''),
        (string) ($_POST['submitted_by'] ?? '')
    );

    $message = $result['message'];
    $messageType = $result['ok'] ? 'success' : 'error';
}

$search = trim((string) ($_GET['search'] ?? ''));
$rows = fetch_rows($pdo, $search);
$totalCount = table_count($pdo);
$reservedCount = (int) $pdo->query("SELECT COUNT(*) FROM subdivision_names WHERE status = 'Reserved'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subdivision Name Availability Tool</title>
    <style>
        :root {
            --green: #2f7d44;
            --green-dark: #256639;
            --blue: #3f5f9b;
            --light-gray: #efefef;
            --mid-gray: #d8d8d8;
            --text: #1f2937;
            --success-bg: #ecfdf3;
            --success-text: #166534;
            --error-bg: #fef2f2;
            --error-text: #991b1b;
            --warn-bg: #fffbeb;
            --warn-text: #92400e;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background: #ffffff;
        }

        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 18px;
        }

        .brand {
            background: #f2f2f2;
            border-bottom: 6px solid var(--green);
            padding: 18px 20px 14px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            align-items: center;
        }

        .brand-left {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .brand-left small {
            display: block;
            font-size: 12px;
            font-weight: 400;
            letter-spacing: 4px;
            margin-top: 6px;
        }

        .brand-right {
            text-align: left;
        }

        .brand-right .title-top {
            color: var(--green);
            font-size: 22px;
            font-weight: 700;
        }

        .brand-right .title-bottom {
            font-size: 18px;
            font-weight: 700;
            margin-top: 4px;
        }

        .note {
            font-size: 12px;
            color: #4b5563;
            margin: 12px 0 18px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-box {
            background: #f7f7f7;
            border: 1px solid var(--mid-gray);
            border-radius: 6px;
            padding: 14px;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 700;
            color: #4b5563;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
        }

        .section-title {
            background: var(--green);
            color: #fff;
            font-weight: 700;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .panel {
            border: 1px solid var(--mid-gray);
            border-radius: 6px;
            background: #fff;
            overflow: hidden;
        }

        .panel-body {
            padding: 14px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 12px;
        }

        input[type="text"] {
            width: 100%;
            border: 1px solid #bdbdbd;
            border-radius: 4px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }

        button, .button-link {
            background: var(--green);
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .button-link.blue {
            background: var(--blue);
        }

        .availability,
        .flash,
        .warning-box {
            border-radius: 4px;
            padding: 12px;
            font-size: 14px;
            margin-top: 10px;
        }

        .available {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid #bbf7d0;
        }

        .taken {
            background: var(--error-bg);
            color: var(--error-text);
            border: 1px solid #fecaca;
        }

        .flash.success {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid #bbf7d0;
        }

        .flash.error {
            background: var(--error-bg);
            color: var(--error-text);
            border: 1px solid #fecaca;
        }

        .warning-box {
            background: var(--warn-bg);
            color: var(--warn-text);
            border: 1px solid #fde68a;
        }

        .search-bar {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
            margin-bottom: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            border: 1px solid #9ca3af;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #e5e7eb;
            font-weight: 700;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #cfcfcf;
            background: #f5f5f5;
        }

        .badge.reserved {
            background: #fff7ed;
            color: #9a3412;
            border-color: #fdba74;
        }

        .small-note {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
        }

        ul.matches {
            margin: 8px 0 0 18px;
            padding: 0;
        }

        @media (max-width: 860px) {
            .brand,
            .grid,
            .stats,
            .form-row,
            .search-bar {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="brand">
            <div class="brand-left">
                YAMHILL COUNTY
                <small>OREGON</small>
            </div>
            <div class="brand-right">
                <div class="title-top">YAMHILL COUNTY SURVEYOR</div>
                <div class="title-bottom">SUBDIVISION NAME AVAILABILITY TOOL</div>
            </div>
        </div>

        <div class="note">
            Search subdivision names, check whether a proposed name is available, and reserve a new name into the county database.
        </div>

        <div class="stats">
            <div class="stat-box">
                <div class="stat-label">Total Names</div>
                <div class="stat-value"><?php echo $totalCount; ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Reserved Names</div>
                <div class="stat-value"><?php echo $reservedCount; ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Export</div>
                <div style="margin-top: 8px;">
                    <a class="button-link blue" href="?action=export">Download CSV</a>
                </div>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="flash <?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <div class="grid">
            <div>
                <div class="section-title">A. Search Existing Names</div>
                <div class="panel">
                    <div class="panel-body">
                        <form method="get" class="search-bar">
                            <input type="text" name="search" value="<?php echo h($search); ?>" placeholder="Search subdivision names...">
                            <button type="submit">Search</button>
                            <a class="button-link blue" href="index.php">Clear</a>
                        </form>

                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 42%;">Subdivision Name</th>
                                    <th style="width: 16%;">Status</th>
                                    <th style="width: 16%;">Date</th>
                                    <th style="width: 26%;">Submitted By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows === []): ?>
                                    <tr>
                                        <td colspan="4">No matching subdivision names found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo h($row['name']); ?></td>
                                            <td>
                                                <span class="badge <?php echo strtolower((string) $row['status']) === 'reserved' ? 'reserved' : ''; ?>">
                                                    <?php echo h($row['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo h($row['date_value'] ?: '—'); ?></td>
                                            <td><?php echo h($row['submitted_by'] ?: '—'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div class="small-note">Showing up to 500 records per page.</div>
                    </div>
                </div>
            </div>

            <div>
                <div class="section-title">B. Check and Reserve Name</div>
                <div class="panel">
                    <div class="panel-body">
                        <form method="post" id="reserveForm">
                            <input type="hidden" name="form_action" value="reserve">

                            <div class="form-row">
                                <input type="text" name="candidate_name" id="candidate_name" placeholder="Proposed subdivision name" required>
                                <input type="text" name="submitted_by" id="submitted_by" placeholder="Submitted by">
                                <button type="submit">Reserve Name</button>
                            </div>
                        </form>

                        <div id="availabilityBox" class="availability" style="display:none;"></div>
                        <div id="similarBox" class="warning-box" style="display:none;"></div>

                        <div class="small-note">
                            Exact duplicates are blocked by the database. Similar names are shown as a warning before reservation.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const nameInput = document.getElementById('candidate_name');
        const availabilityBox = document.getElementById('availabilityBox');
        const similarBox = document.getElementById('similarBox');
        let timer = null;

        function escapeHtml(value) {
            return value
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        async function checkName(name) {
            const response = await fetch(`?ajax=check&name=${encodeURIComponent(name)}`);
            return await response.json();
        }

        nameInput.addEventListener('input', function () {
            clearTimeout(timer);
            const value = this.value.trim();

            if (!value) {
                availabilityBox.style.display = 'none';
                similarBox.style.display = 'none';
                availabilityBox.innerHTML = '';
                similarBox.innerHTML = '';
                return;
            }

            timer = setTimeout(async () => {
                try {
                    const result = await checkName(value);

                    availabilityBox.style.display = 'block';
                    availabilityBox.className = 'availability ' + (result.available ? 'available' : 'taken');
                    availabilityBox.innerHTML = result.available
                        ? '<strong>Available:</strong> this subdivision name does not currently exist in the database.'
                        : '<strong>Taken:</strong> this subdivision name already exists or is reserved.';

                    if (Array.isArray(result.matches) && result.matches.length > 0) {
                        similarBox.style.display = 'block';
                        similarBox.innerHTML = '<strong>Similar Names Found</strong><ul class="matches">' +
                            result.matches.map(item => '<li>' + escapeHtml(item.name) + '</li>').join('') +
                            '</ul>';
                    } else {
                        similarBox.style.display = 'none';
                        similarBox.innerHTML = '';
                    }
                } catch (error) {
                    availabilityBox.style.display = 'block';
                    availabilityBox.className = 'availability taken';
                    availabilityBox.innerHTML = 'Unable to check name availability right now.';
                    similarBox.style.display = 'none';
                    similarBox.innerHTML = '';
                }
            }, 250);
        });
    </script>
</body>
</html>
