<?php
/**
 * CityPulse - Database Connection Test
 *
 * A tiny helper page that verifies the database connection works.
 *
 * How to test with XAMPP:
 *   1. Start Apache and MySQL in the XAMPP control panel
 *   2. Import database/schema.sql (phpMyAdmin or mysql CLI)
 *   3. Open this page in your browser:
 *      http://localhost/CityPulse/config/test_connection.php
 *
 * If everything is set up, you should see a green success message
 * and a list of the tables that were created.
 */

require_once __DIR__ . '/database.php';

// Ask MySQL which tables exist in the connected database.
// This also proves the schema was imported correctly.
$tables = [];
$result = mysqli_query($conn, 'SHOW TABLES');
if ($result) {
    while ($row = mysqli_fetch_array($result)) {
        $tables[] = $row[0];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CityPulse - Database Test</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .test-ok { color: #4ade80; font-weight: 700; font-size: 1.15rem; }
        .test-list { list-style: none; padding: 0; margin: 1rem 0 2rem; }
        .test-list li {
            display: inline-block;
            background: #131316;
            border: 1px solid #26262b;
            border-radius: 8px;
            padding: 0.4rem 1rem;
            margin: 0.25rem;
            color: #facc15;
        }
    </style>
</head>
<body>
    <main class="page">
        <h1>Database Connection Test</h1>

        <p class="test-ok">&#10003; Connected successfully!</p>
        <p class="muted">Database: <?= htmlspecialchars(DB_NAME) ?> &middot; Host: <?= htmlspecialchars(DB_HOST) ?></p>

        <h2>Tables found</h2>
        <?php if (!empty($tables)) : ?>
            <ul class="test-list">
                <?php foreach ($tables as $table) : ?>
                    <li><?= htmlspecialchars($table) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p class="muted">No tables yet. Import database/schema.sql to create them.</p>
        <?php endif; ?>

        <p><a class="btn-secondary" href="../index.php">&larr; Back to Home</a></p>
    </main>
</body>
</html>