CREATE DATABASE IF NOT EXISTS cci_bank CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cci_bank;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer','banker') NOT NULL DEFAULT 'customer',
    status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_name (last_name, first_name),
    INDEX idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_number VARCHAR(24) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type ENUM('checking','savings') NOT NULL DEFAULT 'checking',
    currency CHAR(3) NOT NULL DEFAULT 'RUB',
    balance_minor BIGINT NOT NULL DEFAULT 0,
    status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_accounts_user (user_id),
    INDEX idx_accounts_status (status),
    CHECK (balance_minor >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL UNIQUE,
    idempotency_key VARCHAR(100) NULL UNIQUE,
    transaction_type ENUM('transfer','deposit','withdrawal','salary','bill_payment','fine_payment','admin_credit','admin_debit','auto_transfer','auto_bill_payment','interest_credit') NOT NULL,
    from_account_id BIGINT UNSIGNED NULL,
    to_account_id BIGINT UNSIGNED NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    related_bill_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transactions_from FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_to FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_transactions_from (from_account_id, created_at),
    INDEX idx_transactions_to (to_account_id, created_at),
    INDEX idx_transactions_created (created_at),
    INDEX idx_transactions_bill (related_bill_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS savings_interest_accruals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    period_month CHAR(7) NOT NULL,
    balance_before_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    annual_rate_bps INT UNSIGNED NOT NULL,
    interest_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_interest_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    UNIQUE KEY uq_savings_interest_account_month (account_id, period_month),
    INDEX idx_savings_interest_period (period_month)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    bill_type ENUM('fine','utility','invoice','other') NOT NULL DEFAULT 'invoice',
    amount_minor BIGINT UNSIGNED NOT NULL,
    remaining_minor BIGINT UNSIGNED NOT NULL,
    due_date DATE NULL,
    status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
    note VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    CONSTRAINT fk_bills_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_bills_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_bills_user_status (user_id, status),
    INDEX idx_bills_due (due_date)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS recurring_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    rule_type ENUM('transfer','bill_autopay') NOT NULL,
    source_account_id BIGINT UNSIGNED NOT NULL,
    destination_account_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    amount_minor BIGINT UNSIGNED NULL,
    frequency ENUM('daily','weekly','monthly') NOT NULL,
    next_run_at DATETIME NOT NULL,
    ends_at DATE NULL,
    status ENUM('active','paused','failed','completed') NOT NULL DEFAULT 'active',
    last_error VARCHAR(255) NULL,
    last_run_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rules_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_rules_source FOREIGN KEY (source_account_id) REFERENCES accounts(id),
    CONSTRAINT fk_rules_destination FOREIGN KEY (destination_account_id) REFERENCES accounts(id),
    INDEX idx_rules_due (status, next_run_at),
    INDEX idx_rules_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scheduled_operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    operation_type ENUM('salary','credit','debit') NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    frequency ENUM('once','daily','weekly','monthly') NOT NULL,
    next_run_at DATETIME NOT NULL,
    status ENUM('active','paused','failed','completed') NOT NULL DEFAULT 'active',
    last_error VARCHAR(255) NULL,
    last_run_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_scheduled_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    CONSTRAINT fk_scheduled_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_scheduled_due (status, next_run_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details VARCHAR(500) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_actor (actor_user_id)
) ENGINE=InnoDB;
