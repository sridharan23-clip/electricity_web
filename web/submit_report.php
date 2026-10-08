<?php
/**
 * OutagePulse – Report Submission & Status Update API
 * Handles:
 *  - 'report_outage': Citizen 1-tap power cut reports
 *  - 'report_restored': Power restored confirmation (auto-resolves active reports)
 *  - 'subscribe_alert': Notification alert subscriptions
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/database.php';

// Parse JSON input or Form POST
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
} else {
    $input = $_POST;
}

$action = $input['action'] ?? 'report_outage';

// Map of common/known sample ZIP codes to default neighborhood names
$zipMap = [
    '94102' => 'Downtown Metro',
    '94122' => 'Sunset District',
    '94107' => 'Industrial East',
    '94110' => 'Highland Park',
    '94123' => 'Marina Heights',
    '94103' => 'Central SOMA',
    '94109' => 'Nob Hill Ridge',
    '94114' => 'Twin Peaks Valley'
];

try {
    switch ($action) {
        // ==========================================
        // 1. REPORT POWER OUTAGE
        // ==========================================
        case 'report_outage':
            $zipcode = trim($input['area_zipcode'] ?? '');
            $areaName = trim($input['area_name'] ?? '');
            $issueType = trim($input['issue_type'] ?? 'complete_blackout');

            if (empty($zipcode)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please provide a valid Area ZIP code.']);
                exit;
            }

            // Sanitize zip
            $zipcode = substr(preg_replace('/[^0-9A-Za-z-]/', '', $zipcode), 0, 10);

            // Default area name if missing
            if (empty($areaName)) {
                $areaName = $zipMap[$zipcode] ?? ('Area ' . $zipcode);
            } else {
                $areaName = substr($areaName, 0, 100);
            }

            // Validate issue type
            $validTypes = ['voltage_drop', 'complete_blackout', 'transformer_spark'];
            if (!in_array($issueType, $validTypes, true)) {
                $issueType = 'complete_blackout';
            }

            // Insert outage report
            $stmt = $pdo->prepare("
                INSERT INTO outage_reports (area_zipcode, area_name, status, issue_type, reported_at, resolved_at)
                VALUES (?, ?, 'power_out', ?, NOW(), NULL)
            ");
            $stmt->execute([$zipcode, $areaName, $issueType]);
            $reportId = $pdo->lastInsertId();

            // Fetch current active count for this ZIP to provide immediate status feedback
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) AS active_count 
                FROM outage_reports 
                WHERE area_zipcode = ? 
                  AND status = 'power_out' 
                  AND reported_at >= NOW() - INTERVAL 2 HOUR
            ");
            $stmtCount->execute([$zipcode]);
            $activeCount = (int)$stmtCount->fetchColumn();

            $statusLevel = $activeCount >= 5 ? 'critical_blackout' : ($activeCount >= 1 ? 'partial_outage' : 'normal');

            echo json_encode([
                'success'      => true,
                'message'      => "Power outage reported for {$areaName} ({$zipcode}). Grid status updated.",
                'report_id'    => (int)$reportId,
                'area_zipcode' => $zipcode,
                'area_name'    => $areaName,
                'issue_type'   => $issueType,
                'active_count' => $activeCount,
                'status_level' => $statusLevel
            ]);
            break;

        // ==========================================
        // 2. POWER RESTORED CONFIRMATION
        // ==========================================
        case 'report_restored':
            $zipcode = trim($input['area_zipcode'] ?? '');
            $areaName = trim($input['area_name'] ?? '');

            if (empty($zipcode)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please provide a valid Area ZIP code.']);
                exit;
            }

            $zipcode = substr(preg_replace('/[^0-9A-Za-z-]/', '', $zipcode), 0, 10);

            if (empty($areaName)) {
                // Look up recent area name or use map
                $stmtName = $pdo->prepare("SELECT area_name FROM outage_reports WHERE area_zipcode = ? ORDER BY id DESC LIMIT 1");
                $stmtName->execute([$zipcode]);
                $foundName = $stmtName->fetchColumn();
                $areaName = $foundName ?: ($zipMap[$zipcode] ?? ('Area ' . $zipcode));
            }

            // Auto-resolve all active reports in this ZIP code
            $stmtResolve = $pdo->prepare("
                UPDATE outage_reports 
                SET status = 'power_restored', resolved_at = NOW() 
                WHERE area_zipcode = ? AND status = 'power_out'
            ");
            $stmtResolve->execute([$zipcode]);
            $resolvedCount = $stmtResolve->rowCount();

            // Insert a positive confirmation report record so the feed reflects the citizen restoration update
            $stmtInsert = $pdo->prepare("
                INSERT INTO outage_reports (area_zipcode, area_name, status, issue_type, reported_at, resolved_at)
                VALUES (?, ?, 'power_restored', 'complete_blackout', NOW(), NOW())
            ");
            $stmtInsert->execute([$zipcode, $areaName]);

            echo json_encode([
                'success'        => true,
                'message'        => "Power restored confirmed! " . ($resolvedCount > 0 ? "{$resolvedCount} active outage report(s) auto-resolved." : "Status marked operational."),
                'area_zipcode'   => $zipcode,
                'area_name'      => $areaName,
                'resolved_count' => $resolvedCount
            ]);
            break;

        // ==========================================
        // 3. SUBSCRIBE FOR NEIGHBORHOOD ALERTS
        // ==========================================
        case 'subscribe_alert':
            $contact = trim($input['phone_or_email'] ?? '');
            $zipcode = trim($input['area_zipcode'] ?? '');
            $enabled = isset($input['alert_enabled']) ? (int)$input['alert_enabled'] : 1;

            if (empty($contact) || empty($zipcode)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Contact details and ZIP code are required.']);
                exit;
            }

            $zipcode = substr(preg_replace('/[^0-9A-Za-z-]/', '', $zipcode), 0, 10);
            $contact = substr($contact, 0, 150);

            // Upsert subscriber
            $stmtSub = $pdo->prepare("
                INSERT INTO subscribed_users (phone_or_email, area_zipcode, alert_enabled, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE alert_enabled = VALUES(alert_enabled)
            ");
            $stmtSub->execute([$contact, $zipcode, $enabled]);

            echo json_encode([
                'success'      => true,
                'message'      => "Subscribed successfully! You will receive grid alerts for ZIP {$zipcode}.",
                'contact'      => $contact,
                'area_zipcode' => $zipcode
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error processing report: ' . $e->getMessage()
    ]);
}
