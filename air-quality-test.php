<?php
/**
 * CityPulse - Air Quality Test Page (Step 9)
 *
 * Calls api/air-quality.php and displays the OpenAQ result:
 * location, PM2.5, PM10, NO2, O3, severity, timestamp and status.
 *
 * How to open:
 *   http://localhost/CityPulse/air-quality-test.php
 *
 * Note: air quality needs an OpenAQ API key. Add yours in
 * config/api_config.php (server-side only). Until a key is set,
 * the page shows "Air quality data temporarily unavailable."
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Air Quality Test | CityPulse</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .aq-card {
            border: 1px solid #26262b;
            border-radius: 10px;
            background: #131316;
            padding: 1.5rem 2rem;
            margin: 1rem 0 2rem;
            max-width: 480px;
        }
        .aq-card h2 { margin-bottom: 1rem; }
        .aq-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.45rem 0;
            border-bottom: 1px solid #1c1c21;
        }
        .aq-row:last-child { border-bottom: none; }
        .aq-row .label { color: #9ca3af; }
        .aq-row .value { color: #ffffff; font-weight: 600; }
        .status-line { color: #9ca3af; margin-top: 0.5rem; }
        #message { margin: 1rem 0; }
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
            <li><a href="air-quality-test.php" class="active">Air Quality Test</a></li>
        </ul>
    </nav>

    <main class="page">
        <h1>Jaipur Air Quality</h1>
        <p class="muted">Fetched live from api/air-quality.php (OpenAQ API v3)</p>

        <div id="message">Loading air quality&hellip;</div>
        <div id="aq-card" class="aq-card" hidden></div>

        <button id="refresh-btn" class="btn-secondary" type="button">Refresh</button>
    </main>

    <footer class="footer">
        <p>CityPulse &copy; <span data-year></span>. Built for smarter neighborhoods.</p>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
        (function () {
            "use strict";

            var messageEl = document.getElementById("message");
            var cardEl = document.getElementById("aq-card");

            function row(label, value) {
                var div = document.createElement("div");
                div.className = "aq-row";
                var l = document.createElement("span");
                l.className = "label";
                l.textContent = label;
                var v = document.createElement("span");
                v.className = "value";
                v.textContent = value;
                div.appendChild(l);
                div.appendChild(v);
                return div;
            }

            function loadAirQuality() {
                messageEl.textContent = "Loading air quality...";
                messageEl.className = "";
                cardEl.hidden = true;

                fetch("api/air-quality.php")
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            messageEl.className = "error";
                            messageEl.textContent = data.message || "Air quality data temporarily unavailable.";
                            return;
                        }

                        var m = data.measurements || {};
                        var fmt = function (v) {
                            return (typeof v === "number" && isFinite(v)) ? v + " µg/m³" : "n/a";
                        };

                        cardEl.innerHTML = "";
                        cardEl.appendChild(row("Location", data.location));
                        cardEl.appendChild(row("Station", data.station || "n/a"));
                        cardEl.appendChild(row("PM2.5", fmt(m.pm25)));
                        cardEl.appendChild(row("PM10", fmt(m.pm10)));
                        cardEl.appendChild(row("NO2", fmt(m.no2)));
                        cardEl.appendChild(row("O3", fmt(m.o3)));
                        cardEl.appendChild(row("Severity", data.severity));
                        cardEl.appendChild(row("Recorded At", data.recorded_at));

                        var statusLine = document.createElement("p");
                        statusLine.className = "status-line";
                        statusLine.textContent = data.stored
                            ? "API status: OK - a new reading was saved to the database."
                            : "API status: OK - reading served from the last stored record.";
                        cardEl.appendChild(statusLine);

                        cardEl.hidden = false;
                        messageEl.textContent = "";
                    })
                    .catch(function (err) {
                        messageEl.className = "error";
                        messageEl.textContent = "Could not load air quality: " + err.message;
                    });
            }

            document.getElementById("refresh-btn").addEventListener("click", loadAirQuality);
            loadAirQuality();
        })();
    </script>
</body>
</html>