<?php
namespace Mhc\Inc\Controllers;

class DashboardController {

    /** Register AJAX routes */
    public function register() {
        add_action('wp_ajax_mhc_dashboard_get',   [$this, 'get']);
    }

    /** Core handler: aggregated dashboard payload */
    public function get() {
        self::check();

        $payload = [
            'generated_at' => current_time('mysql'),
            'stats'        => $this->stats(),
            'alerts'       => $this->alerts(),
            'quick'        => $this->quick(),
            'recent'       => $this->recent(),
        ];

        wp_send_json_success($payload);
    }

    /** Security */
    protected static function check() {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }

    /** Helpers */
    protected function tableExists($table) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return $exists === $table;
    }

    protected function getColumns($table) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A) ?: [];
        return array_map(static fn($r) => $r['Field'], $rows);
    }

    /** Stats for cards (totals + 60d window) */
    protected function stats() {
        global $wpdb; $pfx = $wpdb->prefix;

        $tables = [
            'roles'        => "{$pfx}mhc_roles",
            'workers'      => "{$pfx}mhc_workers",
            'patients'     => "{$pfx}mhc_patients",
            'assignments'  => "{$pfx}mhc_patient_workers",
            'payrolls'     => "{$pfx}mhc_payrolls",
            'hours'        => "{$pfx}mhc_hours_entries",
            'extras'       => "{$pfx}mhc_extra_payments",
        ];

        $out = [
            'roles'        => ['total' => 0, 'active' => 0],
            'workers'      => ['total' => 0, 'active' => 0],
            'patients'     => ['total' => 0, 'active' => 0],
            'assignments'  => 0,
            'payrolls_60d' => ['count' => 0, 'total_paid' => 0.0], // total_paid = hours.total + extras.amount (>=0 and <0)
        ];

        if ($this->tableExists($tables['roles'])) {
            $out['roles']['total']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['roles']}");
            $out['roles']['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['roles']} WHERE is_active=1");
        }

        if ($this->tableExists($tables['workers'])) {
            $out['workers']['total']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['workers']}");
            $out['workers']['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['workers']} WHERE is_active=1");
        }

        if ($this->tableExists($tables['patients'])) {
            $out['patients']['total']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['patients']}");
            $hasActive = $wpdb->get_row("SHOW COLUMNS FROM {$tables['patients']} LIKE 'is_active'");
            if ($hasActive) {
                $out['patients']['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['patients']} WHERE is_active=1");
            }
        }

        if ($this->tableExists($tables['assignments'])) {
            $out['assignments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['assignments']}");
        }

        // Payroll count in last 60d
        if ($this->tableExists($tables['payrolls'])) {
            $out['payrolls_60d']['count'] = (int) $wpdb->get_var("
                SELECT COUNT(*) FROM {$tables['payrolls']}
                WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
            ");
        }

        // Total paid in last 60d: SUM(hours.total) + SUM(extras.amount) for payrolls started in last 60d
        if ($this->tableExists($tables['payrolls'])) {
            $payrollIdsSql = "
                SELECT id FROM {$tables['payrolls']}
                WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
            ";

            $sumHours = 0.0;
            if ($this->tableExists($tables['hours'])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $sumHours = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(h.total),0)
                    FROM {$tables['hours']} h
                    INNER JOIN ({$payrollIdsSql}) p ON p.id = h.payroll_id
                ");
            }

            $sumExtras = 0.0;
            if ($this->tableExists($tables['extras'])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $sumExtras = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(e.amount),0)
                    FROM {$tables['extras']} e
                    INNER JOIN ({$payrollIdsSql}) p ON p.id = e.payroll_id
                ");
            }

            $out['payrolls_60d']['total_paid'] = $sumHours + $sumExtras;
        }

        return $out;
    }

    /**
     * Alerts:
     *  - Over 30h same patient within latest payroll (if exists) or last 14d fallback.
     *  - Negative extra payments (amount < 0) in that period.
     */
    protected function alerts() {
        global $wpdb; $pfx = $wpdb->prefix;

        $payrolls    = "{$pfx}mhc_payrolls";
        $hours       = "{$pfx}mhc_hours_entries";
        $extras      = "{$pfx}mhc_extra_payments";
        $assignments = "{$pfx}mhc_patient_workers";
        $patients    = "{$pfx}mhc_patients";
        $workers     = "{$pfx}mhc_workers";

        $alerts = ['over30h' => [], 'negAdjustments' => []];

        $haveHours  = $this->tableExists($hours);
        $haveExtras = $this->tableExists($extras);

        if (!$haveHours && !$haveExtras) return $alerts;

        $latestPayrollId = null;
        if ($this->tableExists($payrolls)) {
            $latestPayrollId = (int) $wpdb->get_var("SELECT id FROM {$payrolls} ORDER BY start_date DESC LIMIT 1");
        }

        // --- Over 30h (by worker & patient)
        if ($haveHours) {
            // We need to join hours -> assignments to fetch worker & patient names.
            $joins = [];
            $joins[] = "LEFT JOIN {$assignments} ap ON ap.id = h.worker_patient_role_id";
            if ($this->tableExists($workers))  $joins[] = "LEFT JOIN {$workers}  w ON w.id = ap.worker_id";
            if ($this->tableExists($patients)) $joins[] = "LEFT JOIN {$patients} pa ON pa.id = ap.patient_id";
            $joinSql = implode("\n", $joins);

            // Window filter: latest payroll OR last 14 days via created_at
            $where = $latestPayrollId
                ? $wpdb->prepare("h.payroll_id = %d", $latestPayrollId)
                : "h.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $over = $wpdb->get_results("
                SELECT
                  ap.worker_id,
                  ap.patient_id,
                  TRIM(CONCAT(COALESCE(w.first_name,''),' ',COALESCE(w.last_name,''))) AS worker_name,
                  COALESCE(pa.first_name,'') AS patient_first,
                  COALESCE(pa.last_name,'')  AS patient_last,
                  SUM(h.hours) AS hours
                FROM {$hours} h
                {$joinSql}
                WHERE {$where}
                GROUP BY ap.worker_id, ap.patient_id, worker_name, patient_first, patient_last
                HAVING SUM(h.hours) > 30
                ORDER BY hours DESC
            ", ARRAY_A) ?: [];

            foreach ($over as $row) {
                $alerts['over30h'][] = [
                    'worker'  => $row['worker_name'] ?: ('Worker #'.$row['worker_id']),
                    'patient' => trim(($row['patient_first'] ?? '').' '.($row['patient_last'] ?? '')) ?: ('Patient #'.$row['patient_id']),
                    'hours'   => (float) $row['hours'],
                ];
            }
        }

        // --- Negative adjustments from extras
        if ($haveExtras) {
            $whereExtras = $latestPayrollId
                ? $wpdb->prepare("e.payroll_id = %d", $latestPayrollId)
                : "e.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $neg = $wpdb->get_results("
                SELECT e.id, e.worker_id, e.patient_id, e.amount, e.notes AS note
                FROM {$extras} e
                WHERE {$whereExtras} AND e.amount < 0
                ORDER BY e.id DESC
                LIMIT 20
            ", ARRAY_A) ?: [];

            $alerts['negAdjustments'] = $neg;
        }

        return $alerts;
    }

    /** Quick metrics (cards) */
    protected function quick() {
        global $wpdb; $pfx = $wpdb->prefix;

        $payrolls = "{$pfx}mhc_payrolls";
        $hours    = "{$pfx}mhc_hours_entries";
        $extras   = "{$pfx}mhc_extra_payments";

        $out = ['hours_14d' => 0, 'neg_count' => 0];

        $haveHours  = $this->tableExists($hours);
        $haveExtras = $this->tableExists($extras);

        if ($haveHours) {
            if ($this->tableExists($payrolls)) {
                // Hours in the most recent 14 days window of *calendar time* (fallback to created_at if needed)
                // Using created_at to avoid depending on a work_date column.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $out['hours_14d'] = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(h.hours),0)
                    FROM {$hours} h
                    WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ");
            } else {
                // No payrolls table, same query anyway
                $out['hours_14d'] = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(h.hours),0)
                    FROM {$hours} h
                    WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ");
            }
        }

        if ($haveExtras) {
            // Negative count in last 14 days
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $out['neg_count'] = (int) $wpdb->get_var("
                SELECT COUNT(*) FROM {$extras}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND amount < 0
            ");
        }

        return $out;
    }

    /** Recent payrolls list (latest 5 with counts/totals) */
    protected function recent() {
        global $wpdb; $pfx = $wpdb->prefix;

        $payrolls = "{$pfx}mhc_payrolls";
        $hours    = "{$pfx}mhc_hours_entries";
        $extras   = "{$pfx}mhc_extra_payments";
        $out      = ['payrolls' => []];

        if (!$this->tableExists($payrolls)) return $out;

        // Get last 5 payrolls
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results("
            SELECT id, start_date, end_date, status
            FROM {$payrolls}
            ORDER BY start_date DESC
            LIMIT 5
        ", ARRAY_A) ?: [];

        if (!$rows) return $out;

        $haveHours  = $this->tableExists($hours);
        $haveExtras = $this->tableExists($extras);

        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            $payload = [
                'id'         => $pid,
                'start_date' => $r['start_date'],
                'end_date'   => $r['end_date'],
                'status'     => $r['status'],
                'items'      => 0,
                'total'      => 0.0,
            ];

            $cnt = 0; $sum = 0.0;

            if ($haveHours) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $cnt += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$hours} WHERE payroll_id = %d", $pid
                ));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $sum += (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(total),0) FROM {$hours} WHERE payroll_id = %d", $pid
                ));
            }

            if ($haveExtras) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $cnt += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$extras} WHERE payroll_id = %d", $pid
                ));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $sum += (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount),0) FROM {$extras} WHERE payroll_id = %d", $pidmc
                ));
            }

            $payload['items'] = $cnt;
            $payload['total'] = $sum;

            $out['payrolls'][] = $payload;
        }

        return $out;
    }
}
