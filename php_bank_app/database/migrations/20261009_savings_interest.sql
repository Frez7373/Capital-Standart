USE cci_bank;

ALTER TABLE transactions
    MODIFY transaction_type ENUM(
        'transfer','deposit','withdrawal','salary','bill_payment','fine_payment',
        'admin_credit','admin_debit','auto_transfer','auto_bill_payment','interest_credit'
    ) NOT NULL;

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
