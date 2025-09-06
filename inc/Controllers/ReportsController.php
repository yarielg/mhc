<?php
namespace Mhc\Inc\Controllers;

defined('ABSPATH') || exit;

class ReportsController {
    const NONCE_ACTION = 'mhc_ajax';

    public function register() {
        add_action('wp_ajax_mhc_reports_worker_payments', [$this, 'ajax_worker_payments']);
        add_action('wp_ajax_mhc_reports_workers_month_totals', [$this, 'ajax_workers_month_totals']);
    }

    public function ajax_workers_month_totals() {
        try {
            if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], self::NONCE_ACTION)) {
                throw new \Exception('Invalid nonce');
            }
            if (!current_user_can('read')) { // tighten if needed
                throw new \Exception('Unauthorized');
            }
            global $wpdb; $pfx = $wpdb->prefix;

            $start = sanitize_text_field($_POST['start_date'] ?? '');
            $end   = sanitize_text_field($_POST['end_date'] ?? '');
            if (!$start || !$end) throw new \Exception('start_date and end_date required');

            // HOURS per worker (qty + money), filtering by payroll end_date
            $sqlH = "
      SELECT wpr.worker_id,
             CONCAT_WS(' ', w.first_name, w.last_name) AS worker_name,
             SUM(he.hours) AS hours_qty,
             SUM(he.total) AS hours_amount
      FROM {$pfx}mhc_hours_entries he
      INNER JOIN {$pfx}mhc_payroll_segments seg ON seg.id = he.segment_id
      INNER JOIN {$pfx}mhc_payrolls p ON p.id = seg.payroll_id
      INNER JOIN {$pfx}mhc_worker_patient_roles wpr ON wpr.id = he.worker_patient_role_id
      INNER JOIN {$pfx}mhc_workers w ON w.id = wpr.worker_id
      WHERE p.end_date BETWEEN %s AND %s
      GROUP BY wpr.worker_id
    ";
            $hours = $wpdb->get_results($wpdb->prepare($sqlH, $start, $end), ARRAY_A);

            // EXTRAS per worker (money), filtering by payroll end_date
            $sqlE = "
      SELECT ep.worker_id, SUM(ep.amount) AS extras_total
      FROM {$pfx}mhc_extra_payments ep
      INNER JOIN {$pfx}mhc_payrolls p ON p.id = ep.payroll_id
      WHERE p.end_date BETWEEN %s AND %s
      GROUP BY ep.worker_id
    ";
            $extras = $wpdb->get_results($wpdb->prepare($sqlE, $start, $end), ARRAY_A);
            $mapExtras = [];
            foreach ($extras as $ex) $mapExtras[(int)$ex['worker_id']] = (float)$ex['extras_total'];

            // Merge per worker
            $items = [];
            $seen  = [];
            $tot_hours_qty = 0.0; $tot_hours_amt = 0.0; $tot_extras = 0.0;
            foreach ($hours as $h) {
                $wid   = (int)$h['worker_id'];
                $qty   = (float)($h['hours_qty'] ?? 0);
                $hamt  = (float)($h['hours_amount'] ?? 0);
                $ex    = (float)($mapExtras[$wid] ?? 0);
                $items[] = [
                    'worker_id'    => $wid,
                    'worker_name'  => $h['worker_name'] ?: "Worker #{$wid}",
                    'hours_qty'    => $qty,
                    'extras_total' => $ex,
                    'grand_total'  => $hamt + $ex,
                ];
                $seen[$wid] = true;
                $tot_hours_qty += $qty; $tot_hours_amt += $hamt; $tot_extras += $ex;
            }

            // Workers that only have extras (no hours in that month)
            if (!empty($mapExtras)) {
                $widOnlyExtras = array_diff(array_keys($mapExtras), array_keys($seen));
                if ($widOnlyExtras) {
                    $in = implode(',', array_map('intval', $widOnlyExtras));
                    $names = $wpdb->get_results("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name FROM {$pfx}mhc_workers WHERE id IN ($in)", OBJECT_K);
                    foreach ($widOnlyExtras as $wid) {
                        $ex = (float)$mapExtras[$wid];
                        $items[] = [
                            'worker_id'    => (int)$wid,
                            'worker_name'  => $names[$wid]->name ?? "Worker #{$wid}",
                            'hours_qty'    => 0,
                            'extras_total' => $ex,
                            'grand_total'  => $ex,
                        ];
                        $tot_extras += $ex;
                    }
                }
            }

            // Sort by grand_total desc
            usort($items, fn($a,$b) => ($b['grand_total'] <=> $a['grand_total']) ?: strcmp($a['worker_name'], $b['worker_name']));

            wp_send_json_success([
                'items'  => $items,
                'totals' => [
                    'hours_qty'    => round($tot_hours_qty, 2),
                    'extras_total' => round($tot_extras, 2),
                    'grand_total'  => round($tot_hours_amt + $tot_extras, 2),
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public function ajax_worker_payments() {
        try {
            if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], self::NONCE_ACTION)) {
                throw new \Exception('Invalid nonce');
            }
            if (!current_user_can('read')) { // adjust capability if needed
                throw new \Exception('Unauthorized');
            }

            global $wpdb;
            $pfx = $wpdb->prefix;

            $worker_id = isset($_POST['worker_id']) ? intval($_POST['worker_id']) : 0;
            $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
            $end_date   = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : '';

            if (!$worker_id || !$start_date || !$end_date) {
                throw new \Exception('Missing parameters');
            }

            // Normalize: swap if reversed
            if (strtotime($start_date) > strtotime($end_date)) {
                [$start_date, $end_date] = [$end_date, $start_date];
            }

            // HOURS (regular)
            $sql_hours = "
        SELECT
          p.id              AS payroll_id,
          p.end_date        AS payroll_end,
          he.id             AS hours_entry_id,
          he.hours,
          he.used_rate      AS rate,
          he.total,
          CONCAT(pa.first_name, ' ', pa.last_name) AS patient,
          r.code            AS role
        FROM {$pfx}mhc_hours_entries      he
        INNER JOIN {$pfx}mhc_payroll_segments seg ON seg.id = he.segment_id
        INNER JOIN {$pfx}mhc_payrolls      p   ON p.id = seg.payroll_id
        INNER JOIN {$pfx}mhc_worker_patient_roles wpr ON wpr.id = he.worker_patient_role_id
        LEFT  JOIN {$pfx}mhc_patients      pa  ON pa.id = wpr.patient_id
        LEFT  JOIN {$pfx}mhc_roles         r   ON r.id = wpr.role_id
        WHERE p.end_date BETWEEN %s AND %s
          AND wpr.worker_id = %d
        ORDER BY p.end_date ASC, he.id ASC
      ";
            $hours_rows = $wpdb->get_results($wpdb->prepare($sql_hours, $start_date, $end_date, $worker_id), ARRAY_A);

            // EXTRAS (assessments, supervision, pendings, etc.)
            $sql_extras = "
        SELECT
          p.id          AS payroll_id,
          p.end_date    AS payroll_end,
          ep.id         AS extra_id,
          sr.label      AS label,
          ep.amount     AS amount,
          CONCAT(pa.first_name, ' ', pa.last_name) AS patient
        FROM {$pfx}mhc_extra_payments ep
        INNER JOIN {$pfx}mhc_payrolls  p  ON p.id = ep.payroll_id
        LEFT  JOIN {$pfx}mhc_special_rates sr ON sr.id = ep.special_rate_id
        LEFT  JOIN {$pfx}mhc_patients pa ON pa.id = ep.patient_id
        WHERE p.end_date BETWEEN %s AND %s
          AND ep.worker_id = %d
        ORDER BY p.end_date ASC, ep.id ASC
      ";
            $extra_rows = $wpdb->get_results($wpdb->prepare($sql_extras, $start_date, $end_date, $worker_id), ARRAY_A);

            // Build unified items
            $items = [];
            $hours_total = 0.0;
            foreach ($hours_rows as $row) {
                $hours_total += floatval($row['total']);
                $items[] = [
                    'type'        => 'hours',
                    'payroll_id'  => intval($row['payroll_id']),
                    'payroll_end' => $row['payroll_end'],
                    'patient'     => $row['patient'],
                    'role'        => $row['role'],
                    'hours'       => (float)$row['hours'],
                    'rate'        => (float)$row['rate'],
                    'total'       => (float)$row['total'],
                ];
            }

            $extras_total = 0.0;
            foreach ($extra_rows as $row) {
                $extras_total += floatval($row['amount']);
                $items[] = [
                    'type'        => 'extra',
                    'payroll_id'  => intval($row['payroll_id']),
                    'payroll_end' => $row['payroll_end'],
                    'patient'     => $row['patient'],
                    'label'       => $row['label'],
                    'amount'      => (float)$row['amount'],
                    'total'       => (float)$row['amount'],
                ];
            }

            // Sort by payroll_end then type (optional; hours before extras)
            usort($items, function($a, $b) {
                $cmp = strcmp($a['payroll_end'], $b['payroll_end']);
                if ($cmp !== 0) return $cmp;
                return strcmp($a['type'], $b['type']);
            });

            // By-month summary (YYYY-MM)
            $by_month = [];
            foreach ($items as $it) {
                $ym = substr($it['payroll_end'], 0, 7);
                if (!isset($by_month[$ym])) $by_month[$ym] = 0.0;
                $by_month[$ym] += floatval($it['total']);
            }
            $by_month_rows = [];
            foreach ($by_month as $ym => $sum) {
                $by_month_rows[] = ['ym' => $ym, 'total' => round($sum, 2)];
            }
            usort($by_month_rows, fn($a, $b) => strcmp($a['ym'], $b['ym']));

            wp_send_json_success([
                'items'  => $items,
                'totals' => [
                    'hours_total'  => round($hours_total, 2),
                    'extras_total' => round($extras_total, 2),
                    'grand_total'  => round($hours_total + $extras_total, 2),
                ],
                'by_month' => $by_month_rows,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }
}