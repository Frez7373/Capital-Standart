<?php
declare(strict_types=1);

function post_money_transaction(string $type, ?int $fromAccountId, ?int $toAccountId, int $amountMinor, string $description, ?int $createdBy, ?int $billId = null, ?string $idempotencyKey = null): int
{
    if ($amountMinor <= 0) throw new InvalidArgumentException('Сумма должна быть больше нуля.');
    if ($fromAccountId === null && $toAccountId === null) throw new InvalidArgumentException('Не указан счёт операции.');
    if ($fromAccountId !== null && $fromAccountId === $toAccountId) throw new InvalidArgumentException('Нельзя переводить деньги на тот же счёт.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($idempotencyKey !== null) {
            $existing = $pdo->prepare('SELECT id FROM transactions WHERE idempotency_key = ? LIMIT 1');
            $existing->execute([$idempotencyKey]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $pdo->commit();
                return (int)$existingId;
            }
        }
        $ids = array_values(array_unique(array_filter([$fromAccountId, $toAccountId], fn($v) => $v !== null)));
        sort($ids, SORT_NUMERIC);
        $accounts = [];
        $stmt = $pdo->prepare('SELECT a.*, u.status AS owner_status FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.id = ? FOR UPDATE');
        foreach ($ids as $id) {
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new RuntimeException('Счёт не найден.');
            $accounts[(int)$id] = $row;
        }
        if ($fromAccountId !== null) {
            $from = $accounts[$fromAccountId];
            if ($from['status'] !== 'active' || $from['owner_status'] !== 'active') throw new RuntimeException('Счёт отправителя заблокирован.');
            if ((int)$from['balance_minor'] < $amountMinor) throw new RuntimeException('Недостаточно средств на счёте.');
        }
        if ($toAccountId !== null) {
            $to = $accounts[$toAccountId];
            if ($to['status'] !== 'active' || $to['owner_status'] !== 'active') throw new RuntimeException('Счёт получателя заблокирован.');
        }
        if ($fromAccountId !== null) {
            $upd = $pdo->prepare('UPDATE accounts SET balance_minor = balance_minor - ? WHERE id = ?');
            $upd->execute([$amountMinor, $fromAccountId]);
        }
        if ($toAccountId !== null) {
            $upd = $pdo->prepare('UPDATE accounts SET balance_minor = balance_minor + ? WHERE id = ?');
            $upd->execute([$amountMinor, $toAccountId]);
        }
        $ref = 'KS-' . strtoupper(bin2hex(random_bytes(8)));
        $ins = $pdo->prepare('INSERT INTO transactions (reference, idempotency_key, transaction_type, from_account_id, to_account_id, amount_minor, description, created_by, related_bill_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$ref, $idempotencyKey, $type, $fromAccountId, $toAccountId, $amountMinor, mb_substr($description, 0, 255), $createdBy, $billId]);
        $txId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $txId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pay_bill(int $billId, int $accountId, int $amountMinor, int $userId, ?int $actorId = null, bool $automatic = false): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bills WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$billId, $userId]);
        $bill = $stmt->fetch();
        if (!$bill || !in_array($bill['status'], ['unpaid','partial'], true)) throw new RuntimeException('Этот счёт уже оплачен или недоступен.');
        $pay = $amountMinor > 0 ? $amountMinor : (int)$bill['remaining_minor'];
        if ($pay <= 0 || $pay > (int)$bill['remaining_minor']) throw new RuntimeException('Неверная сумма оплаты.');
        $stmt = $pdo->prepare('SELECT a.*, u.status AS owner_status FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.id = ? FOR UPDATE');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account || (int)$account['user_id'] !== $userId) throw new RuntimeException('Выберите свой счёт.');
        if ($account['status'] !== 'active' || $account['owner_status'] !== 'active') throw new RuntimeException('Счёт заблокирован.');
        if ((int)$account['balance_minor'] < $pay) throw new RuntimeException('Недостаточно средств для оплаты.');
        $pdo->prepare('UPDATE accounts SET balance_minor = balance_minor - ? WHERE id = ?')->execute([$pay, $accountId]);
        $remaining = (int)$bill['remaining_minor'] - $pay;
        $newStatus = $remaining === 0 ? 'paid' : 'partial';
        $pdo->prepare('UPDATE bills SET remaining_minor = ?, status = ?, paid_at = ? WHERE id = ?')
            ->execute([$remaining, $newStatus, $remaining === 0 ? date('Y-m-d H:i:s') : null, $billId]);
        $ref = 'KS-' . strtoupper(bin2hex(random_bytes(8)));
        $type = $bill['bill_type'] === 'fine' ? 'fine_payment' : ($automatic ? 'auto_bill_payment' : 'bill_payment');
        $desc = ($automatic ? 'Автооплата: ' : 'Оплата: ') . $bill['title'];
        $pdo->prepare('INSERT INTO transactions (reference, transaction_type, from_account_id, to_account_id, amount_minor, description, created_by, related_bill_id) VALUES (?, ?, ?, NULL, ?, ?, ?, ?)')
            ->execute([$ref, $type, $accountId, $pay, mb_substr($desc, 0, 255), $actorId ?? $userId, $billId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function user_accounts(int $userId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM accounts WHERE user_id = ?' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function transaction_history_for_user(int $userId, int $limit = 100): array
{
    $limit = $limit > 0 ? min(500, $limit) : 0;
    $limitSql = $limit > 0 ? ' LIMIT ' . $limit : '';
    $stmt = db()->prepare("SELECT t.*, fa.account_number AS from_number, fa.user_id AS from_owner_id, ta.account_number AS to_number, ta.user_id AS to_owner_id
        FROM transactions t
        LEFT JOIN accounts fa ON fa.id = t.from_account_id
        LEFT JOIN accounts ta ON ta.id = t.to_account_id
        WHERE fa.user_id = ? OR ta.user_id = ?
        ORDER BY t.created_at DESC, t.id DESC" . $limitSql);
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}

function next_run_date(string $frequency, ?DateTimeImmutable $base = null): DateTimeImmutable
{
    $base ??= new DateTimeImmutable('now');
    if ($frequency === 'daily') return $base->modify('+1 day');
    if ($frequency === 'weekly') return $base->modify('+1 week');
    if ($frequency === 'monthly') {
        // Clamp the day to the target month's last day (e.g. Jan 31 -> Feb 28/29).
        $day = (int)$base->format('d');
        $targetMonth = $base->modify('first day of this month')->modify('+1 month');
        $lastDay = (int)$targetMonth->format('t');
        return $targetMonth->setDate((int)$targetMonth->format('Y'), (int)$targetMonth->format('m'), min($day, $lastDay))
            ->setTime((int)$base->format('H'), (int)$base->format('i'), (int)$base->format('s'));
    }
    return $base->modify('+1 day');
}


/**
 * Credits savings interest once per calendar month. Interest is calculated
 * monthly as balance * annual rate / 12, rounded to the nearest minor unit.
 * The unique (account_id, period_month) key and account row lock prevent duplicates.
 */
function process_savings_interest(DateTimeImmutable $now): int
{
    global $config;
    $pdo = db();
    $period = $now->format('Y-m');
    $ratePercent = max(0.0, min(1000.0, (float)($config['savings_annual_rate_percent'] ?? 5.0)));
    $rateBps = (int)round($ratePercent * 100);
    $ids = $pdo->query("SELECT a.id FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.type='savings' AND a.status='active' AND u.status='active' ORDER BY a.id")->fetchAll(PDO::FETCH_COLUMN);
    $processed = 0;

    foreach ($ids as $rawId) {
        $accountId = (int)$rawId;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT a.*, u.status AS owner_status FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.id=? FOR UPDATE');
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();
            if (!$account || $account['type'] !== 'savings' || $account['status'] !== 'active' || $account['owner_status'] !== 'active') {
                $pdo->commit();
                continue;
            }

            $stmt = $pdo->prepare('SELECT id FROM savings_interest_accruals WHERE account_id=? AND period_month=? LIMIT 1');
            $stmt->execute([$accountId, $period]);
            if ($stmt->fetchColumn()) {
                $pdo->commit();
                continue;
            }

            $balance = max(0, (int)$account['balance_minor']);
            // Avoid multiplying a potentially large balance by the rate all at once.
            $interest = intdiv($balance, 120000) * $rateBps
                + intdiv((($balance % 120000) * $rateBps) + 60000, 120000);

            $pdo->prepare('INSERT INTO savings_interest_accruals (account_id, period_month, balance_before_minor, annual_rate_bps, interest_minor) VALUES (?,?,?,?,?)')
                ->execute([$accountId, $period, $balance, $rateBps, $interest]);

            if ($interest > 0) {
                $pdo->prepare('UPDATE accounts SET balance_minor=balance_minor+? WHERE id=?')->execute([$interest, $accountId]);
                $rateLabel = rtrim(rtrim(number_format($ratePercent, 2, '.', ''), '0'), '.');
                $description = 'Проценты по накопительному счёту за ' . $period . ' (' . $rateLabel . '% годовых)';
                $reference = 'KS-' . strtoupper(bin2hex(random_bytes(8)));
                $pdo->prepare("INSERT INTO transactions (reference, transaction_type, from_account_id, to_account_id, amount_minor, description, created_by) VALUES (?, 'interest_credit', NULL, ?, ?, ?, NULL)")
                    ->execute([$reference, $accountId, $interest, mb_substr($description, 0, 255)]);
            }
            $pdo->commit();
            $processed++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Keep one damaged account from stopping interest processing for the others.
            error_log('Капитал-Стандарт: savings interest for account #' . $accountId . ' failed: ' . $e->getMessage());
        }
    }
    return $processed;
}
