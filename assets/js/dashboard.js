/* ============================================================
   CityPulse - Dashboard Script (Step 7)
   Plain JavaScript (no frameworks). Fetches data from the
   existing PHP APIs and updates the dashboard sections.

   Endpoints used:
     - api/weather.php         (weather card)
     - api/traffic.php         (traffic card)
     - api/incidents.php       (incidents card)
     - api/air-quality.php     (air quality card)
     - api/analyze.php         (area pulse, what's happening)
     - api/normalized-data.php (recent activity, trend, sources, map marker data)

   Refresh: every 30 seconds, only the data sections update
   (no full page reload). Each section fails gracefully.
   ============================================================ */

(function () {
    "use strict";

    // Thresholds mirroring the Step 6 analysis engine.
    var INCIDENTS_MODERATE_AT = 10;
    var INCIDENTS_HIGH_AT = 20;
    var MAX_SOURCE_AGE_LIVE_MIN = 60;      // sources with data newer than this = LIVE
    var MAX_WEATHER_AGE_LIVE_MIN = 30;     // weather updates every ~10 minutes
    var MAX_AIR_QUALITY_AGE_LIVE_MIN = 20; // air quality stores a reading every ~15 minutes
    var REFRESH_INTERVAL_MS = 30000;       // 30 seconds
    var RECENT_ACTIVITY_LIMIT = 10;
    var TREND_HOURS = 8;
    var timelineHours = 6;

    // Leaflet map (Step 8): centered on Jaipur. A tiny visual offset
    // keeps overlapping markers in the same zone clickable.
    var MAP_CENTER = [26.9124, 75.7873];
    var MAP_ZOOM = 12;
    var MARKER_OFFSET = {
        weather:  { lat: 0,      lng: 0 },
        traffic:  { lat: 0,      lng: 0.0008 },
        incident: { lat: 0,      lng: -0.0008 },
        air_quality: { lat: 0.0016, lng: 0.0016 }
    };
    var map = null;
    var mapGroups = null;
    var mapFilter = "all";
    var DEMO_SCENARIOS = {
        NORMAL: "NORMAL",
        MODERATE: "MODERATE",
        HIGH_ACTIVITY: "HIGH_ACTIVITY"
    };

    var state = {
        demoMode: false,
        demoScenario: DEMO_SCENARIOS.NORMAL,
        weather: null,
        traffic: null,
        incidents: null,
        airQuality: null,
        analysis: null,
        normalized: null
    };

    // ---------- helpers ----------

    function el(id) {
        return document.getElementById(id);
    }

    function fmtTime(d) {
        return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    }

    function fmtClock(d) {
        return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    }

    function fmtDate(d) {
        return d.toLocaleDateString([], { weekday: "short", year: "numeric", month: "short", day: "numeric" });
    }

    // "2026-09-24 09:34:38" -> Date (local)
    function parseTs(ts) {
        return new Date(ts.replace(" ", "T"));
    }

    // "2026-09-24 09:34:38" -> "09:34 AM"
    function fmtShortTime(ts) {
        if (!ts) return "--";
        var d = parseTs(ts);
        if (isNaN(d.getTime())) return ts;
        return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    }

    function normalizeSeverity(s) {
        var k = String(s || "").toLowerCase();
        if (k === "high" || k === "severe" || k === "critical") return "HIGH";
        if (k === "moderate") return "MODERATE";
        return "NORMAL";
    }

    function badge(label, statusClass) {
        var span = document.createElement("span");
        span.className = "badge " + statusClass;
        span.textContent = label;
        return span;
    }

    function severityBadge(s) {
        var sev = normalizeSeverity(s);
        return badge(sev, "st-" + sev.toLowerCase());
    }

    function fetchJson(url) {
        return fetch(url).then(function (r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
        });
    }

    function setText(id, text) {
        var node = el(id);
        if (node) node.textContent = text;
    }

    function demoLabel(scenario) {
        if (scenario === DEMO_SCENARIOS.MODERATE) return "MODERATE";
        if (scenario === DEMO_SCENARIOS.HIGH_ACTIVITY) return "HIGH ACTIVITY";
        return "NORMAL";
    }

    function renderDemoControls() {
        var toggle = el("demo-mode-toggle");
        var chip = el("demo-chip");
        var controls = el("demo-controls");
        var status = el("demo-status");
        var scenarioButtons = document.querySelectorAll(".demo-scenario-btn");

        if (toggle) {
            toggle.classList.toggle("is-demo", state.demoMode);
            toggle.setAttribute("aria-pressed", String(state.demoMode));
            toggle.textContent = state.demoMode ? "Demo Mode: ON" : "Demo Mode: OFF";
        }

        if (chip) chip.classList.toggle("hidden", !state.demoMode);
        if (controls) controls.classList.toggle("hidden", !state.demoMode);
        if (status) {
            status.classList.toggle("hidden", !state.demoMode);
            status.textContent = "Selected: " + demoLabel(state.demoScenario) + " · Simulated data for demonstration";
        }

        scenarioButtons.forEach(function (btn) {
            var active = btn.getAttribute("data-demo-scenario") === state.demoScenario;
            btn.classList.toggle("active", active);
        });
    }

    function formatDemoTimestamp(date) {
        return date.toISOString().slice(0, 19).replace("T", " ");
    }

    function buildDemoScenario(scenarioName) {
        var now = new Date();
        var base = { recorded_at: formatDemoTimestamp(now) };
        var weather = {};
        var traffic = [];
        var incidents = [];
        var normalized = [];
        var pulse = "NORMAL";
        var anomalies = [];
        var correlations = [];

        if (scenarioName === DEMO_SCENARIOS.MODERATE) {
            weather = {
                location: "Jaipur",
                latitude: 26.9124,
                longitude: 75.7873,
                temperature: 34.2,
                relative_humidity: 58,
                rainfall: 12.8,
                wind_speed: 28.5,
                weather_condition: "Partly Cloudy",
                severity: "MODERATE",
                recorded_at: base.recorded_at
            };
            traffic = [
                { id: 1, location: "Zone A", latitude: 26.9124, longitude: 75.7873, delay_minutes: 22, traffic_level: "Moderate", severity: "MODERATE", recorded_at: base.recorded_at },
                { id: 2, location: "Zone B", latitude: 26.8980, longitude: 75.7780, delay_minutes: 12, traffic_level: "Moderate", severity: "MODERATE", recorded_at: base.recorded_at },
                { id: 3, location: "Zone C", latitude: 26.9210, longitude: 75.8050, delay_minutes: 28, traffic_level: "Heavy", severity: "HIGH", recorded_at: base.recorded_at }
            ];
            incidents = [
                { id: 1, location: "Zone A", latitude: 26.9124, longitude: 75.7873, incident_type: "Road Closure", description: "Road work and partial lane closures near the market route.", severity: "MODERATE", recorded_at: base.recorded_at },
                { id: 2, location: "Zone C", latitude: 26.9210, longitude: 75.8050, incident_type: "Drain Overflow", description: "Blocked drain causing standing water on the main road.", severity: "MODERATE", recorded_at: base.recorded_at },
                { id: 3, location: "Zone B", latitude: 26.8980, longitude: 75.7780, incident_type: "Street Light Outage", description: "Several street lights are malfunctioning near the residential stretch.", severity: "LOW", recorded_at: base.recorded_at }
            ];
            pulse = "MODERATE";
            anomalies = [
                { type: "traffic", location: "Zone C", severity: "HIGH", reason: "Traffic delay in Zone C is 28 minutes and congestion is rising." },
                { type: "weather", location: "Jaipur", severity: "MODERATE", reason: "Weather conditions are warmer and wetter than a typical normal day." }
            ];
        } else if (scenarioName === DEMO_SCENARIOS.HIGH_ACTIVITY) {
            weather = {
                location: "Jaipur",
                latitude: 26.9124,
                longitude: 75.7873,
                temperature: 38.6,
                relative_humidity: 72,
                rainfall: 33.4,
                wind_speed: 52.2,
                weather_condition: "Heavy Rain",
                severity: "HIGH",
                recorded_at: base.recorded_at
            };
            traffic = [
                { id: 1, location: "Zone A", latitude: 26.9124, longitude: 75.7873, delay_minutes: 42, traffic_level: "Heavy", severity: "HIGH", recorded_at: base.recorded_at },
                { id: 2, location: "Zone B", latitude: 26.8980, longitude: 75.7780, delay_minutes: 26, traffic_level: "Heavy", severity: "HIGH", recorded_at: base.recorded_at },
                { id: 3, location: "Zone C", latitude: 26.9210, longitude: 75.8050, delay_minutes: 48, traffic_level: "Gridlock", severity: "HIGH", recorded_at: base.recorded_at }
            ];
            incidents = [
                { id: 1, location: "Zone A", latitude: 26.9124, longitude: 75.7873, incident_type: "Power Outage", description: "Multiple blocks are facing a major power disruption after recent storms.", severity: "HIGH", recorded_at: base.recorded_at },
                { id: 2, location: "Zone A", latitude: 26.9124, longitude: 75.7873, incident_type: "Water Pipeline Burst", description: "A ruptured water line is flooding the market road and nearby walkways.", severity: "HIGH", recorded_at: base.recorded_at },
                { id: 3, location: "Zone C", latitude: 26.9210, longitude: 75.8050, incident_type: "Road Closure", description: "A key corridor is closed following a vehicle pile-up and debris removal work.", severity: "HIGH", recorded_at: base.recorded_at },
                { id: 4, location: "Zone B", latitude: 26.8980, longitude: 75.7780, incident_type: "Drain Overflow", description: "Continuous rain is causing water to collect and block residential streets.", severity: "MODERATE", recorded_at: base.recorded_at }
            ];
            pulse = "HIGH";
            anomalies = [
                { type: "weather", location: "Jaipur", severity: "HIGH", reason: "Heavy rainfall and high winds are creating severe weather conditions in the city." },
                { type: "traffic", location: "Zone A", severity: "HIGH", reason: "Traffic delay in Zone A is 42 minutes with heavy congestion across major corridors." },
                { type: "incident", location: "Zone A", severity: "HIGH", reason: "High-severity incidents are clustered around Zone A and disrupting movement." }
            ];
            correlations = [
                { type: "possible_correlation", location: "Zone A", events: ["weather", "traffic"], time_difference_minutes: 5, message: "Possible correlation detected between severe weather and sharply increased traffic delay in Zone A.", label: "POSSIBLE" },
                { type: "possible_correlation", location: "Zone C", events: ["traffic", "incident"], time_difference_minutes: 8, message: "Traffic and incident activity increased together in Zone C during the same period.", label: "POSSIBLE" }
            ];
        } else {
            weather = {
                location: "Jaipur",
                latitude: 26.9124,
                longitude: 75.7873,
                temperature: 30.8,
                relative_humidity: 44,
                rainfall: 1.5,
                wind_speed: 15.4,
                weather_condition: "Clear",
                severity: "NORMAL",
                recorded_at: base.recorded_at
            };
            traffic = [
                { id: 1, location: "Zone A", latitude: 26.9124, longitude: 75.7873, delay_minutes: 8, traffic_level: "Light", severity: "NORMAL", recorded_at: base.recorded_at },
                { id: 2, location: "Zone B", latitude: 26.8980, longitude: 75.7780, delay_minutes: 6, traffic_level: "Light", severity: "NORMAL", recorded_at: base.recorded_at },
                { id: 3, location: "Zone C", latitude: 26.9210, longitude: 75.8050, delay_minutes: 10, traffic_level: "Moderate", severity: "NORMAL", recorded_at: base.recorded_at }
            ];
            incidents = [
                { id: 1, location: "Zone B", latitude: 26.8980, longitude: 75.7780, incident_type: "Street Light Outage", description: "One lamp post near the residential lane is currently offline.", severity: "LOW", recorded_at: base.recorded_at },
                { id: 2, location: "Zone A", latitude: 26.9124, longitude: 75.7873, incident_type: "Garbage Complaint", description: "Minor waste accumulation reported near the market side street.", severity: "LOW", recorded_at: base.recorded_at }
            ];
            pulse = "NORMAL";
            anomalies = [];
            correlations = [];
        }

        var airQuality = {
            success: true,
            source: "OpenAQ",
            location: "Jaipur",
            latitude: 26.9124,
            longitude: 75.7873,
            measurements: scenarioName === DEMO_SCENARIOS.MODERATE ? { pm25: 41.8, pm10: 96.2, no2: 52.5, o3: 84.7 } : (scenarioName === DEMO_SCENARIOS.HIGH_ACTIVITY ? { pm25: 72.9, pm10: 201.0, no2: 118.8, o3: 156.1 } : { pm25: 22.4, pm10: 51.8, no2: 28.5, o3: 48.9 }),
            severity: scenarioName === DEMO_SCENARIOS.MODERATE ? "MODERATE" : (scenarioName === DEMO_SCENARIOS.HIGH_ACTIVITY ? "HIGH" : "NORMAL"),
            recorded_at: base.recorded_at
        };

        var normalizedData = [];
        var pushRecords = function (source, eventType, location, lat, lng, value, severity, ts) {
            normalizedData.push({
                source: source,
                event_type: eventType,
                location: location,
                latitude: lat,
                longitude: lng,
                value: value,
                severity: severity,
                timestamp: ts
            });
        };

        pushRecords("weather", "weather", weather.location, weather.latitude, weather.longitude, weather.temperature, weather.severity, weather.recorded_at);
        traffic.forEach(function (item) {
            pushRecords("traffic", "traffic", item.location, item.latitude, item.longitude, item.delay_minutes, item.severity, item.recorded_at);
        });
        incidents.forEach(function (item) {
            pushRecords("incident", "incident", item.location, item.latitude, item.longitude, item.incident_type, item.severity, item.recorded_at);
        });
        pushRecords("air_quality", "air_quality", airQuality.location, airQuality.latitude, airQuality.longitude, airQuality.measurements.pm25, airQuality.severity, airQuality.recorded_at);

        return {
            weather: weather,
            traffic: traffic,
            incidents: incidents,
            airQuality: airQuality,
            analysis: {
                success: true,
                analysis_time: base.recorded_at,
                area_pulse: pulse,
                anomalies: anomalies,
                correlations: correlations,
                notice: "Demo scenario active: " + demoLabel(scenarioName)
            },
            normalized: {
                success: true,
                count: normalizedData.length,
                data: normalizedData
            }
        };
    }

    function applyDemoData() {
        if (!state.demoMode) return;
        var demo = buildDemoScenario(state.demoScenario);
        state.weather = demo.weather;
        state.traffic = demo.traffic;
        state.incidents = demo.incidents;
        state.airQuality = demo.airQuality;
        state.analysis = demo.analysis;
        state.normalized = demo.normalized;
        renderAll();
        renderDemoControls();
    }

    function renderAll() {
        renderWeather();
        renderTraffic();
        renderIncidents();
        renderAirQuality();
        renderPulse();
        renderSummary();
        renderAlerts();
        renderTimeline();
        renderHappening();
        renderRecent();
        renderTrend();
        renderSources();
        updateMapMarkers();
        setText("last-updated", fmtTime(new Date()));
    }

    function uniqueLocations(items, limit) {
        var result = [];
        var seen = {};
        (items || []).forEach(function (item) {
            var loc = item && item.location ? item.location : null;
            if (!loc || seen[loc]) return;
            seen[loc] = true;
            result.push(loc);
            if (limit && result.length >= limit) return;
        });
        return result;
    }

    function summaryTextForLocations(pulse, data, signalLocations) {
        var pulseText = pulse || "NORMAL";
        var locs = signalLocations && signalLocations.length ? signalLocations : ["Jaipur"];
        var areaText = locs.join(", ");

        if (pulseText === "NORMAL") {
            return "City conditions are currently normal. No significant anomalies have been detected.";
        }

        if (pulseText === "MODERATE") {
            var sentences = ["Moderate activity is being observed."];
            var trafficText = "Traffic has increased in " + areaText + ".";
            if (data && data.analysis && data.analysis.anomalies) {
                var trafficAnomalies = data.analysis.anomalies.filter(function (an) { return an.type === "traffic"; });
                if (trafficAnomalies.length) {
                    trafficText = "Traffic has increased in " + uniqueLocations(trafficAnomalies, 2).join(", ") + ".";
                }
            }
            var airText = "Air quality is showing moderate levels.";
            if (data && data.airQuality && data.airQuality.severity && data.airQuality.severity !== "NORMAL") {
                airText = "Air quality is showing " + data.airQuality.severity.toLowerCase() + " levels.";
            }
            var extraText = "Recent incidents are also concentrated in the same area.";
            if (data && data.incidents && data.incidents.length) {
                var incidentLocs = uniqueLocations(data.incidents, 2);
                if (incidentLocs.length) {
                    extraText = "Recent incidents are being reported around " + incidentLocs.join(", ") + ".";
                }
            }
            sentences.push(trafficText + " " + airText + " " + extraText);
            return sentences.join(" ");
        }

        var weatherText = "Heavy weather conditions are affecting the monitored area.";
        var trafficText = "Traffic is significantly elevated.";
        var incidentText = "Multiple incidents are being reported.";
        var locList = locs.length > 1 ? locs.slice(0, 2).join(" and ") : locs[0];

        if (data && data.analysis && data.analysis.anomalies) {
            var weatherAnoms = data.analysis.anomalies.filter(function (an) { return an.type === "weather"; });
            if (weatherAnoms.length) {
                weatherText = "Heavy weather conditions are affecting " + uniqueLocations(weatherAnoms, 2).join(", ") + ".";
            }
            var trafficAnoms = data.analysis.anomalies.filter(function (an) { return an.type === "traffic"; });
            if (trafficAnoms.length) {
                trafficText = "Traffic is significantly elevated in " + uniqueLocations(trafficAnoms, 2).join(", ") + ".";
            }
            var incidentAnoms = data.analysis.anomalies.filter(function (an) { return an.type === "incident"; });
            if (incidentAnoms.length) {
                incidentText = "Multiple incidents are being reported in " + uniqueLocations(incidentAnoms, 2).join(", ") + ".";
            }
        }

        var highSentence = "High activity is currently being observed. " + weatherText + " " + trafficText + " " + incidentText;
        var correlationSentence = "";
        if (data && data.analysis && data.analysis.correlations && data.analysis.correlations.length) {
            var corr = data.analysis.correlations[0];
            var corrLoc = corr.location || locList;
            correlationSentence = "Traffic activity coincides with rainfall in " + corrLoc + ".";
            if (corr.events && corr.events.indexOf("incident") !== -1) {
                correlationSentence = "Incidents are occurring near areas with increased traffic in " + corrLoc + ".";
            }
            if (corr.events && corr.events.indexOf("air_quality") !== -1) {
                correlationSentence = "Air quality changes coincide with increased activity in " + corrLoc + ".";
            }
        }
        return highSentence + (correlationSentence ? " " + correlationSentence : "");
    }

    function renderSummary() {
        var summaryEl = el("city-pulse-summary");
        var statusEl = el("summary-status");
        var timeEl = el("summary-updated");

        if (!summaryEl || !statusEl || !timeEl) return;

        if (state.demoMode) {
            var demoText = "Simulated data for demonstration";
            var summary = summaryTextForLocations(state.analysis && state.analysis.area_pulse ? state.analysis.area_pulse : "NORMAL", state, ["Jaipur", "Zone A", "Zone C"]);
            summaryEl.textContent = summary + " " + demoText;
            statusEl.textContent = demoLabel(state.demoScenario);
            statusEl.className = "badge st-demo";
            timeEl.textContent = fmtTime(new Date());
            return;
        }

        if (!state.analysis || !state.analysis.success) {
            summaryEl.textContent = "City Pulse summary is temporarily unavailable.";
            statusEl.textContent = "N/A";
            statusEl.className = "badge st-unavailable";
            timeEl.textContent = "--";
            return;
        }

        var pulse = state.analysis.area_pulse || "NORMAL";
        var summary = summaryTextForLocations(pulse, state, uniqueLocations(state.normalized && state.normalized.data ? state.normalized.data : [], 3));
        summaryEl.textContent = summary;
        statusEl.textContent = pulse;
        statusEl.className = "badge st-" + pulse.toLowerCase();
        timeEl.textContent = fmtShortTime((state.analysis && state.analysis.analysis_time) || new Date().toISOString().slice(0,19).replace("T"," "));
    }

    function buildAlerts() {
        var a = state.analysis || {};
        var pulse = a.area_pulse || "NORMAL";
        var anomalies = Array.isArray(a.anomalies) ? a.anomalies : [];
        var correlations = Array.isArray(a.correlations) ? a.correlations : [];
        var alerts = [];
        var now = a.analysis_time || new Date().toISOString().slice(0, 19).replace("T", " ");

        if (pulse === "HIGH") {
            alerts.push({
                level: "HIGH",
                message: "High activity is being observed across the monitored area.",
                location: "Jaipur",
                time: now
            });
        }

        if (pulse === "MODERATE") {
            alerts.push({
                level: "MODERATE",
                message: "Moderate activity is being observed in the monitored area.",
                location: "Jaipur",
                time: now
            });
        }

        anomalies.forEach(function (an) {
            var loc = an.location || "Jaipur";
            var msg = an.type ? (an.type.charAt(0).toUpperCase() + an.type.slice(1)) : "Condition";

            if (an.severity === "HIGH") {
                alerts.push({
                    level: "HIGH",
                    message: "High " + msg.toLowerCase() + " activity detected in " + loc + ".",
                    location: loc,
                    time: now
                });
            } else if (an.severity === "MODERATE") {
                alerts.push({
                    level: "MODERATE",
                    message: "Moderate " + msg.toLowerCase() + " activity observed in " + loc + ".",
                    location: loc,
                    time: now
                });
            } else if (an.type === "weather") {
                alerts.push({
                    level: "INFORMATION",
                    message: "Rainfall is currently being observed in " + loc + ".",
                    location: loc,
                    time: now
                });
            }
        });

        correlations.forEach(function (corr) {
            var loc = corr.location || "Jaipur";
            var eventMessage = "Traffic activity coincides with rainfall in " + loc + ".";
            if (corr.events && corr.events.indexOf("incident") !== -1) {
                eventMessage = "Incidents are occurring near areas with increased traffic in " + loc + ".";
            }
            if (corr.events && corr.events.indexOf("air_quality") !== -1) {
                eventMessage = "Air quality changes coincide with increased activity in " + loc + ".";
            }
            alerts.push({
                level: "INFORMATION",
                message: eventMessage,
                location: loc,
                time: now
            });
        });

        if (!alerts.length) {
            alerts.push({
                level: "INFORMATION",
                message: "No active alerts. City conditions are stable.",
                location: "Jaipur",
                time: now
            });
        }

        alerts.sort(function (x, y) {
            var rank = { HIGH: 3, MODERATE: 2, INFORMATION: 1 };
            return (rank[y.level] || 0) - (rank[x.level] || 0);
        });

        return alerts.slice(0, 5);
    }

    function renderAlerts() {
        var list = el("city-pulse-alerts");
        var demoLabel = el("alerts-demo-label");
        if (!list) return;

        if (demoLabel) {
            demoLabel.classList.toggle("hidden", !state.demoMode);
        }

        if (!state.analysis || !state.analysis.success) {
            list.innerHTML = '<p class="empty-note">Alerts temporarily unavailable.</p>';
            return;
        }

        var alerts = buildAlerts();
        list.innerHTML = "";

        alerts.forEach(function (alert) {
            var item = document.createElement("div");
            item.className = "alert-item";

            var badge = document.createElement("div");
            badge.className = "alert-severity badge st-" + alert.level.toLowerCase();
            badge.textContent = alert.level;

            var content = document.createElement("div");
            content.className = "alert-content";

            var message = document.createElement("div");
            message.className = "alert-message";
            message.textContent = alert.message;

            var meta = document.createElement("div");
            meta.className = "alert-meta";

            if (alert.location) {
                var loc = document.createElement("span");
                loc.textContent = "Location: " + alert.location;
                meta.appendChild(loc);
            }
            if (alert.time) {
                var time = document.createElement("span");
                time.textContent = "Time: " + alert.time;
                meta.appendChild(time);
            }

            content.appendChild(message);
            content.appendChild(meta);

            var timeBox = document.createElement("div");
            timeBox.className = "alert-time";
            timeBox.textContent = fmtShortTime(alert.time);

            item.appendChild(badge);
            item.appendChild(content);
            item.appendChild(timeBox);
            list.appendChild(item);
        });
    }

    function timelineValue(record) {
        if (record.value === null || record.value === undefined || record.value === "") {
            return "--";
        }
        if (record.source === "weather") return record.value + " °C";
        if (record.source === "traffic") return record.value + " min delay";
        if (record.source === "air_quality") return record.value + " µg/m³ PM2.5";
        return String(record.value);
    }

    function renderTimeline() {
        var list = el("historical-timeline");
        var summary = el("timeline-summary");
        var demoLabel = el("timeline-demo-label");
        if (!list || !summary) return;

        if (demoLabel) demoLabel.classList.toggle("hidden", !state.demoMode);

        if (!state.normalized || !state.normalized.success || !Array.isArray(state.normalized.data)) {
            list.innerHTML = '<p class="empty-note">Historical activity is temporarily unavailable.</p>';
            summary.textContent = "Timeline unavailable.";
            return;
        }

        var cutoff = Date.now() - timelineHours * 60 * 60 * 1000;
        var records = state.normalized.data.filter(function (record) {
            var timestamp = parseTs(record.timestamp).getTime();
            return isFinite(timestamp) && timestamp >= cutoff;
        }).sort(function (a, b) {
            return parseTs(b.timestamp).getTime() - parseTs(a.timestamp).getTime();
        });

        summary.textContent = records.length + " event" + (records.length === 1 ? "" : "s") +
            " recorded in the last " + timelineHours + " hour" + (timelineHours === 1 ? "" : "s") + ".";

        if (!records.length) {
            list.innerHTML = '<p class="empty-note">No recent activity found.</p>';
            return;
        }

        list.innerHTML = "";
        records.forEach(function (record) {
            var row = document.createElement("div");
            row.className = "timeline-row";
            row.setAttribute("role", "row");

            var time = document.createElement("span");
            time.className = "timeline-time";
            time.textContent = fmtShortTime(record.timestamp);
            time.setAttribute("data-label", "Time");

            var location = document.createElement("span");
            location.className = "timeline-location";
            location.textContent = record.location || "--";
            location.setAttribute("data-label", "Location");

            var source = document.createElement("span");
            source.className = "timeline-source";
            source.textContent = record.source || "--";
            source.setAttribute("data-label", "Source");

            var eventType = document.createElement("span");
            eventType.className = "timeline-event-type";
            eventType.textContent = record.event_type || "--";
            eventType.setAttribute("data-label", "Event type");

            var severity = document.createElement("span");
            severity.className = "timeline-severity";
            severity.appendChild(severityBadge(record.severity));
            severity.setAttribute("data-label", "Severity");

            var value = document.createElement("span");
            value.className = "timeline-value";
            value.textContent = timelineValue(record);
            value.setAttribute("data-label", "Value / message");

            row.appendChild(time);
            row.appendChild(location);
            row.appendChild(source);
            row.appendChild(eventType);
            row.appendChild(severity);
            row.appendChild(value);
            list.appendChild(row);
        });
    }

    // ---------- card renderers (each fails on its own) ----------

    function renderWeather() {
        var w = state.weather;
        var badgeEl = el("weather-badge");
        if (!w || w.error) {
            el("weather-badge").innerHTML = "";
            badgeEl.appendChild(badge("Unavailable", "st-unavailable"));
            setText("weather-value", "Weather data unavailable");
            setText("weather-desc", "Could not reach the weather service.");
            setText("weather-updated", "--");
            return;
        }
        badgeEl.innerHTML = "";
        badgeEl.appendChild(severityBadge(w.severity));
        setText("weather-value", w.temperature + " °C");
        setText("weather-desc", w.weather_condition + " · " + w.rainfall + " mm rain");
        setText("weather-updated", fmtShortTime(w.recorded_at));
    }

    function renderTraffic() {
        var t = state.traffic;
        var badgeEl = el("traffic-badge");
        if (!t || t.error || !t.length) {
            badgeEl.innerHTML = "";
            badgeEl.appendChild(badge("Unavailable", "st-unavailable"));
            setText("traffic-value", "Traffic data unavailable");
            setText("traffic-desc", "Could not load traffic readings.");
            setText("traffic-updated", "--");
            return;
        }
        // Worst reading = highest delay.
        var worst = t.reduce(function (a, b) {
            return (parseInt(b.delay_minutes, 10) || 0) > (parseInt(a.delay_minutes, 10) || 0) ? b : a;
        }, t[0]);

        badgeEl.innerHTML = "";
        badgeEl.appendChild(severityBadge(worst.severity));
        setText("traffic-value", worst.delay_minutes + " min delay");
        setText("traffic-desc", (worst.traffic_level || "High") + " traffic in " + worst.location);
        setText("traffic-updated", fmtShortTime(t[0].recorded_at));
    }

    function renderIncidents() {
        var inc = state.incidents;
        var badgeEl = el("incidents-badge");
        if (!inc || inc.error || !inc.length) {
            badgeEl.innerHTML = "";
            badgeEl.appendChild(badge("Unavailable", "st-unavailable"));
            setText("incidents-value", "Incident data unavailable");
            setText("incidents-desc", "Could not load incident reports.");
            setText("incidents-updated", "--");
            return;
        }
        var count = inc.length;
        var sev = count < INCIDENTS_MODERATE_AT ? "NORMAL"
            : count <= INCIDENTS_HIGH_AT ? "MODERATE"
            : "HIGH";

        badgeEl.innerHTML = "";
        badgeEl.appendChild(badge(sev, "st-" + sev.toLowerCase()));
        setText("incidents-value", count + " reports");
        setText("incidents-desc", "Total incident reports");
        setText("incidents-updated", fmtShortTime(inc[0].recorded_at));
    }

    function renderAirQuality() {
        var aq = state.airQuality;
        var badgeEl = el("air-quality-badge");

        if (!aq || !aq.success || !aq.measurements) {
            badgeEl.innerHTML = "";
            badgeEl.appendChild(badge("Unavailable", "st-unavailable"));
            setText("air-quality-value", "Air quality data unavailable");
            setText("air-quality-desc", "Air quality data unavailable");
            setText("air-quality-updated", "--");
            return;
        }

        var m = aq.measurements;
        var pm25 = typeof m.pm25 === "number" && isFinite(m.pm25) ? m.pm25 : null;
        var pm10 = typeof m.pm10 === "number" && isFinite(m.pm10) ? m.pm10 : null;

        badgeEl.innerHTML = "";
        badgeEl.appendChild(severityBadge(aq.severity));
        setText("air-quality-value", pm25 === null ? "n/a" : pm25 + " µg/m³");
        setText("air-quality-desc", pm10 === null
            ? "No PM10 data"
            : "PM10: " + pm10 + " µg/m³");
        setText("air-quality-updated", fmtShortTime(aq.recorded_at));
    }

    function renderPulse() {
        var a = state.analysis;
        var pulseEl = el("pulse-value");
        var badgeEl = el("pulse-badge");

        if (!a || !a.success) {
            pulseEl.className = "pulse-value";
            pulseEl.textContent = "--";
            setText("pulse-hint", "Analysis temporarily unavailable");
            badgeEl.innerHTML = "";
            badgeEl.appendChild(badge("n/a", "st-unavailable"));
            setText("pulse-card-value", "n/a");
            setText("pulse-card-desc", "Analysis temporarily unavailable");
            setText("pulse-updated", "--");
            return;
        }

        var pulse = a.area_pulse || "NORMAL";
        pulseEl.className = "pulse-value pulse-" + pulse;
        pulseEl.textContent = pulse + " ACTIVITY";

        var highCount = 0;
        a.anomalies.forEach(function (an) { if (an.severity === "HIGH") highCount++; });
        setText("pulse-hint", highCount + " high-severity anomaly(s) detected" +
            (a.correlations.length ? " · " + a.correlations.length + " possible correlation(s)" : ""));

        badgeEl.innerHTML = "";
        badgeEl.appendChild(badge(pulse, "st-" + pulse.toLowerCase()));
        setText("pulse-card-value", pulse);
        setText("pulse-card-desc", highCount + " high-severity anomaly(s), " + a.anomalies.length + " total");
        setText("pulse-updated", fmtShortTime(a.analysis_time));
    }

    // ---------- What's Happening? ----------

    function renderHappening() {
        var box = el("happening");
        box.innerHTML = "";

        var a = state.analysis;
        if (!a || !a.success) {
            var note = document.createElement("p");
            note.className = "empty-note";
            note.textContent = "Analysis temporarily unavailable";
            box.appendChild(note);
            return;
        }

        if (a.anomalies.length) {
            a.anomalies.forEach(function (an) {
                var item = document.createElement("div");
                item.className = "happening-item";
                var head = document.createElement("div");
                head.appendChild(severityBadge(an.severity));
                var typeLabel = document.createElement("span");
                typeLabel.textContent = " " + an.type.toUpperCase() + " · " + an.location;
                head.appendChild(typeLabel);
                item.appendChild(head);
                var reason = document.createElement("span");
                reason.className = "happening-reason";
                reason.textContent = an.reason;
                item.appendChild(reason);
                box.appendChild(item);
            });
        } else {
            var none = document.createElement("p");
            none.className = "empty-note";
            none.textContent = "No significant anomalies detected.";
            box.appendChild(none);
        }

        // Possible correlations - clearly labeled, never presented as causation.
        if (a.correlations.length) {
            a.correlations.forEach(function (c) {
                var item = document.createElement("div");
                item.className = "happening-item";
                var label = document.createElement("span");
                label.className = "correlation-label";
                label.textContent = "POSSIBLE CORRELATION";
                item.appendChild(label);
                var msg = document.createElement("span");
                msg.className = "happening-reason";
                msg.textContent = c.message;
                item.appendChild(msg);
                var meta = document.createElement("span");
                meta.className = "empty-note";
                meta.textContent = c.location + " · events: " + c.events.join(" + ") +
                    " · within " + c.time_difference_minutes + " min";
                item.appendChild(meta);
                box.appendChild(item);
            });
        } else if (!a.anomalies.length) {
            var noneCorr = document.createElement("p");
            noneCorr.className = "empty-note";
            noneCorr.textContent = "No possible correlations detected.";
            box.appendChild(noneCorr);
        }
    }

    // ---------- Recent Activity ----------

    function renderRecent() {
        var box = el("recent");
        box.innerHTML = "";

        var n = state.normalized;
        if (!n || !n.success || !n.data || !n.data.length) {
            var note = document.createElement("p");
            note.className = "empty-note";
            note.textContent = "Recent activity unavailable.";
            box.appendChild(note);
            return;
        }

        // Newest first, limit the list.
        var sorted = n.data.slice().sort(function (x, y) {
            return parseTs(y.timestamp).getTime() - parseTs(x.timestamp).getTime();
        });
        sorted.slice(0, RECENT_ACTIVITY_LIMIT).forEach(function (r) {
            var row = document.createElement("div");
            row.className = "recent-row";

            var time = document.createElement("span");
            time.className = "recent-time";
            time.textContent = fmtShortTime(r.timestamp);

            var type = document.createElement("span");
            type.className = "recent-type";
            type.textContent = r.source;

            var loc = document.createElement("span");
            loc.className = "recent-loc";
            loc.textContent = r.location;

            row.appendChild(time);
            row.appendChild(type);
            row.appendChild(loc);
            row.appendChild(severityBadge(r.severity));
            box.appendChild(row);
        });
    }

    // ---------- Simple trend (HTML/CSS/JS, no library) ----------

    function renderTrend() {
        var box = el("trend-chart");
        var badgeEl = el("trend-badge");
        box.innerHTML = "";
        badgeEl.textContent = "";

        var n = state.normalized;
        if (!n || !n.success || !n.data || !n.data.length) {
            var note = document.createElement("p");
            note.className = "empty-note";
            note.textContent = "Trend unavailable.";
            box.appendChild(note);
            return;
        }

        // Bucket events (any source) into hourly slots over the last TREND_HOURS.
        var now = Date.now();
        var buckets = [];
        var i;
        for (i = TREND_HOURS - 1; i >= 0; i--) {
            buckets.push({
                start: now - (i + 1) * 3600000,
                end: now - i * 3600000,
                label: "",
                count: 0
            });
        }
        buckets.forEach(function (b) {
            b.label = new Date(b.start).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
        });

        n.data.forEach(function (r) {
            var t = parseTs(r.timestamp).getTime();
            buckets.forEach(function (b) {
                if (t >= b.start && t < b.end) b.count++;
            });
        });

        // Trend direction: compare earlier half vs later half of the window.
        var mid = Math.floor(buckets.length / 2);
        var earlierAvg = 0, laterAvg = 0;
        for (i = 0; i < mid; i++) earlierAvg += buckets[i].count;
        for (i = mid; i < buckets.length; i++) laterAvg += buckets[i].count;
        earlierAvg = earlierAvg / mid;
        laterAvg = laterAvg / (buckets.length - mid);

        var direction = "STABLE";
        if (laterAvg > earlierAvg + 0.5) direction = "INCREASING";
        else if (laterAvg < earlierAvg - 0.5) direction = "DECREASING";

        badgeEl.textContent = direction;
        badgeEl.className = "trend-badge st-" + direction.toLowerCase();

        var maxCount = Math.max.apply(null, buckets.map(function (b) { return b.count; })) || 1;
        var maxBarPx = 100; // bar area height in pixels (leaves room for labels)

        buckets.forEach(function (b) {
            var col = document.createElement("div");
            col.className = "trend-col";

            var bar = document.createElement("div");
            bar.className = "trend-bar";
            var px = b.count === 0 ? 4 : Math.max(10, Math.round((b.count / maxCount) * maxBarPx));
            bar.style.height = px + "px";
            bar.title = b.count + " event(s) at " + b.label;

            var label = document.createElement("div");
            label.className = "trend-label";
            label.textContent = b.label;

            col.appendChild(bar);
            col.appendChild(label);
            box.appendChild(col);
        });
    }

    // ---------- Data sources (LIVE / AVAILABLE / UNAVAILABLE) ----------

    function sourceStatus(payload, isArray, maxAgeMinutes) {
        if (state.demoMode) return { status: "DEMO", note: "Simulated data" };
        if (!payload || payload.error) return { status: "UNAVAILABLE", note: "" };
        var latest = isArray ? (payload.length ? payload[0].recorded_at : null) : payload.recorded_at;
        if (!latest) return { status: "UNAVAILABLE", note: "no records" };
        var ageMin = (Date.now() - parseTs(latest).getTime()) / 60000;
        if (ageMin <= maxAgeMinutes) {
            return { status: "LIVE", note: "Last data " + fmtShortTime(latest) };
        }
        return { status: "AVAILABLE", note: "Last data " + fmtShortTime(latest) };
    }

    function renderSources() {
        var box = el("sources");
        box.innerHTML = "";

        var sources = [
            { label: "Weather",     payload: state.weather,     isArray: false, maxAge: MAX_WEATHER_AGE_LIVE_MIN },
            { label: "Traffic",     payload: state.traffic,     isArray: true,  maxAge: MAX_SOURCE_AGE_LIVE_MIN },
            { label: "Incidents",   payload: state.incidents,   isArray: true,  maxAge: MAX_SOURCE_AGE_LIVE_MIN },
            { label: "Air Quality", payload: state.airQuality,  isArray: false, maxAge: MAX_AIR_QUALITY_AGE_LIVE_MIN }
        ];

        sources.forEach(function (s) {
            var info = sourceStatus(s.payload, s.isArray, s.maxAge);

            var item = document.createElement("div");
            item.className = "source-item";

            var name = document.createElement("div");
            name.className = "source-name";
            name.textContent = s.label;

            var status = document.createElement("div");
            status.className = "source-status st-" + info.status.toLowerCase();
            status.textContent = info.status;

            var note = document.createElement("div");
            note.className = "source-note";
            note.textContent = info.note || (info.status === "UNAVAILABLE" ? "Currently unreachable" : "");

            item.appendChild(name);
            item.appendChild(status);
            item.appendChild(note);
            box.appendChild(item);
        });
    }

    // ---------- Leaflet civic map (Step 8) ----------

    function esc(value) {
        return String(value === null || value === undefined ? "" : value).replace(/[&<>"']/g, function (ch) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
        });
    }

    function mapNotice(text) {
        var node = el("map-notice");
        if (node) node.textContent = text || "";
    }

    function popupRow(label, value) {
        return '<div class="cp-popup-row"><span class="lbl">' + esc(label) + '</span><span class="val">' + esc(value) + '</span></div>';
    }

    function popupHtml(record) {
        var valueLabel, valueText;
        if (record.source === "weather") {
            valueLabel = "Temperature";
            valueText = record.value + " °C";
        } else if (record.source === "traffic") {
            valueLabel = "Delay";
            valueText = record.value + " min";
        } else if (record.source === "air_quality") {
            valueLabel = "PM2.5";
            valueText = record.value + " µg/m³";
        } else {
            valueLabel = "Type";
            valueText = record.value;
        }

        return '<div class="cp-popup">'
            + '<div class="cp-popup-type">' + esc(record.source).toUpperCase() + '</div>'
            + popupRow("Location", record.location)
            + popupRow(valueLabel, valueText)
            + popupRow("Severity", record.severity)
            + popupRow("Time", fmtShortTime(record.timestamp))
            + '</div>';
    }

    function makeMapMarker(record) {
        // Skip records without valid coordinates - never break the map.
        var lat = parseFloat(record.latitude);
        var lng = parseFloat(record.longitude);
        if (!isFinite(lat) || !isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            return null;
        }

        var source = record.source === "incident" ? "incident" : record.source;
        var off = MARKER_OFFSET[source] || { lat: 0, lng: 0 };

        var icon = L.divIcon({
            className: "cp-marker",
            html: '<span class="cp-dot dot-' + source + '"></span>',
            iconSize: [18, 18],
            iconAnchor: [9, 9]
        });

        var marker = L.marker([lat + off.lat, lng + off.lng], { icon: icon });
        marker.bindPopup(popupHtml(record));
        return marker;
    }

    function initMap() {
        var container = el("cityMap");
        if (!container) return;

        if (typeof L === "undefined") {
            mapNotice("Map data temporarily unavailable.");
            return;
        }
        if (map) return; // already initialized

        map = L.map("cityMap", { zoomControl: true }).setView(MAP_CENTER, MAP_ZOOM);

        // OpenStreetMap tiles - the geographic layer only.
        // Civic markers come from the CityPulse APIs, not from OpenStreetMap.
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        mapGroups = {
            weather: L.layerGroup(),
            traffic: L.layerGroup(),
            incident: L.layerGroup(),
            air_quality: L.layerGroup()
        };

        // Filter buttons - show/hide marker groups without reloading the page.
        document.querySelectorAll(".map-filter-btn").forEach(function (btn) {
            btn.addEventListener("click", function () {
                document.querySelectorAll(".map-filter-btn").forEach(function (b) {
                    b.classList.toggle("active", b === btn);
                });
                mapFilter = btn.getAttribute("data-filter");
                applyMapFilter();
            });
        });
    }

    function applyMapFilter() {
        if (!map || !mapGroups) return;
        Object.keys(mapGroups).forEach(function (key) {
            if (mapFilter === "all" || mapFilter === key) {
                if (!map.hasLayer(mapGroups[key])) map.addLayer(mapGroups[key]);
            } else if (map.hasLayer(mapGroups[key])) {
                map.removeLayer(mapGroups[key]);
            }
        });
    }

    function updateMapMarkers() {
        if (!map || !mapGroups) return;

        Object.keys(mapGroups).forEach(function (key) { mapGroups[key].clearLayers(); });

        var n = state.normalized;
        if (!n || !n.success || !n.data || !n.data.length) {
            mapNotice("Map data temporarily unavailable.");
            applyMapFilter();
            return;
        }

        mapNotice("");
        n.data.forEach(function (record) {
            var marker = makeMapMarker(record);
            if (!marker) return;
            var key = record.source === "incident" ? "incident" : record.source;
            mapGroups[key].addLayer(marker);
        });

        applyMapFilter();
    }

    // ---------- main load + auto refresh ----------

    function loadAll() {
        if (state.demoMode) {
            applyDemoData();
            return;
        }

        Promise.allSettled([
            fetchJson("api/weather.php"),
            fetchJson("api/traffic.php"),
            fetchJson("api/incidents.php"),
            fetchJson("api/air-quality.php"),
            fetchJson("api/analyze.php"),
            fetchJson("api/normalized-data.php")
        ]).then(function (results) {
            state.weather    = results[0].status === "fulfilled" ? results[0].value : null;
            state.traffic    = results[1].status === "fulfilled" ? results[1].value : null;
            state.incidents  = results[2].status === "fulfilled" ? results[2].value : null;
            state.airQuality = results[3].status === "fulfilled" ? results[3].value : null;
            state.analysis   = results[4].status === "fulfilled" ? results[4].value : null;
            state.normalized = results[5].status === "fulfilled" ? results[5].value : null;

            renderAll();
        });
    }

    // Header clock (updates every second, no page reload).
    function tickClock() {
        var now = new Date();
        setText("header-clock", fmtClock(now));
        setText("header-date", fmtDate(now));
    }

    document.getElementById("demo-mode-toggle").addEventListener("click", function () {
        state.demoMode = !state.demoMode;
        if (state.demoMode) {
            applyDemoData();
        } else {
            loadAll();
            renderDemoControls();
        }
    });

    document.querySelectorAll(".demo-scenario-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            state.demoScenario = btn.getAttribute("data-demo-scenario");
            if (state.demoMode) {
                applyDemoData();
            } else {
                renderDemoControls();
            }
        });
    });

    document.querySelectorAll(".timeline-filter-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            timelineHours = parseInt(btn.getAttribute("data-timeline-hours"), 10) || 6;
            document.querySelectorAll(".timeline-filter-btn").forEach(function (filterBtn) {
                filterBtn.classList.toggle("active", filterBtn === btn);
            });
            renderTimeline();
        });
    });

    tickClock();
    setInterval(tickClock, 1000);
    renderDemoControls();
    initMap();
    loadAll();
    setInterval(loadAll, REFRESH_INTERVAL_MS);
})();