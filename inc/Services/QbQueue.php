<?php

namespace Mhc\Inc\Services;

defined('ABSPATH') || exit;

class QbQueue
{
    /** Ensure tables exist. Call on admin_init or plugin load. */
    public static function maybe_create_tables()
    {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $qtable = $pfx . 'mhc_qb_queue';
        $ctable = $pfx . 'mhc_qb_checks';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$qtable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payroll_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            period_start DATE DEFAULT NULL,
            period_end DATE DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            qb_check_id VARCHAR(191) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payroll_worker (payroll_id, worker_id),
            PRIMARY KEY (id)
        ) {$charset};";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$ctable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payroll_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            qb_check_id VARCHAR(191) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_payroll (payroll_id),
            KEY idx_worker (worker_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($sql2);

        // Ensure queue table has available_at column for scheduling retries
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$qtable}", 0);
        if (!in_array('available_at', $cols)) {
            $wpdb->query("ALTER TABLE {$qtable} ADD COLUMN available_at DATETIME NULL DEFAULT NULL AFTER updated_at");
        }
    }

    /** Enqueue a single worker/payroll row (idempotent) */
    public static function enqueueWorker(int $payroll_id, int $worker_id, float $amount, ?string $period_start = null, ?string $period_end = null): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhc_qb_queue';

        // If exists and status is done, skip
        $exists = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table} WHERE payroll_id=%d AND worker_id=%d", $payroll_id, $worker_id));
        if ($exists) {
            if ($exists->status === 'done') return false;
            // already enqueued or failed -> update amount and leave status
            $wpdb->update($table, [
                'amount' => $amount,
                'period_start' => $period_start,
                'period_end' => $period_end,
            ], ['id' => $exists->id]);
            return true;
        }

        $ok = $wpdb->insert($table, [
            'payroll_id' => $payroll_id,
            'worker_id' => $worker_id,
            'amount' => $amount,
            'period_start' => $period_start,
            'period_end' => $period_end,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        return (bool)$ok;
    }

    /** Enqueue all workers for a payroll (builds totals similar to ajax_all_checks_qb) */
    public static function enqueuePayroll(int $payroll_id): array
    {
        global $wpdb;
        $pfx = $wpdb->prefix;

        // 1) workers with hours
        $res1 = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT w.id
            FROM {$pfx}mhc_hours_entries he
            INNER JOIN {$pfx}mhc_worker_patient_roles wpr ON wpr.id = he.worker_patient_role_id
            INNER JOIN {$pfx}mhc_workers w ON w.id = wpr.worker_id
            INNER JOIN {$pfx}mhc_payroll_segments seg ON seg.id = he.segment_id
            WHERE seg.payroll_id = %d
        ", $payroll_id));

        $worker_ids = [];
        $workers = [];
        foreach ($res1 as $w) { $worker_ids[$w->id] = true; $workers[$w->id] = $w; }

        // 2) workers with extras
        $res2 = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT w.id
            FROM {$pfx}mhc_extra_payments ep
            INNER JOIN {$pfx}mhc_workers w ON w.id = ep.worker_id
            WHERE ep.payroll_id = %d
        ", $payroll_id));
        foreach ($res2 as $w) { if (!isset($worker_ids[$w->id])) { $worker_ids[$w->id] = true; $workers[$w->id] = $w; } }

        if (empty($workers)) return ['queued' => 0, 'skipped' => 0];

        // load payroll dates
        $payroll = \Mhc\Inc\Models\Payroll::findById($payroll_id);
        $start = $payroll->start_date ?? date('Y-m-d');
        $end = $payroll->end_date ?? date('Y-m-d');

        $queued = 0;
        foreach (array_keys($workers) as $wid) {
            // compute totals per worker similar to ajax_all_checks_qb
            $hours = \Mhc\Inc\Models\HoursEntry::listDetailedForPayroll($payroll_id, ['worker_id' => $wid]);
            $extras = \Mhc\Inc\Models\ExtraPayment::listDetailedForPayroll($payroll_id, ['worker_id' => $wid]);
            $ta = 0.0; $te = 0.0; foreach ($hours as $h) $ta += (float)$h->total; foreach ($extras as $e) $te += (float)$e->amount;
            $grand_total = round($ta + $te, 2);
            if ($grand_total <= 0) continue;
            if (self::enqueueWorker($payroll_id, $wid, $grand_total, $start, $end)) $queued++;
        }
        return ['queued' => $queued];
    }

    /** Process a limited number of pending queue items. Returns summary array */
    public static function processQueue(int $limit = 5, bool $force = false): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhc_qb_queue';
        $ctable = $wpdb->prefix . 'mhc_qb_checks';

        // Only pick rows that are pending and whose available_at is null or <= now (unless force)
        if ($force) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC LIMIT %d", 'pending', $limit));
        } else {
            $now = current_time('mysql');
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s AND (available_at IS NULL OR available_at <= %s) ORDER BY created_at ASC LIMIT %d", 'pending', $now, $limit));
        }

        $created = [];
        $errors = [];

        foreach ($rows as $r) {
            // mark processing
            $wpdb->update($table, ['status' => 'processing', 'updated_at' => current_time('mysql')], ['id' => $r->id]);

            // Use existing controller logic to create check
            $controller = new \Mhc\Inc\Controllers\QuickBooksController();
            $res = $controller->create_check_for_worker((int)$r->worker_id, (float)$r->amount, $r->period_start, $r->period_end);

            if (is_wp_error($res)) {
                $attempts = (int)$r->attempts + 1;
                $last_error = $res->get_error_message();
                // Exponential backoff: available_at = now + (2^(attempts-1) * 60) seconds
                $backoff_seconds = pow(2, max(0, $attempts - 1)) * 60;
                $available_at = date('Y-m-d H:i:s', time() + $backoff_seconds);

                if ($attempts >= 5) {
                    // mark permanently failed after max attempts
                    $wpdb->update($table, ['status' => 'failed', 'attempts' => $attempts, 'last_error' => $last_error, 'updated_at' => current_time('mysql')], ['id' => $r->id]);
                } else {
                    // set attempts and next available_at and set status back to pending
                    $wpdb->update($table, ['status' => 'pending', 'attempts' => $attempts, 'last_error' => $last_error, 'available_at' => $available_at, 'updated_at' => current_time('mysql')], ['id' => $r->id]);
                }

                $errors[] = ['worker_id' => $r->worker_id, 'error' => $last_error, 'attempts' => $attempts];
                continue;
            }

            // success: insert into checks table and update queue row
            $qb_id = $res['check_id'] ?? null;
            $wpdb->insert($ctable, [
                'payroll_id' => $r->payroll_id,
                'worker_id' => $r->worker_id,
                'qb_check_id' => $qb_id,
                'amount' => $r->amount,
            ]);

            $wpdb->update($table, ['status' => 'done', 'qb_check_id' => $qb_id, 'updated_at' => current_time('mysql')], ['id' => $r->id]);
            $created[] = ['worker_id' => $r->worker_id, 'check_id' => $qb_id];
        }

        return ['created' => $created, 'errors' => $errors];
    }
}
