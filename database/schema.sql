-- ============================================================
-- CityPulse - Database Schema (Step 2)
--
-- Creates the "citypulse" database and the three core tables:
--   1. weather_data  - weather readings per location
--   2. traffic_data  - traffic conditions per location
--   3. incidents     - civic incident / complaint reports
--
-- No sample data is inserted. Simulated civic data will be added
-- in the next step.
--
-- How to run this file:
--   Option A (phpMyAdmin):
--      1. Start Apache + MySQL in the XAMPP control panel
--      2. Open http://localhost/phpmyadmin
--      3. Click "Import", choose this file, click "Go"
--
--   Option B (command line):
--      C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
--
-- Why indexes on (location) and (recorded_at)?
--   Later steps query data by location and time to show the
--   "Area Pulse", so these indexes keep those queries fast.
-- ============================================================

-- 1) The database itself
CREATE DATABASE IF NOT EXISTS citypulse
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE citypulse;

-- ============================================================
-- Table: weather_data
-- Stores weather readings (temperature, rainfall, wind, etc.)
-- taken for different city locations. Used to power the
-- weather part of the Area Pulse.
-- ============================================================
CREATE TABLE IF NOT EXISTS weather_data (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    location         VARCHAR(100)     NOT NULL,                     -- neighborhood / area name (e.g. "Downtown")
    latitude         DECIMAL(9, 6)    NOT NULL,                     -- GPS latitude
    longitude        DECIMAL(9, 6)    NOT NULL,                     -- GPS longitude
    temperature      DECIMAL(4, 1)    NOT NULL,                     -- temperature in degrees Celsius
    rainfall         DECIMAL(5, 2)    NOT NULL DEFAULT 0.00,        -- rainfall in millimetres
    wind_speed       DECIMAL(5, 2)    NOT NULL DEFAULT 0.00,        -- wind speed in km/h
    weather_condition VARCHAR(50)     NOT NULL,                     -- e.g. Clear, Rainy, Stormy, Foggy
    severity         VARCHAR(20)      NOT NULL,                     -- e.g. Low, Moderate, High, Severe
    recorded_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP, -- when the reading was taken
    PRIMARY KEY (id),
    KEY idx_weather_location (location),                            -- fast lookups by location name
    KEY idx_weather_recorded_at (recorded_at),                      -- fast lookups by time
    KEY idx_weather_location_recorded_at (location, recorded_at)    -- fast location + time range queries
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================
-- Table: traffic_data
-- Stores traffic conditions (delays, congestion level) for
-- different city locations. Used to power the traffic part
-- of the Area Pulse.
-- ============================================================
CREATE TABLE IF NOT EXISTS traffic_data (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    location         VARCHAR(100)     NOT NULL,                     -- neighborhood / area name
    latitude         DECIMAL(9, 6)    NOT NULL,                     -- GPS latitude
    longitude        DECIMAL(9, 6)    NOT NULL,                     -- GPS longitude
    delay_minutes    INT UNSIGNED     NOT NULL DEFAULT 0,           -- extra travel time in minutes
    traffic_level    VARCHAR(20)      NOT NULL,                     -- e.g. Light, Moderate, Heavy, Gridlock
    severity         VARCHAR(20)      NOT NULL,                     -- e.g. Low, Moderate, High, Severe
    recorded_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP, -- when the reading was taken
    PRIMARY KEY (id),
    KEY idx_traffic_location (location),
    KEY idx_traffic_recorded_at (recorded_at),
    KEY idx_traffic_location_recorded_at (location, recorded_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================
-- Table: incidents
-- Stores civic incident / complaint reports (potholes, leaks,
-- outages, closures, etc.) submitted for city locations.
-- Used to power the incident part of the Area Pulse.
-- ============================================================
CREATE TABLE IF NOT EXISTS incidents (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    location         VARCHAR(100)     NOT NULL,                     -- neighborhood / area name
    latitude         DECIMAL(9, 6)    NOT NULL,                     -- GPS latitude
    longitude        DECIMAL(9, 6)    NOT NULL,                     -- GPS longitude
    incident_type    VARCHAR(50)      NOT NULL,                     -- e.g. Pothole, Water Leak, Power Outage, Road Closure
    description      TEXT             NULL,                         -- free-text details from the report
    severity         VARCHAR(20)      NOT NULL,                     -- e.g. Low, Moderate, High, Severe
    recorded_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP, -- when the incident was reported
    PRIMARY KEY (id),
    KEY idx_incidents_location (location),
    KEY idx_incidents_recorded_at (recorded_at),
    KEY idx_incidents_location_recorded_at (location, recorded_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ============================================================
-- Table: air_quality_data (Step 9)
-- Stores air-quality readings (PM2.5, PM10, NO2, O3) fetched from
-- the OpenAQ API for city locations.
--   - pm25 is the primary value and is always stored.
--   - pm10 / no2 / o3 are NULL when a station did not report them
--     (a missing pollutant is NOT an error).
--   - severity uses the standard NORMAL / MODERATE / HIGH set.
-- ============================================================
CREATE TABLE IF NOT EXISTS air_quality_data (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    location    VARCHAR(100)  NOT NULL,                     -- neighborhood / area name (e.g. "Jaipur")
    latitude    DECIMAL(9, 6) NOT NULL,                     -- GPS latitude (station or area centre)
    longitude   DECIMAL(9, 6) NOT NULL,                     -- GPS longitude
    pm25        DECIMAL(6, 1) NOT NULL,                     -- PM2.5 in µg/m³ (primary value)
    pm10        DECIMAL(6, 1) NULL,                         -- PM10 in µg/m³ (NULL when unavailable)
    no2         DECIMAL(6, 1) NULL,                         -- NO2 in µg/m³ (NULL when unavailable)
    o3          DECIMAL(6, 1) NULL,                         -- O3 in µg/m³ (NULL when unavailable)
    severity    VARCHAR(20)   NOT NULL,                     -- NORMAL | MODERATE | HIGH
    recorded_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP, -- when the reading was taken
    PRIMARY KEY (id),
    KEY idx_air_quality_location (location),
    KEY idx_air_quality_recorded_at (recorded_at),
    KEY idx_air_quality_location_recorded_at (location, recorded_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;