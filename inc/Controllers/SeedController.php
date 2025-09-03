<?php
namespace Mhc\Inc\Controllers;

defined('ABSPATH') || exit;


class SeedController {

    public static function register() {
        //to add fake data use: /wp-admin/admin-ajax.php?action=mhc_seed_fake_data
        add_action('wp_ajax_mhc_seed_fake_data', [__CLASS__, 'ajax_seed_fake_data']);
    }

    /**
     * AJAX endpoint to seed Workers and Patients with fake data
     */
    public static function ajax_seed_fake_data() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        global $wpdb;
        $pfx = $wpdb->prefix;
        $faker = self::getFaker();

        // Seed Workers
        $roles = $wpdb->get_results("SELECT id, code FROM {$pfx}mhc_roles WHERE is_active=1", ARRAY_A);
        $role_ids = array_column($roles, 'id', 'code');
        $worker_ids = [];
        $rbt_ids = [];
        $bcba_ids = [];
        for ($i = 0; $i < 10; $i++) {
            $first = $faker->firstName;
            $last = $faker->lastName;
            $company = $faker->company;
            $email = strtolower($first . '.' . $last . rand(1,99) . '@example.com');
            $wpdb->insert("{$pfx}mhc_workers", [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'company' => $company,
                'is_active' => 1,
                'start_date' => date('Y-m-d', strtotime('-1 year')),
            ]);
            $wid = $wpdb->insert_id;
            $worker_ids[] = $wid;

            // Assign a random role
            $role_code = (rand(0,1) ? 'RBT' : 'BCBA');
            $role_id = $role_ids[$role_code] ?? reset($role_ids);
            $wpdb->insert("{$pfx}mhc_worker_roles", [
                'worker_id' => $wid,
                'role_id' => $role_id,
                'general_rate' => rand(20, 60),
                'start_date' => date('Y-m-d', strtotime('-1 year')),
            ]);
            if ($role_code === 'RBT') $rbt_ids[] = $wid;
            if ($role_code === 'BCBA') $bcba_ids[] = $wid;
        }

        // Seed Patients
        $patient_ids = [];
        for ($i = 0; $i < 10; $i++) {
            $first = $faker->firstName;
            $last = $faker->lastName;
            $wpdb->insert("{$pfx}mhc_patients", [
                'first_name' => $first,
                'last_name' => $last,
                'is_active' => 1,
                'start_date' => date('Y-m-d', strtotime('-1 year')),
            ]);
            $pid = $wpdb->insert_id;
            $patient_ids[] = $pid;

            // Asignar un RBT y un BCBA obligatorios
            if (!empty($rbt_ids)) {
                $rbt = $rbt_ids[array_rand($rbt_ids)];
                $wpdb->insert("{$pfx}mhc_worker_patient_roles", [
                    'worker_id' => $rbt,
                    'patient_id' => $pid,
                    'role_id' => $role_ids['RBT'],
                    'rate' => rand(20, 60),
                    'start_date' => date('Y-m-d', strtotime('-1 year')),
                ]);
            }
            if (!empty($bcba_ids)) {
                $bcba = $bcba_ids[array_rand($bcba_ids)];
                $wpdb->insert("{$pfx}mhc_worker_patient_roles", [
                    'worker_id' => $bcba,
                    'patient_id' => $pid,
                    'role_id' => $role_ids['BCBA'],
                    'rate' => rand(20, 60),
                    'start_date' => date('Y-m-d', strtotime('-1 year')),
                ]);
            }
        }

        wp_send_json_success(['workers' => count($worker_ids), 'patients' => count($patient_ids)]);
    }

    /**
     * Get a Faker instance
     */
    protected static function getFaker() {
        return \Faker\Factory::create();
    }
}
