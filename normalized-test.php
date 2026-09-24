<?php
/**
 * CityPulse - Normalized Data Test Page (Step 5)
 *
 * Fetches the combined, normalized feed from api/normalized-data.php
 * and shows the summary plus one common table for all sources.
 *
 * How to open:
 *   http://localhost/CityPulse/normalized-test.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Normalized Data Test | CityPulse</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.5rem 0 2rem;
            font-size: 0.9rem;
        }
        .data-table th,
        .data-table td {
            border: 1px solid #26262b;
            padding: 0.45rem 0.6rem;
            text-align: left;
        }
        .data-table th {
            background: #131316;
            color: #facc15;
        }
        .data-table td {
            color: #ffffff;
            vertical-align: top;
        }
        .data-table tbody tr:nth-child(even) td {
            background: #101013;
        }
        .summary {
            padding: 0.9rem 1.1rem;
            border: 1px solid #26262b;
            border-radius: 10px;
            background: #131316;
            margin-bottom: 1.5rem;
            color: #9ca3af;
        }
        .summary strong { color: #ffffff; }
        .error { color: #f87171; }
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
            <li><a href="normalized-test.php" class="active">Normalized</a></li>
        </ul>
    </nav>

    <main class="page">
        <h1>Normalized Data Test</h1>
        <p class="muted">Weather + traffic + incident data through one common API (api/normalized-data.php)</p>

        <div id="summary" class="summary">Loading data&hellip;</div>

        <h2>Normalized Records</h2>
        <div id="records-table"></div>
    </main>

    <footer class="footer">
        <p>CityPulse &copy; <span data-year></span>. Built for smarter neighborhoods.</p>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
        (function () {
            "use strict";

            var summaryEl = document.getElementById("summary");
            var tableEl = document.getElementById("records-table");

            function buildTable(rows) {
                var table = document.createElement("table");
                table.className = "data-table";

                var headers = [
                    { label: "Source",      key: "source" },
                    { label: "Event Type",  key: "event_type" },
                    { label: "Location",    key: "location" },
                    { label: "Value",       key: "value" },
                    { label: "Severity",    key: "severity" },
                    { label: "Timestamp",   key: "timestamp" }
                ];

                var thead = document.createElement("thead");
                var headerRow = document.createElement("tr");
                headers.forEach(function (h) {
                    var th = document.createElement("th");
                    th.textContent = h.label;
                    headerRow.appendChild(th);
                });
                thead.appendChild(headerRow);
                table.appendChild(thead);

                var tbody = document.createElement("tbody");
                rows.forEach(function (row) {
                    var tr = document.createElement("tr");
                    headers.forEach(function (h) {
                        var td = document.createElement("td");
                        var value = row[h.key];
                        td.textContent = (value === null || value === undefined) ? "" : value;
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                return table;
            }

            fetch("api/normalized-data.php")
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        summaryEl.className = "summary error";
                        summaryEl.textContent = "Error: " + data.error;
                        return;
                    }

                    var s = data.summary;
                    summaryEl.innerHTML =
                        "Total records: <strong>" + s.total_records + "</strong>" +
                        " &middot; Weather: <strong>" + s.weather_record_count + "</strong>" +
                        " &middot; Traffic: <strong>" + s.traffic_record_count + "</strong>" +
                        " &middot; Incidents: <strong>" + s.incident_record_count + "</strong>" +
                        " &middot; Latest timestamp: <strong>" + (s.latest_timestamp || "n/a") + "</strong>";

                    tableEl.innerHTML = "";
                    if (data.data.length) {
                        tableEl.appendChild(buildTable(data.data));
                    } else {
                        tableEl.textContent = "No normalized data yet.";
                    }
                })
                .catch(function (err) {
                    summaryEl.className = "summary error";
                    summaryEl.textContent = "Could not load data: " + err.message;
                });
        })();
    </script>
</body>
</html>