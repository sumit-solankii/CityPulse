<?php
/**
 * CityPulse - Main Dashboard (Step 7)
 *
 * Glanceable civic dashboard combining weather, traffic, incidents
 * and the analysis results via the existing PHP APIs.
 *
 * How to open:
 *   http://localhost/CityPulse/dashboard.php
 *
 * Data is loaded and refreshed from:
 *   - api/weather.php
 *   - api/traffic.php
 *   - api/incidents.php
 *   - api/air-quality.php
 *   - api/analyze.php
 *   - api/normalized-data.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CityPulse Dashboard | Real-Time Civic Pulse</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
</head>
<body class="dashboard-page">
    <header class="dash-header">
        <div class="brand-group">
            <span class="brand-dot" aria-hidden="true"></span>
            <div class="brand-text">
                <h1 class="brand">CITYPULSE</h1>
                <p class="subtitle">Real-Time Civic Pulse</p>
            </div>
        </div>
        <div class="header-info">
            <div class="clock-block">
                <span id="header-clock">--:--:-- --</span>
                <span id="header-date" class="muted">---</span>
            </div>
            <div class="chip area-chip">Monitored: <strong>Jaipur / Zone A</strong></div>
            <div class="chip live-chip" title="Dashboard refreshes every 30 seconds">
                <span class="live-dot" aria-hidden="true"></span> LIVE
            </div>
            <div id="demo-chip" class="chip demo-chip hidden" aria-live="polite">
                <span class="demo-dot" aria-hidden="true"></span> DEMO MODE
            </div>
            <div id="demo-controls" class="demo-controls hidden" aria-label="Demo mode scenarios">
                <button type="button" class="demo-scenario-btn active" data-demo-scenario="NORMAL">NORMAL</button>
                <button type="button" class="demo-scenario-btn" data-demo-scenario="MODERATE">MODERATE</button>
                <button type="button" class="demo-scenario-btn" data-demo-scenario="HIGH_ACTIVITY">HIGH ACTIVITY</button>
            </div>
            <button id="demo-mode-toggle" type="button" class="chip demo-toggle" aria-pressed="false">
                Demo Mode: OFF
            </button>
            <div id="demo-status" class="chip demo-status hidden" aria-live="polite">Selected: NORMAL · Simulated data for demonstration</div>
            <div class="chip updated-chip">Last updated: <strong id="last-updated">--:--:--</strong></div>
        </div>
    </header>

    <main class="dash-main">

        <!-- Area Pulse (loaded from api/analyze.php) -->
        <section class="card pulse-section" aria-label="Area Pulse">
            <p class="section-kicker">AREA PULSE</p>
            <div id="pulse-value" class="pulse-value">--</div>
            <p id="pulse-hint" class="muted">Loading&hellip;</p>
        </section>

        <section class="card summary-card" aria-live="polite">
            <div class="summary-header">
                <h2>City Pulse Summary</h2>
                <span id="summary-status" class="badge st-normal">NORMAL</span>
            </div>
            <p id="city-pulse-summary" class="summary-text">Loading summary&hellip;</p>
            <p class="summary-meta">Last updated: <span id="summary-updated">--</span></p>
        </section>

        <section class="card alerts-card" aria-live="polite">
            <div class="alerts-header">
                <h2>City Pulse Alerts</h2>
                <span id="alerts-demo-label" class="badge st-demo hidden">DEMO MODE — Simulated data</span>
            </div>
            <div id="city-pulse-alerts" class="alert-list">
                <p class="empty-note">Loading alerts&hellip;</p>
            </div>
        </section>

        <section class="card timeline-card" aria-live="polite">
            <div class="timeline-header">
                <div>
                    <h2>Historical Timeline</h2>
                    <p id="timeline-summary" class="timeline-summary">Loading activity&hellip;</p>
                </div>
                <span id="timeline-demo-label" class="badge st-demo hidden">DEMO MODE — Simulated events</span>
            </div>
            <div class="timeline-filters" role="group" aria-label="Historical timeline range">
                <button type="button" class="timeline-filter-btn" data-timeline-hours="1">Last 1 hour</button>
                <button type="button" class="timeline-filter-btn active" data-timeline-hours="6">Last 6 hours</button>
                <button type="button" class="timeline-filter-btn" data-timeline-hours="24">Last 24 hours</button>
            </div>
            <div class="timeline-table" role="table" aria-label="Recent civic activity">
                <div class="timeline-row timeline-heading" role="row">
                    <span role="columnheader">Time</span>
                    <span role="columnheader">Location</span>
                    <span role="columnheader">Source</span>
                    <span role="columnheader">Event type</span>
                    <span role="columnheader">Severity</span>
                    <span role="columnheader">Value / message</span>
                </div>
                <div id="historical-timeline" class="timeline-body">
                    <p class="empty-note">Loading activity&hellip;</p>
                </div>
            </div>
        </section>

        <!-- Four civic status cards -->
        <section class="cards-grid" aria-label="Civic status cards">
            <article class="card status-card" data-card="weather">
                <h2>WEATHER</h2>
                <div class="card-badge" id="weather-badge"></div>
                <div class="card-value" id="weather-value">--</div>
                <p class="card-desc" id="weather-desc">Loading&hellip;</p>
                <p class="card-updated">Updated: <span id="weather-updated">--</span></p>
            </article>

            <article class="card status-card" data-card="traffic">
                <h2>TRAFFIC</h2>
                <div class="card-badge" id="traffic-badge"></div>
                <div class="card-value" id="traffic-value">--</div>
                <p class="card-desc" id="traffic-desc">Loading&hellip;</p>
                <p class="card-updated">Updated: <span id="traffic-updated">--</span></p>
            </article>

            <article class="card status-card" data-card="incidents">
                <h2>INCIDENTS</h2>
                <div class="card-badge" id="incidents-badge"></div>
                <div class="card-value" id="incidents-value">--</div>
                <p class="card-desc" id="incidents-desc">Loading&hellip;</p>
                <p class="card-updated">Updated: <span id="incidents-updated">--</span></p>
            </article>

            <article class="card status-card" data-card="air_quality">
                <h2>AIR QUALITY</h2>
                <div class="card-badge" id="air-quality-badge"></div>
                <div class="card-value" id="air-quality-value">--</div>
                <p class="card-desc" id="air-quality-desc">Loading&hellip;</p>
                <p class="card-updated">Updated: <span id="air-quality-updated">--</span></p>
            </article>

            <article class="card status-card" data-card="pulse">
                <h2>OVERALL PULSE</h2>
                <div class="card-badge" id="pulse-badge"></div>
                <div class="card-value" id="pulse-card-value">--</div>
                <p class="card-desc" id="pulse-card-desc">Loading&hellip;</p>
                <p class="card-updated">Analysis: <span id="pulse-updated">--</span></p>
            </article>
        </section>

        <!-- Interactive civic map (Leaflet + OpenStreetMap) -->
        <section class="card map-card">
            <div class="map-head">
                <h2>LIVE CIVIC MAP</h2>
                <div class="map-filters" role="group" aria-label="Filter map markers">
                    <button type="button" class="map-filter-btn active" data-filter="all">All</button>
                    <button type="button" class="map-filter-btn" data-filter="weather">Weather</button>
                    <button type="button" class="map-filter-btn" data-filter="traffic">Traffic</button>
                    <button type="button" class="map-filter-btn" data-filter="incident">Incidents</button>
                    <button type="button" class="map-filter-btn" data-filter="air_quality">Air Quality</button>
                </div>
            </div>
            <div id="cityMap" class="city-map"></div>
            <p id="map-notice" class="map-notice"></p>
            <div class="map-legend">
                <span class="legend-item"><span class="legend-dot dot-weather"></span> Weather</span>
                <span class="legend-item"><span class="legend-dot dot-traffic"></span> Traffic</span>
                <span class="legend-item"><span class="legend-dot dot-incident"></span> Incident</span>
                <span class="legend-item"><span class="legend-dot dot-air_quality"></span> Air Quality</span>
                <span class="legend-note muted">Map tiles &copy; OpenStreetMap contributors &middot; civic data from CityPulse APIs</span>
            </div>
        </section>

        <!-- What's Happening? + Recent Activity -->
        <section class="grid-two">
            <article class="card">
                <h2>What's Happening?</h2>
                <div id="happening" class="happening"></div>
            </article>

            <article class="card">
                <h2>Recent Activity</h2>
                <div id="recent" class="recent"></div>
            </article>
        </section>

        <!-- Simple trend visualization (no chart library) -->
        <section class="card trend-card">
            <h2>Activity Trend <span id="trend-badge" class="trend-badge"></span></h2>
            <div id="trend-chart" class="trend-chart" aria-label="Activity trend chart"></div>
            <p class="muted">Combined event activity across all sources &mdash; last 8 hours (all real data).</p>
        </section>

        <!-- Data sources -->
        <section class="card sources-card">
            <h2>Data Sources</h2>
            <p class="sources-summary">Health updates with the dashboard refresh.</p>
            <div id="sources" class="sources-grid"></div>
        </section>

    </main>

    <footer class="footer">
        <p>CityPulse &copy; <span data-year></span>. Built for smarter neighborhoods.</p>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>