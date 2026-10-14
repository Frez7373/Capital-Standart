<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function partner_json(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function partner_customer(string $email): array
{
    $stmt = db()->prepare("SELECT id,email,role,status FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || $user['role'] !== 'customer' || $user['status'] !== 'active') {
        throw new RuntimeException('Активный клиент банка с таким email не найден.');
    }
    return $user;
}

function partner_find_account(string $accountNumber): array
{
    $stmt = db()->prepare("SELECT a.id,a.account_number,a.name,a.type,a.currency,a.balance_minor,a.status,
                                  u.id AS owner_id,u.email AS owner_email,u.role AS owner_role,u.status AS owner_status
                           FROM accounts a JOIN users u ON u.id=a.user_id
                           WHERE UPPER(a.account_number)=UPPER(?) LIMIT 1");
    $stmt->execute([$accountNumber]);
    $account = $stmt->fetch();
    if (!$account) throw new RuntimeException('Банковский счёт не найден.');
    if ($account['status'] !== 'active' || $account['owner_status'] !== 'active' || $account['owner_role'] !== 'customer') {
        throw new RuntimeException('Банковский счёт заблокирован или недоступен.');
    }
    return $account;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    partner_json(405, ['success'=>false,'error'=>'method_not_allowed','message'=>'Используйте POST.']);
}

$expectedKey = trim((string)($config['partner_api_key'] ?? ''));
$providedKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($expectedKey === '') {
    partner_json(503, ['success'=>false,'error'=>'api_not_configured','message'=>'Не задан BANK_PARTNER_API_KEY.']);
}
if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    partner_json(401, ['success'=>false,'error'=>'unauthorized','message'=>'Неверный ключ интеграции.']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65536) {
    partner_json(413, ['success'=>false,'error'=>'request_too_large','message'=>'Слишком большой запрос.']);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    partner_json(400, ['success'=>false,'error'=>'invalid_json','message'=>'Ожидается JSON-объект.']);
}

$action = (string)($input['action'] ?? '');
try {
    if ($action === 'list_accounts') {
        $email = mb_strtolower(trim((string)($input['customer_email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Укажите корректный email клиента.');
        $customer = partner_customer($email);
        $stmt = db()->prepare("SELECT account_number,name,type,currency,balance_minor
                               FROM accounts WHERE user_id=? AND status='active' ORDER BY created_at ASC,id ASC");
        $stmt->execute([(int)$customer['id']]);
        $accounts = [];
        foreach ($stmt->fetchAll() as $account) {
            $minor = (int)$account['balance_minor'];
            $accounts[] = [
                'account_number'=>(string)$account['account_number'],
                'name'=>(string)$account['name'],
                'type'=>(string)$account['type'],
                'currency'=>(string)$account['currency'],
                'balance'=>number_format(intdiv($minor, 100) + (($minor % 100) / 100), 2, '.', ''),
            ];
        }
        partner_json(200, ['success'=>true,'accounts'=>$accounts]);
    }

    if ($action === 'issue_fine') {
        $email = mb_strtolower(trim((string)($input['customer_email'] ?? '')));
        $external = trim((string)($input['external_ref'] ?? ''));
        $title = mb_substr(trim((string)($input['title'] ?? 'Штраф ГИБДД')), 0, 160);
        $details = mb_substr(trim((string)($input['details'] ?? '')), 0, 500);
        $plate = mb_substr(trim((string)($input['vehicle_plate'] ?? '')), 0, 20);
        $due = valid_date_or_null((string)($input['due_date'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Укажите корректный email клиента.');
        if ($external === '' || mb_strlen($external) > 50) throw new InvalidArgumentException('Некорректный внешний номер постановления.');
        if ($title === '') throw new InvalidArgumentException('Название штрафа не может быть пустым.');
        if ($due === null) throw new InvalidArgumentException('Укажите корректную дату оплаты.');
        $amount = money_to_minor((string)($input['amount'] ?? ''));
        $customer = partner_customer($email);

        $stmt = db()->prepare("SELECT l.external_bill_number,b.id AS bill_id,b.title,b.amount_minor
                               FROM external_bill_links l JOIN bills b ON b.id=l.internal_bill_id
                               WHERE l.provider='capital_gibdd' AND l.external_bill_number=? LIMIT 1");
        $stmt->execute([$external]);
        $existing = $stmt->fetch();
        if ($existing) {
            partner_json(200, ['success'=>true,'duplicate'=>true,'bill_id'=>(int)$existing['bill_id'],'external_ref'=>$external,'message'=>'Это постановление уже передано в банк.']);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO bills (user_id,title,bill_type,amount_minor,remaining_minor,due_date,status,note,created_by)
                           VALUES (?,?,'fine',?,?,?,'unpaid',?,NULL)")
                ->execute([(int)$customer['id'], $title . ($plate !== '' ? ' · ' . $plate : ''), $amount, $amount, $due, $details !== '' ? $details : null]);
            $billId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO external_bill_links (provider,external_bill_number,internal_bill_id) VALUES ('capital_gibdd',?,?)")
                ->execute([$external, $billId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $stmt = db()->prepare("SELECT internal_bill_id FROM external_bill_links WHERE provider='capital_gibdd' AND external_bill_number=? LIMIT 1");
            $stmt->execute([$external]);
            $existingId = $stmt->fetchColumn();
            if ($existingId) partner_json(200, ['success'=>true,'duplicate'=>true,'bill_id'=>(int)$existingId,'external_ref'=>$external,'message'=>'Это постановление уже передано в банк.']);
            throw $e;
        }
        audit('gibdd_fine_received', 'Получен штраф ГИБДД ' . $external, (int)$customer['id']);
        partner_json(200, ['success'=>true,'bill_id'=>$billId,'external_ref'=>$external,'message'=>'Штраф добавлен в банк.']);
    }

    if ($action === 'vehicle_purchase_transfer') {
        $buyerEmail = mb_strtolower(trim((string)($input['buyer_email'] ?? '')));
        $sellerEmail = mb_strtolower(trim((string)($input['seller_email'] ?? '')));
        $sourceNumber = strtoupper(trim((string)($input['source_account_number'] ?? '')));
        $destinationNumber = strtoupper(trim((string)($input['destination_account_number'] ?? '')));
        $external = trim((string)($input['external_ref'] ?? ''));
        if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL) || !filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email покупателя или продавца некорректен.');
        }
        if (strcasecmp($buyerEmail, $sellerEmail) === 0) throw new InvalidArgumentException('Нельзя оплачивать покупку со своего же счёта.');
        if ($sourceNumber === '' || $destinationNumber === '' || $sourceNumber === $destinationNumber) {
            throw new InvalidArgumentException('Укажите разные счета покупателя и продавца.');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $external)) throw new InvalidArgumentException('Некорректный номер сделки.');

        $amountRaw = $input['amount'] ?? '';
        if (!is_string($amountRaw) && !is_int($amountRaw) && !is_float($amountRaw)) throw new InvalidArgumentException('Некорректная сумма сделки.');
        $amountMinor = money_to_minor((string)$amountRaw);
        $buyer = partner_customer($buyerEmail);
        $seller = partner_customer($sellerEmail);
        $from = partner_find_account($sourceNumber);
        $to = partner_find_account($destinationNumber);
        if ((int)$from['owner_id'] !== (int)$buyer['id'] || strcasecmp((string)$from['owner_email'], $buyerEmail) !== 0) {
            throw new RuntimeException('Выбранный счёт не принадлежит покупателю.');
        }
        if ((int)$to['owner_id'] !== (int)$seller['id'] || strcasecmp((string)$to['owner_email'], $sellerEmail) !== 0) {
            throw new RuntimeException('Счёт получения не принадлежит продавцу.');
        }
        $idempotencyKey = 'gibdd-car:' . $external;
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id,reference,transaction_type,from_account_id,to_account_id,amount_minor
                               FROM transactions WHERE idempotency_key=? LIMIT 1');
        $stmt->execute([$idempotencyKey]);
        $prior = $stmt->fetch();
        if ($prior) {
            if ($prior['transaction_type'] !== 'transfer'
                || (int)$prior['from_account_id'] !== (int)$from['id']
                || (int)$prior['to_account_id'] !== (int)$to['id']
                || (int)$prior['amount_minor'] !== $amountMinor) {
                partner_json(409, ['success'=>false,'error'=>'idempotency_conflict','message'=>'Номер сделки уже использован для другого перевода.']);
            }
            partner_json(200, ['success'=>true,'duplicate'=>true,'transaction_reference'=>(string)$prior['reference'],'message'=>'Перевод этой сделки уже выполнен.']);
        }
        try {
            $txId = post_money_transaction('transfer', (int)$from['id'], (int)$to['id'], $amountMinor,
                'Покупка автомобиля через АвтоКонтроль · ' . $external, (int)$buyer['id'], null, $idempotencyKey);
        } catch (Throwable $e) {
            // Concurrent retries can race on the unique idempotency key. Confirm the committed transaction before returning failure.
            $stmt = $pdo->prepare('SELECT id,reference,transaction_type,from_account_id,to_account_id,amount_minor
                                   FROM transactions WHERE idempotency_key=? LIMIT 1');
            $stmt->execute([$idempotencyKey]);
            $prior = $stmt->fetch();
            if ($prior && $prior['transaction_type'] === 'transfer'
                && (int)$prior['from_account_id'] === (int)$from['id']
                && (int)$prior['to_account_id'] === (int)$to['id']
                && (int)$prior['amount_minor'] === $amountMinor) {
                partner_json(200, ['success'=>true,'duplicate'=>true,'transaction_reference'=>(string)$prior['reference'],'message'=>'Перевод этой сделки уже выполнен.']);
            }
            throw $e;
        }
        $stmt = $pdo->prepare('SELECT reference FROM transactions WHERE id=?');
        $stmt->execute([$txId]);
        $reference = (string)$stmt->fetchColumn();
        audit('vehicle_purchase_transfer', 'Перевод по сделке ' . $external . ' на сумму ' . $amountMinor . ' minor units', (int)$buyer['id']);
        partner_json(200, ['success'=>true,'transaction_reference'=>$reference,'message'=>'Оплата автомобиля выполнена.']);
    }

    partner_json(400, ['success'=>false,'error'=>'unknown_action','message'=>'Неизвестное действие API.']);
} catch (InvalidArgumentException $e) {
    partner_json(422, ['success'=>false,'error'=>'validation_error','message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Capital-Standart partner API: ' . $e->getMessage());
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Внутренняя ошибка банковского API.';
    partner_json(400, ['success'=>false,'error'=>'operation_failed','message'=>$message]);
}
