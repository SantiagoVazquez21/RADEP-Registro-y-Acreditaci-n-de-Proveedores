-- Migration: Add QR columns to empleados_evento and create qr_validation_log
-- Run this with your database tool (mysql < 001_add_qr_columns.sql) or via phpMyAdmin

ALTER TABLE IF EXISTS empleados_evento
  ADD COLUMN IF NOT EXISTS qr_token VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS qr_generated_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS qr_revoked TINYINT(1) NOT NULL DEFAULT 0;

-- Index to speed up token lookups
CREATE INDEX IF NOT EXISTS idx_ee_qr_token ON empleados_evento (qr_token(32));

-- Optional audit table to log validations
CREATE TABLE IF NOT EXISTS qr_validation_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  evento_id INT NULL,
  empleado_id INT NULL,
  token VARCHAR(128) NULL,
  scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  result VARCHAR(50) NOT NULL,
  scanner_ip VARCHAR(45) NULL,
  notes TEXT NULL,
  INDEX (evento_id),
  INDEX (empleado_id),
  INDEX (token(32))
);
