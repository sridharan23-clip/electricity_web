/**
 * OutagePulse – Dynamic Real-Time Client Controller
 * Powers live data polling, status meter animation, audio cues, and AJAX reporting.
 */

document.addEventListener('DOMContentLoaded', () => {
    // Application State
    const state = {
        activeFilterZip: '',
        searchQuery: '',
        isPolling: true,
        pollInterval: 15000, // 15 seconds
        lastData: null,
        soundEnabled: true
    };

    // DOM Elements
    const elements = {
        // Meter Elements
        gaugeFill: document.getElementById('statusGaugeFill'),
        meterLevelLabel: document.getElementById('meterLevelLabel'),
        meterSubtext: document.getElementById('meterSubtext'),
        meterBadge: document.getElementById('meterBadge'),
        activeCountDisplay: document.getElementById('activeCountDisplay'),
        totalOutagesKpi: document.getElementById('totalOutagesKpi'),
        impactedAreasKpi: document.getElementById('impactedAreasKpi'),
        restoredCountKpi: document.getElementById('restoredCountKpi'),
        blackoutCountKpi: document.getElementById('blackoutCountKpi'),
        
        // Feed Elements
        neighborhoodList: document.getElementById('neighborhoodList'),
        activityFeedList: document.getElementById('activityFeedList'),
        searchInput: document.getElementById('neighborhoodSearch'),
        lastUpdatedTime: document.getElementById('lastUpdatedTime'),
        refreshBtn: document.getElementById('manualRefreshBtn'),
        soundToggleBtn: document.getElementById('soundToggleBtn'),

        // Modals
        outageModal: document.getElementById('outageModal'),
        restoreModal: document.getElementById('restoreModal'),
        subscribeModal: document.getElementById('subscribeModal'),

        // Forms
        outageForm: document.getElementById('outageReportForm'),
        restoreForm: document.getElementById('restoreReportForm'),
        subscribeForm: document.getElementById('subscribeAlertForm'),

        // Triggers
        openOutageModalBtn: document.getElementById('openOutageModalBtn'),
        openRestoreModalBtn: document.getElementById('openRestoreModalBtn'),
        geoLocateBtn: document.getElementById('geoLocateBtn'),
        toastContainer: document.getElementById('toastContainer')
    };

    // =========================================================================
    // Web Audio Synthesizer (Zero-dependency audio cues)
    // =========================================================================
    function playAudioTone(type = 'alert') {
        if (!state.soundEnabled) return;
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            const ctx = new AudioContext();

            if (type === 'alert') {
                // Urgent alert tone
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(320, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(180, ctx.currentTime + 0.25);
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.linearRampToValueAtTime(0.01, ctx.currentTime + 0.25);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.25);
            } else if (type === 'restore') {
                // Uplifting power restored arpeggio
                const notes = [440, 554.37, 659.25]; // A Major triad
                notes.forEach((freq, idx) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    const startTime = ctx.currentTime + (idx * 0.08);
                    gain.gain.setValueAtTime(0.15, startTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.4);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(startTime);
                    osc.stop(startTime + 0.4);
                });
            }
        } catch (e) {
            console.debug('Audio play not allowed or supported:', e);
        }
    }

    // Trigger haptic vibration if supported
    function triggerHaptic(pattern = [80, 40, 80]) {
        if (navigator.vibrate) {
            navigator.vibrate(pattern);
        }
    }

    // =========================================================================
    // Toast Notifications
    // =========================================================================
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        let icon = 'ℹ️';
        if (type === 'success') icon = '⚡';
        if (type === 'error') icon = '⚠️';

        toast.innerHTML = `<span>${icon}</span><span>${escapeHtml(message)}</span>`;
        elements.toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // =========================================================================
    // API Fetcher & Status Poller
    // =========================================================================
    async function fetchStatus(zipcode = '') {
        try {
            const url = `get_status.php${zipcode ? `?zipcode=${encodeURIComponent(zipcode)}` : ''}`;
            const res = await fetch(url, { cache: 'no-cache' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            
            if (data.success) {
                state.lastData = data;
                renderDashboard(data);
            } else {
                console.error('API Error:', data.error);
            }
        } catch (err) {
            console.warn('Failed to fetch grid status:', err);
        }
    }

    // =========================================================================
    // UI Renderers: Status Meter & Metrics
    // =========================================================================
    function renderDashboard(data) {
        const summary = data.summary;
        const gridStatus = summary.grid_status;

        // 1. Update KPI counters
        animateNumber(elements.totalOutagesKpi, summary.total_active_reports);
        animateNumber(elements.impactedAreasKpi, summary.impacted_areas_count);
        animateNumber(elements.restoredCountKpi, summary.total_restored_24h);
        animateNumber(elements.blackoutCountKpi, summary.breakdown.blackouts);
        animateNumber(elements.activeCountDisplay, summary.total_active_reports);

        // 2. Dynamic Status Meter
        // Update Meter Fill Percentage & Color
        const meterScore = Math.min(100, Math.max(5, gridStatus.score));
        elements.gaugeFill.style.width = `${meterScore}%`;

        // Update Level Text and Badges
        elements.meterLevelLabel.textContent = gridStatus.label;
        elements.meterLevelLabel.style.color = gridStatus.color;
        elements.meterSubtext.textContent = gridStatus.subtext;

        elements.meterBadge.textContent = gridStatus.badge;
        elements.meterBadge.className = `meter-badge status-${gridStatus.level}`;

        // 3. Render Neighborhoods List
        renderNeighborhoods(data.neighborhoods);

        // 4. Render Activity Stream
        renderRecentFeed(data.recent_feed);

        // 5. Update timestamp
        if (elements.lastUpdatedTime) {
            const now = new Date();
            elements.lastUpdatedTime.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
    }

    // Smooth count-up animation for metrics
    function animateNumber(element, targetNum) {
        if (!element) return;
        const current = parseInt(element.textContent, 10) || 0;
        if (current === targetNum) return;

        const duration = 400;
        const startTime = performance.now();

        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const val = Math.floor(current + (targetNum - current) * progress);
            element.textContent = val;

            if (progress < 1) {
                requestAnimationFrame(update);
            } else {
                element.textContent = targetNum;
            }
        }
        requestAnimationFrame(update);
    }

    // =========================================================================
    // Render Neighborhood Rows
    // =========================================================================
    function renderNeighborhoods(neighborhoods) {
        if (!elements.neighborhoodList) return;
        elements.neighborhoodList.innerHTML = '';

        const query = state.searchQuery.toLowerCase().trim();
        const filtered = neighborhoods.filter(n => {
            if (!query) return true;
            return n.area_name.toLowerCase().includes(query) || n.area_zipcode.includes(query);
        });

        if (filtered.length === 0) {
            elements.neighborhoodList.innerHTML = `
                <div style="padding: 2.5rem; text-align: center; color: var(--text-muted);">
                    <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No neighborhood found matching "${escapeHtml(state.searchQuery)}"</p>
                    <p style="font-size: 0.85rem;">You can still click <strong>"I Have No Power"</strong> to report for any ZIP.</p>
                </div>
            `;
            return;
        }

        filtered.forEach(item => {
            const row = document.createElement('div');
            row.className = 'neighborhood-row';
            row.setAttribute('data-zip', item.area_zipcode);

            const reportCount = item.active_count;
            const statusLevel = item.status_level;

            // Generate breakdown badges
            let breakdownHtml = '';
            if (item.blackout_count > 0) {
                breakdownHtml += `<span class="tag-issue blackout">⚡ ${item.blackout_count} Blackout</span>`;
            }
            if (item.voltagedrop_count > 0) {
                breakdownHtml += `<span class="tag-issue voltage">📉 ${item.voltagedrop_count} Brownout</span>`;
            }
            if (item.spark_count > 0) {
                breakdownHtml += `<span class="tag-issue spark">💥 ${item.spark_count} Sparks</span>`;
            }
            if (!breakdownHtml) {
                breakdownHtml = `<span style="font-size: 0.75rem; color: var(--color-power-green);">✓ Grid Normal</span>`;
            }

            row.innerHTML = `
                <div class="n-info">
                    <div class="n-status-dot ${statusLevel}"></div>
                    <div class="n-meta">
                        <h4>
                            ${escapeHtml(item.area_name)} 
                            <span class="n-zip-tag">${escapeHtml(item.area_zipcode)}</span>
                        </h4>
                        <div class="n-breakdown-tags">
                            ${breakdownHtml}
                        </div>
                    </div>
                </div>
                <div class="n-actions-box">
                    <div class="n-report-counter">
                        <div class="counter-num status-${statusLevel}">${reportCount}</div>
                        <div class="counter-sub">${reportCount === 1 ? 'Report' : 'Reports'}</div>
                    </div>
                    ${reportCount > 0 ? `
                        <button type="button" class="btn-quick-restore" data-zip="${escapeHtml(item.area_zipcode)}" data-name="${escapeHtml(item.area_name)}" title="Confirm power returned to this area">
                            ✓ Restored
                        </button>
                    ` : `
                        <button type="button" class="btn-quick-report" data-zip="${escapeHtml(item.area_zipcode)}" data-name="${escapeHtml(item.area_name)}" title="Report power outage in this area">
                            + Report
                        </button>
                    `}
                </div>
            `;

            elements.neighborhoodList.appendChild(row);
        });

        // Attach dynamic quick actions inside row
        elements.neighborhoodList.querySelectorAll('.btn-quick-report').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const zip = btn.getAttribute('data-zip');
                const name = btn.getAttribute('data-name');
                openOutageModalWithData(zip, name);
            });
        });

        elements.neighborhoodList.querySelectorAll('.btn-quick-restore').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const zip = btn.getAttribute('data-zip');
                const name = btn.getAttribute('data-name');
                openRestoreModalWithData(zip, name);
            });
        });
    }

    // =========================================================================
    // Render Activity Stream (Live Feed)
    // =========================================================================
    function renderRecentFeed(feed) {
        if (!elements.activityFeedList) return;
        elements.activityFeedList.innerHTML = '';

        if (!feed || feed.length === 0) {
            elements.activityFeedList.innerHTML = `
                <div style="padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                    No recent reports recorded yet.
                </div>
            `;
            return;
        }

        feed.forEach(item => {
            const div = document.createElement('div');
            div.className = 'feed-item';

            const isRestored = item.status === 'power_restored';
            const icon = isRestored ? '✓' : (item.issue_type === 'transformer_spark' ? '💥' : (item.issue_type === 'voltage_drop' ? '📉' : '⚡'));
            const statusClass = isRestored ? 'power_restored' : 'power_out';
            
            const title = isRestored ? `Power Restored in ${item.area_name}` : `Power Outage in ${item.area_name}`;
            const sub = isRestored 
                ? `Confirmed restored for ZIP ${item.area_zipcode}`
                : `Reported issue: ${formatIssueName(item.issue_type)} (${item.area_zipcode})`;

            const timeStr = formatRelativeTime(item.reported_at);

            div.innerHTML = `
                <div class="feed-icon ${statusClass}">${icon}</div>
                <div class="feed-body">
                    <div class="feed-title">${escapeHtml(title)}</div>
                    <div class="feed-desc">${escapeHtml(sub)}</div>
                    <div class="feed-time">${escapeHtml(timeStr)}</div>
                </div>
            `;
            elements.activityFeedList.appendChild(div);
        });
    }

    function formatIssueName(type) {
        if (type === 'voltage_drop') return 'Voltage Drop / Brownout';
        if (type === 'transformer_spark') return 'Transformer Spark Hazard';
        return 'Complete Blackout';
    }

    function formatRelativeTime(dateStr) {
        if (!dateStr) return 'Just now';
        const d = new Date(dateStr.replace(' ', 'T'));
        const now = new Date();
        const diffSeconds = Math.max(0, Math.floor((now - d) / 1000));

        if (diffSeconds < 60) return 'Just now';
        const diffMinutes = Math.floor(diffSeconds / 60);
        if (diffMinutes < 60) return `${diffMinutes}m ago`;
        const diffHours = Math.floor(diffMinutes / 60);
        if (diffHours < 24) return `${diffHours}h ago`;
        return `${Math.floor(diffHours / 24)}d ago`;
    }

    // =========================================================================
    // Form Handlers & Modal Interactions
    // =========================================================================

    // 1. One-Tap Outage Report Modal
    function openOutageModalWithData(zip = '', name = '') {
        const zipField = document.getElementById('reportZipcode');
        const nameField = document.getElementById('reportAreaName');
        if (zipField && zip) zipField.value = zip;
        if (nameField && name) nameField.value = name;
        openModal(elements.outageModal);
    }

    function openRestoreModalWithData(zip = '', name = '') {
        const zipField = document.getElementById('restoreZipcode');
        const nameField = document.getElementById('restoreAreaName');
        if (zipField && zip) zipField.value = zip;
        if (nameField && name) nameField.value = name;
        openModal(elements.restoreModal);
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
        const firstInput = modal.querySelector('input');
        if (firstInput) setTimeout(() => firstInput.focus(), 100);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
    }

    // Close buttons & backdrop clicks
    document.querySelectorAll('.modal-close-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modal = e.target.closest('.modal-overlay');
            closeModal(modal);
        });
    });

    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal(modal);
        });
    });

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(closeModal);
        }
    });

    if (elements.openOutageModalBtn) {
        elements.openOutageModalBtn.addEventListener('click', () => {
            openOutageModalWithData();
        });
    }

    if (elements.openRestoreModalBtn) {
        elements.openRestoreModalBtn.addEventListener('click', () => {
            openRestoreModalWithData();
        });
    }

    // Issue Type Option Selection in Modal
    const issueCards = document.querySelectorAll('.issue-type-option');
    issueCards.forEach(card => {
        card.addEventListener('click', () => {
            issueCards.forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            const hiddenInput = document.getElementById('selectedIssueType');
            if (hiddenInput) {
                hiddenInput.value = card.getAttribute('data-value');
            }
        });
    });

    // Quick ZIP pills in Hero
    document.querySelectorAll('.zip-badge-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const zip = btn.getAttribute('data-zip');
            const name = btn.getAttribute('data-name') || '';
            openOutageModalWithData(zip, name);
        });
    });

    // Geolocation Support ("Use My Location")
    if (elements.geoLocateBtn) {
        elements.geoLocateBtn.addEventListener('click', () => {
            if (!navigator.geolocation) {
                showToast('Geolocation is not supported by your browser.', 'error');
                return;
            }
            elements.geoLocateBtn.textContent = '📍 Locating...';
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    elements.geoLocateBtn.textContent = '📍 Location Found';
                    // In a production app, a reverse-geocoder would convert lat/lng to ZIP.
                    // For demo reliability, pick the primary downtown ZIP or detected area.
                    const zipInput = document.getElementById('reportZipcode');
                    const areaInput = document.getElementById('reportAreaName');
                    if (zipInput && !zipInput.value) zipInput.value = '94102';
                    if (areaInput && !areaInput.value) areaInput.value = 'Current Location';
                    showToast('Location coordinates detected. Zipcode prefilled!', 'success');
                    setTimeout(() => { elements.geoLocateBtn.textContent = '📍 Use My Location'; }, 2000);
                },
                (err) => {
                    elements.geoLocateBtn.textContent = '📍 Use My Location';
                    showToast('Unable to retrieve location. Please type your ZIP.', 'error');
                },
                { timeout: 7000 }
            );
        });
    }

    // =========================================================================
    // Form Submission: Submit Outage Report (AJAX)
    // =========================================================================
    if (elements.outageForm) {
        elements.outageForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = elements.outageForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            const zip = document.getElementById('reportZipcode').value.trim();
            const areaName = document.getElementById('reportAreaName').value.trim();
            const issueType = document.getElementById('selectedIssueType').value || 'complete_blackout';

            if (!zip) {
                showToast('Please enter your Area ZIP code.', 'error');
                return;
            }

            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Broadcasting Alert...';

                const payload = {
                    action: 'report_outage',
                    area_zipcode: zip,
                    area_name: areaName,
                    issue_type: issueType
                };

                const res = await fetch('submit_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();

                if (data.success) {
                    playAudioTone('alert');
                    triggerHaptic([120, 80, 120]);
                    showToast(data.message, 'success');
                    closeModal(elements.outageModal);
                    elements.outageForm.reset();
                    // Refetch status immediately
                    await fetchStatus();
                } else {
                    showToast(data.error || 'Failed to submit report.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Network error while reporting outage.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    }

    // =========================================================================
    // Form Submission: Power Restored Confirmation (AJAX)
    // =========================================================================
    if (elements.restoreForm) {
        elements.restoreForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = elements.restoreForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            const zip = document.getElementById('restoreZipcode').value.trim();
            const areaName = document.getElementById('restoreAreaName').value.trim();

            if (!zip) {
                showToast('Please enter your Area ZIP code.', 'error');
                return;
            }

            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Resolving Reports...';

                const payload = {
                    action: 'report_restored',
                    area_zipcode: zip,
                    area_name: areaName
                };

                const res = await fetch('submit_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();

                if (data.success) {
                    playAudioTone('restore');
                    triggerHaptic([60, 60, 200]);
                    showToast(data.message, 'success');
                    closeModal(elements.restoreModal);
                    elements.restoreForm.reset();
                    await fetchStatus();
                } else {
                    showToast(data.error || 'Failed to confirm power restoration.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Network error while reporting restoration.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    }

    // =========================================================================
    // Form Submission: Alert Subscription (AJAX)
    // =========================================================================
    if (elements.subscribeForm) {
        elements.subscribeForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = elements.subscribeForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            const contact = document.getElementById('subContact').value.trim();
            const zip = document.getElementById('subZipcode').value.trim();

            if (!contact || !zip) {
                showToast('Please provide both contact and ZIP code.', 'error');
                return;
            }

            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Subscribing...';

                const payload = {
                    action: 'subscribe_alert',
                    phone_or_email: contact,
                    area_zipcode: zip,
                    alert_enabled: 1
                };

                const res = await fetch('submit_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    elements.subscribeForm.reset();
                } else {
                    showToast(data.error || 'Failed to subscribe.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Network error while subscribing.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    }

    // =========================================================================
    // Real-Time Search Filter
    // =========================================================================
    if (elements.searchInput) {
        elements.searchInput.addEventListener('input', (e) => {
            state.searchQuery = e.target.value;
            if (state.lastData && state.lastData.neighborhoods) {
                renderNeighborhoods(state.lastData.neighborhoods);
            }
        });
    }

    // Manual Refresh
    if (elements.refreshBtn) {
        elements.refreshBtn.addEventListener('click', () => {
            elements.refreshBtn.style.transform = 'rotate(180deg)';
            fetchStatus().then(() => {
                setTimeout(() => elements.refreshBtn.style.transform = '', 300);
            });
        });
    }

    // Audio Toggle
    if (elements.soundToggleBtn) {
        elements.soundToggleBtn.addEventListener('click', () => {
            state.soundEnabled = !state.soundEnabled;
            elements.soundToggleBtn.innerHTML = state.soundEnabled ? '🔔' : '🔕';
            elements.soundToggleBtn.title = state.soundEnabled ? 'Audio alerts enabled' : 'Audio alerts muted';
            showToast(state.soundEnabled ? 'Audio alerts turned ON' : 'Audio alerts muted');
        });
    }

    // =========================================================================
    // Background Polling Loop
    // =========================================================================
    let pollTimer = setInterval(() => {
        if (state.isPolling) {
            fetchStatus();
        }
    }, state.pollInterval);

    // Pause polling when tab is hidden to save power & network
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            state.isPolling = false;
        } else {
            state.isPolling = true;
            fetchStatus(); // Immediate refresh on refocus
        }
    });

    // Helper: Escape HTML string to prevent XSS
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Initial load
    fetchStatus();
});
