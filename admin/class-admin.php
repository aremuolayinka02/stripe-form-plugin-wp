<?php
class PFB_Admin
{
    public function __construct()
    {
        // Register custom post type
        add_action('init', array($this, 'register_form_post_type'));

        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));

        // Save post meta
        add_action('save_post', array($this, 'save_form_meta'));

        // Add settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register plugin settings
        add_action('admin_init', array($this, 'register_settings'));

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Handle database repair
        add_action('admin_init', array($this, 'handle_database_repair'));
    }

    public function register_form_post_type()
    {
        $labels = array(
            'name'               => 'Payment Forms',
            'singular_name'      => 'Payment Form',
            'menu_name'         => 'Payment Forms',
            'add_new'           => 'Add New Form',
            'add_new_item'      => 'Add New Payment Form',
            'edit_item'         => 'Edit Payment Form',
            'new_item'          => 'New Payment Form',
            'view_item'         => 'View Payment Form',
            'search_items'      => 'Search Payment Forms',
            'not_found'         => 'No payment forms found',
            'not_found_in_trash' => 'No payment forms found in Trash'
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,  // Change to true
            'publicly_queryable'  => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'payment-form'),
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 30,
            'menu_icon'          => 'dashicons-money-alt', // Changed icon to be more payment-related
            'supports'           => array('title')
        );

        register_post_type('payment_form', $args);
    }

    public function handle_database_repair()
    {
        if (
            isset($_POST['pfb_repair_database']) &&
            isset($_POST['pfb_repair_nonce']) &&
            wp_verify_nonce($_POST['pfb_repair_nonce'], 'pfb_repair_database')
        ) {
            $result = $this->repair_database();

            if (is_array($result)) {
                if ($result['success']) {
                    add_action('admin_notices', function () use ($result) {
                        echo '<div class="notice notice-success is-dismissible">';
                        echo '<p><strong>Database repair completed successfully.</strong></p>';
                        if (!empty($result['updates'])) {
                            echo '<ul>';
                            foreach ($result['updates'] as $update) {
                                echo '<li>' . esc_html($update) . '</li>';
                            }
                            echo '</ul>';
                        }
                        echo '</div>';
                    });
                } else {
                    add_action('admin_notices', function () use ($result) {
                        echo '<div class="notice notice-error is-dismissible">';
                        echo '<p><strong>Database repair encountered errors:</strong></p>';
                        if (!empty($result['updates'])) {
                            echo '<ul>';
                            foreach ($result['updates'] as $update) {
                                echo '<li>' . esc_html($update) . '</li>';
                            }
                            echo '</ul>';
                        }
                        echo '</div>';
                    });
                }
            }
        }
    }

    public function add_meta_boxes()
    {
        add_meta_box(
            'form_builder',           // Unique ID
            'Form Builder',           // Box title
            array($this, 'render_form_builder'),  // Content callback, must be of type callable
            'payment_form',           // Post type
            'normal',                 // Context
            'high'                    // Priority
        );

        add_meta_box(
            'payment_settings',
            'Payment Settings',
            array($this, 'render_payment_settings'),
            'payment_form',
            'normal',
            'high'
        );

        add_meta_box(
            'shortcode_info',
            'Shortcode',
            array($this, 'render_shortcode_info'),
            'payment_form',
            'side',
            'high'
        );
    }

    public function register_settings()
    {
        register_setting('pfb_settings', 'pfb_test_mode');
        register_setting('pfb_settings', 'pfb_test_public_key');
        register_setting('pfb_settings', 'pfb_test_secret_key');
        register_setting('pfb_settings', 'pfb_live_public_key');
        register_setting('pfb_settings', 'pfb_live_secret_key');
        register_setting('pfb_settings', 'pfb_webhook_secret');
        register_setting('pfb_settings', 'pfb_enable_stripe_emails');
    }

    public function render_form_builder($post)
    {
        wp_nonce_field('save_form_builder', 'form_builder_nonce');

        $form_fields = get_post_meta($post->ID, '_form_fields', true);
        $customer_email_field = get_post_meta($post->ID, '_customer_email_field', true);
?>
        <div class="form-builder-container">
            <div class="email-field-notice">
                <p><strong>Important:</strong> To enable Stripe email receipts, add an Email field to your form and check the "Customer Email" option for that field.</p>
                <p>You also need to enable the "Stripe email receipts" option in the <a href="<?php echo admin_url('edit.php?post_type=payment_form&page=pfb-settings'); ?>">plugin settings</a>.</p>
            </div>

            <div class="field-types">
                <button type="button" class="add-field" data-type="text">Add Text Field</button>
                <button type="button" class="add-field" data-type="email">Add Email Field</button>
                <button type="button" class="add-field" data-type="textarea">Add Textarea</button>
            </div>

            <div class="form-fields-container">
                <?php
                if (is_array($form_fields)) {
                    foreach ($form_fields as $index => $field) {
                        $this->render_field_row($field, $index, $customer_email_field);
                    }
                }
                ?>
            </div>
        </div>
    <?php
    }

    private function render_field_row($field = array(), $index = 0, $customer_email_field = '')
{
    $field_id = isset($field['label']) ? sanitize_title($field['label']) : '';
?>
    <div class="field-row">
        <input type="hidden" name="field_type[]" value="<?php echo esc_attr($field['type'] ?? 'text'); ?>">
        <input type="text" name="field_label[]" placeholder="Field Label"
            value="<?php echo esc_attr($field['label'] ?? ''); ?>">
        <label>
            <input type="checkbox" name="field_required[]" value="1"
                <?php checked(isset($field['required']) && $field['required']); ?>>
            Required
        </label>
        <?php if (($field['type'] ?? '') === 'email'): ?>
            <label>
                <input type="radio" name="customer_email_field" value="<?php echo esc_attr($field_id); ?>"
                    <?php checked($customer_email_field, $field_id); ?>>
                Customer Email
            </label>
        <?php endif; ?>
        <button type="button" class="remove-field">Remove</button>
    </div>
<?php
}

    public function render_payment_settings($post)
    {
        $amount = get_post_meta($post->ID, '_payment_amount', true);
        $currency = get_post_meta($post->ID, '_payment_currency', true) ?: 'usd';
    ?>
        <div class="payment-settings">
            <p>
                <label>Payment Amount:</label>
                <input type="number" name="payment_amount"
                    value="<?php echo esc_attr($amount); ?>"
                    step="0.01" min="0">
            </p>
            <p>
                <label>Currency:</label>
                <select name="payment_currency">
                    <option value="usd" <?php selected($currency, 'usd'); ?>>USD</option>
                    <option value="eur" <?php selected($currency, 'eur'); ?>>EUR</option>
                    <option value="gbp" <?php selected($currency, 'gbp'); ?>>GBP</option>
                </select>
            </p>
        </div>
    <?php
    }

    public function render_shortcode_info($post)
    {
    ?>
        <div class="shortcode-info">
            <p>Use this shortcode to display the form:</p>
            <code>[payment_form id="<?php echo $post->ID; ?>"]</code>
        </div>
    <?php
    }

    public function save_form_meta($post_id)
    {
        if (get_post_type($post_id) !== 'payment_form') {
            return;
        }

        if (
            !isset($_POST['form_builder_nonce']) ||
            !wp_verify_nonce($_POST['form_builder_nonce'], 'save_form_builder')
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Save form fields
        $fields = array();
        if (isset($_POST['field_type']) && is_array($_POST['field_type'])) {
            foreach ($_POST['field_type'] as $index => $type) {
                $label = sanitize_text_field($_POST['field_label'][$index] ?? '');
                $fields[] = array(
                    'type' => sanitize_text_field($type),
                    'label' => $label,
                    'id' => sanitize_title($label),
                    'required' => isset($_POST['field_required'][$index])
                );
            }
        }
        update_post_meta($post_id, '_form_fields', $fields);

        // Save customer email field
        if (isset($_POST['customer_email_field'])) {
            update_post_meta(
                $post_id,
                '_customer_email_field',
                sanitize_text_field($_POST['customer_email_field'])
            );
        } else {
            delete_post_meta($post_id, '_customer_email_field');
        }

        // Save payment settings
        if (isset($_POST['payment_amount'])) {
            update_post_meta(
                $post_id,
                '_payment_amount',
                floatval($_POST['payment_amount'])
            );
        }
        if (isset($_POST['payment_currency'])) {
            update_post_meta(
                $post_id,
                '_payment_currency',
                sanitize_text_field($_POST['payment_currency'])
            );
        }
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
    ?>
        <div class="wrap">
            <h2>Payment Form Builder Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('pfb_settings');
                do_settings_sections('pfb_settings');
                wp_nonce_field('pfb_settings_nonce', 'pfb_settings_nonce');
                ?>
                <table class="form-table">
                    <tr>
                        <th>Test Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="pfb_test_mode" value="1"
                                    <?php checked(get_option('pfb_test_mode', true)); ?>>
                                Enable Test Mode
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Test Public Key</th>
                        <td>
                            <input type="text" name="pfb_test_public_key"
                                value="<?php echo esc_attr(get_option('pfb_test_public_key')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Test Secret Key</th>
                        <td>
                            <input type="password" name="pfb_test_secret_key"
                                value="<?php echo esc_attr(get_option('pfb_test_secret_key')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Live Public Key</th>
                        <td>
                            <input type="text" name="pfb_live_public_key"
                                value="<?php echo esc_attr(get_option('pfb_live_public_key')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Live Secret Key</th>
                        <td>
                            <input type="password" name="pfb_live_secret_key"
                                value="<?php echo esc_attr(get_option('pfb_live_secret_key')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook Secret</th>
                        <td>
                            <input type="password" name="pfb_webhook_secret"
                                value="<?php echo esc_attr(get_option('pfb_webhook_secret')); ?>"
                                class="regular-text">
                            <p class="description">Enter your Stripe webhook signing secret</p>
                        </td>
                    </tr>
                </table>
                <tr>
                <h3>Email Receipts</h3>
                <td>
                    <label>
                        <input type="checkbox" name="pfb_enable_stripe_emails" value="1"
                            <?php checked(get_option('pfb_enable_stripe_emails', false)); ?>>
                        Enable Stripe email receipts
                    </label>
                    <p class="description">When enabled, Stripe will send payment receipts to customers (requires an email field marked as "Customer Email" in your form).</p>
                </td>
            </tr>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h3>Database Maintenance</h3>
            <p>If you're experiencing issues with missing columns in the database, use this button to repair the database structure.</p>
            <form method="post" action="">
                <?php wp_nonce_field('pfb_repair_database', 'pfb_repair_nonce'); ?>
                <input type="hidden" name="pfb_repair_database" value="1">
                <?php submit_button('Repair Database', 'secondary', 'repair_database'); ?>
            </form>
        </div>
    <?php
    }

    public function repair_database()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pfb_submissions';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            // Table doesn't exist, create it
            $this->create_tables();
            return true;
        }

        $updates = [];
        $success = true;

        // Check for mode column
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'mode'");
        if (empty($column_exists)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN mode varchar(10) AFTER currency");
            if ($result !== false) {
                $updates[] = 'Added "mode" column';

                // Set default value for existing records
                $test_mode = get_option('pfb_test_mode', true);
                $default_mode = $test_mode ? 'test' : 'live';
                $wpdb->query("UPDATE {$table_name} SET mode = '{$default_mode}' WHERE mode IS NULL");

                // Add index
                $wpdb->query("ALTER TABLE {$table_name} ADD INDEX mode (mode)");
            } else {
                $success = false;
                $updates[] = 'Failed to add "mode" column: ' . $wpdb->last_error;
            }
        }

        // Check for payment_intent index
        $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'payment_intent'");
        if (empty($index_exists)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD INDEX payment_intent (payment_intent)");
            if ($result !== false) {
                $updates[] = 'Added index for "payment_intent" column';
            } else {
                $success = false;
                $updates[] = 'Failed to add index for "payment_intent" column: ' . $wpdb->last_error;
            }
        }

        return [
            'success' => $success,
            'updates' => $updates
        ];
    }

    public function render_orders_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Include the orders class
        require_once PFB_PLUGIN_DIR . 'admin/class-orders.php';

        // Initialize the orders table
        $orders_table = new PFB_Orders_Table();
        $orders_table->prepare_items();

    ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="orders-filters">
                <form method="get">
                    <input type="hidden" name="post_type" value="payment_form" />
                    <input type="hidden" name="page" value="pfb-orders" />

                    <?php
                    // Mode filter
                    $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : '';
                    ?>
                    <select name="mode">
                        <option value=""><?php _e('All Modes', 'payment-form-builder'); ?></option>
                        <option value="test" <?php selected($mode, 'test'); ?>><?php _e('Test Mode', 'payment-form-builder'); ?></option>
                        <option value="live" <?php selected($mode, 'live'); ?>><?php _e('Live Mode', 'payment-form-builder'); ?></option>
                    </select>

                    <?php
                    // Status filter
                    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
                    ?>
                    <select name="status">
                        <option value=""><?php _e('All Statuses', 'payment-form-builder'); ?></option>
                        <option value="pending" <?php selected($status, 'pending'); ?>><?php _e('Pending', 'payment-form-builder'); ?></option>
                        <option value="completed" <?php selected($status, 'completed'); ?>><?php _e('Completed', 'payment-form-builder'); ?></option>
                        <option value="failed" <?php selected($status, 'failed'); ?>><?php _e('Failed', 'payment-form-builder'); ?></option>
                    </select>

                    <?php
                    // Search box
                    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
                    ?>
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search by Transaction ID', 'payment-form-builder'); ?>" />

                    <?php submit_button(__('Filter', 'payment-form-builder'), 'secondary', 'filter_action', false); ?>
                </form>
            </div>

            <div id="ajax-response"></div>

            <form id="orders-filter" method="get">
                <?php $orders_table->display(); ?>
            </form>
        </div>
<?php
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'edit.php?post_type=payment_form',
            'Orders',
            'Orders',
            'manage_options',
            'pfb-orders',
            array($this, 'render_orders_page')
        );


        add_submenu_page(
            'edit.php?post_type=payment_form',
            'Settings',
            'Settings',
            'manage_options',
            'pfb-settings',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_scripts($hook)
    {
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        if (get_post_type() !== 'payment_form') {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style(
            'pfb-admin',
            PFB_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            PFB_VERSION
        );

        wp_enqueue_script(
            'pfb-admin',
            PFB_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery', 'jquery-ui-sortable'),
            PFB_VERSION,
            true
        );
    }
}
