-- AutoControl 200 / GIBDD fine synchronization for Capital-Standart.
-- Run once against the existing cci_bank database. Fresh installs use schema.sql.
USE cci_bank;

CREATE TABLE IF NOT EXISTS external_bill_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    external_bill_number VARCHAR(50) NOT NULL,
    external_fine_number VARCHAR(50) NOT NULL,
    bank_bill_id BIGINT UNSIGNED NOT NULL,
    bank_user_id BIGINT UNSIGNED NOT NULL,
    vehicle_plate VARCHAR(20) NOT NULL,
    payment_id VARCHAR(120) NULL,
    callback_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    callback_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    callback_last_error VARCHAR(500) NULL,
    callback_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_external_bill_link_bill FOREIGN KEY (bank_bill_id) REFERENCES bills(id) ON DELETE CASCADE,
    UNIQUE KEY uq_external_bill_provider_number (provider, external_bill_number),
    UNIQUE KEY uq_external_bill_bank_bill (bank_bill_id),
    INDEX idx_external_bill_callback (callback_status, callback_attempts)
) ENGINE=InnoDB;
