/* ============================================================
   CityPulse - Dashboard Script (Step 7)
   Plain JavaScript (no frameworks). Fetches data from the
   existing PHP APIs and updates the dashboard sections.

   Endpoints used:
     - api/weather.php         (weather card)
     - api/traffic.php         (traffic card)
     - api/incidents.php       (incidents card)
     - api/analyze.php         (area pulse, what's happening)
     - api/normalized-data.php (recent activity, trend, sources)

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
    var REFRESH_INTERVAL_MS = 30000;       // 30 seconds
    var RECENT_ACTIVITY_LIMIT = 10;
    var TREND_HOURS = 8;

    // Leaflet map (Step 8): centered on Jaipur. A tiny visual offset
    // keeps overlapping markers in the same zone clickable.
    var MAP_CENTER = [26.9124, 75.7873];
    var MAP_ZOOM = 12;
    var MARKER_OFFSET = {
        weather:  { lat: 0,      lng: 0 },
        traffic:  { lat: 0,      lng: 0.0008 },
        incident: { lat: 0,      lng: -0.0008 }
    };
    var map = null;
    var mapGroups = null;
    var mapFilter = "all";

    var state = {
        weather: null,
        traffic: null,
        incidents: null,
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
            { label: "Weather",   payload: state.weather,   isArray: false, maxAge: MAX_WEATHER_AGE_LIVE_MIN },
            { label: "Traffic",   payload: state.traffic,   isArray: true,  maxAge: MAX_SOURCE_AGE_LIVE_MIN },
            { label: "Incidents", payload: state.incidents, isArray: true,  maxAge: MAX_SOURCE_AGE_LIVE_MIN }
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
            incident: L.layerGroup()
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
        Promise.allSettled([
            fetchJson("api/weather.php"),
            fetchJson("api/traffic.php"),
            fetchJson("api/incidents.php"),
            fetchJson("api/analyze.php"),
            fetchJson("api/normalized-data.php")
        ]).then(function (results) {
            state.weather    = results[0].status === "fulfilled" ? results[0].value : null;
            state.traffic    = results[1].status === "fulfilled" ? results[1].value : null;
            state.incidents  = results[2].status === "fulfilled" ? results[2].value : null;
            state.analysis   = results[3].status === "fulfilled" ? results[3].value : null;
            state.normalized = results[4].status === "fulfilled" ? results[4].value : null;

            renderWeather();
            renderTraffic();
            renderIncidents();
            renderPulse();
            renderHappening();
            renderRecent();
            renderTrend();
            renderSources();
            updateMapMarkers();

            setText("last-updated", fmtTime(new Date()));
        });
    }

    // Header clock (updates every second, no page reload).
    function tickClock() {
        var now = new Date();
        setText("header-clock", fmtClock(now));
        setText("header-date", fmtDate(now));
    }

    tickClock();
    setInterval(tickClock, 1000);

    initMap();
    loadAll();
    setInterval(loadAll, REFRESH_INTERVAL_MS);
})();