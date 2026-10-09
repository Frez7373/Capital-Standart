<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$lock = $pdo->query("SELECT GET_LOCK('cci_bank_scheduler', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    fwrite(STDOUT, "Scheduler is already running; exiting.\n");
    exit(0);
}
$done = 0;
$failed = 0;
$now = new DateTimeImmutable('now');
$interestAccounts = 0;
$gibddCreated = 0;
$gibddCallbacks = 0;

try {
    try {
        $interestAccounts = process_savings_interest($now);
    } catch (Throwable $interestError) {
        // Keep recurring payments operational while an older database is being migrated.
        $failed++;
        error_log('Капитал-Стандарт: interest processing unavailable: ' . $interestError->getMessage());
    }
    // Import GIBDD fines before recurring bill auto-pay rules run.
    try {
        $gibddSync = sync_gibdd_fines();
        $gibddCreated = (int)($gibddSync['created'] ?? 0);
        if (!empty($gibddSync['errors'])) $failed += (int)$gibddSync['errors'];
    } catch (Throwable $gibddError) {
        $failed++;
        error_log('Капитал-Стандарт: GIBDD fine sync failed: ' . $gibddError->getMessage());
    }

    // Customer recurring transfers and bill auto-pay rules.
    $rules = $pdo->prepare("SELECT * FROM recurring_rules WHERE status='active' AND next_run_at <= ? ORDER BY next_run_at ASC LIMIT 100");
    $rules->execute([$now->format('Y-m-d H:i:s')]);
    foreach ($rules->fetchAll() as $rule) {
        $ruleId = (int)$rule['id'];
        $userId = (int)$rule['user_id'];
        $next = next_run_date($rule['frequency'], $now);
        $error = null;
        if ($rule['ends_at'] && $rule['ends_at'] < $now->format('Y-m-d')) {
            $pdo->prepare("UPDATE recurring_rules SET status='completed', last_run_at=?, last_error=NULL WHERE id=? AND status='active'")
                ->execute([$now->format('Y-m-d H:i:s'), $ruleId]);
            continue;
        }
        try {
            if ($rule['rule_type'] === 'transfer') {
                post_money_transaction('auto_transfer', (int)$rule['source_account_id'], (int)$rule['destination_account_id'], (int)$rule['amount_minor'], 'Автоперевод: ' . $rule['title'], $userId, null, 'rule:' . $ruleId . ':' . $rule['next_run_at']);
            } else {
                $billStmt = $pdo->prepare("SELECT id, remaining_minor FROM bills WHERE user_id=? AND status IN ('unpaid','partial') AND (due_date IS NULL OR due_date <= ?) ORDER BY due_date IS NULL, due_date ASC, created_at ASC");
                $billStmt->execute([$userId, $now->format('Y-m-d')]);
                foreach ($billStmt->fetchAll() as $bill) {
                    try {
                        pay_bill((int)$bill['id'], (int)$rule['source_account_id'], 0, $userId, $userId, true);
                    } catch (Throwable $billError) {
                        if (str_contains(mb_strtolower($billError->getMessage()), 'недостаточно средств')) {
                            $error = $billError->getMessage();
                            break;
                        }
                        // A bill might have been paid/cancelled during this pass; continue safely.
                        $error = $billError->getMessage();
                    }
                }
            }
            $done++;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $failed++;
            error_log('Капитал-Стандарт: recurring rule #' . $ruleId . ' failed: ' . $e->getMessage());
        }
        $completed = $rule['ends_at'] && $rule['ends_at'] <= $next->format('Y-m-d') ? 'completed' : 'active';
        $pdo->prepare('UPDATE recurring_rules SET next_run_at=?, last_run_at=?, last_error=?, status=? WHERE id=?')
            ->execute([$next->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $error ? mb_substr($error,0,255) : null, $completed, $ruleId]);
    }

    // Banker-defined payrolls, credits and scheduled debits.
    $opsStmt = $pdo->prepare("SELECT * FROM scheduled_operations WHERE status='active' AND next_run_at <= ? ORDER BY next_run_at ASC LIMIT 100");
    $opsStmt->execute([$now->format('Y-m-d H:i:s')]);
    foreach ($opsStmt->fetchAll() as $op) {
        $operationId = (int)$op['id'];
        $frequency = $op['frequency'];
        $next = $frequency === 'once' ? null : next_run_date($frequency, $now);
        try {
            if ($op['operation_type'] === 'debit') {
                post_money_transaction('admin_debit', (int)$op['account_id'], null, (int)$op['amount_minor'], $op['title'], $op['created_by'] ? (int)$op['created_by'] : null, null, 'schedule:' . $operationId . ':' . $op['next_run_at']);
            } else {
                post_money_transaction($op['operation_type'] === 'salary' ? 'salary' : 'admin_credit', null, (int)$op['account_id'], (int)$op['amount_minor'], $op['title'], $op['created_by'] ? (int)$op['created_by'] : null, null, 'schedule:' . $operationId . ':' . $op['next_run_at']);
            }
            $done++;
            if ($frequency === 'once') {
                $pdo->prepare("UPDATE scheduled_operations SET status='completed', last_run_at=?, last_error=NULL WHERE id=?")->execute([$now->format('Y-m-d H:i:s'), $operationId]);
            } else {
                $pdo->prepare("UPDATE scheduled_operations SET next_run_at=?, last_run_at=?, last_error=NULL WHERE id=?")->execute([$next->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $operationId]);
            }
        } catch (Throwable $e) {
            $failed++;
            error_log('Капитал-Стандарт: scheduled operation #' . $operationId . ' failed: ' . $e->getMessage());
            if ($frequency === 'once') {
                $pdo->prepare("UPDATE scheduled_operations SET status='failed', last_run_at=?, last_error=? WHERE id=?")->execute([$now->format('Y-m-d H:i:s'), mb_substr($e->getMessage(),0,255), $operationId]);
            } else {
                $pdo->prepare('UPDATE scheduled_operations SET next_run_at=?, last_run_at=?, last_error=? WHERE id=?')
                    ->execute([$next->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), mb_substr($e->getMessage(),0,255), $operationId]);
            }
        }
    }
} finally {
    // Retry callbacks that could not reach AutoControl 200 after an earlier payment.
    try { $gibddCallbacks = notify_gibdd_callbacks(100); }
    catch (Throwable $gibddCallbackError) {
        $failed++;
        error_log('Капитал-Стандарт: GIBDD callbacks failed: ' . $gibddCallbackError->getMessage());
    }
    $pdo->query("SELECT RELEASE_LOCK('cci_bank_scheduler')");
}

fwrite(STDOUT, sprintf("Планировщик «Капитал-Стандарт» завершён. Processed: %d; failed: %d; savings interest accounts: %d; GIBDD fines imported: %d; GIBDD payments confirmed: %d; at %s\n", $done, $failed, $interestAccounts, $gibddCreated, $gibddCallbacks, $now->format('Y-m-d H:i:s')));
