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

        add_action('delete_post', array($this, 'delete_form_fields'));

        add_action('add_meta_boxes', array($this, 'remove_unwanted_meta_boxes'), 99);
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
            // Create the main plugin instance to access create_tables
            $plugin = payment_form_builder();

            // Call the create_tables method
            if (method_exists($plugin, 'create_tables')) {
                $plugin->create_tables();

                // Add success message
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Database tables have been created/repaired successfully.</strong></p>';
                    echo '</div>';
                });
            } else {
                // Add error message
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>Could not repair database: create_tables method not found.</strong></p>';
                    echo '</div>';
                });
            }
        }
    }

    public function delete_form_fields($post_id)
    {
        if (get_post_type($post_id) !== 'payment_form') {
            return;
        }

        global $wpdb;
        $form_fields_table = $wpdb->prefix . 'pfb_form_fields';

        $wpdb->delete(
            $form_fields_table,
            array('form_id' => $post_id),
            array('%d')
        );
    }

    public function add_meta_boxes()
    {
        add_meta_box(
            'payment_form_tabs',
            'Payment Form Settings',
            array($this, 'render_tabbed_interface'),
            'payment_form',
            'normal',
            'high'
        );

        // Keep the shortcode meta box
        add_meta_box(
            'shortcode_info',
            'Shortcode',
            array($this, 'render_shortcode_info'),
            'payment_form',
            'side',
            'high'
        );
    }

    public function render_tabbed_interface($post)
    {
        wp_nonce_field('save_form_builder', 'form_builder_nonce');
?>
        <div class="pfb-tabs-container">
            <ul class="pfb-tabs-nav">
                <li><a href="#tab-basic" class="active">Basic</a></li>
                <li><a href="#tab-emails">Emails</a></li>
            </ul>

            <div id="tab-basic" class="pfb-tab-content active">
                <h3>Form Builder</h3>
                <?php $this->render_form_builder($post); ?>

                <h3>Payment Settings</h3>
                <?php $this->render_payment_settings($post); ?>
            </div>

            <div id="tab-emails" class="pfb-tab-content">
                <h3>Email Notifications</h3>
                <p>Configure email notifications that will be sent when payments are processed.</p>

                <?php
                // Get saved email settings
                $admin_email_enabled = get_post_meta($post->ID, '_admin_email_enabled', true);
                $admin_email_subject = get_post_meta($post->ID, '_admin_email_subject', true) ?: 'New payment received';
                $admin_email_recipients = get_post_meta($post->ID, '_admin_email_recipients', true) ?: get_option('admin_email');
                ?>

                <div class="email-settings-section">
                    <h4>Admin Notification</h4>
                    <p>
                        <label>
                            <input type="checkbox" name="admin_email_enabled" value="1" <?php checked($admin_email_enabled, '1'); ?>>
                            Send email notification to admin when payment is completed
                        </label>
                    </p>

                    <p>
                        <label>Email Subject:</label>
                        <input type="text" name="admin_email_subject" value="<?php echo esc_attr($admin_email_subject); ?>" class="regular-text">
                    </p>

                    <p>
                        <label>Recipients (comma separated):</label>
                        <input type="text" name="admin_email_recipients" value="<?php echo esc_attr($admin_email_recipients); ?>" class="regular-text">
                        <span class="description">Leave empty to use the site admin email: <?php echo esc_html(get_option('admin_email')); ?></span>
                    </p>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Tab functionality
                $('.pfb-tabs-nav a').on('click', function(e) {
                    e.preventDefault();

                    // Update active tab
                    $('.pfb-tabs-nav a').removeClass('active');
                    $(this).addClass('active');

                    // Show the corresponding tab content
                    var target = $(this).attr('href');
                    $('.pfb-tab-content').removeClass('active');
                    $(target).addClass('active');
                });
            });
        </script>
    <?php
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

    public function remove_unwanted_meta_boxes()
    {
        // Only on our custom post type
        if (get_post_type() !== 'payment_form') {
            return;
        }

        // Remove all default meta boxes from the side context except 'submitdiv' (Publish box)
        global $wp_meta_boxes;

        if (isset($wp_meta_boxes['payment_form']['side'])) {
            foreach ($wp_meta_boxes['payment_form']['side'] as $priority => $boxes) {
                foreach ($boxes as $box_id => $box) {
                    // Keep only the publish box and our shortcode box
                    if ($box_id !== 'submitdiv' && $box_id !== 'shortcode_info') {
                        remove_meta_box($box_id, 'payment_form', 'side');
                    }
                }
            }
        }
    }

    public function render_form_builder($post)
    {
        global $wpdb;
        $form_fields_table = $wpdb->prefix . 'pfb_form_fields';

        // Check if the table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") == $form_fields_table;

        if ($table_exists) {
            // Get form fields from the database
            $form_fields_json = $wpdb->get_var($wpdb->prepare(
                "SELECT field_data FROM $form_fields_table WHERE form_id = %d",
                $post->ID
            ));

            $form_fields = $form_fields_json ? json_decode($form_fields_json, true) : array();
        } else {
            // Fall back to post meta if table doesn't exist
            $form_fields = get_post_meta($post->ID, '_form_fields', true);
        }

        $customer_email_field = get_post_meta($post->ID, '_customer_email_field', true);

        // Output existing fields as JSON for JavaScript
        echo '<script>window.existingFormFields = ' . (empty($form_fields) ? '[]' : json_encode($form_fields)) . ';</script>';
        ?>
        <div class="form-builder-container">
            <div class="email-field-notice">
                <p><strong>Important:</strong> To enable Stripe email receipts, add an Email field to your form and check the "Customer Email" option for that field.</p>
                <p>You also need to enable the "Stripe email receipts" option in the <a href="<?php echo admin_url('edit.php?post_type=payment_form&page=pfb-settings'); ?>">plugin settings</a>.</p>
            </div>

            <div class="field-types">
                <button type="button" id="add-text-field" class="add-field-button">Add Text Field</button>
                <button type="button" id="add-email-field" class="add-field-button">Add Email Field</button>
                <button type="button" id="add-textarea-field" class="add-field-button">Add Textarea</button>
                <button type="button" id="add-two-column-field" class="add-field-button">Add Two-Column Fields</button>
            </div>

            <div id="form-fields-container" class="form-fields-container">
                <!-- Fields will be added here dynamically by JavaScript -->
            </div>

            <!-- Hidden input that will hold our JSON data - CRITICAL -->
            <input type="hidden" name="form_fields_json" id="form-fields-json" value="">

            <!-- Debug button -->
            <button type="button" id="debug-form-data" class="button" style="margin-top: 15px;">Debug Form Data</button>
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
            error_log('Not a payment form post type');
            return;
        }

        if (!isset($_POST['form_builder_nonce']) || !wp_verify_nonce($_POST['form_builder_nonce'], 'save_form_builder')) {
            error_log('Nonce verification failed');
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            error_log('Doing autosave, skipping');
            return;
        }

        // Log all POST data for debugging
        error_log('POST data received: ' . print_r($_POST, true));

        // Get form fields from JSON
        $fields_json = isset($_POST['form_fields_json']) ? stripslashes($_POST['form_fields_json']) : '';

        if (empty($fields_json)) {
            error_log('No form fields data received. POST keys: ' . implode(', ', array_keys($_POST)));

            // FALLBACK: Create a default field if none exists
            $fields = [
                [
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true
                ]
            ];
            $fields_json = json_encode($fields);
            error_log('Using fallback field data: ' . $fields_json);
        } else {
            $fields = json_decode($fields_json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('Invalid JSON data: ' . json_last_error_msg());
                return;
            }
        }

        global $wpdb;
        $form_fields_table = $wpdb->prefix . 'pfb_form_fields';

        // Check if the table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") == $form_fields_table;

        if (!$table_exists) {
            // Table doesn't exist, try to create it
            $plugin = payment_form_builder();
            if (method_exists($plugin, 'create_tables')) {
                $plugin->create_tables();
                // Check again if the table was created
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") == $form_fields_table;
            }

            if (!$table_exists) {
                error_log('Form fields table could not be created. Check database permissions.');
                return;
            }
        }

        // Log the received fields
        error_log('Received form fields: ' . $fields_json);

        // Validate fields
        $valid = true;
        $customer_email = '';

        foreach ($fields as $field) {
            if (empty($field['type'])) {
                error_log('Invalid field: missing type');
                $valid = false;
                break;
            }

            if ($field['type'] === 'two-column') {
                if (!isset($field['label']) || !is_array($field['label']) || count($field['label']) !== 2) {
                    error_log('Invalid two-column field: incorrect label format');
                    $valid = false;
                    break;
                }

                if (!isset($field['required']) || !is_array($field['required']) || count($field['required']) !== 2) {
                    error_log('Invalid two-column field: incorrect required format');
                    $valid = false;
                    break;
                }
            } else {
                if (!isset($field['label']) || empty($field['label'])) {
                    error_log('Invalid field: missing label');
                    $valid = false;
                    break;
                }

                if (!isset($field['required'])) {
                    error_log('Invalid field: missing required status');
                    $valid = false;
                    break;
                }
            }

            // Track customer email field
            if ($field['type'] === 'email' && isset($field['customer_email']) && $field['customer_email']) {
                $customer_email = $field['label'];
            }
        }

        if (!$valid) {
            error_log('Form validation failed. Not saving to database.');
            return;
        }

        // Save to database table
        $field_data_json = json_encode($fields);

        // Check if this form already has fields in the database
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $form_fields_table WHERE form_id = %d",
            $post_id
        ));

        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $form_fields_table,
                array('field_data' => $field_data_json),
                array('form_id' => $post_id),
                array('%s'),
                array('%d')
            );

            if ($result === false) {
                error_log('Failed to update form fields: ' . $wpdb->last_error);
            } else {
                error_log('Successfully updated form fields for form ID: ' . $post_id);

                // Log the saved data for verification
                $saved_data = $wpdb->get_var($wpdb->prepare(
                    "SELECT field_data FROM $form_fields_table WHERE form_id = %d",
                    $post_id
                ));
                error_log('Verified saved data: ' . $saved_data);
            }
        } else {
            // Insert new record
            $result = $wpdb->insert(
                $form_fields_table,
                array(
                    'form_id' => $post_id,
                    'field_data' => $field_data_json
                ),
                array('%d', '%s')
            );

            if ($result === false) {
                error_log('Failed to insert form fields: ' . $wpdb->last_error);
            } else {
                error_log('Successfully inserted form fields for form ID: ' . $post_id);

                // Log the saved data for verification
                $saved_data = $wpdb->get_var($wpdb->prepare(
                    "SELECT field_data FROM $form_fields_table WHERE form_id = %d",
                    $post_id
                ));
                error_log('Verified saved data: ' . $saved_data);
            }
        }

        // Save payment settings in post meta
        if (isset($_POST['payment_amount'])) {
            update_post_meta($post_id, '_payment_amount', floatval($_POST['payment_amount']));
        }

        if (isset($_POST['payment_currency'])) {
            update_post_meta($post_id, '_payment_currency', sanitize_text_field($_POST['payment_currency']));
        }

        // Save customer email field reference in post meta for quick access
        if (!empty($customer_email)) {
            update_post_meta($post_id, '_customer_email_field', sanitize_text_field($customer_email));
        } else {
            delete_post_meta($post_id, '_customer_email_field');
        }

        // Add this at the end to save email settings
        if (isset($_POST['admin_email_enabled'])) {
            update_post_meta($post_id, '_admin_email_enabled', '1');
        } else {
            delete_post_meta($post_id, '_admin_email_enabled');
        }

        if (isset($_POST['admin_email_subject'])) {
            update_post_meta($post_id, '_admin_email_subject', sanitize_text_field($_POST['admin_email_subject']));
        }

        if (isset($_POST['admin_email_recipients'])) {
            update_post_meta($post_id, '_admin_email_recipients', sanitize_text_field($_POST['admin_email_recipients']));
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
        $submissions_table = $wpdb->prefix . 'pfb_submissions';
        $form_fields_table = $wpdb->prefix . 'pfb_form_fields';

        $updates = [];
        $success = true;

        // Check if tables exist
        $submissions_exists = $wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") == $submissions_table;
        $form_fields_exists = $wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") == $form_fields_table;

        if (!$submissions_exists || !$form_fields_exists) {
            // Tables don't exist, create them
            $plugin = payment_form_builder();
            if (method_exists($plugin, 'create_tables')) {
                $plugin->create_tables();

                if (!$submissions_exists && $wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") == $submissions_table) {
                    $updates[] = 'Created submissions table';
                }

                if (!$form_fields_exists && $wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") == $form_fields_table) {
                    $updates[] = 'Created form fields table';
                }
            }
        }

        // Check for mode column in submissions table
        if ($submissions_exists) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$submissions_table} LIKE 'mode'");
            if (empty($column_exists)) {
                $result = $wpdb->query("ALTER TABLE {$submissions_table} ADD COLUMN mode varchar(10) AFTER currency");
                if ($result !== false) {
                    $updates[] = 'Added "mode" column';

                    // Set default value for existing records
                    $test_mode = get_option('pfb_test_mode', true);
                    $default_mode = $test_mode ? 'test' : 'live';
                    $wpdb->query("UPDATE {$submissions_table} SET mode = '{$default_mode}' WHERE mode IS NULL");

                    // Add index
                    $wpdb->query("ALTER TABLE {$submissions_table} ADD INDEX mode (mode)");
                } else {
                    $success = false;
                    $updates[] = 'Failed to add "mode" column: ' . $wpdb->last_error;
                }
            }

            // Check for payment_intent index
            $index_exists = $wpdb->get_results("SHOW INDEX FROM {$submissions_table} WHERE Key_name = 'payment_intent'");
            if (empty($index_exists)) {
                $result = $wpdb->query("ALTER TABLE {$submissions_table} ADD INDEX payment_intent (payment_intent)");
                if ($result !== false) {
                    $updates[] = 'Added index for "payment_intent" column';
                } else {
                    $success = false;
                    $updates[] = 'Failed to add index for "payment_intent" column: ' . $wpdb->last_error;
                }
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

        // Enqueue the form builder script
        wp_enqueue_script(
            'pfb-form-builder',
            PFB_PLUGIN_URL . 'admin/js/form-builder.js',
            array('jquery', 'jquery-ui-sortable'),
            PFB_VERSION . '.' . time(), // Add timestamp to prevent caching during development
            true
        );
    }
}
