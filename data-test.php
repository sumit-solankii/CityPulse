<?php
/**
 * CityPulse - Data Test Page (Step 3)
 *
 * Fetches the simulated traffic and incident data from the local
 * JSON endpoints and renders it in two simple HTML tables.
 *
 * How to open:
 *   http://localhost/CityPulse/data-test.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Test | CityPulse</title>
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
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .section-title .count {
            color: #9ca3af;
            font-weight: 400;
            font-size: 0.95rem;
        }
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
            <li><a href="data-test.php" class="active">Data Test</a></li>
        </ul>
    </nav>

    <main class="page">
        <h1>CityPulse Data Test</h1>
        <p class="muted">Data is fetched live from api/traffic.php and api/incidents.php</p>

        <div id="summary" class="summary">Loading data&hellip;</div>

        <h2 class="section-title">Traffic Data <span id="traffic-count" class="count"></span></h2>
        <div id="traffic-table"></div>

        <h2 class="section-title">Incidents <span id="incident-count" class="count"></span></h2>
        <div id="incident-table"></div>
    </main>

    <footer class="footer">
        <p>CityPulse &copy; <span data-year></span>. Built for smarter neighborhoods.</p>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
        (function () {
            "use strict";

            var summaryEl = document.getElementById("summary");

            // Build a simple HTML table from header + row definitions.
            function buildTable(headers, rows) {
                var table = document.createElement("table");
                table.className = "data-table";

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

            // Fetch all three endpoints at once and render the tables.
            function load() {
                summaryEl.innerHTML = "Loading data&hellip;";

                Promise.all([
                    fetch("api/traffic.php").then(function (r) {
                        if (!r.ok) throw new Error("api/traffic.php failed");
                        return r.json();
                    }),
                    fetch("api/incidents.php").then(function (r) {
                        if (!r.ok) throw new Error("api/incidents.php failed");
                        return r.json();
                    }),
                    fetch("api/data_status.php").then(function (r) {
                        if (!r.ok) throw new Error("api/data_status.php failed");
                        return r.json();
                    })
                ]).then(function (results) {
                    var traffic = results[0];
                    var incidents = results[1];
                    var status = results[2];

                    // Show the record counts and latest timestamps.
                    summaryEl.innerHTML = "Traffic records: <strong>" + traffic.length + "</strong>" +
                        " &middot; Incident records: <strong>" + incidents.length + "</strong>" +
                        " &middot; Latest traffic time: <strong>" + (status.latest_traffic_record_time || "n/a") + "</strong>" +
                        " &middot; Latest incident time: <strong>" + (status.latest_incident_record_time || "n/a") + "</strong>";

                    document.getElementById("traffic-count").textContent = "(" + traffic.length + " records)";
                    document.getElementById("incident-count").textContent = "(" + incidents.length + " records)";

                    // Render the traffic table.
                    var trafficBox = document.getElementById("traffic-table");
                    trafficBox.innerHTML = "";
                    if (traffic.length) {
                        trafficBox.appendChild(buildTable([
                            { label: "ID",            key: "id" },
                            { label: "Location",      key: "location" },
                            { label: "Latitude",      key: "latitude" },
                            { label: "Longitude",     key: "longitude" },
                            { label: "Delay (min)",   key: "delay_minutes" },
                            { label: "Traffic Level", key: "traffic_level" },
                            { label: "Severity",      key: "severity" },
                            { label: "Recorded At",   key: "recorded_at" }
                        ], traffic));
                    } else {
                        trafficBox.textContent = "No traffic data yet. Run database/seed_data.php first.";
                    }

                    // Render the incidents table.
                    var incidentBox = document.getElementById("incident-table");
                    incidentBox.innerHTML = "";
                    if (incidents.length) {
                        incidentBox.appendChild(buildTable([
                            { label: "ID",           key: "id" },
                            { label: "Location",     key: "location" },
                            { label: "Latitude",     key: "latitude" },
                            { label: "Longitude",    key: "longitude" },
                            { label: "Type",         key: "incident_type" },
                            { label: "Description",  key: "description" },
                            { label: "Severity",     key: "severity" },
                            { label: "Recorded At",  key: "recorded_at" }
                        ], incidents));
                    } else {
                        incidentBox.textContent = "No incident data yet. Run database/seed_data.php first.";
                    }
                }).catch(function (err) {
                    summaryEl.innerHTML = "Error loading data: " + err.message;
                });
            }

            load();
        })();
    </script>
</body>
</html>