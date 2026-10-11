<?php
declare(strict_types=1);

function gibdd_api_request(string $method, string $path, ?array $payload = null): array
{
    $cfg = $GLOBALS['config'] ?? [];
    $base = trim((string)($cfg['gibdd_api_url'] ?? ''));
    $token = trim((string)($cfg['gibdd_api_token'] ?? ''));
    if ($base === '' || $token === '') {
        throw new RuntimeException('Интеграция ГИБДД не настроена: задайте GIBDD_API_URL и GIBDD_API_TOKEN.');
    }
    $url = $base . $path;
    $headers = "Authorization: Bearer " . $token . "\r\nAccept: application/json\r\n";
    $content = '';
    if ($payload !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $content,
        'timeout' => 12,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) throw new RuntimeException('Не удалось подключиться к API ГИБДД.');
    $status = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) $status = (int)$m[1];
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['ok'])) {
        throw new RuntimeException(is_array($data) ? (string)($data['message'] ?? $data['error'] ?? 'Ошибка API ГИБДД.') : 'API ГИБДД вернул некорректный ответ.');
    }
    return $data;
}

function sync_gibdd_bills(): array
{
    $result = ['imported' => 0, 'skipped_unmapped' => 0, 'errors' => 0];
    $data = gibdd_api_request('GET', '/api/capital_standard.php?action=pending');
    $pdo = db();
    foreach (($data['bills'] ?? []) as $b) {
        try {
            $external = trim((string)($b['bill_number'] ?? ''));
            $customerKey = trim((string)($b['bank_customer_id'] ?? ''));
            $amount = (int)round(((float)($b['amount'] ?? 0)) * 100);
            if ($external === '' || $customerKey === '' || $amount < 1) {
                $result['skipped_unmapped']++;
                continue;
            }
            // In GIBDD, bank_customer_id must equal the customer's email in Capital-Standart.
            $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = "customer" LIMIT 1');
            $st->execute([$customerKey]);
            $customer = $st->fetch();
            if (!$customer) {
                $result['skipped_unmapped']++;
                continue;
            }
            $exists = $pdo->prepare("SELECT id FROM external_bill_links WHERE provider='capital_gibdd' AND external_bill_number=?");
            $exists->execute([$external]);
            if ($exists->fetchColumn()) continue;

            $title = 'Штраф ГИБДД · ' . (string)($b['plate'] ?? '') . ' · ' . (string)($b['fine_number'] ?? $external);
            $note = trim((string)($b['article'] ?? '') . ' — ' . (string)($b['description'] ?? ''));
            if (!empty($b['due_date'])) $note .= ' · Срок оплаты: ' . $b['due_date'];
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO bills (user_id,title,bill_type,amount_minor,remaining_minor,due_date,status,note,created_by) VALUES (?,?,'fine',?,?,?,'unpaid',?,NULL)");
            $ins->execute([(int)$customer['id'], mb_substr($title,0,160), $amount, $amount, $b['due_date'] ?: null, mb_substr($note,0,500)]);
            $internalId = (int)$pdo->lastInsertId();
            $link = $pdo->prepare("INSERT INTO external_bill_links (provider,external_bill_number,internal_bill_id) VALUES ('capital_gibdd',?,?)");
            $link->execute([$external, $internalId]);
            $pdo->commit();
            $result['imported']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['errors']++;
            error_log('GIBDD bill sync: ' . $e->getMessage());
        }
    }
    return $result;
}

function sync_paid_gibdd_bills(): int
{
    $pdo = db();
    $st = $pdo->query("SELECT l.id,l.external_bill_number,t.reference FROM external_bill_links l JOIN bills b ON b.id=l.internal_bill_id LEFT JOIN transactions t ON t.related_bill_id=b.id AND t.transaction_type IN ('fine_payment','bill_payment','auto_bill_payment') WHERE b.status='paid' AND l.external_status<>'paid' GROUP BY l.id,l.external_bill_number,t.reference ORDER BY l.id LIMIT 100");
    $done = 0;
    foreach ($st->fetchAll() as $row) {
        try {
            $paymentId = (string)($row['reference'] ?: ('KS-PAY-' . $row['id']));
            gibdd_api_request('POST', '/api/capital_standard.php', [
                'action' => 'mark_paid',
                'bill_number' => $row['external_bill_number'],
                'payment_id' => $paymentId,
            ]);
            $upd = $pdo->prepare("UPDATE external_bill_links SET external_status='paid',external_payment_id=?,last_error=NULL WHERE id=?");
            $upd->execute([$paymentId, (int)$row['id']]);
            $done++;
        } catch (Throwable $e) {
            $upd = $pdo->prepare("UPDATE external_bill_links SET external_status='sync_error',last_error=? WHERE id=?");
            $upd->execute([mb_substr($e->getMessage(),0,255),(int)$row['id']]);
            error_log('GIBDD payment confirmation: ' . $e->getMessage());
        }
    }
    return $done;
}
