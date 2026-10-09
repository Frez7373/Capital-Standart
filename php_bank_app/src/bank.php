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
        $fullyPaid = $remaining === 0;
        if ($fullyPaid && bank_gibdd_link_table_exists()) {
            $pdo->prepare("UPDATE external_bill_links SET payment_id=?, callback_status='pending', callback_last_error=NULL WHERE bank_bill_id=? AND callback_status <> 'sent'")
                ->execute([$ref, $billId]);
        }
        $pdo->commit();
        if ($fullyPaid) {
            try { notify_gibdd_callbacks(1); }
            catch (Throwable $callbackError) { error_log('Капитал-Стандарт: ГИБДД callback failed: ' . $callbackError->getMessage()); }
        }
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

/** Return true when the external payment mapping migration is installed. */
function bank_gibdd_link_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $stmt = db()->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='external_bill_links' LIMIT 1");
        $stmt->execute();
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        $exists = false;
    }
    return $exists;
}

function gibdd_integration_enabled(): bool
{
    global $config;
    return trim((string)($config['gibdd_api_url'] ?? '')) !== ''
        && trim((string)($config['gibdd_api_token'] ?? '')) !== '';
}

/** Authenticated request to AutoControl 200. */
function gibdd_api_request(string $method, string $action, ?array $payload = null): array
{
    global $config;
    $baseUrl = trim((string)($config['gibdd_api_url'] ?? ''));
    $token = trim((string)($config['gibdd_api_token'] ?? ''));
    if ($baseUrl === '' || $token === '') throw new RuntimeException('Не настроены GIBDD_API_URL и GIBDD_API_TOKEN.');
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    $url = $baseUrl . $separator . 'action=' . rawurlencode($action);
    $headers = "Authorization: Bearer " . $token . "\r\nAccept: application/json\r\n";
    $options = ['method' => strtoupper($method), 'header' => $headers, 'timeout' => 5, 'ignore_errors' => true];
    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('Не удалось сформировать запрос к ГИБДД.');
        $options['header'] .= "Content-Type: application/json\r\n";
        $options['content'] = $json;
    }
    $context = stream_context_create(['http' => $options]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $headerLine) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $m)) $status = (int)$m[1];
    }
    if ($raw === false) throw new RuntimeException('API ГИБДД недоступен по адресу ' . $baseUrl . '.');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new RuntimeException('API ГИБДД вернул некорректный JSON.');
    if ($status < 200 || $status >= 300 || empty($decoded['ok'])) {
        throw new RuntimeException('API ГИБДД: ' . mb_substr((string)($decoded['message'] ?? ('HTTP ' . $status)), 0, 220));
    }
    return $decoded;
}

/** Convert RUB decimal to integer kopecks without floating-point arithmetic. */
function gibdd_money_to_minor(mixed $value): int
{
    $raw = trim(str_replace([' ', ','], ['', '.'], (string)$value));
    if (!preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $raw, $m)) throw new RuntimeException('В начислении ГИБДД указана некорректная сумма.');
    $minor = ((int)$m[1] * 100) + (int)str_pad((string)($m[2] ?? ''), 2, '0');
    if ($minor <= 0 || $minor > 9000000000000000) throw new RuntimeException('Сумма штрафа вне допустимого диапазона.');
    return $minor;
}

/**
 * Pull fines from AutoControl 200 and create matching bank bills.
 * The GIBDD owner's bank_customer_id must be the numeric users.id in this bank.
 */
function sync_gibdd_fines(): array
{
    if (!gibdd_integration_enabled()) return ['enabled'=>false,'created'=>0,'skipped'=>0,'errors'=>0];
    if (!bank_gibdd_link_table_exists()) throw new RuntimeException('Не установлена миграция database/migrations/20261009_gibdd_fine_integration.sql.');
    $response = gibdd_api_request('GET', 'pending');
    $created = 0; $skipped = 0; $errors = 0;
    foreach (($response['bills'] ?? []) as $fine) {
        $externalNumber = trim((string)($fine['bill_number'] ?? ''));
        try {
            if ($externalNumber === '' || strlen($externalNumber) > 50) throw new RuntimeException('Пустой или некорректный номер начисления.');
            $existing = db()->prepare("SELECT id FROM external_bill_links WHERE provider='autocontrol200' AND external_bill_number=? LIMIT 1");
            $existing->execute([$externalNumber]);
            if ($existing->fetchColumn()) { $skipped++; continue; }
            $customerRaw = trim((string)($fine['customer_id'] ?? ''));
            if (!preg_match('/^\d{1,20}$/', $customerRaw) || (int)$customerRaw < 1) throw new RuntimeException('В профиле владельца ГИБДД не указан числовой ID клиента банка.');
            $bankUserId = (int)$customerRaw;
            $customer = db()->prepare("SELECT id FROM users WHERE id=? AND role='customer' AND status='active' LIMIT 1");
            $customer->execute([$bankUserId]);
            if (!$customer->fetchColumn()) throw new RuntimeException('Клиент банка #' . $bankUserId . ' не найден или заблокирован.');
            $amountMinor = gibdd_money_to_minor($fine['amount'] ?? '');
            $fineNumber = trim((string)($fine['fine_number'] ?? ''));
            $plate = trim((string)($fine['plate'] ?? ''));
            $title = mb_substr('Штраф ГИБДД ' . $fineNumber . ' · ' . $plate, 0, 160);
            $dueDate = (string)($fine['due_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || !checkdate((int)substr($dueDate,5,2),(int)substr($dueDate,8,2),(int)substr($dueDate,0,4))) $dueDate = null;
            $note = mb_substr(implode(' · ', array_filter([
                'Постановление ' . $fineNumber,
                'Автомобиль: ' . $plate . ' ' . trim((string)($fine['make'] ?? '') . ' ' . (string)($fine['model'] ?? '')),
                'Статья: ' . trim((string)($fine['article'] ?? '')),
                'Описание: ' . trim((string)($fine['description'] ?? '')),
                'Взыскатель: ' . trim((string)($fine['creditor'] ?? 'АвтоКонтроль 200 / ГИБДД')),
                'Начисление: ' . $externalNumber,
            ], static fn($part) => trim($part) !== '')), 0, 500);
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $check = $pdo->prepare("SELECT id FROM external_bill_links WHERE provider='autocontrol200' AND external_bill_number=? FOR UPDATE");
                $check->execute([$externalNumber]);
                if ($check->fetchColumn()) { $pdo->commit(); $skipped++; continue; }
                $pdo->prepare("INSERT INTO bills (user_id,title,bill_type,amount_minor,remaining_minor,due_date,status,note,created_by) VALUES (?,?,'fine',?,?,?,'unpaid',?,NULL)")
                    ->execute([$bankUserId,$title,$amountMinor,$amountMinor,$dueDate,$note]);
                $bankBillId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO external_bill_links (provider,external_bill_number,external_fine_number,bank_bill_id,bank_user_id,vehicle_plate) VALUES ('autocontrol200',?,?,?,?,?)")
                    ->execute([$externalNumber,$fineNumber,$bankBillId,$bankUserId,$plate]);
                $pdo->commit();
                audit('gibdd_fine_received', 'Получено начисление ГИБДД ' . $externalNumber . ' для клиента #' . $bankUserId, null);
                $created++;
            } catch (Throwable $inner) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $inner;
            }
        } catch (Throwable $e) {
            $errors++;
            error_log('Капитал-Стандарт: импорт штрафа ГИБДД ' . $externalNumber . ' не выполнен: ' . $e->getMessage());
        }
    }
    return ['enabled'=>true,'created'=>$created,'skipped'=>$skipped,'errors'=>$errors];
}

/** Send payment receipts to GIBDD. Failed callbacks remain queued for cron retry. */
function notify_gibdd_callbacks(int $limit = 50): int
{
    if (!gibdd_integration_enabled() || !bank_gibdd_link_table_exists()) return 0;
    $limit = max(1, min(200, $limit));
    $links = db()->query("SELECT id,external_bill_number,payment_id FROM external_bill_links WHERE callback_status IN ('pending','failed') AND payment_id IS NOT NULL ORDER BY id ASC LIMIT " . $limit)->fetchAll();
    $sent = 0;
    foreach ($links as $link) {
        $linkId = (int)$link['id'];
        try {
            gibdd_api_request('POST', 'mark_paid', ['action'=>'mark_paid','bill_number'=>(string)$link['external_bill_number'],'payment_id'=>(string)$link['payment_id']]);
            db()->prepare("UPDATE external_bill_links SET callback_status='sent',callback_attempts=callback_attempts+1,callback_last_error=NULL,callback_sent_at=NOW() WHERE id=?")->execute([$linkId]);
            $sent++;
        } catch (Throwable $e) {
            db()->prepare("UPDATE external_bill_links SET callback_status='failed',callback_attempts=callback_attempts+1,callback_last_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,500),$linkId]);
            error_log('Капитал-Стандарт: подтверждение оплаты ГИБДД для связи #' . $linkId . ' отложено: ' . $e->getMessage());
        }
    }
    return $sent;
}
