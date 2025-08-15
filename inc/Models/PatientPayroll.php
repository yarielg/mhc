
<?php
namespace Mhc\Inc\Models;

class PatientPayroll {
    public static function findById($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$pfx}mhc_patient_payrolls WHERE id = %d", $id),
            ARRAY_A
        );
    }

    // Lista de pacientes por payroll, con join a pacientes y filtro opcional de is_processed
    public static function findByPayroll($payroll_id, $args = []) {
        global $wpdb;
        $pfx = $wpdb->prefix;

        $where = ["pp.payroll_id = %d"];
        $params = [$payroll_id];

        if (isset($args['is_processed']) && $args['is_processed'] !== '' && $args['is_processed'] !== 'all') {
            $where[] = "pp.is_processed = %d";
            $params[] = (int)$args['is_processed'];
        }

        $sql = "
            SELECT
                pp.id,
                pp.payroll_id,
                pp.patient_id,
                pp.is_processed,
                p.first_name,
                p.last_name
            FROM {$pfx}mhc_patient_payrolls pp
            INNER JOIN {$pfx}mhc_patients p ON p.id = pp.patient_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.last_name ASC, p.first_name ASC
        ";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    }

    // Crear una fila (uso interno)
    public static function create($data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $wpdb->insert("{$pfx}mhc_patient_payrolls", [
            'patient_id' => (int)$data['patient_id'],
            'payroll_id' => (int)$data['payroll_id'],
            'is_processed' => isset($data['is_processed']) ? (int)$data['is_processed'] : 0,
            'created_at' => current_time('mysql')
        ], ['%d','%d','%d','%s']);
        return (int)$wpdb->insert_id;
    }

    // Actualizar (uso interno)
    public static function update($id, $data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $fields = [];
        $formats = [];
        $where = ['id' => (int)$id];
        $where_format = ['%d'];

        if (isset($data['is_processed'])) { $fields['is_processed'] = (int)$data['is_processed']; $formats[] = '%d'; }
        if (!empty($fields)) {
            $fields['updated_at'] = current_time('mysql');
            $formats[] = '%s';
            return $wpdb->update("{$pfx}mhc_patient_payrolls", $fields, $where, $formats, $where_format) !== false;
        }
        return false;
    }

    public static function delete($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        return (bool)$wpdb->delete("{$pfx}mhc_patient_payrolls", ['id' => (int)$id], ['%d']);
    }

    // Marcar procesado / no procesado para un patient en un payroll
    public static function setProcessed($payroll_id, $patient_id, $flag) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $row_id = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$pfx}mhc_patient_payrolls
            WHERE payroll_id = %d AND patient_id = %d
            LIMIT 1
        ", $payroll_id, $patient_id));

        if ($row_id) {
            return self::update((int)$row_id, ['is_processed' => (int)$flag]);
        }
        return false;
    }

    // Sembrar todos los pacientes activos en un payroll (evita duplicar si ya existe)
    public static function seedForPayroll($payroll_id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $payroll_id = (int)$payroll_id;

        // Pacientes activos
        $patients = $wpdb->get_col("SELECT id FROM {$pfx}mhc_patients WHERE is_active = 1");
        if (empty($patients)) return 0;

        $inserted = 0;
        foreach ($patients as $patient_id) {
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$pfx}mhc_patient_payrolls
                WHERE payroll_id = %d AND patient_id = %d
                LIMIT 1
            ", $payroll_id, $patient_id));
            if (!$exists) {
                self::create([
                    'payroll_id' => $payroll_id,
                    'patient_id' => (int)$patient_id,
                    'is_processed' => 0,
                ]);
                $inserted++;
            }
        }
        return $inserted;
    }

    // Contadores útiles para el frontend
    public static function countsByStatus($payroll_id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $total = (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$pfx}mhc_patient_payrolls WHERE payroll_id = %d
        ", $payroll_id));
        $processed = (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$pfx}mhc_patient_payrolls WHERE payroll_id = %d AND is_processed = 1
        ", $payroll_id));
        return [
            'total' => $total,
            'processed' => $processed,
            'pending' => max(0, $total - $processed),
        ];
    }

        /**
     * Stats para header de la vista de payroll
     * - processed, pending, total
     */
    public static function stats($payroll_id) {
        return self::countsByStatus($payroll_id);
    }
}
