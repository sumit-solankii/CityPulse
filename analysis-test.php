<?php
/**
 * CityPulse - Analysis Test Page (Step 6)
 *
 * Calls api/analyze.php and displays the Area Pulse, detected
 * anomalies and possible correlations.
 *
 * How to open:
 *   http://localhost/CityPulse/analysis-test.php
 *
 * NOTE: visual styling (colors/labels) lives here in the FRONTEND.
 * The PHP analysis API only returns data.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analysis Test | CityPulse</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .card {
            border: 1px solid #26262b;
            border-radius: 10px;
            background: #131316;
            padding: 1.2rem 1.5rem;
            margin: 1rem 0 2rem;
        }
        .pulse-label {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: 0.05em;
        }
        .pulse-NORMAL   { color: #94a3b8; }
        .pulse-MODERATE { color: #facc15; }
        .pulse-HIGH     { color: #f87171; }

        .badge {
            display: inline-block;
            padding: 0.15rem 0.6rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-NORMAL   { background: #1e293b; color: #94a3b8; }
        .badge-MODERATE { background: #facc15; color: #0b0b0d; }
        .badge-HIGH     { background: #f87171; color: #0b0b0d; }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.5rem 0 1.5rem;
            font-size: 0.9rem;
        }
        .data-table th,
        .data-table td {
            border: 1px solid #26262b;
            padding: 0.45rem 0.6rem;
            text-align: left;
        }
        .data-table th { background: #101013; color: #facc15; }
        .data-table td { color: #ffffff; vertical-align: top; }
        .data-table tbody tr:nth-child(even) td { background: #101013; }

        .empty-note { color: #9ca3af; margin: 0.5rem 0 1.5rem; }
        .error { color: #f87171; }
        .meta { color: #9ca3af; margin-bottom: 0.25rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <a class="navbar-brand" href="index.php">
            <span class="brand-dot"></span> CityPulse
        </a>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="dashboard.php">City Pulse</a></li>
            <li><a href="data-test.php">Data Test</a></li>
            <li><a href="weather-test.php">Weather Test</a></li>
            <li><a href="normalized-test.php">Normalized</a></li>
            <li><a href="analysis-test.php" class="active">Analysis</a></li>
        </ul>
    </nav>

    <main class="page">
        <h1>Area Analysis</h1>
        <p class="muted">Rule-based anomalies and possible correlations from api/analyze.php</p>

        <div id="message" class="error"></div>

        <div class="card">
            <p class="meta">Current Area Pulse</p>
            <div id="pulse" class="pulse-label">Loading&hellip;</div>
            <p class="meta" id="analysis-time"></p>
        </div>

        <h2>Detected Anomalies</h2>
        <div id="anomalies"></div>

        <h2>Possible Correlations</h2>
        <div id="correlations"></div>
    </main>

    <footer class="footer">
        <p>CityPulse &copy; <span data-year></span>. Built for smarter neighborhoods.</p>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
        (function () {
            "use strict";

            var messageEl = document.getElementById("message");
            var pulseEl = document.getElementById("pulse");
            var timeEl = document.getElementById("analysis-time");

            function badge(text, extraClass) {
                var span = document.createElement("span");
                span.className = "badge " + (extraClass || "");
                span.textContent = text;
                return span;
            }

            function simpleTable(headers, rows) {
                var table = document.createElement("table");
                table.className = "data-table";

                var thead = document.createElement("thead");
                var hr = document.createElement("tr");
                headers.forEach(function (h) {
                    var th = document.createElement("th");
                    th.textContent = h.label;
                    hr.appendChild(th);
                });
                thead.appendChild(hr);
                table.appendChild(thead);

                var tbody = document.createElement("tbody");
                rows.forEach(function (tr) {
                    var tbr = document.createElement("tr");
                    headers.forEach(function (h) {
                        var td = document.createElement("td");
                        var value = tr[h.key];
                        if (h.render) {
                            td.appendChild(h.render(value, tr));
                        } else {
                            td.textContent = (value === null || value === undefined) ? "" : value;
                        }
                        tbr.appendChild(td);
                    });
                    tbody.appendChild(tbr);
                });
                table.appendChild(tbody);
                return table;
            }

            function renderAnomalies(list) {
                var box = document.getElementById("anomalies");
                box.innerHTML = "";
                if (!list.length) {
                    var p = document.createElement("p");
                    p.className = "empty-note";
                    p.textContent = "No anomalies detected.";
                    box.appendChild(p);
                    return;
                }
                box.appendChild(simpleTable(
                    [
                        { label: "Type",     key: "type" },
                        { label: "Location", key: "location" },
                        { label: "Severity", key: "severity", render: function (v) { return badge(v, "badge-" + v); } },
                        { label: "Explanation", key: "reason" }
                    ],
                    list
                ));
            }

            function renderCorrelations(list) {
                var box = document.getElementById("correlations");
                box.innerHTML = "";
                if (!list.length) {
                    var p = document.createElement("p");
                    p.className = "empty-note";
                    p.textContent = "No possible correlations detected.";
                    box.appendChild(p);
                    return;
                }
                box.appendChild(simpleTable(
                    [
                        { label: "Location",  key: "location" },
                        { label: "Events",    key: "events", render: function (v) { return document.createTextNode(v.join(" + ")); } },
                        { label: "Time Diff", key: "time_difference_minutes", render: function (v) { return document.createTextNode(v + " min"); } },
                        { label: "Explanation", key: "message" },
                        { label: "Label",     key: "label", render: function () { return badge("Possible Correlation", "badge-MODERATE"); } }
                    ],
                    list
                ));
            }

            fetch("api/analyze.php")
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        messageEl.textContent = "Error: " + (data.error || "analysis failed");
                        return;
                    }

                    messageEl.textContent = data.notice || "";
                    pulseEl.className = "pulse-label pulse-" + data.area_pulse;
                    pulseEl.textContent = data.area_pulse;
                    timeEl.textContent = "Analysis time: " + data.analysis_time;

                    renderAnomalies(data.anomalies);
                    renderCorrelations(data.correlations);
                })
                .catch(function (err) {
                    messageEl.textContent = "Could not load analysis: " + err.message;
                    pulseEl.textContent = "n/a";
                });
        })();
    </script>
</body>
</html>