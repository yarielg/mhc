<?php

/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Base;

class Settings
{

    public function register()
    {
        add_action('template_redirect', array($this, 'redirect_users'));

        // Endpoints AJAX para día de inicio de semana
        add_action('wp_ajax_mhc_get_week_start_day', [$this, 'ajax_get_week_start_day']);
        add_action('wp_ajax_mhc_set_week_start_day', [$this, 'ajax_set_week_start_day']);
        add_action('wp_ajax_mhc_reset_week_start_day', [$this, 'ajax_reset_week_start_day']);


        add_action('admin_menu', [$this, 'mhc_add_admin_page']);
        add_action('admin_init', [$this, 'mhc_register_settings']);
        // AJAX endpoints to generate/clear process key (used by admin UI)
        add_action('wp_ajax_mhc_generate_process_key', [$this, 'ajax_generate_process_key']);
        add_action('wp_ajax_mhc_clear_process_key', [$this, 'ajax_clear_process_key']);
    }

    /**
     * Valida acceso AJAX (puedes personalizar según tu lógica de seguridad)
     */
    private function check()
    {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }

    /**
     * Devuelve el día configurado de inicio de semana
     */
    public function ajax_get_week_start_day()
    {
        $this->check();
        $day = get_option('mhc_week_start_day', 'monday');
        wp_send_json_success(['week_start_day' => $day]);
    }

    /**
     * Actualiza el día de inicio de semana
     * POST: week_start_day (string)
     */
    public function ajax_set_week_start_day()
    {
        $this->check();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No autorizado'], 403);
        }
        $day = isset($_POST['week_start_day']) ? sanitize_text_field($_POST['week_start_day']) : '';
        $valid_days = ['monday', 'sunday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        if (!in_array($day, $valid_days, true)) {
            wp_send_json_error(['message' => 'Valor inválido'], 400);
        }
        update_option('mhc_week_start_day', $day);
        wp_send_json_success(['week_start_day' => $day]);
    }

    /**
     * Restaura el valor por defecto (lunes)
     */
    public function ajax_reset_week_start_day()
    {
        $this->check();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No autorizado'], 403);
        }
        update_option('mhc_week_start_day', 'monday');
        wp_send_json_success(['week_start_day' => 'monday']);
    }

    public function redirect_users()
    {
        // === 🔒 EXCEPTION: Skip redirect for QuickBooks OAuth callback ===
        $request_path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        if (strpos($request_path, 'qb/callback') === 0) {
            return; // allow the callback to process normally
        }

        if (is_404()) {
            $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

            // List all your Vue routes here (without leading slash)
            $vue_routes = [
                'workers',
                'patients',
                'payrolls',
                '/payrolls/new',
                'reports',
                'reports/all',
            ];

            // If the requested path matches a Vue route, load your plugin's root page
            if (in_array($request_uri, $vue_routes, true)) {
                // Load your plugin's main template instead of 404
                status_header(200);
                wp_safe_redirect(home_url('/'));
                exit;
            }
        }

        if (is_user_logged_in() || is_page('app-login')) return;

        wp_safe_redirect(home_url('/app-login'));
        exit;
    }

    public function mhc_add_admin_page()
    {
        // Ensure there's a top-level menu for the plugin so submenus have a parent
        // If you already have a different class creating the main menu, you can remove this
        add_menu_page(
            __('MHC Payroll', 'mhc'),
            __('MHC Payroll', 'mhc'),
            'manage_options',
            'mhc_main_menu',
            [$this, 'render_main_page'],
            'dashicons-groups',
            2
        );

        // QuickBooks settings submenu
        add_submenu_page(
            'mhc_main_menu', // parent slug for the plugin
            __('QuickBooks Settings', 'mhc'),
            __('QuickBooks', 'mhc'),
            'manage_options',
            'mhc_quickbooks_settings',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'mhc_main_menu',
            __('Vendors from QuickBooks', 'mhc'),
            __('Vendors', 'mhc'),
            'manage_options',
            'mhc_quickbooks_vendors',
            [$this, 'render_vendors_page']
        );
    }

    /**
     * Render the main plugin admin page (simple wrapper). Modify as needed to show
     * your Vue app or plugin dashboard.
     */
    public function render_main_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
        <div class="wrap">
            <h1><?php _e('MHC Payroll', 'mhc'); ?></h1>
            <p><?php _e('Welcome to the MHC Payroll plugin. Use the submenus to access specific settings and pages.', 'mhc'); ?></p>
        </div>
    <?php
    }

    public function mhc_register_settings()
    {
        // === Sección general ===
        add_settings_section(
            'mhc_qb_general_section',
            __('QuickBooks API Configuration', 'mhc'),
            null,
            'mhc_quickbooks_settings'
        );

        // Client ID
        add_settings_field(
            'mhc_qb_client_id',
            __('Client ID', 'mhc'),
            [$this, 'text_field'],
            'mhc_quickbooks_settings',
            'mhc_qb_general_section',
            ['label_for' => 'mhc_qb_client_id']
        );
        register_setting('mhc_qb_options', 'mhc_qb_client_id');

        // Client Secret
        add_settings_field(
            'mhc_qb_client_secret',
            __('Client Secret', 'mhc'),
            [$this, 'text_field'],
            'mhc_quickbooks_settings',
            'mhc_qb_general_section',
            ['label_for' => 'mhc_qb_client_secret']
        );
        register_setting('mhc_qb_options', 'mhc_qb_client_secret');

        // Realm ID
        add_settings_field(
            'mhc_qb_realm_id',
            __('Realm ID', 'mhc'),
            [$this, 'text_field'],
            'mhc_quickbooks_settings',
            'mhc_qb_general_section',
            ['label_for' => 'mhc_qb_realm_id']
        );
        register_setting('mhc_qb_options', 'mhc_qb_realm_id');

        // Base URL
        add_settings_field(
            'mhc_qb_base_url',
            __('Base URL', 'mhc'),
            [$this, 'select_base_url'],
            'mhc_quickbooks_settings',
            'mhc_qb_general_section',
            ['label_for' => 'mhc_qb_base_url']
        );
        register_setting('mhc_qb_options', 'mhc_qb_base_url');

        add_settings_field(
            'mhc_qb_checking_account_id',
            __('Checking Account ID', 'mhc'),
            [$this, 'text_field'],
            'mhc_quickbooks_settings',
            'mhc_qb_general_section',
            ['label_for' => 'mhc_qb_checking_account_id']
        );
        register_setting('mhc_qb_options', 'mhc_qb_checking_account_id');

        add_settings_field(
            'mhc_qb_expense_account_id',
            __('Expense Account ID', 'mhc'),
            [$this, 'text_field'],
            'mhc_quickbooks_settings',
            'mhc_qb_general_section',
            ['label_for' => 'mhc_qb_expense_account_id']
        );
        register_setting('mhc_qb_options', 'mhc_qb_expense_account_id');


        // Process key for external queue processing
        add_settings_field(
            'mhc_qb_process_key',
            __('Queue Process Key', 'mhc'),
            [$this, 'process_key_field'],
            'mhc_quickbooks_settings',
            'mhc_qb_general_section',
            ['label_for' => 'mhc_qb_process_key']
        );
        register_setting('mhc_qb_options', 'mhc_qb_process_key');
    }

    public function text_field($args)
    {
        $option = get_option($args['label_for'], '');
        printf(
            '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text">',
            esc_attr($args['label_for']),
            esc_attr($option)
        );
    }

    public function select_base_url($args)
    {
        // Use the registered option name so the settings API reads/writes the correct option
        $option_name = $args['label_for'] ?? 'mhc_qb_base_url';
        $option = get_option($option_name, 'sandbox');
    ?>
        <select name="<?php echo esc_attr($option_name); ?>" id="<?php echo esc_attr($option_name); ?>">
            <option value="sandbox" <?php selected($option, 'sandbox'); ?>>Sandbox</option>
            <option value="production" <?php selected($option, 'production'); ?>>Production</option>
        </select>
    <?php
        // No hidden input here — let WP Settings API save the select's value via its name
    }

    /* public function render_settings_page()
    {
    ?>
        <div class="wrap">
            <h1><?php _e('QuickBooks API Settings', 'mhc'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('mhc_qb_options');
                do_settings_sections('mhc_quickbooks_settings');
                submit_button(__('Save Settings', 'mhc'));
                ?>
            </form>
        </div>
    <?php
    } */

    public function render_settings_page()
    {
        $client_id = get_option('mhc_qb_client_id');
        $client_secret = get_option('mhc_qb_client_secret');
        $realm_id = get_option('mhc_qb_realm_id');
        $base_url = get_option('mhc_qb_base_url', 'sandbox');
        $access_token = get_option('mhc_qb_access_token');
        $is_connected = !empty($access_token) && !empty($realm_id);

        // URL base de autorización
        $auth_url = 'https://appcenter.intuit.com/connect/oauth2';
        $redirect_uri = home_url('/qb/callback');

        // Construimos la URL OAuth2 solo si tiene client_id configurado
        $connect_url = '';
        if ($client_id && $redirect_uri) {
            $params = [
                'client_id' => $client_id,
                'response_type' => 'code',
                'scope' => 'com.intuit.quickbooks.accounting',
                'redirect_uri' => $redirect_uri,
                'state' => wp_create_nonce('mhc_qb_auth'),
            ];
            $connect_url = $auth_url . '?' . http_build_query($params);
        }

    ?>
        <div class="wrap">
            <h1><?php _e('QuickBooks API Settings', 'mhc'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('mhc_qb_options');
                do_settings_sections('mhc_quickbooks_settings');
                submit_button(__('Save Settings', 'mhc'));
                ?>
            </form>

            <hr>

            <h2><?php _e('QuickBooks Connection', 'mhc'); ?></h2>
            <?php if ($is_connected): ?>
                <p>✅ <strong><?php _e('Connected to QuickBooks Online', 'mhc'); ?></strong></p>
                <form method="post">
                    <input type="hidden" name="mhc_qb_disconnect" value="1">
                    <?php submit_button(__('Disconnect from QuickBooks', 'mhc'), 'delete'); ?>
                </form>
            <?php else: ?>
                <?php if ($connect_url): ?>
                    <a href="<?php echo esc_url($connect_url); ?>" class="button button-primary">
                        <?php _e('Connect to QuickBooks', 'mhc'); ?>
                    </a>
                <?php else: ?>
                    <p><em><?php _e('Please save Client ID and other settings before connecting.', 'mhc'); ?></em></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php

        // Handle manual disconnect
        if (isset($_POST['mhc_qb_disconnect'])) {
            delete_option('mhc_qb_access_token');
            delete_option('mhc_qb_refresh_token');
            add_settings_error('mhc_qb_messages', 'mhc_qb_disconnected', __('Disconnected from QuickBooks.', 'mhc'), 'updated');
        }
    }

    /** Render process key field (shows key and buttons to generate/clear) */
    public function process_key_field($args)
    {
        if (!current_user_can('manage_options')) return;
        $key = get_option('mhc_qb_process_key', '');
        ?>
        <div>
            <input type="text" readonly id="mhc_qb_process_key" name="mhc_qb_process_key" value="<?php echo esc_attr($key); ?>" class="regular-text code">
            <p class="description"><?php _e('This secret key can be used to call the queue processing endpoint from external schedulers. Keep it safe.', 'mhc'); ?></p>
            <button type="button" class="button" id="mhc-generate-key"><?php _e('Generate new key', 'mhc'); ?></button>
            <button type="button" class="button button-danger" id="mhc-clear-key"><?php _e('Clear key', 'mhc'); ?></button>
            <script>
                (function() {
                    const gen = document.getElementById('mhc-generate-key');
                    const clr = document.getElementById('mhc-clear-key');
                    const field = document.getElementById('mhc_qb_process_key');
                    const nonce = '<?php echo wp_create_nonce('mhc_qb_admin_ajax'); ?>';

                    gen.addEventListener('click', async (e) => {
                        e.preventDefault();
                        gen.disabled = true;
                        const body = new URLSearchParams({
                            action: 'mhc_generate_process_key',
                            _wpnonce: nonce,
                            security: nonce
                        });
                        const res = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body
                        });
                        const data = await res.json();
                        gen.disabled = false;
                        if (data.success && data.data && data.data.key) {
                            field.value = data.data.key;
                        } else {
                            alert('Error generating key');
                        }
                    });

                    clr.addEventListener('click', async (e) => {
                        e.preventDefault();
                        if (!confirm('Clear the process key?')) return;
                        clr.disabled = true;
                        const body = new URLSearchParams({
                            action: 'mhc_clear_process_key',
                            _wpnonce: nonce,
                            security: nonce
                        });
                        const res = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body
                        });
                        const data = await res.json();
                        clr.disabled = false;
                        if (data.success) {
                            field.value = '';
                        } else {
                            alert('Error clearing key');
                        }
                    });
                })();
            </script>
        </div>
<?php
        // (AJAX handlers are used instead of inline form posts)
    }

    public function ajax_generate_process_key()
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('mhc_qb_admin_ajax', 'security');
        $new = wp_generate_password(40, false, false);
        update_option('mhc_qb_process_key', $new);
        wp_send_json_success(['key' => $new]);
    }

    public function ajax_clear_process_key()
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('mhc_qb_admin_ajax', 'security');
        delete_option('mhc_qb_process_key');
        wp_send_json_success(['cleared' => true]);
    }

    public function render_vendors_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>QuickBooks Vendors</h1>';

        // Instanciar el servicio y obtener todos los vendors (paginado)
        $qb = new \Mhc\Inc\Services\QuickBooksService();

        $all_vendors = [];
        $start = 1;
        $max = 1000; // QBO permite hasta 1000 en una llamada
        $totalCount = null;

        try {
            do {
                $query = sprintf('select * from Vendor STARTPOSITION %d MAXRESULTS %d', $start, $max);
                $endpoint = 'query?query=' . urlencode($query) . '&minorversion=75';
                $response = $qb->request('GET', $endpoint);

                if (is_wp_error($response)) {
                    echo '<div class="notice notice-error"><p><strong>Error:</strong> ' . esc_html($response->get_error_message()) . '</p></div>';
                    echo '</div>';
                    return;
                }

                $vendors = $response['QueryResponse']['Vendor'] ?? [];
                if (!empty($vendors) && is_array($vendors)) {
                    $all_vendors = array_merge($all_vendors, $vendors);
                }

                // Try to read totalCount when provided
                if ($totalCount === null && isset($response['QueryResponse']['totalCount'])) {
                    $totalCount = (int)$response['QueryResponse']['totalCount'];
                }

                $fetched = is_array($vendors) ? count($vendors) : 0;
                $start += $max;

                // Stop if we've fetched all according to totalCount
                if ($totalCount !== null && count($all_vendors) >= $totalCount) break;

                // safety: stop if a page returns less than requested
            } while ($fetched === $max);
        } catch (\Exception $e) {
            echo '<div class="notice notice-error"><p><strong>Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            echo '</div>';
            return;
        }

        $vendors = $all_vendors;

        if (empty($vendors)) {
            echo '<p>No vendors found in QuickBooks.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Active</th></tr></thead><tbody>';

        foreach ($vendors as $v) {
            $id    = esc_html($v['Id'] ?? '');
            $name  = esc_html($v['DisplayName'] ?? '');
            $email = esc_html($v['PrimaryEmailAddr']['Address'] ?? '');
            $active = !empty($v['Active']) && $v['Active'] ? '✅' : '❌';

            echo "<tr><td>{$id}</td><td>{$name}</td><td>{$email}</td><td>{$active}</td></tr>";
        }

        echo '</tbody></table></div>';
    }
}
