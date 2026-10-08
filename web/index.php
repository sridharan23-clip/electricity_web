<?php
/**
 * OutagePulse – Crowdsourced Neighborhood Power Cut Reporter
 * Master Front-End View (HTML5, CSS, PHP & JavaScript)
 */

require_once __DIR__ . '/database.php';

// Initial Server-Side Data Fetch (ensures instant render before JS hydration)
$initialData = [
    'total_active' => 0,
    'impacted_areas' => 0,
    'total_restored' => 0,
    'blackouts' => 0,
    'status_level' => 'normal',
    'status_label' => 'Normal Grid',
    'status_badge' => 'OPERATIONAL',
    'meter_score' => 5
];

try {
    $stmtOverall = $pdo->query("
        SELECT 
            COUNT(*) AS total_active_reports,
            COUNT(DISTINCT area_zipcode) AS impacted_areas,
            SUM(CASE WHEN issue_type = 'complete_blackout' THEN 1 ELSE 0 END) AS total_blackouts
        FROM outage_reports
        WHERE status = 'power_out' 
          AND reported_at >= NOW() - INTERVAL 2 HOUR
    ");
    $overall = $stmtOverall->fetch() ?: [];

    $stmtRestored = $pdo->query("
        SELECT COUNT(*) AS total_restored_24h 
        FROM outage_reports 
        WHERE status = 'power_restored' 
          AND (resolved_at >= NOW() - INTERVAL 24 HOUR OR reported_at >= NOW() - INTERVAL 24 HOUR)
    ");
    $restoredCount = (int)$stmtRestored->fetchColumn();

    $activeCount = (int)($overall['total_active_reports'] ?? 0);
    $initialData['total_active'] = $activeCount;
    $initialData['impacted_areas'] = (int)($overall['impacted_areas'] ?? 0);
    $initialData['total_restored'] = $restoredCount;
    $initialData['blackouts'] = (int)($overall['total_blackouts'] ?? 0);

    if ($activeCount <= 0) {
        $initialData['status_level'] = 'normal';
        $initialData['status_label'] = 'Normal Grid';
        $initialData['status_badge'] = 'OPERATIONAL';
        $initialData['meter_score'] = 5;
    } elseif ($activeCount < 5) {
        $initialData['status_level'] = 'partial_outage';
        $initialData['status_label'] = 'Partial Outage';
        $initialData['status_badge'] = 'UNSTABLE';
        $initialData['meter_score'] = min(65, 25 + ($activeCount * 10));
    } else {
        $initialData['status_level'] = 'critical_blackout';
        $initialData['status_label'] = 'Critical Blackout';
        $initialData['status_badge'] = 'CRITICAL';
        $initialData['meter_score'] = min(100, 70 + ($activeCount * 3));
    }
} catch (Exception $e) {
    // Graceful fallback to default values
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OutagePulse – Crowdsourced Neighborhood Power Cut Reporter</title>
    
    <!-- SEO Best Practices -->
    <meta name="description" content="OutagePulse is a real-time, crowd-verified power outage tracker. Instantly report power cuts, verify neighborhood electrical blackouts, and monitor grid status.">
    <meta name="keywords" content="power cut, power outage reporter, neighborhood blackout, electrical grid status, voltage drop, transformer spark">
    <meta name="author" content="OutagePulse Team">
    <meta property="og:title" content="OutagePulse – Real-Time Power Cut Reporter">
    <meta property="og:description" content="Instant crowd-verified power outage reports in your neighborhood. See active blackouts, report power loss, and confirm restoration.">
    <meta property="og:type" content="website">

    <!-- Google Fonts: Outfit & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="styles.css">
</head>
<body>

    <!-- Site Header -->
    <header class="site-header">
        <div class="container header-inner">
            <a href="index.php" class="brand-wrapper" id="brandLink">
                <div class="brand-icon-box">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/>
                    </svg>
                </div>
                <div class="brand-text">
                    <h1>Outage<span class="pulse-highlight">Pulse</span></h1>
                    <div class="brand-tagline">Neighborhood Power Monitor</div>
                </div>
            </a>

            <div class="header-status-badge">
                <div class="live-beacon"></div>
                <span>LIVE FEED • 2H WINDOW</span>
            </div>

            <div class="header-actions">
                <button type="button" class="btn-icon" id="soundToggleBtn" title="Toggle audio alerts">
                    🔔
                </button>
                <button type="button" class="btn-icon" id="manualRefreshBtn" title="Refresh grid status">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <polyline points="1 20 1 14 7 14"></polyline>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                </button>
                <div style="font-family: var(--font-mono); font-size: 0.8rem; color: var(--text-muted); display: none;" id="lastUpdatedWrap">
                    <span id="lastUpdatedTime">--:--:--</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="container">

        <!-- Hero Section: Emergency Trigger & Live Status Meter -->
        <section class="hero-section">
            <div class="action-bar-grid">
                
                <!-- 1. Major Emergency Action Card -->
                <div class="emergency-trigger-card">
                    <div>
                        <div class="emergency-card-header">
                            <span class="alert-pill">
                                <span class="alert-pill-pulse"></span>
                                Urgent Response Active
                            </span>
                            <span class="time-window-note">Rolling 2-Hour Window</span>
                        </div>
                        <h2 class="emergency-card-title">Power Out in Your Area?</h2>
                        <p class="emergency-card-desc">
                            Don't wait in the dark wondering if it's your home circuit or a broader grid failure. 
                            Submit a one-tap report to notify neighbors and alert repair crews.
                        </p>
                    </div>

                    <div>
                        <div class="hero-btn-group">
                            <button type="button" class="btn-emergency" id="openOutageModalBtn">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/>
                                </svg>
                                <span>I Have No Power</span>
                            </button>
                            <button type="button" class="btn-restored" id="openRestoreModalBtn">
                                <span>✓ Power Restored?</span>
                            </button>
                        </div>

                        <!-- Quick Neighborhood Pills -->
                        <div class="quick-area-selector">
                            <span class="quick-area-label">Quick Select ZIP:</span>
                            <div class="quick-zip-pills">
                                <button type="button" class="zip-badge-btn" data-zip="94102" data-name="Downtown Metro">94102 Downtown</button>
                                <button type="button" class="zip-badge-btn" data-zip="94122" data-name="Sunset District">94122 Sunset</button>
                                <button type="button" class="zip-badge-btn" data-zip="94107" data-name="Industrial East">94107 Industrial</button>
                                <button type="button" class="zip-badge-btn" data-zip="94110" data-name="Highland Park">94110 Highland</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Dynamic Status Meter Card -->
                <div class="grid-status-meter-card" id="statusMeterCard">
                    <div class="meter-card-header">
                        <div class="meter-title-group">
                            <h3>Area Grid Status Meter</h3>
                            <p>Dynamic assessment based on report volume</p>
                        </div>
                        <div class="meter-badge status-<?= $initialData['status_level'] ?>" id="meterBadge">
                            <?= $initialData['status_badge'] ?>
                        </div>
                    </div>

                    <!-- Visual Gauge Bar -->
                    <div class="meter-visual-container">
                        <div class="meter-gauge-track">
                            <div class="meter-gauge-fill" id="statusGaugeFill" style="width: <?= $initialData['meter_score'] ?>%;"></div>
                        </div>
                        <div class="meter-threshold-markers">
                            <span class="meter-mark-normal">Normal (0)</span>
                            <span class="meter-mark-partial">Partial (1-4)</span>
                            <span class="meter-mark-critical">Critical (5+)</span>
                        </div>
                    </div>

                    <!-- Status Readout Info -->
                    <div class="meter-readout">
                        <div class="readout-main">
                            <span class="readout-level-label" id="meterLevelLabel">
                                <?= $initialData['status_label'] ?>
                            </span>
                            <span class="readout-level-subtext" id="meterSubtext">
                                <?= $initialData['status_level'] === 'normal' ? 'Power Flowing Normally' : 'Active disruptions reported' ?>
                            </span>
                        </div>
                        <div class="readout-stat-box">
                            <div class="readout-stat-number" id="activeCountDisplay">
                                <?= $initialData['total_active'] ?>
                            </div>
                            <div class="readout-stat-desc">Active Reports</div>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <!-- KPI Metrics Banner -->
        <section class="stats-banner">
            <div class="stat-card">
                <div class="stat-icon red">⚡</div>
                <div class="stat-content">
                    <div class="stat-value" id="totalOutagesKpi"><?= $initialData['total_active'] ?></div>
                    <div class="stat-label">Active Power Cuts (2h)</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon amber">📍</div>
                <div class="stat-content">
                    <div class="stat-value" id="impactedAreasKpi"><?= $initialData['impacted_areas'] ?></div>
                    <div class="stat-label">Impacted Areas</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">✓</div>
                <div class="stat-content">
                    <div class="stat-value" id="restoredCountKpi"><?= $initialData['total_restored'] ?></div>
                    <div class="stat-label">Restored (24h)</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon cyan">💥</div>
                <div class="stat-content">
                    <div class="stat-value" id="blackoutCountKpi"><?= $initialData['blackouts'] ?></div>
                    <div class="stat-label">Total Blackouts</div>
                </div>
            </div>
        </section>

        <!-- Main Grid Layout: Neighborhood Feed + Sidebar Activity Stream -->
        <div class="main-content-layout">

            <!-- Left: Neighborhood Outage Status Feed -->
            <section class="section-card">
                <div class="section-card-header">
                    <div class="section-title-wrap">
                        <h2>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon>
                                <line x1="8" y1="2" x2="8" y2="18"></line>
                                <line x1="16" y1="6" x2="16" y2="22"></line>
                            </svg>
                            Neighborhood Status Feed
                        </h2>
                        <p>Crowd-verified report counts in the last 2 hours</p>
                    </div>

                    <div class="feed-filter-tools">
                        <div class="search-input-wrap">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" id="neighborhoodSearch" placeholder="Filter by ZIP or area...">
                        </div>
                    </div>
                </div>

                <!-- Live Neighborhood Rows -->
                <div class="neighborhood-list" id="neighborhoodList">
                    <!-- Populated dynamically by dynamic.js -->
                    <div style="padding: 2rem; text-align: center; color: var(--text-muted);">
                        Connecting to neighborhood grid feed...
                    </div>
                </div>
            </section>

            <!-- Right: Activity Stream, Alert Subscription & Safety Card -->
            <aside class="sidebar-layout">

                <!-- Alert Subscription Box -->
                <div class="subscribe-promo-card">
                    <h3>🔔 Instant Outage Alerts</h3>
                    <p>Receive SMS or email notifications when power cuts are detected or restored in your ZIP.</p>
                    
                    <form id="subscribeAlertForm">
                        <div class="form-group">
                            <input type="text" id="subContact" class="form-input" placeholder="Email or Phone Number" required>
                        </div>
                        <div class="form-group">
                            <input type="text" id="subZipcode" class="form-input" placeholder="Neighborhood ZIP (e.g. 94102)" maxlength="10" required>
                        </div>
                        <button type="submit" class="btn-subscribe">
                            Get Power Alerts
                        </button>
                    </form>
                </div>

                <!-- Real-time Activity Stream -->
                <div class="section-card">
                    <div class="section-card-header">
                        <div class="section-title-wrap">
                            <h3 style="font-size: 1.05rem;">Live Activity Stream</h3>
                            <p>Real-time citizen updates</p>
                        </div>
                    </div>
                    <div class="activity-feed-list" id="activityFeedList">
                        <!-- Populated dynamically -->
                    </div>
                </div>

                <!-- Emergency Tips -->
                <div class="section-card" style="padding: 1.25rem 1.5rem;">
                    <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.5rem; color: #ffffff;">⚡ Electrical Safety Notice</h4>
                    <ul style="font-size: 0.8rem; color: var(--text-secondary); padding-left: 1.2rem; line-height: 1.6;">
                        <li>If you hear transformer explosions or see downed lines, stay <strong>at least 30 feet away</strong>.</li>
                        <li>Unplug sensitive electronics to avoid surges when electricity returns.</li>
                        <li>Check your breaker panel first to confirm if power loss is isolated to your home.</li>
                    </ul>
                </div>

            </aside>
        </div>

    </main>

    <!-- =========================================================================
         Modal 1: One-Tap Outage Report
         ========================================================================= -->
    <div class="modal-overlay" id="outageModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Report Power Outage</h3>
                <button type="button" class="modal-close-btn" aria-label="Close modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="outageReportForm">
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                            <label for="reportZipcode" class="field-label" style="margin-bottom: 0;">Area ZIP Code *</label>
                            <button type="button" id="geoLocateBtn" style="background: none; border: none; color: var(--color-cyan); font-size: 0.78rem; cursor: pointer; font-weight: 600;">
                                📍 Use My Location
                            </button>
                        </div>
                        <input type="text" id="reportZipcode" class="form-input" placeholder="e.g. 94102" maxlength="10" required>
                    </div>

                    <div class="form-group">
                        <label for="reportAreaName" class="field-label">Neighborhood / Area Name (Optional)</label>
                        <input type="text" id="reportAreaName" class="form-input" placeholder="e.g. Downtown Metro">
                    </div>

                    <div class="form-group">
                        <label class="field-label">Issue Type *</label>
                        <input type="hidden" id="selectedIssueType" value="complete_blackout">
                        
                        <div class="issue-type-grid">
                            <div class="issue-type-option selected" data-value="complete_blackout">
                                <span class="issue-icon-lg">⚡</span>
                                <span class="issue-title">Blackout</span>
                                <span class="issue-subtitle">Total loss</span>
                            </div>
                            <div class="issue-type-option" data-value="voltage_drop">
                                <span class="issue-icon-lg">📉</span>
                                <span class="issue-title">Brownout</span>
                                <span class="issue-subtitle">Voltage drop</span>
                            </div>
                            <div class="issue-type-option" data-value="transformer_spark">
                                <span class="issue-icon-lg">💥</span>
                                <span class="issue-title">Spark Hazard</span>
                                <span class="issue-subtitle">Sparks heard</span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-modal-submit">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M13 2L3 14H12L11 22L21 10H12L13 2Z"/>
                        </svg>
                        Broadcast Outage Report
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         Modal 2: Power Restored Confirmation
         ========================================================================= -->
    <div class="modal-overlay" id="restoreModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Confirm Power Restored</h3>
                <button type="button" class="modal-close-btn" aria-label="Close modal">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.25rem;">
                    Confirming that electricity has returned will <strong>auto-resolve active outage reports</strong> for this neighborhood and reset the meter to normal.
                </p>
                <form id="restoreReportForm">
                    <div class="form-group">
                        <label for="restoreZipcode" class="field-label">Area ZIP Code *</label>
                        <input type="text" id="restoreZipcode" class="form-input" placeholder="e.g. 94102" maxlength="10" required>
                    </div>

                    <div class="form-group">
                        <label for="restoreAreaName" class="field-label">Neighborhood Name (Optional)</label>
                        <input type="text" id="restoreAreaName" class="form-input" placeholder="e.g. Downtown Metro">
                    </div>

                    <button type="submit" class="btn-modal-submit btn-modal-restore">
                        ✓ Confirm Electricity Restored
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Site Footer -->
    <footer class="site-footer">
        <div class="container footer-content">
            <div>
                <strong>OutagePulse</strong> &copy; <?= date('Y') ?> – Crowdsourced Neighborhood Power Cut Reporter.
            </div>
            <div style="font-size: 0.8rem; font-family: var(--font-mono);">
                Powered by PHP &bull; MySQL &bull; Vanilla JS &bull; HTML5
            </div>
        </div>
    </footer>

    <!-- Dynamic Controller Script -->
    <script src="dynamic.js"></script>
</body>
</html>
