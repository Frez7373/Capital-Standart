<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $stored === '' || !hash_equals($stored, $sent)) {
        http_response_code(419);
        render_error_page(419, 'Форма устарела', 'Обновите страницу и повторите действие.');
        exit;
    }
}

function money_to_minor(string $input): int
{
    $input = trim(str_replace([' ', ','], ['', '.'], $input));
    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $input)) {
        throw new InvalidArgumentException('Введите сумму в формате 1250 или 1250.50.');
    }
    [$whole, $fraction] = array_pad(explode('.', $input, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');
    $minor = ((int)$whole * 100) + (int)$fraction;
    if ($minor <= 0) throw new InvalidArgumentException('Сумма должна быть больше нуля.');
    if ($minor > 9000000000000000) throw new InvalidArgumentException('Слишком большая сумма.');
    return $minor;
}

function money(int|string|null $minor, string $currency = 'RUB'): string
{
    $value = (int)($minor ?? 0);
    $negative = $value < 0;
    $value = abs($value);
    $symbol = ['RUB' => '₽', 'EUR' => '€', 'SEK' => 'SEK', 'USD' => '$'][$currency] ?? $currency;
    return ($negative ? '−' : '') . number_format(intdiv($value, 100), 0, ',', ' ') . ',' . str_pad((string)($value % 100), 2, '0', STR_PAD_LEFT) . ' ' . e($symbol);
}

function account_number(): string
{
    return 'KS' . date('y') . strtoupper(bin2hex(random_bytes(5)));
}

function valid_date_or_null(string $date): ?string
{
    if ($date === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date ? $date : null;
}

function post_only(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        render_error_page(405, 'Метод не поддерживается', 'Для этого действия используйте форму на странице.');
        exit;
    }
    verify_csrf();
}

function frequency_label(string $freq): string
{
    return ['once'=>'Один раз', 'daily'=>'Ежедневно', 'weekly'=>'Еженедельно', 'monthly'=>'Ежемесячно'][$freq] ?? $freq;
}

function transaction_type_detail_label(string $type): string
{
    return [
        'transfer' => 'Перевод',
        'deposit' => 'Пополнение',
        'withdrawal' => 'Снятие',
        'salary' => 'Заработная плата',
        'bill_payment' => 'Оплата счёта',
        'fine_payment' => 'Оплата штрафа',
        'admin_credit' => 'Пополнение банком',
        'admin_debit' => 'Списание банком',
        'auto_transfer' => 'Регулярный перевод',
        'auto_bill_payment' => 'Автоматическая оплата',
        'interest_credit' => 'Начисление процентов',
    ][$type] ?? 'Операция';
}

function transaction_label(array $tx, int $viewerId): string
{
    $fromOwner = (int)($tx['from_owner_id'] ?? 0);
    $toOwner = (int)($tx['to_owner_id'] ?? 0);
    $from = (int)($tx['from_account_id'] ?? 0);
    $to = (int)($tx['to_account_id'] ?? 0);
    if ($tx['transaction_type'] === 'interest_credit') return 'Начисление процентов';
    if (in_array($tx['transaction_type'], ['deposit','salary','admin_credit'], true)) return 'Пополнение';
    if (in_array($tx['transaction_type'], ['withdrawal','admin_debit'], true)) return 'Списание';
    if (in_array($tx['transaction_type'], ['bill_payment','fine_payment','auto_bill_payment'], true)) return 'Оплата счёта';
    if ($toOwner === $viewerId && $fromOwner !== $viewerId) return 'Входящий перевод';
    if ($fromOwner === $viewerId && $toOwner !== $viewerId) return 'Исходящий перевод';
    if ($tx['transaction_type'] === 'auto_transfer') return $from ? 'Автоперевод' : 'Перевод';
    return 'Операция';
}
