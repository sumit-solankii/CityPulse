<?php
/**
 * CityPulse - Landing Page
 * Real-Time Civic Pulse for Smarter Neighborhoods.
 * Base frontend foundation only (no APIs, DB, or logic yet).
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CityPulse - Real-Time Civic Pulse for Smarter Neighborhoods">
    <title>CityPulse | Real-Time Civic Pulse for Smarter Neighborhoods</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="landing">
    <nav class="navbar">
        <a class="navbar-brand" href="index.php">
            <span class="brand-dot"></span> CityPulse
        </a>
        <ul class="nav-links">
            <li><a href="index.php" class="active">Home</a></li>
            <li><a href="dashboard.php">City Pulse</a></li>
        </ul>
    </nav>

    <main class="hero">
        <h1 class="hero-title">CityPulse</h1>
        <p class="hero-tagline">Real-Time Civic Pulse for Smarter Neighborhoods</p>
        <p class="hero-description">
            CityPulse combines weather, traffic, air quality, and civic incident data
            into one clear view of your neighborhood. It surfaces unusual activity and
            possible correlations so communities can respond faster and plan smarter.
        </p>
        <a class="btn-primary" href="dashboard.php">View City Pulse</a>
    </main>

    <footer class="footer">
        <p>CityPulse &copy; <span data-year></span>. Built for smarter neighborhoods.</p>
    </footer>

    <script src="assets/js/main.js"></script>
</body>
</html>