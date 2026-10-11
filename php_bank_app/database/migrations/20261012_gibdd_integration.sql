USE cci_bank;

CREATE TABLE IF NOT EXISTS external_bill_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL DEFAULT 'capital_gibdd',
    external_bill_number VARCHAR(50) NOT NULL,
    internal_bill_id BIGINT UNSIGNED NOT NULL,
    external_status ENUM('imported','paid','sync_error') NOT NULL DEFAULT 'imported',
    external_payment_id VARCHAR(120) NULL,
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_external_bill (provider, external_bill_number),
    UNIQUE KEY uq_external_internal_bill (internal_bill_id),
    CONSTRAINT fk_external_bill_internal FOREIGN KEY (internal_bill_id) REFERENCES bills(id) ON DELETE CASCADE
) ENGINE=InnoDB;
