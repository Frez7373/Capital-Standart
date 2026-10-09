<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$page = (string)($_GET['page'] ?? '');
$user = current_user();

function action_redirect(string $page, string $message, string $type = 'success'): never
{
    flash($type, $message);
    redirect('?page=' . rawurlencode($page));
}

function owned_account(int $accountId, int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM accounts WHERE id = ? AND user_id = ?');
    $stmt->execute([$accountId, $userId]);
    $account = $stmt->fetch();
    if (!$account) throw new RuntimeException('Счёт не найден или не принадлежит вам.');
    return $account;
}

function safe_post_string(string $key, int $max = 200): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'login': {
                $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $attempts = $_SESSION['login_attempts'] ?? ['count'=>0,'since'=>time()];
                if (time() - (int)$attempts['since'] > 600) $attempts = ['count'=>0,'since'=>time()];
                if ((int)$attempts['count'] >= 8) throw new RuntimeException('Слишком много попыток. Подождите 10 минут и попробуйте снова.');
                $stmt = db()->prepare('SELECT id, password_hash, status FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $found = $stmt->fetch();
                if (!$found || !password_verify($password, $found['password_hash'])) {
                    $attempts['count'] = (int)$attempts['count'] + 1;
                    $_SESSION['login_attempts'] = $attempts;
                    throw new RuntimeException('Неверный адрес электронной почты или пароль.');
                }
                if ($found['status'] !== 'active') throw new RuntimeException('Доступ к аккаунту заблокирован. Обратитесь в банк.');
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$found['id'];
                $_SESSION['login_attempts'] = ['count'=>0,'since'=>time()];
                audit('login', 'Успешный вход в систему', (int)$found['id']);
                $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
                $stmt->execute([(int)$found['id']]);
                redirect($stmt->fetchColumn() === 'banker' ? '?page=banker' : '?page=dashboard');
            }
            case 'register': {
                $first = safe_post_string('first_name', 80);
                $last = safe_post_string('last_name', 80);
                $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $password2 = (string)($_POST['password_confirm'] ?? '');
                if ($first === '' || $last === '') throw new RuntimeException('Заполните имя и фамилию.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) throw new RuntimeException('Введите корректный email.');
                if (strlen($password) < 10) throw new RuntimeException('Пароль должен содержать не менее 10 символов.');
                if (!hash_equals($password, $password2)) throw new RuntimeException('Пароли не совпадают.');
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (first_name,last_name,email,password_hash,role,status) VALUES (?,?,?,?,'customer','active')");
                    $stmt->execute([$first, $last, $email, password_hash($password, PASSWORD_DEFAULT)]);
                    $newId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO accounts (user_id,account_number,name,type,currency,balance_minor,status) VALUES (?,?,?,'checking','RUB',0,'active')")
                        ->execute([$newId, account_number(), 'Основной счёт']);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($e instanceof PDOException && (string)$e->getCode() === '23000') throw new RuntimeException('Пользователь с таким email уже зарегистрирован.');
                    throw $e;
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newId;
                audit('register', 'Зарегистрирован новый клиент', $newId);
                redirect('?page=dashboard');
            }
            case 'logout': {
                $oldId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                if ($oldId) audit('logout', 'Выход из системы', $oldId);
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], '', (bool)$params['secure'], (bool)$params['httponly']);
                }
                session_destroy();
                redirect('?page=login');
            }
            case 'create_account': {
                $u = require_login();
                if ($u['role'] !== 'customer') throw new RuntimeException('Это действие доступно клиентам.');
                $type = (string)($_POST['type'] ?? 'checking');
                if (!in_array($type, ['checking','savings'], true)) throw new RuntimeException('Неизвестный тип счёта.');
                $name = safe_post_string('name', 100) ?: ($type === 'savings' ? 'Накопительный счёт' : 'Текущий счёт');
                db()->prepare('INSERT INTO accounts (user_id,account_number,name,type,currency,balance_minor,status) VALUES (?,?,?,?,\'RUB\',0,\'active\')')
                    ->execute([(int)$u['id'], account_number(), $name, $type]);
                audit('account_created', 'Создан счёт типа ' . $type, (int)$u['id']);
                action_redirect('accounts', 'Счёт успешно создан.');
            }
            case 'transfer': {
                $u = require_login();
                if ($u['role'] !== 'customer') throw new RuntimeException('Операция недоступна.');
                $fromId = (int)($_POST['from_account_id'] ?? 0);
                $from = owned_account($fromId, (int)$u['id']);
                $number = strtoupper(trim((string)($_POST['destination_account'] ?? '')));
                $description = safe_post_string('description', 255) ?: 'Перевод между клиентами';
                $amount = money_to_minor((string)($_POST['amount'] ?? ''));
                if ($from['status'] !== 'active') throw new RuntimeException('Исходный счёт заблокирован.');
                $stmt = db()->prepare('SELECT id FROM accounts WHERE account_number = ? LIMIT 1');
                $stmt->execute([$number]);
                $toId = (int)($stmt->fetchColumn() ?: 0);
                if (!$toId) throw new RuntimeException('Счёт получателя не найден. Проверьте номер счёта.');
                post_money_transaction('transfer', $fromId, $toId, $amount, $description, (int)$u['id']);
                audit('transfer', 'Выполнен перевод ' . $amount . ' minor units со счёта ' . $fromId . ' на ' . $toId, (int)$u['id']);
                action_redirect('history', 'Перевод успешно выполнен.');
            }
            case 'pay_bill': {
                $u = require_login();
                if ($u['role'] !== 'customer') throw new RuntimeException('Операция недоступна.');
                $billId = (int)($_POST['bill_id'] ?? 0);
                $accountId = (int)($_POST['account_id'] ?? 0);
                owned_account($accountId, (int)$u['id']);
                pay_bill($billId, $accountId, 0, (int)$u['id']);
                audit('bill_paid', 'Оплачен счёт #' . $billId, (int)$u['id']);
                action_redirect('bills', 'Счёт оплачен.');
            }
            case 'create_rule': {
                $u = require_login();
                if ($u['role'] !== 'customer') throw new RuntimeException('Операция недоступна.');
                $kind = (string)($_POST['rule_type'] ?? 'transfer');
                if (!in_array($kind, ['transfer','bill_autopay'], true)) throw new RuntimeException('Неизвестный тип автоплатежа.');
                $sourceId = (int)($_POST['source_account_id'] ?? 0);
                $source = owned_account($sourceId, (int)$u['id']);
                if ($source['status'] !== 'active') throw new RuntimeException('Выбранный счёт заблокирован.');
                $freq = (string)($_POST['frequency'] ?? 'monthly');
                if (!in_array($freq, ['daily','weekly','monthly'], true)) throw new RuntimeException('Неверная периодичность.');
                $runRaw = trim((string)($_POST['next_run_at'] ?? ''));
                $runDate = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $runRaw);
                if (!$runDate || $runDate < new DateTimeImmutable('now')) throw new RuntimeException('Укажите дату и время запуска в будущем.');
                $endDate = valid_date_or_null((string)($_POST['ends_at'] ?? ''));
                if (($_POST['ends_at'] ?? '') !== '' && $endDate === null) throw new RuntimeException('Неверная дата окончания.');
                if ($endDate && $endDate < date('Y-m-d')) throw new RuntimeException('Дата окончания не может быть в прошлом.');
                $title = safe_post_string('title', 160) ?: ($kind === 'transfer' ? 'Регулярный перевод' : 'Автооплата счетов');
                $toId = null; $amount = null;
                if ($kind === 'transfer') {
                    $amount = money_to_minor((string)($_POST['amount'] ?? ''));
                    $number = strtoupper(trim((string)($_POST['destination_account'] ?? '')));
                    $stmt = db()->prepare('SELECT id FROM accounts WHERE account_number = ? AND status = \'active\' LIMIT 1');
                    $stmt->execute([$number]);
                    $toId = (int)($stmt->fetchColumn() ?: 0);
                    if (!$toId) throw new RuntimeException('Активный счёт получателя не найден.');
                    if ($toId === $sourceId) throw new RuntimeException('Нельзя переводить деньги на тот же счёт.');
                }
                db()->prepare("INSERT INTO recurring_rules (user_id,rule_type,source_account_id,destination_account_id,title,amount_minor,frequency,next_run_at,ends_at,status) VALUES (?,?,?,?,?,?,?,?,?,'active')")
                    ->execute([(int)$u['id'], $kind, $sourceId, $toId, $title, $amount, $freq, $runDate->format('Y-m-d H:i:s'), $endDate]);
                audit('recurring_rule_created', 'Создано правило: ' . $title, (int)$u['id']);
                action_redirect('automation', 'Автоматическое правило добавлено.');
            }
            case 'toggle_rule': {
                $u = require_login();
                $id = (int)($_POST['rule_id'] ?? 0);
                $stmt = db()->prepare("UPDATE recurring_rules SET status = IF(status='active','paused','active'), last_error = NULL WHERE id = ? AND user_id = ? AND status IN ('active','paused')");
                $stmt->execute([$id, (int)$u['id']]);
                if (!$stmt->rowCount()) throw new RuntimeException('Правило не найдено или его нельзя изменить.');
                action_redirect('automation', 'Статус автоплатежа изменён.');
            }
            case 'banker_toggle_user': {
                $u = require_banker();
                $target = (int)($_POST['user_id'] ?? 0);
                $stmt = db()->prepare("UPDATE users SET status = IF(status='active','blocked','active') WHERE id = ? AND role = 'customer'");
                $stmt->execute([$target]);
                if (!$stmt->rowCount()) throw new RuntimeException('Клиент не найден.');
                audit('customer_status_changed', 'Изменён статус клиента #' . $target, (int)$u['id']);
                action_redirect('banker_customers', 'Статус клиента обновлён.');
            }
            case 'banker_toggle_account': {
                $u = require_banker();
                $target = (int)($_POST['account_id'] ?? 0);
                $stmt = db()->prepare("UPDATE accounts SET status = IF(status='active','blocked','active') WHERE id = ? AND user_id IN (SELECT id FROM users WHERE role='customer')");
                $stmt->execute([$target]);
                if (!$stmt->rowCount()) throw new RuntimeException('Счёт клиента не найден.');
                audit('account_status_changed', 'Изменён статус счёта #' . $target, (int)$u['id']);
                action_redirect('banker_customers', 'Статус счёта обновлён.');
            }
            case 'banker_operation': {
                $u = require_banker();
                $accountId = (int)($_POST['account_id'] ?? 0);
                $stmt = db()->prepare('SELECT a.*, u.status AS owner_status FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.id=?');
                $stmt->execute([$accountId]);
                $account = $stmt->fetch();
                if (!$account) throw new RuntimeException('Счёт не найден.');
                if ($account['status'] !== 'active' || $account['owner_status'] !== 'active') throw new RuntimeException('Нельзя настроить операцию по заблокированному счёту или клиенту.');
                $ownerCheck = db()->prepare("SELECT role FROM users WHERE id=?");
                $ownerCheck->execute([(int)$account['user_id']]);
                if ($ownerCheck->fetchColumn() !== 'customer') throw new RuntimeException('Операции доступны только по клиентским счетам.');
                $op = (string)($_POST['operation_type'] ?? 'credit');
                if (!in_array($op, ['salary','credit','debit'], true)) throw new RuntimeException('Неверный тип операции.');
                $amount = money_to_minor((string)($_POST['amount'] ?? ''));
                $title = safe_post_string('title', 160) ?: ['salary'=>'Заработная плата','credit'=>'Пополнение от банка','debit'=>'Списание банком'][$op];
                $mode = (string)($_POST['mode'] ?? 'now');
                if ($mode === 'now') {
                    if ($op === 'debit') post_money_transaction('admin_debit', $accountId, null, $amount, $title, (int)$u['id']);
                    else post_money_transaction($op === 'salary' ? 'salary' : 'admin_credit', null, $accountId, $amount, $title, (int)$u['id']);
                    audit('banker_money_operation', $op . ' ' . $amount . ' to/from account #' . $accountId, (int)$u['id']);
                    action_redirect('banker_operations', 'Операция успешно проведена.');
                }
                $freq = (string)($_POST['frequency'] ?? 'monthly');
                if (!in_array($freq, ['once','daily','weekly','monthly'], true)) throw new RuntimeException('Неверная периодичность.');
                $runRaw = trim((string)($_POST['next_run_at'] ?? ''));
                $runDate = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $runRaw);
                if (!$runDate || $runDate < new DateTimeImmutable('now')) throw new RuntimeException('Укажите будущую дату первого запуска.');
                db()->prepare("INSERT INTO scheduled_operations (account_id,operation_type,amount_minor,title,frequency,next_run_at,status,created_by) VALUES (?,?,?,?,?,?,'active',?)")
                    ->execute([$accountId, $op, $amount, $title, $freq, $runDate->format('Y-m-d H:i:s'), (int)$u['id']]);
                audit('scheduled_operation_created', 'Создана автооперация ' . $op . ' по счёту #' . $accountId, (int)$u['id']);
                action_redirect('banker_schedules', 'Автооперация добавлена в расписание.');
            }
            case 'banker_issue_bill': {
                $u = require_banker();
                $target = (int)($_POST['user_id'] ?? 0);
                $title = safe_post_string('title', 160);
                $type = (string)($_POST['bill_type'] ?? 'invoice');
                $amount = money_to_minor((string)($_POST['amount'] ?? ''));
                $due = valid_date_or_null((string)($_POST['due_date'] ?? ''));
                $note = safe_post_string('note', 500);
                if ($title === '') throw new RuntimeException('Введите название счёта или штрафа.');
                if (!in_array($type, ['fine','utility','invoice','other'], true)) throw new RuntimeException('Неверный тип счёта.');
                if (($_POST['due_date'] ?? '') !== '' && $due === null) throw new RuntimeException('Неверная дата оплаты.');
                $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND role='customer' AND status='active'");
                $stmt->execute([$target]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Активный клиент не найден.');
                db()->prepare("INSERT INTO bills (user_id,title,bill_type,amount_minor,remaining_minor,due_date,status,note,created_by) VALUES (?,?,?,?,?,?,'unpaid',?,?)")
                    ->execute([$target, $title, $type, $amount, $amount, $due, $note ?: null, (int)$u['id']]);
                audit('bill_issued', 'Выставлен счёт клиенту #' . $target . ': ' . $title, (int)$u['id']);
                action_redirect('banker_bills', 'Счёт или штраф выставлен клиенту.');
            }
            case 'banker_cancel_bill': {
                $u = require_banker();
                $id = (int)($_POST['bill_id'] ?? 0);
                $stmt = db()->prepare("UPDATE bills SET status='cancelled' WHERE id=? AND status IN ('unpaid','partial')");
                $stmt->execute([$id]);
                if (!$stmt->rowCount()) throw new RuntimeException('Счёт уже оплачен или не найден.');
                audit('bill_cancelled', 'Отменён счёт #' . $id, (int)$u['id']);
                action_redirect('banker_bills', 'Счёт отменён.');
            }
            case 'banker_toggle_schedule': {
                $u = require_banker();
                $id = (int)($_POST['schedule_id'] ?? 0);
                $stmt = db()->prepare("UPDATE scheduled_operations SET status = IF(status='active','paused','active'), last_error=NULL WHERE id=? AND status IN ('active','paused')");
                $stmt->execute([$id]);
                if (!$stmt->rowCount()) throw new RuntimeException('Расписание не найдено или уже завершено.');
                action_redirect('banker_schedules', 'Расписание обновлено.');
            }
            default:
                throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $ex) {
        error_log('Капитал-Стандарт: ошибка операции: ' . $ex->getMessage());
        $message = $ex instanceof InvalidArgumentException || $ex instanceof RuntimeException ? $ex->getMessage() : 'Не удалось выполнить действие. Проверьте данные и повторите попытку.';
        $fallback = 'login';
        $current = current_user();
        if ($current) $fallback = $current['role'] === 'banker' ? 'banker' : 'dashboard';
        if (str_starts_with($action, 'banker_')) $fallback = 'banker';
        if ($action === 'transfer') $fallback = 'transfer';
        if ($action === 'create_account') $fallback = 'accounts';
        if (in_array($action, ['pay_bill','banker_issue_bill','banker_cancel_bill'], true)) $fallback = $current && $current['role']==='banker' ? 'banker_bills' : 'bills';
        if (in_array($action, ['create_rule','toggle_rule'], true)) $fallback = 'automation';
        if ($action === 'banker_operation') $fallback = 'banker_operations';
        if ($action === 'banker_toggle_schedule') $fallback = 'banker_schedules';
        if (in_array($action, ['banker_toggle_user','banker_toggle_account'], true)) $fallback = 'banker_customers';
        action_redirect($fallback, $message, 'error');
    }
}

if ($page === 'logout') {
    page_header('Выход', $user);
    echo '<section class="empty-state"><div class="empty-icon">↗</div><h1>Выйти из аккаунта?</h1><p>Сеанс будет завершён безопасно.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="button" type="submit">Выйти</button> <a class="button button-secondary" href="?page=' . ($user ? ($user['role']==='banker'?'banker':'dashboard') : 'login') . '">Отмена</a></form></section>';
    page_footer(); exit;
}

if (!$user && !in_array($page, ['', 'login', 'register'], true)) redirect('?page=login');
if ($user && in_array($page, ['', 'login', 'register'], true)) redirect($user['role']==='banker' ? '?page=banker' : '?page=dashboard');
if ($user && $user['role'] === 'banker' && in_array($page, ['dashboard','accounts','transfer','bills','automation','history'], true)) redirect('?page=banker');
if ($user && $user['role'] === 'customer' && str_starts_with($page, 'banker')) {
    http_response_code(403); render_error_page(403, 'Недостаточно прав', 'Раздел предназначен для сотрудников банка.'); exit;
}

if ($page === '' || $page === 'login') {
    page_header('Вход');
    ?><div class="auth-wrap"><div class="auth-brand"><div class="brand-mark">КС</div></div><section class="card auth-card"><div class="eyebrow">Личный кабинет</div><h1>С возвращением</h1><p>Войдите, чтобы управлять счетами и платежами.</p><form method="post" autocomplete="on"><?= csrf_field() ?><input type="hidden" name="action" value="login"><?php input_field('Электронная почта','email','email','you@example.com'); input_field('Пароль','password','password','Ваш пароль'); ?><button class="button" type="submit" style="width:100%">Войти в банк</button></form><div class="auth-switch">Впервые с нами? <a href="?page=register">Создать аккаунт</a></div></section></div><?php
    page_footer(); exit;
}

if ($page === 'register') {
    page_header('Регистрация');
    ?><div class="auth-wrap"><div class="auth-brand"><div class="brand-mark">КС</div></div><section class="card auth-card"><div class="eyebrow">Новый клиент</div><h1>Откройте свой счёт</h1><p>Создайте профиль — основной счёт будет открыт автоматически.</p><form method="post" autocomplete="on"><?= csrf_field() ?><input type="hidden" name="action" value="register"><div class="form-grid"><?php input_field('Имя','first_name','text','Александр'); input_field('Фамилия','last_name','text','Иванов'); ?><div class="full"><?php input_field('Электронная почта','email','email','you@example.com'); ?></div><div class="full"><?php input_field('Пароль (от 10 символов)','password','password','Создайте надёжный пароль'); input_field('Повторите пароль','password_confirm','password','Ещё раз пароль'); ?></div></div><button class="button" type="submit" style="width:100%">Создать аккаунт</button></form><div class="auth-switch">Уже зарегистрированы? <a href="?page=login">Войти</a></div></section></div><?php
    page_footer(); exit;
}

$u = require_login();
$userId = (int)$u['id'];

if ($page === 'dashboard') {
    $accounts = user_accounts($userId);
    $activeAccounts = array_filter($accounts, fn($a) => $a['status'] === 'active');
    $total = array_sum(array_map(fn($a)=>(int)$a['balance_minor'], $accounts));
    $stmt = db()->prepare("SELECT COUNT(*) FROM bills WHERE user_id=? AND status IN ('unpaid','partial')"); $stmt->execute([$userId]); $openBills = (int)$stmt->fetchColumn();
    $history = array_slice(transaction_history_for_user($userId, 6), 0, 6);
    page_header('Обзор', $u, 'dashboard');
    page_title('ВАШИ ФИНАНСЫ', 'Здравствуйте, ' . $u['first_name'] . '!', 'Ваши деньги, счета и регулярные платежи — в одном месте.');
    ?><div class="form-note">Ваш ID клиента в «Капитал-Стандарт»: <strong>#<?= $userId ?></strong>. Администратор АвтоКонтроль 200 использует его для привязки вашего профиля владельца.</div><div class="grid grid-3"><div class="card stat-card balance-card"><span class="stat-icon">₽</span><div class="stat-label">Общий баланс</div><div class="stat-value"><?= money($total) ?></div><div class="stat-note">По всем вашим счетам</div></div><div class="card stat-card"><span class="stat-icon">▣</span><div class="stat-label">Открытые счета</div><div class="stat-value"><?= count($activeAccounts) ?></div><div class="stat-note">Активные банковские счета</div></div><div class="card stat-card"><span class="stat-icon">▤</span><div class="stat-label">К оплате</div><div class="stat-value"><?= $openBills ?></div><div class="stat-note">Неоплаченные счета и штрафы</div></div></div>
    <div class="section-head"><h2>Быстрые действия</h2></div><div class="quick-actions"><a class="quick-action" href="?page=transfer"><span class="quick-icon">↗</span><span><strong>Перевести деньги</strong><small>Между счетами клиентов</small></span></a><a class="quick-action" href="?page=accounts"><span class="quick-icon">＋</span><span><strong>Открыть счёт</strong><small>Текущий или накопительный</small></span></a><a class="quick-action" href="?page=bills"><span class="quick-icon">▤</span><span><strong>Оплатить счёт</strong><small>Штрафы, квитанции, счета</small></span></a></div>
    <div class="section-head"><h2>Мои счета</h2><a href="?page=accounts">Все счета →</a></div><div class="grid grid-3"><?php if (!$accounts): ?><div class="card">Счетов пока нет.</div><?php else: foreach (array_slice($accounts,0,3) as $a): ?><article class="card account-card"><div class="account-top"><div><div class="account-type"><?= e(account_type_label($a['type'])) ?></div><h3><?= e($a['name']) ?></h3></div><?= status_pill($a['status']) ?></div><div><div class="account-balance"><?= money((int)$a['balance_minor'], $a['currency']) ?></div><div class="account-number">№ <?= e($a['account_number']) ?></div></div><div class="account-footer"><span class="muted small">Открыт <?= e(date('d.m.Y', strtotime($a['created_at']))) ?></span><a class="button button-secondary button-small" href="?page=history">История</a></div></article><?php endforeach; endif; ?></div>
    <div class="section-head"><h2>Последние операции</h2><a href="?page=history">Вся история →</a></div><?php render_transactions_table($history, $userId); ?>
    <?php page_footer(); exit;
}

if ($page === 'accounts') {
    $accounts = user_accounts($userId);
    page_header('Мои счета', $u, 'accounts');
    page_title('ПРОДУКТЫ', 'Мои счета', 'Разделяйте повседневные расходы и деньги, которые откладываете на цели.');
    ?><div class="grid grid-3"><?php foreach ($accounts as $a): ?><article class="card account-card"><div class="account-top"><div><div class="account-type"><?= e(account_type_label($a['type'])) ?></div><h3><?= e($a['name']) ?></h3></div><?= status_pill($a['status']) ?></div><div><div class="account-balance"><?= money((int)$a['balance_minor'], $a['currency']) ?></div><div class="account-number">Номер: <?= e($a['account_number']) ?></div></div><div class="account-footer"><span class="small muted"><?= $a['type']==='savings'?'Ставка ' . number_format((float)$config['savings_annual_rate_percent'], 2, ',', ' ') . '% годовых':'Для повседневных платежей' ?></span><a class="button button-secondary button-small" href="?page=history">История</a></div></article><?php endforeach; ?></div>
    <div class="section-head"><h2>Открыть новый счёт</h2></div><div class="grid grid-2"><section class="card"><h2>Новый счёт</h2><p class="muted small">Счёт появится сразу после создания. Первоначальный баланс — 0 RUB.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_account"><label class="field"><span>Тип счёта</span><select name="type"><option value="checking">Текущий счёт</option><option value="savings">Накопительный счёт</option></select></label><?php input_field('Название счёта','name','text','Например, Отпуск'); ?><button class="button" type="submit">＋ Открыть счёт</button></form></section><section class="card help-card"><h3>Как лучше организовать счета?</h3><ul><li><strong>Текущий счёт</strong> — переводы, оплаты и повседневные расходы.</li><li><strong>Накопительный счёт</strong> — отдельный баланс для целей и резервов; пополните его переводом со своего текущего счёта.</li><li>Деньги можно перевести обратно или использовать для платежей — специальных ограничений на снятие нет.</li></ul><p><strong>Начисление процентов:</strong> на накопительный счёт автоматически начисляется <?= e(number_format((float)$config['savings_annual_rate_percent'], 2, ',', ' ')) ?>% годовых. Проценты зачисляются раз в месяц отдельной операцией в истории.</p></section></div><?php
    page_footer(); exit;
}

if ($page === 'transfer') {
    $accounts = user_accounts($userId, true);
    page_header('Переводы', $u, 'transfer');
    page_title('ПЛАТЕЖИ', 'Перевод денег', 'Переводите средства на другой счёт банка «Капитал-Стандарт» — операция попадёт в историю.');
    ?><div class="split-layout"><section class="card"><h2>Новый перевод</h2><p class="muted small">Проверьте номер счёта получателя перед отправкой.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="transfer"><label class="field"><span>С какого счёта</span><?= account_select($accounts, 'from_account_id') ?></label><?php input_field('Номер счёта получателя','destination_account','text','KS26…'); input_field('Сумма, RUB','amount','number','0.00',['min'=>'0.01','step'=>'0.01']); input_field('Назначение платежа','description','text','Например, Возврат долга',['required'=>false]); ?><div class="form-note">Перевод выполняется сразу. При недостатке средств или блокировке одного из счетов операция не проводится.</div><button class="button" type="submit">Перевести деньги →</button></form></section><section class="card help-card"><h3>Перед отправкой</h3><ul><li>Уточните номер счёта у получателя.</li><li>Переводы доступны между активными счетами внутри этой системы.</li><li>Внешние карты, банковские системы и реальные платежи не подключены.</li><li>Результат перевода можно проверить в разделе «История».</li></ul><a class="button button-secondary" href="?page=history">Открыть историю</a></section></div><?php
    page_footer(); exit;
}

if ($page === 'history') {
    $history = transaction_history_for_user($userId, 0);
    page_header('История операций', $u, 'history');
    page_title('ДВИЖЕНИЕ СРЕДСТВ', 'История операций', 'Все входящие и исходящие переводы, пополнения и оплаты по вашим счетам.');
    render_transactions_table($history, $userId, true);
    page_footer(); exit;
}

if ($page === 'bills') {
    $accounts = user_accounts($userId, true);
    $stmt = db()->prepare('SELECT * FROM bills WHERE user_id=? ORDER BY FIELD(status,\'unpaid\',\'partial\',\'paid\',\'cancelled\'), due_date IS NULL, due_date ASC, created_at DESC'); $stmt->execute([$userId]); $bills = $stmt->fetchAll();
    page_header('Счета и штрафы', $u, 'bills');
    page_title('ПЛАТЕЖИ', 'Счета и штрафы', 'Оплачивайте выставленные счета прямо со своего активного счёта.');
    if (!$bills): ?><section class="empty-state"><div class="empty-icon">✓</div><h2>Счетов пока нет</h2><p>Когда банк выставит вам счёт или штраф, он появится здесь.</p></section><?php else: ?><div class="grid grid-2"><?php foreach ($bills as $b): ?><article class="card"><div class="account-top"><div><div class="account-type"><?= e(['fine'=>'Штраф','utility'=>'Коммунальный платёж','invoice'=>'Счёт','other'=>'Другое'][$b['bill_type']] ?? 'Счёт') ?></div><h3><?= e($b['title']) ?></h3></div><?= status_pill($b['status']) ?></div><div class="account-balance"><?= money((int)$b['remaining_minor']) ?></div><div class="small muted">Исходная сумма: <?= money((int)$b['amount_minor']) ?></div><?php if ($b['due_date']): ?><p class="small muted">Срок оплаты: <?= e(date('d.m.Y',strtotime($b['due_date']))) ?></p><?php endif; ?><?php if ($b['note']): ?><p class="small"><?= e($b['note']) ?></p><?php endif; ?><?php if (in_array($b['status'],['unpaid','partial'],true)): ?><div class="divider"></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pay_bill"><input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>"><label class="field"><span>Списать со счёта</span><?= account_select($accounts,'account_id') ?></label><button class="button" type="submit">Оплатить <?= money((int)$b['remaining_minor']) ?></button></form><?php else: ?><p class="small muted"><?= $b['status']==='paid'?'Оплачено '.e($b['paid_at'] ? date('d.m.Y H:i',strtotime($b['paid_at'])) : ''):'Платёж закрыт' ?></p><?php endif; ?></article><?php endforeach; ?></div><?php endif;
    page_footer(); exit;
}

if ($page === 'automation') {
    $accounts = user_accounts($userId, true);
    $stmt = db()->prepare('SELECT r.*, sa.account_number AS source_number, da.account_number AS destination_number FROM recurring_rules r JOIN accounts sa ON sa.id=r.source_account_id LEFT JOIN accounts da ON da.id=r.destination_account_id WHERE r.user_id=? ORDER BY r.created_at DESC'); $stmt->execute([$userId]); $rules = $stmt->fetchAll();
    page_header('Автоплатежи', $u, 'automation');
    page_title('АВТОМАТИЗАЦИЯ', 'Автоплатежи и переводы', 'Настройте правила один раз — система будет запускать их по расписанию через cron.');
    ?><div class="split-layout"><section class="card"><h2>Создать автоматическое правило</h2><form method="post" id="rule-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_rule"><label class="field"><span>Тип правила</span><select name="rule_type" id="rule-type"><option value="transfer">Повторяющийся перевод</option><option value="bill_autopay">Автооплата счетов и штрафов</option></select></label><label class="field"><span>С какого счёта списывать</span><?= account_select($accounts,'source_account_id') ?></label><div id="transfer-fields"><?php input_field('Счёт получателя','destination_account','text','KS26…'); input_field('Сумма перевода, RUB','amount','number','0.00',['min'=>'0.01','step'=>'0.01']); ?></div><?php input_field('Название правила','title','text','Например, Регулярный перевод',['required'=>false]); ?><label class="field"><span>Периодичность</span><select name="frequency"><option value="monthly">Ежемесячно</option><option value="weekly">Еженедельно</option><option value="daily">Ежедневно</option></select></label><?php input_field('Первый запуск','next_run_at','datetime-local','',['min'=>date('Y-m-d\\TH:i',time()+60)]); input_field('Завершить после даты (необязательно)','ends_at','date','',['required'=>false]); ?><div class="form-note">Автоперевод проводится только при наличии средств и активных счетов. Автооплата рассчитывается по неоплаченным счетам, срок которых наступил.</div><button class="button" type="submit">＋ Создать правило</button></form></section><section class="card help-card"><h3>Как работают правила</h3><ul><li>Сначала укажите будущую дату первого запуска.</li><li>Если денег недостаточно, система сохранит ошибку и попробует снова в следующий запланированный период.</li><li>Правило можно приостановить в любой момент.</li><li>Запуск выполняется планировщиком сервера, а не открытой вкладкой браузера.</li></ul></section></div>
    <div class="section-head"><h2>Мои правила</h2></div><?php if (!$rules): ?><div class="empty-state"><div class="empty-icon">◷</div><h2>Нет активных правил</h2><p>Создайте повторяющийся перевод или настройте автооплату счетов.</p></div><?php else: ?><div class="grid grid-2"><?php foreach ($rules as $r): ?><article class="card"><div class="account-top"><div><div class="account-type"><?= $r['rule_type']==='transfer'?'Регулярный перевод':'Автооплата счетов' ?></div><h3><?= e($r['title']) ?></h3></div><?= status_pill($r['status']) ?></div><p class="small muted">Со счёта <?= e($r['source_number']) ?><?= $r['destination_number']?' → '.e($r['destination_number']):'' ?></p><?php if ($r['amount_minor'] !== null): ?><div class="account-balance"><?= money((int)$r['amount_minor']) ?></div><?php endif; ?><p class="small muted"><?= e(frequency_label($r['frequency'])) ?> · следующий запуск: <?= e(date('d.m.Y H:i',strtotime($r['next_run_at']))) ?></p><?php if ($r['last_error']): ?><p class="danger-note">Последняя ошибка: <?= e($r['last_error']) ?></p><?php endif; ?><div class="account-footer"><span class="small muted"><?= $r['last_run_at']?'Последний запуск: '.e(date('d.m.Y H:i',strtotime($r['last_run_at']))):'Ещё не запускалось' ?></span><?php if (in_array($r['status'],['active','paused'],true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>"><button class="button button-secondary button-small" type="submit"><?= $r['status']==='active'?'Приостановить':'Возобновить' ?></button></form><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
    <script>document.addEventListener('DOMContentLoaded',()=>{const s=document.getElementById('rule-type'),f=document.getElementById('transfer-fields');function update(){const on=s.value==='transfer';f.hidden=!on;f.querySelectorAll('input').forEach(i=>i.required=on)}s.addEventListener('change',update);update()});</script><?php
    page_footer(); exit;
}

// Banker interface below
if ($page === 'banker' || $page === 'banker_customers' || $page === 'banker_operations' || $page === 'banker_bills' || $page === 'banker_schedules') {
    require_banker();
}

if ($page === 'banker') {
    $pdo = db();
    $customers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
    $accountsCount = (int)$pdo->query("SELECT COUNT(*) FROM accounts a JOIN users u ON u.id=a.user_id WHERE u.role='customer'")->fetchColumn();
    $balances = (int)$pdo->query("SELECT COALESCE(SUM(a.balance_minor),0) FROM accounts a JOIN users u ON u.id=a.user_id WHERE u.role='customer'")->fetchColumn();
    $unpaid = (int)$pdo->query("SELECT COUNT(*) FROM bills WHERE status IN ('unpaid','partial')")->fetchColumn();
    $latest = $pdo->query('SELECT al.*,u.first_name,u.last_name FROM audit_log al LEFT JOIN users u ON u.id=al.actor_user_id ORDER BY al.created_at DESC,al.id DESC LIMIT 8')->fetchAll();
    page_header('Панель банкира', $u, 'banker');
    page_title('ПАНЕЛЬ УПРАВЛЕНИЯ', 'Добро пожаловать, ' . $u['first_name'], 'Обзор клиентов, балансов и операций банка «Капитал-Стандарт».');
    ?><div class="grid grid-4"><div class="card stat-card"><span class="stat-icon">♙</span><div class="stat-label">Клиенты</div><div class="stat-value"><?= $customers ?></div><div class="stat-note">Зарегистрированные клиенты</div></div><div class="card stat-card"><span class="stat-icon">▣</span><div class="stat-label">Счета</div><div class="stat-value"><?= $accountsCount ?></div><div class="stat-note">По всем клиентам</div></div><div class="card stat-card balance-card"><span class="stat-icon">₽</span><div class="stat-label">Баланс системы</div><div class="stat-value"><?= money($balances) ?></div><div class="stat-note">Сумма балансов счетов</div></div><div class="card stat-card"><span class="stat-icon">▤</span><div class="stat-label">К оплате</div><div class="stat-value"><?= $unpaid ?></div><div class="stat-note">Счета и штрафы клиентов</div></div></div>
    <div class="section-head"><h2>Управление банком</h2></div><div class="quick-actions"><a class="quick-action" href="?page=banker_customers"><span class="quick-icon">♙</span><span><strong>Клиенты и счета</strong><small>База клиентов, статусы и блокировки</small></span></a><a class="quick-action" href="?page=banker_operations"><span class="quick-icon">↗</span><span><strong>Движение средств</strong><small>Зарплаты, пополнения, списания</small></span></a><a class="quick-action" href="?page=banker_bills"><span class="quick-icon">▤</span><span><strong>Выставить счёт</strong><small>Штраф, квитанция или иной платёж</small></span></a></div>
    <div class="section-head"><h2>Последние действия в системе</h2></div><?php if (!$latest): ?><div class="card muted">Журнал пока пуст.</div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Сотрудник / клиент</th><th>Действие</th><th>Подробности</th><th>IP</th></tr></thead><tbody><?php foreach ($latest as $row): ?><tr><td class="no-wrap"><?= e(date('d.m.Y H:i',strtotime($row['created_at']))) ?></td><td><?= e(trim(($row['first_name']??'').' '.($row['last_name']??'')) ?: 'Система') ?></td><td><?= e($row['action']) ?></td><td><?= e($row['details']) ?></td><td class="mono"><?= e($row['ip_address']??'—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php page_footer(); exit;
}

if ($page === 'banker_customers') {
    $search = trim((string)($_GET['q'] ?? ''));
    $sql = "SELECT u.id,u.first_name,u.last_name,u.email,u.status,u.created_at,COUNT(a.id) AS account_count,COALESCE(SUM(a.balance_minor),0) AS total_balance FROM users u LEFT JOIN accounts a ON a.user_id=u.id WHERE u.role='customer'";
    $params = [];
    if ($search !== '') { $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR EXISTS (SELECT 1 FROM accounts ax WHERE ax.user_id=u.id AND ax.account_number LIKE ?))'; $like='%'.$search.'%'; $params=[$like,$like,$like,$like]; }
    $sql .= ' GROUP BY u.id ORDER BY u.created_at DESC';
    $stmt=db()->prepare($sql);$stmt->execute($params);$customers=$stmt->fetchAll();
    $allAccounts = db()->query("SELECT a.*,u.first_name,u.last_name,u.email,u.status AS owner_status FROM accounts a JOIN users u ON u.id=a.user_id WHERE u.role='customer' ORDER BY a.created_at DESC")->fetchAll();
    page_header('Клиенты', $u, 'customers'); page_title('КЛИЕНТСКАЯ БАЗА', 'Клиенты и счета', 'Просматривайте клиентов, балансы и управляйте статусами доступа.');
    ?><section class="card" style="margin-bottom:18px"><form method="get" class="inline-form"><input type="hidden" name="page" value="banker_customers"><input style="flex:1;min-width:200px;padding:11px 13px;border:1px solid var(--line);border-radius:10px" type="search" name="q" value="<?= e($search) ?>" placeholder="Имя, email или номер счёта"><button class="button" type="submit">Найти клиента</button><?php if($search!==''):?><a class="button button-secondary" href="?page=banker_customers">Сбросить</a><?php endif;?></form></section><div class="section-head"><h2>Клиенты <span class="badge-soft"><?= count($customers) ?></span></h2></div><?php if (!$customers): ?><div class="empty-state"><h2>Клиенты не найдены</h2><p>Попробуйте изменить поисковый запрос.</p></div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Клиент</th><th>Счета</th><th>Общий баланс</th><th>Регистрация</th><th>Статус</th><th>Действие</th></tr></thead><tbody><?php foreach($customers as $c):?><tr><td><div class="customer-cell"><span class="customer-dot"><?= e(mb_strtoupper(mb_substr($c['first_name'],0,1))) ?></span><span><strong><?= e($c['first_name'].' '.$c['last_name']) ?></strong><span class="table-secondary"><?= e($c['email']) ?> · ID <?= (int)$c['id'] ?></span></span></div></td><td><?= (int)$c['account_count'] ?></td><td class="amount"><?= money((int)$c['total_balance']) ?></td><td><?= e(date('d.m.Y',strtotime($c['created_at']))) ?></td><td><?= status_pill($c['status']) ?></td><td><form method="post" class="inline-form" style="min-width:155px"><?= csrf_field() ?><input type="hidden" name="action" value="banker_toggle_user"><input type="hidden" name="user_id" value="<?= (int)$c['id'] ?>"><button class="button button-small <?= $c['status']==='active'?'button-danger':'button-secondary' ?>" data-confirm="<?= $c['status']==='active'?'Заблокировать клиента?':'Разблокировать клиента?' ?>" type="submit"><?= $c['status']==='active'?'Заблокировать':'Разблокировать' ?></button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    <div class="section-head"><h2>Банковские счета <span class="badge-soft"><?= count($allAccounts) ?></span></h2></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Владелец</th><th>Номер счёта</th><th>Тип / название</th><th>Баланс</th><th>Статус владельца</th><th>Статус счёта</th><th>Действие</th></tr></thead><tbody><?php foreach($allAccounts as $a):?><tr><td><?= e($a['first_name'].' '.$a['last_name']) ?><span class="table-secondary"><?= e($a['email']) ?></span></td><td class="mono"><?= e($a['account_number']) ?></td><td><?= e(account_type_label($a['type'])) ?><span class="table-secondary"><?= e($a['name']) ?></span></td><td class="amount"><?= money((int)$a['balance_minor'],$a['currency']) ?></td><td><?= status_pill($a['owner_status']) ?></td><td><?= status_pill($a['status']) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="banker_toggle_account"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><button class="button button-small <?= $a['status']==='active'?'button-danger':'button-secondary' ?>" data-confirm="<?= $a['status']==='active'?'Заблокировать счёт?':'Разблокировать счёт?' ?>" type="submit"><?= $a['status']==='active'?'Блокировать':'Разблокировать' ?></button></form></td></tr><?php endforeach;?></tbody></table></div><?php
    page_footer(); exit;
}

if ($page === 'banker_operations') {
    $accounts = db()->query("SELECT a.*,u.first_name,u.last_name,u.email FROM accounts a JOIN users u ON u.id=a.user_id WHERE u.role='customer' ORDER BY u.last_name,u.first_name,a.created_at")->fetchAll();
    $history = db()->query("SELECT t.*,a1.account_number AS from_number,a2.account_number AS to_number,u1.first_name AS from_first,u1.last_name AS from_last,u2.first_name AS to_first,u2.last_name AS to_last FROM transactions t LEFT JOIN accounts a1 ON a1.id=t.from_account_id LEFT JOIN accounts a2 ON a2.id=t.to_account_id LEFT JOIN users u1 ON u1.id=a1.user_id LEFT JOIN users u2 ON u2.id=a2.user_id ORDER BY t.created_at DESC,t.id DESC LIMIT 100")->fetchAll();
    page_header('Операции', $u, 'operations'); page_title('КАССОВЫЕ ОПЕРАЦИИ', 'Пополнения и списания', 'Проводите разовые операции или настраивайте будущие зарплаты и регулярные начисления.');
    ?><div class="split-layout"><section class="card"><h2>Новая операция</h2><form method="post" id="bank-operation-form"><?= csrf_field() ?><input type="hidden" name="action" value="banker_operation"><label class="field"><span>Клиентский счёт</span><select name="account_id" required><option value="">Выберите счёт</option><?php foreach($accounts as $a):?><option value="<?= (int)$a['id'] ?>"><?= e($a['first_name'].' '.$a['last_name'].' · '.$a['name'].' · '.$a['account_number'].' · '.money((int)$a['balance_minor'])) ?></option><?php endforeach;?></select></label><label class="field"><span>Тип операции</span><select name="operation_type"><option value="salary">Зарплата / начисление</option><option value="credit">Пополнение от банка</option><option value="debit">Списание со счёта</option></select></label><?php input_field('Сумма, RUB','amount','number','0.00',['min'=>'0.01','step'=>'0.01']); input_field('Назначение','title','text','Например, Зарплата за октябрь'); ?><label class="field"><span>Когда выполнить?</span><select name="mode" id="op-mode"><option value="now">Сейчас, один раз</option><option value="schedule">По расписанию</option></select></label><div id="schedule-fields" hidden><label class="field"><span>Периодичность</span><select name="frequency"><option value="monthly">Ежемесячно</option><option value="weekly">Еженедельно</option><option value="daily">Ежедневно</option><option value="once">Один раз в выбранную дату</option></select></label><?php input_field('Первый запуск','next_run_at','datetime-local','',['min'=>date('Y-m-d\\TH:i',time()+60)]); ?></div><div class="form-note">Операция проводится только по активному счёту. Списание сверх доступного баланса запрещено.</div><button class="button" type="submit">Провести / запланировать</button></form></section><section class="card help-card"><h3>Типы операций</h3><ul><li><strong>Зарплата</strong> — зачисление с пометкой зарплаты.</li><li><strong>Пополнение</strong> — разовое или регулярное увеличение баланса.</li><li><strong>Списание</strong> — снятие средств со счёта клиента; при недостатке средств операция отклоняется.</li><li>Все операции фиксируются в журнале аудита.</li></ul></section></div><div class="section-head"><h2>Последние операции</h2></div><?php if(!$history):?><div class="empty-state"><h2>Операций пока нет</h2><p>Проведённые переводы и начисления будут отображаться здесь.</p></div><?php else:?><div class="table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Тип</th><th>Откуда → куда</th><th>Сумма</th><th>Назначение</th><th>Референс</th></tr></thead><tbody><?php foreach($history as $tx):?><tr><td class="no-wrap"><?= e(date('d.m.Y H:i',strtotime($tx['created_at']))) ?></td><td><?= e(['transfer'=>'Перевод','deposit'=>'Пополнение','withdrawal'=>'Снятие','salary'=>'Зарплата','bill_payment'=>'Оплата счёта','fine_payment'=>'Штраф','admin_credit'=>'Начисление','admin_debit'=>'Списание','auto_transfer'=>'Автоперевод','auto_bill_payment'=>'Автооплата','interest_credit'=>'Начисление процентов'][$tx['transaction_type']]??$tx['transaction_type']) ?></td><td><span class="table-secondary"><?= e(trim(($tx['from_first']??'').' '.($tx['from_last']??'')) ?: 'Банк') ?> <?= $tx['from_number']?'· '.e($tx['from_number']):'' ?></span><span class="table-secondary">→ <?= e(trim(($tx['to_first']??'').' '.($tx['to_last']??'')) ?: 'Банк') ?> <?= $tx['to_number']?'· '.e($tx['to_number']):'' ?></span></td><td class="amount"><?= money((int)$tx['amount_minor']) ?></td><td><?= e($tx['description']) ?></td><td class="mono"><?= e($tx['reference']) ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?><?php
    ?><script>document.addEventListener('DOMContentLoaded',()=>{const s=document.getElementById('op-mode'),f=document.getElementById('schedule-fields');function update(){f.hidden=s.value!=='schedule';f.querySelectorAll('input,select').forEach(i=>i.required=s.value==='schedule')};s.addEventListener('change',update);update()});</script><?php page_footer(); exit;
}

if ($page === 'banker_bills') {
    $customers = db()->query("SELECT id,first_name,last_name,email FROM users WHERE role='customer' AND status='active' ORDER BY last_name,first_name")->fetchAll();
    $bills = db()->query("SELECT b.*,u.first_name,u.last_name,u.email FROM bills b JOIN users u ON u.id=b.user_id ORDER BY b.created_at DESC")->fetchAll();
    page_header('Счета и штрафы', $u, 'bills'); page_title('ПЛАТЁЖНЫЕ ТРЕБОВАНИЯ', 'Счета и штрафы', 'Выставляйте разовые платежи клиентам и отслеживайте их состояние.');
    ?><div class="split-layout"><section class="card"><h2>Выставить счёт</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="banker_issue_bill"><label class="field"><span>Клиент</span><select name="user_id" required><option value="">Выберите клиента</option><?php foreach($customers as $c):?><option value="<?= (int)$c['id'] ?>"><?= e($c['first_name'].' '.$c['last_name'].' · '.$c['email']) ?></option><?php endforeach;?></select></label><?php input_field('Название','title','text','Например, Штраф за парковку'); ?><label class="field"><span>Тип платежа</span><select name="bill_type"><option value="fine">Штраф</option><option value="invoice">Счёт</option><option value="utility">Коммунальный платёж</option><option value="other">Другое</option></select></label><?php input_field('Сумма, RUB','amount','number','0.00',['min'=>'0.01','step'=>'0.01']); input_field('Срок оплаты (необязательно)','due_date','date','',['required'=>false]); ?><label class="field"><span>Комментарий клиенту</span><textarea name="note" maxlength="500" placeholder="Основание, номер документа или пояснение"></textarea></label><button class="button" type="submit">Выставить счёт</button></form></section><section class="card help-card"><h3>Порядок работы</h3><ul><li>Клиент увидит выставленный счёт в личном кабинете.</li><li>Счёт можно оплатить целиком; повторное списание после оплаты недоступно.</li><li>Неоплаченный или частично оплаченный счёт можно отменить.</li><li>Все платежи и изменения сохраняются в истории.</li></ul></section></div><div class="section-head"><h2>Реестр счетов</h2></div><?php if(!$bills):?><div class="empty-state"><h2>Счетов пока нет</h2></div><?php else:?><div class="table-wrap"><table class="data-table"><thead><tr><th>Клиент</th><th>Счёт</th><th>Тип</th><th>Остаток</th><th>Срок</th><th>Статус</th><th>Действие</th></tr></thead><tbody><?php foreach($bills as $b):?><tr><td><?= e($b['first_name'].' '.$b['last_name']) ?><span class="table-secondary"><?= e($b['email']) ?></span></td><td><strong><?= e($b['title']) ?></strong><span class="table-secondary">№ <?= (int)$b['id'] ?></span></td><td><?= e(['fine'=>'Штраф','utility'=>'Коммунальный','invoice'=>'Счёт','other'=>'Другое'][$b['bill_type']]??$b['bill_type']) ?></td><td class="amount"><?= money((int)$b['remaining_minor']) ?><span class="table-secondary">из <?= money((int)$b['amount_minor']) ?></span></td><td><?= $b['due_date']?e(date('d.m.Y',strtotime($b['due_date']))):'—' ?></td><td><?= status_pill($b['status']) ?></td><td><?php if(in_array($b['status'],['unpaid','partial'],true)):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="banker_cancel_bill"><input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>"><button class="button button-danger button-small" data-confirm="Отменить счёт?" type="submit">Отменить</button></form><?php else:?><span class="muted small">—</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?><?php page_footer(); exit;
}

if ($page === 'banker_schedules') {
    $schedules = db()->query('SELECT so.*,a.account_number,a.name AS account_name,u.first_name,u.last_name,creator.first_name AS creator_first,creator.last_name AS creator_last FROM scheduled_operations so JOIN accounts a ON a.id=so.account_id JOIN users u ON u.id=a.user_id LEFT JOIN users creator ON creator.id=so.created_by ORDER BY so.next_run_at ASC')->fetchAll();
    page_header('Автооперации', $u, 'schedules'); page_title('ПЛАНИРОВЩИК', 'Автоматические операции', 'Контролируйте регулярные зарплаты, начисления и списания.');
    if(!$schedules):?><div class="empty-state"><div class="empty-icon">◷</div><h2>Расписаний пока нет</h2><p>Создайте первое расписание в разделе «Операции».</p><a class="button" href="?page=banker_operations">Создать операцию</a></div><?php else:?><div class="table-wrap"><table class="data-table"><thead><tr><th>Клиент / счёт</th><th>Операция</th><th>Сумма</th><th>Периодичность</th><th>Следующий запуск</th><th>Статус</th><th>Последняя ошибка</th><th>Действие</th></tr></thead><tbody><?php foreach($schedules as $s):?><tr><td><?= e($s['first_name'].' '.$s['last_name']) ?><span class="table-secondary"><?= e($s['account_name'].' · '.$s['account_number']) ?></span></td><td><?= e(['salary'=>'Зарплата','credit'=>'Пополнение','debit'=>'Списание'][$s['operation_type']]??$s['operation_type']) ?><span class="table-secondary"><?= e($s['title']) ?></span></td><td class="amount"><?= money((int)$s['amount_minor']) ?></td><td><?= e(frequency_label($s['frequency'])) ?></td><td><?= e(date('d.m.Y H:i',strtotime($s['next_run_at']))) ?></td><td><?= status_pill($s['status']) ?></td><td class="danger-note"><?= e($s['last_error']??'—') ?></td><td><?php if(in_array($s['status'],['active','paused'],true)):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="banker_toggle_schedule"><input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>"><button class="button button-small button-secondary" type="submit"><?= $s['status']==='active'?'Приостановить':'Возобновить' ?></button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif; ?><div class="form-note" style="margin-top:18px">Расписание исполняется командой <span class="mono">php bin/cron.php</span>. Настройте запуск через системный cron каждые 1–5 минут.</div><?php page_footer(); exit;
}

http_response_code(404);
render_error_page(404, 'Страница не найдена', 'Такой страницы нет. Возможно, она была перемещена.');

function render_transactions_table(array $history, int $viewerId, bool $showChart = false): void
{
    if ($showChart) {
        $months = [];
        $monthNames = ['янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек'];
        $currentMonth = new DateTimeImmutable('first day of this month');
        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $currentMonth->modify('-' . $offset . ' months');
            $key = $month->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $monthNames[(int)$month->format('n') - 1] . ' ' . $month->format('y'),
                'income' => 0,
                'expense' => 0,
            ];
        }
        $incomeTypes = ['deposit', 'salary', 'admin_credit', 'interest_credit'];
        $expenseTypes = ['withdrawal', 'admin_debit', 'bill_payment', 'fine_payment', 'auto_bill_payment'];
        foreach ($history as $tx) {
            $monthKey = date('Y-m', strtotime((string)$tx['created_at']));
            if (!isset($months[$monthKey])) continue;
            $fromOwner = (int)($tx['from_owner_id'] ?? 0);
            $toOwner = (int)($tx['to_owner_id'] ?? 0);
            $type = (string)$tx['transaction_type'];
            $bucket = null;
            if (in_array($type, $incomeTypes, true)) $bucket = 'income';
            elseif (in_array($type, $expenseTypes, true)) $bucket = 'expense';
            elseif ($toOwner === $viewerId && $fromOwner !== $viewerId) $bucket = 'income';
            elseif ($fromOwner === $viewerId && $toOwner !== $viewerId) $bucket = 'expense';
            // Transfers between the customer's own accounts are intentionally excluded.
            if ($bucket !== null) $months[$monthKey][$bucket] += (int)$tx['amount_minor'];
        }
        $chartJson = json_encode(array_values($months), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        echo '<section class="card history-chart-card" data-history-chart data-chart-data="' . e((string)$chartJson) . '">';
        echo '<div class="history-chart-head"><div><div class="eyebrow">НАГЛЯДНАЯ СТАТИСТИКА</div><h2>Движение денег</h2><p class="muted small">Сравнение поступлений и списаний за последние 12 месяцев.</p></div>';
        echo '<button type="button" class="button button-secondary" data-chart-toggle aria-expanded="false" aria-controls="history-chart-panel">Открыть диаграмму</button></div>';
        echo '<div id="history-chart-panel" data-chart-panel hidden><div class="chart-summary">';
        echo '<div class="chart-summary-item"><span><i class="chart-dot chart-dot-income"></i>Поступило за год</span><strong data-chart-total="income">—</strong></div>';
        echo '<div class="chart-summary-item"><span><i class="chart-dot chart-dot-expense"></i>Списано за год</span><strong data-chart-total="expense">—</strong></div>';
        echo '</div><canvas class="history-chart-canvas" data-chart-canvas role="img" aria-label="Столбчатая диаграмма поступлений и списаний за последние 12 месяцев"></canvas>';
        echo '<p class="chart-footnote">Переводы между вашими собственными счетами не учитываются как доход или расход, чтобы не задваивать оборот.</p></div></section>';
    }
    if (!$history) {
        echo '<div class="empty-state"><div class="empty-icon">≡</div><h2>Операций пока нет</h2><p>Когда вы совершите перевод или оплатите счёт, запись появится здесь.</p></div>';
        return;
    }
    echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Операция</th><th>Назначение / счёт</th><th>Сумма</th><th>Референс</th></tr></thead><tbody>';
    foreach ($history as $tx) {
        $fromOwner = (int)($tx['from_owner_id'] ?? 0); $toOwner = (int)($tx['to_owner_id'] ?? 0);
        $incoming = $toOwner === $viewerId && $fromOwner !== $viewerId;
        $outgoing = $fromOwner === $viewerId && $toOwner !== $viewerId;
        $label = transaction_label($tx, $viewerId);
        $sign = $outgoing || in_array($tx['transaction_type'], ['withdrawal','admin_debit','bill_payment','fine_payment','auto_bill_payment'], true) ? '−' : ($incoming || in_array($tx['transaction_type'], ['deposit','salary','admin_credit','interest_credit'], true) ? '+' : '');
        $class = $sign==='+'?'positive':($sign==='−'?'negative':'');
        $details = $tx['description'];
        if ($incoming) $details .= ' · от ' . ($tx['from_number'] ?? 'счёта');
        elseif ($outgoing) $details .= ' · на ' . ($tx['to_number'] ?? 'счёт');
        echo '<tr><td class="no-wrap">' . e(date('d.m.Y H:i', strtotime($tx['created_at']))) . '</td><td><strong>' . e($label) . '</strong><span class="table-secondary">' . e(transaction_type_detail_label((string)$tx['transaction_type'])) . '</span></td><td>' . e($details) . '</td><td class="amount ' . $class . '">' . e($sign) . money((int)$tx['amount_minor']) . '</td><td class="mono">' . e($tx['reference']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
