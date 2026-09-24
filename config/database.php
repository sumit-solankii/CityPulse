<?php
/**
 * CityPulse - Database Connection
 *
 * Creates a single reusable mysqli connection to the local
 * XAMPP MySQL database.
 *
 * How to use this file in any other PHP script:
 *
 *     require_once __DIR__ . '/../config/database.php';
 *
 * After including it, the $conn variable holds the connection
 * and can be passed to mysqli_query() and friends.
 */

// ----- Local XAMPP development settings -----
// NOTE: These are development-only credentials for your local machine.
// Never print them in the frontend, and never commit real passwords.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'citypulse');

// Create the connection (host, user, password, database name).
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// If the connection failed, log the detailed error for developers,
// but only show a friendly message to users (no credentials leak).
if (!$conn) {
    error_log('CityPulse database connection failed: ' . mysqli_connect_error());
    die('Database connection failed. Make sure MySQL is running in XAMPP and the "citypulse" database exists (import database/schema.sql).');
}

// Use UTF-8 so special characters (emoji, accents, non-Latin text) are stored correctly.
mysqli_set_charset($conn, 'utf8mb4');