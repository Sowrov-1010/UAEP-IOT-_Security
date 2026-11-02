-- UAEP Smart Access & Safety Dashboard (XAMPP / MySQL)
-- In phpMyAdmin, create/import this database, then import this file.

CREATE DATABASE IF NOT EXISTS uaep_smart_access
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE uaep_smart_access;

CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(60) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  role ENUM('access','sensors','camera','other') NOT NULL DEFAULT 'sensors',
  state ENUM('online','offline') NOT NULL DEFAULT 'offline',
  last_seen DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_devices_ip (ip)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id INT UNSIGNED NULL,
  category VARCHAR(30) NOT NULL,
  message VARCHAR(255) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_created_at (created_at),
  CONSTRAINT fk_events_device FOREIGN KEY (device_id)
    REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- One row is stored each time the dashboard server polls a device's /api/status.
-- The raw JSON payload preserves any future or custom sensor fields as well.
CREATE TABLE IF NOT EXISTS sensor_readings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id INT UNSIGNED NULL,
  device_name VARCHAR(60) NOT NULL,
  device_ip VARCHAR(45) NOT NULL,
  sampled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pir_motion TINYINT(1) NULL,
  distance_cm DECIMAL(8,2) NULL,
  ultrasonic_ok TINYINT(1) NULL,
  obstacle TINYINT(1) NULL,
  gas_raw INT NULL,
  gas_alarm TINYINT(1) NULL,
  fire_detected TINYINT(1) NULL,
  fire_level TINYINT NULL,
  temperature_c DECIMAL(6,2) NULL,
  humidity_pct DECIMAL(6,2) NULL,
  dht_ok TINYINT(1) NULL,
  light_raw INT NULL,
  is_dark TINYINT(1) NULL,
  street_led_on TINYINT(1) NULL,
  buzzer_on TINYINT(1) NULL,
  alarm_active TINYINT(1) NULL,
  alarm_reason VARCHAR(40) NULL,
  payload JSON NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sensor_readings_device_time (device_id, sampled_at),
  CONSTRAINT fk_sensor_readings_device FOREIGN KEY (device_id)
    REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB;
