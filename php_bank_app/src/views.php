<?php
declare(strict_types=1);

function page_header(string $title, ?array $user = null, string $active = ''): void
{
    global $config;
    $appName = $config['app_name'];
    $flashes = take_flashes();
    ?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f7fb">
    <script>try{if(localStorage.getItem('kapital-standart-theme')==='dark')document.documentElement.setAttribute('data-theme','dark')}catch(e){}</script>
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <a class="brand" href="?page=<?= $user ? ($user['role'] === 'banker' ? 'banker' : 'dashboard') : 'login' ?>">
            <span class="brand-mark">КС</span><span><strong><?= e($appName) ?></strong><small>Банк • личный кабинет</small></span>
        </a>
        <?php if ($user): ?>
            <div class="topbar-user"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему"><span data-theme-icon>☾</span><span data-theme-label>Тёмная тема</span></button><span class="avatar"><?= e(mb_strtoupper(mb_substr($user['first_name'], 0, 1))) ?></span><span class="user-name"><?= e($user['first_name'] . ' ' . $user['last_name']) ?><small><?= $user['role'] === 'banker' ? 'Сотрудник банка' : 'Клиент' ?></small></span><a class="logout-link" href="?page=logout">Выйти</a></div>
        <?php else: ?>
            <div class="topbar-auth"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему"><span data-theme-icon>☾</span><span data-theme-label>Тёмная тема</span></button><a href="?page=login">Войти</a><a class="button button-small" href="?page=register">Открыть счёт</a></div>
        <?php endif; ?>
    </header>
    <?php if ($user): ?>
        <nav class="main-nav" aria-label="Главное меню">
            <?php if ($user['role'] === 'banker'): ?>
                <a class="<?= $active==='banker'?'active':'' ?>" href="?page=banker"><span>▦</span> Обзор</a>
                <a class="<?= $active==='customers'?'active':'' ?>" href="?page=banker_customers"><span>♙</span> Клиенты</a>
                <a class="<?= $active==='operations'?'active':'' ?>" href="?page=banker_operations"><span>↗</span> Операции</a>
                <a class="<?= $active==='bills'?'active':'' ?>" href="?page=banker_bills"><span>▤</span> Штрафы и счета</a>
                <a class="<?= $active==='schedules'?'active':'' ?>" href="?page=banker_schedules"><span>◷</span> Автооперации</a>
            <?php else: ?>
                <a class="<?= $active==='dashboard'?'active':'' ?>" href="?page=dashboard"><span>▦</span> Обзор</a>
                <a class="<?= $active==='accounts'?'active':'' ?>" href="?page=accounts"><span>▣</span> Мои счета</a>
                <a class="<?= $active==='transfer'?'active':'' ?>" href="?page=transfer"><span>↗</span> Перевод</a>
                <a class="<?= $active==='bills'?'active':'' ?>" href="?page=bills"><span>▤</span> Счета и штрафы</a>
                <a class="<?= $active==='automation'?'active':'' ?>" href="?page=automation"><span>◷</span> Автоплатежи</a>
                <a class="<?= $active==='history'?'active':'' ?>" href="?page=history"><span>≡</span> История</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <main class="main-content">
        <?php foreach ($flashes as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><span><?= $f['type']==='success'?'✓':'!' ?></span><?= e($f['message']) ?></div><?php endforeach; ?>
<?php
}

function page_footer(): void
{
    global $config;
    ?>
    </main>
    <footer class="site-footer"><span>© <?= date('Y') ?> <?= e($config['app_name']) ?> · Интернет-банк</span><span>Личные финансы под контролем</span></footer>
</div>
<script src="assets/app.js?v=3"></script>
</body>
</html><?php
}

function render_error_page(int $code, string $title, string $message): void
{
    $user = current_user();
    page_header($title, $user);
    echo '<section class="empty-state"><div class="empty-icon">!</div><h1>' . e($title) . '</h1><p>' . e($message) . '</p><a class="button" href="?page=' . ($user ? ($user['role']==='banker'?'banker':'dashboard') : 'login') . '">Вернуться</a></section>';
    page_footer();
}

function page_title(string $eyebrow, string $title, string $subtitle = ''): void
{
    echo '<div class="page-heading"><div><div class="eyebrow">' . e($eyebrow) . '</div><h1>' . e($title) . '</h1>';
    if ($subtitle !== '') echo '<p>' . e($subtitle) . '</p>';
    echo '</div></div>';
}

function status_pill(string $status, ?string $label = null): string
{
    $labels = ['active'=>'Активен','blocked'=>'Заблокирован','unpaid'=>'Не оплачен','partial'=>'Частично оплачен','paid'=>'Оплачен','cancelled'=>'Отменён','paused'=>'Приостановлен','failed'=>'Ошибка','completed'=>'Завершён'];
    $class = in_array($status, ['active','paid','completed'], true) ? 'good' : (in_array($status, ['blocked','failed','cancelled'], true) ? 'bad' : 'pending');
    return '<span class="status-pill ' . $class . '">' . e($label ?? ($labels[$status] ?? $status)) . '</span>';
}

function account_select(array $accounts, string $name = 'account_id', ?int $selected = null, bool $includeBlocked = false): string
{
    $html = '<select name="' . e($name) . '" required><option value="">Выберите счёт</option>';
    foreach ($accounts as $a) {
        if (!$includeBlocked && $a['status'] !== 'active') continue;
        $sel = $selected !== null && (int)$a['id'] === $selected ? ' selected' : '';
        $label = $a['name'] . ' · ' . $a['account_number'] . ' · ' . money((int)$a['balance_minor'], $a['currency']);
        if ($a['status'] !== 'active') $label .= ' (заблокирован)';
        $html .= '<option value="' . (int)$a['id'] . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
}

function account_type_label(string $type): string
{
    return $type === 'savings' ? 'Накопительный' : 'Текущий';
}

function input_field(string $label, string $name, string $type = 'text', string $placeholder = '', array $attrs = []): void
{
    $required = ($attrs['required'] ?? true) ? ' required' : '';
    $min = isset($attrs['min']) ? ' min="' . e($attrs['min']) . '"' : '';
    $max = isset($attrs['max']) ? ' max="' . e($attrs['max']) . '"' : '';
    $step = isset($attrs['step']) ? ' step="' . e($attrs['step']) . '"' : '';
    $value = isset($attrs['value']) ? ' value="' . e($attrs['value']) . '"' : '';
    echo '<label class="field"><span>' . e($label) . '</span><input type="' . e($type) . '" name="' . e($name) . '" placeholder="' . e($placeholder) . '"' . $required . $min . $max . $step . $value . '></label>';
}
