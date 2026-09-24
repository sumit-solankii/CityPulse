<?php
/**
 * CityPulse - Weather Test Page (Step 4)
 *
 * Fetches the current Jaipur weather from api/weather.php and
 * displays it in a simple card.
 *
 * How to open:
 *   http://localhost/CityPulse/weather-test.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weather Test | CityPulse</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .weather-card {
            border: 1px solid #26262b;
            border-radius: 10px;
            background: #131316;
            padding: 1.5rem 2rem;
            margin: 1rem 0 2rem;
            max-width: 480px;
        }
        .weather-card h2 { margin-bottom: 1rem; }
        .weather-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.45rem 0;
            border-bottom: 1px solid #1c1c21;
        }
        .weather-row:last-child { border-bottom: none; }
        .weather-row .label { color: #9ca3af; }
        .weather-row .value { color: #ffffff; font-weight: 600; }
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
            <li><a href="weather-test.php" class="active">Weather Test</a></li>
        </ul>
    </nav>

    <main class="page">
        <h1>Jaipur Weather</h1>
        <p class="muted">Fetched live from api/weather.php (Open-Meteo)</p>

        <div id="message">Loading weather&hellip;</div>
        <div id="weather-card" class="weather-card" hidden></div>

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
            var cardEl = document.getElementById("weather-card");

            function row(label, value) {
                var div = document.createElement("div");
                div.className = "weather-row";
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

            function loadWeather() {
                messageEl.textContent = "Loading weather...";
                messageEl.className = "";
                cardEl.hidden = true;

                fetch("api/weather.php")
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) {
                            messageEl.className = "error";
                            messageEl.textContent = "Error: " + data.error;
                            return;
                        }

                        cardEl.innerHTML = "";
                        cardEl.appendChild(row("Location", data.location));
                        cardEl.appendChild(row("Temperature", data.temperature + " °C"));
                        cardEl.appendChild(row("Rainfall", data.rainfall + " mm"));
                        cardEl.appendChild(row("Wind Speed", data.wind_speed + " km/h"));
                        cardEl.appendChild(row("Condition", data.weather_condition));
                        cardEl.appendChild(row("Severity", data.severity));
                        cardEl.appendChild(row("Recorded At", data.recorded_at));

                        var statusLine = document.createElement("p");
                        statusLine.className = "status-line";
                        statusLine.textContent = data.stored
                            ? "A new weather record was saved to the database."
                            : "Weather data was fetched (no new database record - one was saved recently).";
                        cardEl.appendChild(statusLine);

                        cardEl.hidden = false;
                        messageEl.textContent = "";
                    })
                    .catch(function (err) {
                        messageEl.className = "error";
                        messageEl.textContent = "Could not load weather: " + err.message;
                    });
            }

            document.getElementById("refresh-btn").addEventListener("click", loadWeather);
            loadWeather();
        })();
    </script>
</body>
</html>