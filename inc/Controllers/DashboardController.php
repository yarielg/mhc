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
            'generated_at'   => current_time('mysql'),
            'stats'          => $this->stats(),
            'alerts'         => $this->alerts(),
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

    /** Stats for cards */
    protected function stats() {
        global $wpdb; $pfx = $wpdb->prefix;

        $tables = [
            'roles'        => "{$pfx}mhc_roles",
            'workers'      => "{$pfx}mhc_workers",
            'patients'     => "{$pfx}mhc_patients",
            'assignments'  => "{$pfx}mhc_patient_workers",
            'payrolls'     => "{$pfx}mhc_payrolls",
            'payrollItems' => "{$pfx}mhc_payroll_items",
        ];

        $out = [
            'roles'        => ['total' => 0, 'active' => 0],
            'workers'      => ['total' => 0, 'active' => 0],
            'patients'     => ['total' => 0, 'active' => 0],
            'assignments'  => 0,
            'payrolls_60d' => ['count' => 0, 'total_paid' => 0.0],
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

        if ($this->tableExists($tables['payrolls'])) {
            $out['payrolls_60d']['count'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$tables['payrolls']} WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)"
            );
        }

        if ($this->tableExists($tables['payrollItems'])) {
            if ($this->tableExists($tables['payrolls'])) {
                // Sum amounts inside payrolls started in last 60d
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $out['payrolls_60d']['total_paid'] = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(pi.amount),0)
                    FROM {$tables['payrollItems']} pi
                    INNER JOIN {$tables['payrolls']} p ON p.id = pi.payroll_id
                    WHERE p.start_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                ");
            } else {
                // Fallback by work_date if payrolls table not present
                $out['payrolls_60d']['total_paid'] = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(amount),0)
                    FROM {$tables['payrollItems']}
                    WHERE work_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                ");
            }
        }

        return $out;
    }

    /**
     * Alerts:
     *  - Over 30h same patient within latest payroll (or last 14d if no payrolls table).
     *  - Any negative amounts (adjustments/pending) in that period.
     */
    protected function alerts() {
        global $wpdb; $pfx = $wpdb->prefix;

        $payrolls = "{$pfx}mhc_payrolls";
        $items    = "{$pfx}mhc_payroll_items";
        $patients = "{$pfx}mhc_patients";
        $workers  = "{$pfx}mhc_workers";

        $alerts = ['over30h' => [], 'negAdjustments' => []];
        if (!$this->tableExists($items)) return $alerts;

        $latestPayrollId = null;
        if ($this->tableExists($payrolls)) {
            $latestPayrollId = (int) $wpdb->get_var("SELECT id FROM {$payrolls} ORDER BY start_date DESC LIMIT 1");
        }

        $joinPatient = $this->tableExists($patients) ? "LEFT JOIN {$patients} pa ON pa.id = pi.patient_id" : "";
        $joinWorker  = $this->tableExists($workers)  ? "LEFT JOIN {$workers}  w  ON w.id = pi.worker_id"  : "";

        $where = $latestPayrollId
            ? $wpdb->prepare("pi.payroll_id = %d", $latestPayrollId)
            : "pi.work_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)";

        // Over 30h
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $over = $wpdb->get_results("
            SELECT
              pi.worker_id,
              pi.patient_id,
              TRIM(CONCAT(COALESCE(w.first_name,''),' ',COALESCE(w.last_name,''))) AS worker_name,
              COALESCE(pa.first_name,'') AS patient_first,
              COALESCE(pa.last_name,'')  AS patient_last,
              SUM(pi.hours) AS hours
            FROM {$items} pi
            {$joinWorker}
            {$joinPatient}
            WHERE {$where}
            GROUP BY pi.worker_id, pi.patient_id, worker_name, patient_first, patient_last
            HAVING SUM(pi.hours) > 30
            ORDER BY hours DESC
        ", ARRAY_A) ?: [];

        foreach ($over as $row) {
            $alerts['over30h'][] = [
                'worker'  => $row['worker_name'] ?: ('Worker #'.$row['worker_id']),
                'patient' => trim(($row['patient_first'] ?? '').' '.($row['patient_last'] ?? '')) ?: ('Patient #'.$row['patient_id']),
                'hours'   => (float) $row['hours'],
            ];
        }

        // Negative adjustments (pending/adjustments entered as negative lines)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $neg = $wpdb->get_results("
            SELECT pi.id, pi.worker_id, pi.patient_id, pi.amount, pi.note
            FROM {$items} pi
            WHERE {$where} AND pi.amount < 0
            ORDER BY pi.id DESC
            LIMIT 20
        ", ARRAY_A) ?: [];

        $alerts['negAdjustments'] = $neg;
        return $alerts;
    }
}
