<?php
/**
 * OutagePulse – Real-Time Status Feed API
 * Returns JSON feed of active power outages, area grid status, and recent reports.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/database.php';

try {
    $filterZip = isset($_GET['zipcode']) ? trim($_GET['zipcode']) : '';

    // 1. Total Active Outage Reports in the last 2 hours (City-Wide)
    $stmtOverall = $pdo->query("
        SELECT 
            COUNT(*) AS total_active_reports,
            COUNT(DISTINCT area_zipcode) AS impacted_areas,
            SUM(CASE WHEN issue_type = 'complete_blackout' THEN 1 ELSE 0 END) AS total_blackouts,
            SUM(CASE WHEN issue_type = 'voltage_drop' THEN 1 ELSE 0 END) AS total_voltage_drops,
            SUM(CASE WHEN issue_type = 'transformer_spark' THEN 1 ELSE 0 END) AS total_sparks
        FROM outage_reports
        WHERE status = 'power_out' 
          AND reported_at >= NOW() - INTERVAL 2 HOUR
    ");
    $overallStats = $stmtOverall->fetch();

    // 2. Total Restored in the last 24 hours
    $stmtRestored = $pdo->query("
        SELECT COUNT(*) AS total_restored_24h 
        FROM outage_reports 
        WHERE status = 'power_restored' 
          AND (resolved_at >= NOW() - INTERVAL 24 HOUR OR reported_at >= NOW() - INTERVAL 24 HOUR)
    ");
    $restoredStats = $stmtRestored->fetch();

    // Status Level evaluator helper
    function evaluateStatus(int $count): array {
        if ($count <= 0) {
            return [
                'level' => 'normal',
                'label' => 'Normal Grid',
                'subtext' => 'Power Flowing Normally',
                'color' => '#10b981',
                'badge' => 'OPERATIONAL',
                'score' => 5
            ];
        } elseif ($count < 5) {
            return [
                'level' => 'partial_outage',
                'label' => 'Partial Outage',
                'subtext' => 'Isolated Reports Verified',
                'color' => '#f59e0b',
                'badge' => 'UNSTABLE',
                'score' => min(65, 25 + ($count * 10))
            ];
        } else {
            return [
                'level' => 'critical_blackout',
                'label' => 'Critical Blackout',
                'subtext' => 'Widespread Failure Detected',
                'color' => '#ff3b57',
                'badge' => 'CRITICAL',
                'score' => min(100, 70 + ($count * 3))
            ];
        }
    }

    $cityStatus = evaluateStatus((int)$overallStats['total_active_reports']);

    // 3. Neighborhood Grouped Breakdown (2-hour rolling window)
    $groupedSql = "
        SELECT 
            a.area_zipcode,
            a.area_name,
            COALESCE(r.active_count, 0) AS active_count,
            COALESCE(r.blackout_count, 0) AS blackout_count,
            COALESCE(r.voltagedrop_count, 0) AS voltagedrop_count,
            COALESCE(r.spark_count, 0) AS spark_count,
            r.latest_report
        FROM (
            SELECT area_zipcode, MAX(area_name) AS area_name
            FROM outage_reports
            GROUP BY area_zipcode
        ) a
        LEFT JOIN (
            SELECT 
                area_zipcode,
                COUNT(*) AS active_count,
                SUM(CASE WHEN issue_type = 'complete_blackout' THEN 1 ELSE 0 END) AS blackout_count,
                SUM(CASE WHEN issue_type = 'voltage_drop' THEN 1 ELSE 0 END) AS voltagedrop_count,
                SUM(CASE WHEN issue_type = 'transformer_spark' THEN 1 ELSE 0 END) AS spark_count,
                MAX(reported_at) AS latest_report
            FROM outage_reports
            WHERE status = 'power_out' 
              AND reported_at >= NOW() - INTERVAL 2 HOUR
            GROUP BY area_zipcode
        ) r ON a.area_zipcode = r.area_zipcode
    ";

    $params = [];
    if (!empty($filterZip)) {
        $groupedSql .= " WHERE a.area_zipcode = ? ";
        $params[] = $filterZip;
    }

    $groupedSql .= " ORDER BY active_count DESC, a.area_name ASC";

    $stmtGrouped = $pdo->prepare($groupedSql);
    $stmtGrouped->execute($params);
    $rawNeighborhoods = $stmtGrouped->fetchAll();

    $neighborhoods = [];
    foreach ($rawNeighborhoods as $row) {
        $activeCount = (int)$row['active_count'];
        $statusInfo = evaluateStatus($activeCount);

        $neighborhoods[] = [
            'area_zipcode'     => $row['area_zipcode'],
            'area_name'        => $row['area_name'],
            'active_count'     => $activeCount,
            'status_level'     => $statusInfo['level'],
            'status_label'     => $statusInfo['label'],
            'status_badge'     => $statusInfo['badge'],
            'status_color'     => $statusInfo['color'],
            'meter_score'      => $statusInfo['score'],
            'blackout_count'   => (int)$row['blackout_count'],
            'voltagedrop_count'=> (int)$row['voltagedrop_count'],
            'spark_count'      => (int)$row['spark_count'],
            'latest_report'    => $row['latest_report']
        ];
    }

    // 4. Live Activity Stream (Recent 15 reports across all areas)
    $feedSql = "
        SELECT 
            id,
            area_zipcode,
            area_name,
            status,
            issue_type,
            reported_at,
            resolved_at
        FROM outage_reports
        ORDER BY id DESC
        LIMIT 15
    ";
    $stmtFeed = $pdo->query($feedSql);
    $recentFeed = $stmtFeed->fetchAll();

    // 5. Selected Area Specific Status (if requested or default to citywide)
    $selectedAreaStatus = null;
    if (!empty($filterZip)) {
        foreach ($neighborhoods as $n) {
            if ($n['area_zipcode'] === $filterZip) {
                $selectedAreaStatus = $n;
                break;
            }
        }
        if (!$selectedAreaStatus) {
            // Area has no prior reports, default to clean normal status
            $selectedAreaStatus = [
                'area_zipcode'     => $filterZip,
                'area_name'        => 'Area ' . $filterZip,
                'active_count'     => 0,
                'status_level'     => 'normal',
                'status_label'     => 'Normal Grid',
                'status_badge'     => 'OPERATIONAL',
                'status_color'     => '#10b981',
                'meter_score'      => 5,
                'blackout_count'   => 0,
                'voltagedrop_count'=> 0,
                'spark_count'      => 0,
                'latest_report'    => null
            ];
        }
    }

    echo json_encode([
        'success'   => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'summary'   => [
            'total_active_reports' => (int)$overallStats['total_active_reports'],
            'impacted_areas_count' => (int)$overallStats['impacted_areas'],
            'total_restored_24h'   => (int)$restoredStats['total_restored_24h'],
            'breakdown' => [
                'blackouts'     => (int)$overallStats['total_blackouts'],
                'voltage_drops' => (int)$overallStats['total_voltage_drops'],
                'sparks'        => (int)$overallStats['total_sparks']
            ],
            'grid_status' => $cityStatus
        ],
        'neighborhoods'      => $neighborhoods,
        'recent_feed'        => $recentFeed,
        'selected_area'      => $selectedAreaStatus
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
