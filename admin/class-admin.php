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

        // Register AJAX handlers
        add_action('admin_init', array($this, 'register_ajax_handlers'));

        add_action('delete_post', array($this, 'delete_form_fields'));

        add_action('add_meta_boxes', array($this, 'remove_unwanted_meta_boxes'), 99);

        add_action('admin_init', array($this, 'handle_css_cache_clear'));

        // Add action to clear cache when settings are updated
        add_action('update_option_pfb_billing_layout', array($this, 'handle_settings_update'), 10, 3);
        add_action('update_option_pfb_enable_billing', array($this, 'handle_settings_update'), 10, 3);
        add_action('update_option_pfb_enable_shipping', array($this, 'handle_settings_update'), 10, 3);
        add_action('update_option_pfb_enable_same_as_billing', array($this, 'handle_settings_update'), 10, 3);
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
            // Call our repair_database method
            $result = $this->repair_database();

            // Add success or error message based on result
            if ($result['success']) {
                add_action('admin_notices', function () use ($result) {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Database tables have been repaired successfully.</strong></p>';
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
                    echo '<p><strong>Could not repair database completely.</strong></p>';
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

        // Handle manual payment check run
        if (
            isset($_POST['pfb_run_payment_check']) &&
            isset($_POST['pfb_run_check_nonce']) &&
            wp_verify_nonce($_POST['pfb_run_check_nonce'], 'pfb_run_payment_check')
        ) {
            // Create the main plugin instance
            $plugin = payment_form_builder();

            if (method_exists($plugin, 'check_pending_payments')) {
                $plugin->check_pending_payments();

                // Add success message
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Payment check has been run successfully. Check the error log for details.</strong></p>';
                    echo '</div>';
                });
            } else {
                // Add error message
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>Could not run payment check: check_pending_payments method not found.</strong></p>';
                    echo '</div>';
                });
            }
        }

        // Handle manual scheduling of payment check
        if (
            isset($_POST['pfb_schedule_payment_check']) &&
            isset($_POST['pfb_schedule_nonce']) &&
            wp_verify_nonce($_POST['pfb_schedule_nonce'], 'pfb_schedule_payment_check')
        ) {
            if (!wp_next_scheduled('pfb_check_pending_payments')) {
                wp_schedule_event(time(), 'five_minutes', 'pfb_check_pending_payments');

                // Add success message
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Payment check has been scheduled successfully.</strong></p>';
                    echo '</div>';
                });
            } else {
                // Add info message
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-info is-dismissible">';
                    echo '<p><strong>Payment check is already scheduled.</strong></p>';
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

    public function handle_settings_update($old_value, $new_value, $option_name)
    {
        $this->clear_settings_cache();

        // Force refresh of options
        wp_cache_set($option_name, $new_value, 'options');

        // Add a transient to indicate settings were updated
        set_transient('pfb_settings_updated', true, 30);
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
                <div class="email-settings-section">
                    <h4>Customer Notifications</h4>

                    <?php
                    // Get saved customer email settings
                    $success_email_enabled = get_post_meta($post->ID, '_customer_success_email_enabled', true);
                    $success_email_subject = get_post_meta($post->ID, '_customer_success_email_subject', true) ?: 'Payment Confirmation';
                    $success_email_template = get_post_meta($post->ID, '_customer_success_email_template', true);

                    $failed_email_enabled = get_post_meta($post->ID, '_customer_failed_email_enabled', true);
                    $failed_email_subject = get_post_meta($post->ID, '_customer_failed_email_subject', true) ?: 'Payment Failed';
                    $failed_email_template = get_post_meta($post->ID, '_customer_failed_email_template', true);
                    ?>

                    <div class="customer-email-settings">
                        <h5>Successful Payment</h5>
                        <p>
                            <label>
                                <input type="checkbox" name="customer_success_email_enabled" value="1" <?php checked($success_email_enabled, '1'); ?>>
                                Send email notification to customer after successful payment
                            </label>
                        </p>

                        <div class="success-email-template" style="<?php echo !$success_email_enabled ? 'display:none;' : ''; ?>">
                            <p>
                                <label>Email Subject:</label>
                                <input type="text" name="customer_success_email_subject" value="<?php echo esc_attr($success_email_subject); ?>" class="regular-text">
                            </p>

                            <p>
                                <label>Email Template:</label>
                                <textarea name="customer_success_email_template" rows="8" class="large-text"><?php echo esc_textarea($success_email_template); ?></textarea>
                            </p>

                            <p class="description">Available variables: {customer_name}, {order_amount}, {order_id}, {payment_date}, {form_title}</p>
                        </div>

                        <h5>Failed Payment</h5>
                        <p>
                            <label>
                                <input type="checkbox" name="customer_failed_email_enabled" value="1" <?php checked($failed_email_enabled, '1'); ?>>
                                Send email notification to customer after failed payment
                            </label>
                        </p>

                        <div class="failed-email-template" style="<?php echo !$failed_email_enabled ? 'display:none;' : ''; ?>">
                            <p>
                                <label>Email Subject:</label>
                                <input type="text" name="customer_failed_email_subject" value="<?php echo esc_attr($failed_email_subject); ?>" class="regular-text">
                            </p>

                            <p>
                                <label>Email Template:</label>
                                <textarea name="customer_failed_email_template" rows="8" class="large-text"><?php echo esc_textarea($failed_email_template); ?></textarea>
                            </p>

                            <p class="description">Available variables: {customer_name}, {order_amount}, {order_id}, {payment_date}, {form_title}</p>
                        </div>
                    </div>
                </div>

                <script>
                    jQuery(document).ready(function($) {
                        // Toggle success email template visibility
                        $('input[name="customer_success_email_enabled"]').on('change', function() {
                            $('.success-email-template').toggle(this.checked);
                        });

                        // Toggle failed email template visibility
                        $('input[name="customer_failed_email_enabled"]').on('change', function() {
                            $('.failed-email-template').toggle(this.checked);
                        });
                    });
                </script>
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

        // Billing and shipping settings
        register_setting('pfb_billing_settings', 'pfb_enable_billing');
        register_setting('pfb_billing_settings', 'pfb_enable_shipping');
        register_setting('pfb_billing_settings', 'pfb_enable_same_as_billing');
        register_setting('pfb_billing_settings', 'pfb_billing_layout');
        register_setting('pfb_billing_settings', 'pfb_billing_fields');
        register_setting('pfb_billing_settings', 'pfb_shipping_layout');
        register_setting('pfb_billing_settings', 'pfb_shipping_fields');


        // Billing and shipping settings with sanitization callbacks
        register_setting('pfb_billing_settings', 'pfb_enable_billing', array(
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting('pfb_billing_settings', 'pfb_enable_shipping', array(
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting('pfb_billing_settings', 'pfb_enable_same_as_billing', array(
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting('pfb_billing_settings', 'pfb_billing_layout', array(
            'sanitize_callback' => array($this, 'sanitize_layout')
        ));
        register_setting('pfb_billing_settings', 'pfb_billing_fields', array(
            'sanitize_callback' => array($this, 'sanitize_fields')
        ));
        register_setting('pfb_billing_settings', 'pfb_shipping_layout', array(
            'sanitize_callback' => array($this, 'sanitize_layout')
        ));
        register_setting('pfb_billing_settings', 'pfb_shipping_fields', array(
            'sanitize_callback' => array($this, 'sanitize_fields')
        ));

        register_setting('pfb_global_css_settings', 'pfb_global_css', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_strip_all_tags',
            'default' => '',
        ));
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


    public function sanitize_layout($value)
    {
        if (empty($value)) {
            return '';
        }

        if (is_string($value)) {
            $layout = json_decode($value, true);
        } else {
            $layout = $value;
        }

        if (!is_array($layout)) {
            return '';
        }

        // Clear the cache when layout is updated
        $this->clear_settings_cache();

        return json_encode($layout);
    }

    public function sanitize_fields($value)
    {
        if (empty($value)) {
            return '';
        }

        $fields = is_array($value) ? $value : explode(',', $value);
        $fields = array_map('sanitize_text_field', $fields);

        // Clear the cache when fields are updated
        $this->clear_settings_cache();

        return implode(',', $fields);
    }

    private function clear_settings_cache()
    {
        wp_cache_delete('pfb_enable_billing', 'options');
        wp_cache_delete('pfb_enable_shipping', 'options');
        wp_cache_delete('pfb_enable_same_as_billing', 'options');
        wp_cache_delete('pfb_billing_layout', 'options');
        wp_cache_delete('pfb_billing_fields', 'options');
        wp_cache_delete('pfb_shipping_layout', 'options');
        wp_cache_delete('pfb_shipping_fields', 'options');
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
        $shipping_amount = get_post_meta($post->ID, '_shipping_amount', true); // Fetch shipping amount
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
            <p>
                <label>Shipping Amount:</label>
                <input type="number" name="shipping_amount"
                    value="<?php echo esc_attr($shipping_amount); ?>"
                    step="0.01" min="0">
                <span class="description">Enter the shipping cost if shipping is enabled.</span>
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

        if (isset($_POST['shipping_amount'])) {
            update_post_meta($post_id, '_shipping_amount', floatval($_POST['shipping_amount']));
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

        // Save customer email settings
        if (isset($_POST['customer_success_email_enabled'])) {
            update_post_meta($post_id, '_customer_success_email_enabled', '1');
        } else {
            delete_post_meta($post_id, '_customer_success_email_enabled');
        }

        if (isset($_POST['customer_success_email_subject'])) {
            update_post_meta($post_id, '_customer_success_email_subject', sanitize_text_field($_POST['customer_success_email_subject']));
        }

        if (isset($_POST['customer_success_email_template'])) {
            update_post_meta($post_id, '_customer_success_email_template', wp_kses_post($_POST['customer_success_email_template']));
        }

        if (isset($_POST['customer_failed_email_enabled'])) {
            update_post_meta($post_id, '_customer_failed_email_enabled', '1');
        } else {
            delete_post_meta($post_id, '_customer_failed_email_enabled');
        }

        if (isset($_POST['customer_failed_email_subject'])) {
            update_post_meta($post_id, '_customer_failed_email_subject', sanitize_text_field($_POST['customer_failed_email_subject']));
        }

        if (isset($_POST['customer_failed_email_template'])) {
            update_post_meta($post_id, '_customer_failed_email_template', wp_kses_post($_POST['customer_failed_email_template']));
        }
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get the active tab, default to 'general'
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    ?>
        <div class="wrap">
            <h2>Payment Form Builder Settings</h2>

            <h2 class="nav-tab-wrapper">
                <a href="?post_type=payment_form&page=pfb-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="?post_type=payment_form&page=pfb-settings&tab=database" class="nav-tab <?php echo $active_tab == 'database' ? 'nav-tab-active' : ''; ?>">Database Management</a>
                <a href="?post_type=payment_form&page=pfb-settings&tab=billing" class="nav-tab <?php echo $active_tab == 'billing' ? 'nav-tab-active' : ''; ?>">Billing</a>
                <a href="?post_type=payment_form&page=pfb-settings&tab=global_css" class="nav-tab <?php echo $active_tab == 'global_css' ? 'nav-tab-active' : ''; ?>">Global CSS</a>
            </h2>

            <?php
            // Display the appropriate tab content
            if ($active_tab == 'general') {
                $this->render_general_settings_tab();
            } elseif ($active_tab == 'database') {
                $this->render_database_settings_tab();
            } elseif ($active_tab == 'billing') {
                $this->render_billing_settings_tab();
            } elseif ($active_tab == 'global_css') {
                $this->render_global_css_settings();
            }
            ?>
        </div>
    <?php
    }




    /**
     * Render the General Settings tab
     */
    private function render_general_settings_tab()
    {
    ?>
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

            <h3>Email Receipts</h3>
            <table class="form-table">
                <tr>
                    <th>Stripe Email Receipts</th>
                    <td>
                        <label>
                            <input type="checkbox" name="pfb_enable_stripe_emails" value="1"
                                <?php checked(get_option('pfb_enable_stripe_emails', false)); ?>>
                            Enable Stripe email receipts
                        </label>
                        <p class="description">When enabled, Stripe will send payment receipts to customers (requires an email field marked as "Customer Email" in your form).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    <?php
    }

    /**
     * Render the Database Management tab
     */
    private function render_database_settings_tab()
    {
    ?>
        <h3>Database Maintenance</h3>
        <p>If you're experiencing issues with missing columns in the database, use this button to repair the database structure.</p>
        <form method="post" action="">
            <?php wp_nonce_field('pfb_repair_database', 'pfb_repair_nonce'); ?>
            <input type="hidden" name="pfb_repair_database" value="1">
            <?php submit_button('Repair Database', 'secondary', 'repair_database'); ?>
        </form>

        <hr>
        <h3>Automatic Payment Checks</h3>
        <p>The plugin automatically checks pending payments every 5 minutes.</p>

        <?php
        $next_run = wp_next_scheduled('pfb_check_pending_payments');

        if ($next_run) {
            echo '<p>Next scheduled check: ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run);
            echo ' (' . human_time_diff(time(), $next_run) . ' from now)</p>';
        } else {
            echo '<p>No payment check currently scheduled. Try deactivating and reactivating the plugin.</p>';

            // Add a button to manually schedule the cron job
        ?>
            <form method="post" action="">
                <?php wp_nonce_field('pfb_schedule_payment_check', 'pfb_schedule_nonce'); ?>
                <input type="hidden" name="pfb_schedule_payment_check" value="1">
                <?php submit_button('Schedule Payment Check', 'secondary', 'schedule_payment_check'); ?>
            </form>
        <?php
        }

        // Add a button to manually run the payment check
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('pfb_run_payment_check', 'pfb_run_check_nonce'); ?>
            <input type="hidden" name="pfb_run_payment_check" value="1">
            <?php submit_button('Run Payment Check Now', 'primary', 'run_payment_check'); ?>
        </form>
    <?php
    }


    /**
     * Render the Billing tab
     */

    private function render_billing_settings_tab()
    {
        // Get current settings
        $enable_billing = get_option('pfb_enable_billing', false);
        $enable_shipping = get_option('pfb_enable_shipping', false);
        $enable_same_as_billing = get_option('pfb_enable_same_as_billing', true);

        // Get saved field layouts
        $billing_layout_option = get_option('pfb_billing_layout', '');
        $billing_layout = !empty($billing_layout_option) ? json_decode($billing_layout_option, true) : $this->get_default_billing_layout();

        if (empty($billing_layout) || !is_array($billing_layout)) {
            $billing_layout = $this->get_default_billing_layout();
        }

        $shipping_layout_option = get_option('pfb_shipping_layout', '');
        $shipping_layout = !empty($shipping_layout_option) ? json_decode($shipping_layout_option, true) : $this->get_default_shipping_layout();

        if (empty($shipping_layout) || !is_array($shipping_layout)) {
            $shipping_layout = $this->get_default_shipping_layout();
        }

        // Get selected fields - ensure they are arrays
        $billing_fields_option = get_option('pfb_billing_fields', '');
        $billing_fields = !empty($billing_fields_option) ? explode(',', $billing_fields_option) : [];

        // If no fields are selected, use all fields from the layout
        if (empty($billing_fields)) {
            $billing_fields = [];
            foreach ($billing_layout as $row) {
                foreach ($row as $field_id) {
                    $billing_fields[] = $field_id;
                }
            }
        }

        $shipping_fields_option = get_option('pfb_shipping_fields', '');
        $shipping_fields = !empty($shipping_fields_option) ? explode(',', $shipping_fields_option) : [];

        // If no fields are selected, use all fields from the layout
        if (empty($shipping_fields)) {
            $shipping_fields = [];
            foreach ($shipping_layout as $row) {
                foreach ($row as $field_id) {
                    $shipping_fields[] = $field_id;
                }
            }
        }

        // All available fields
        $all_billing_fields = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'company' => 'Company',
            'address_1' => 'Address Line 1',
            'address_2' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State/Province',
            'postcode' => 'Postal Code',
            'country' => 'Country',
            'phone' => 'Phone',
            'email' => 'Email'
        ];

        $all_shipping_fields = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'company' => 'Company',
            'address_1' => 'Address Line 1',
            'address_2' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State/Province',
            'postcode' => 'Postal Code',
            'country' => 'Country',
            'phone' => 'Phone'
        ];
    ?>
        <form method="post" action="options.php" id="pfb-billing-settings-form">
            <?php
            settings_fields('pfb_billing_settings');
            do_settings_sections('pfb_billing_settings');
            ?>

            <h3>Billing Information</h3>
            <table class="form-table">
                <tr>
                    <th>Enable Billing Form</th>
                    <td>
                        <label>
                            <input type="checkbox" name="pfb_enable_billing" value="1"
                                <?php checked($enable_billing); ?> id="pfb-enable-billing">
                            Display billing information form on payment forms
                        </label>
                        <p class="description">When enabled, customers will be asked to provide billing information.</p>
                    </td>
                </tr>
            </table>

            <div id="pfb-billing-fields-container" <?php echo !$enable_billing ? 'style="display:none;"' : ''; ?>>
                <h4>Billing Fields Layout</h4>
                <p>Drag and drop fields to rearrange them. Place fields side by side to create a two-column layout.</p>

                <div class="pfb-field-builder">
                    <div class="pfb-available-fields">
                        <h4>Available Fields</h4>
                        <ul id="pfb-available-billing-fields">
                            <?php foreach ($all_billing_fields as $field_id => $field_label): ?>
                                <?php if (!in_array($field_id, $billing_fields)): ?>
                                    <li data-field="<?php echo esc_attr($field_id); ?>" draggable="true" class="draggable-field">
                                        <?php echo esc_html($field_label); ?>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="pfb-field-layout">
                        <h4>Form Layout</h4>
                        <div id="pfb-billing-layout" class="pfb-layout-container">
                            <?php foreach ($billing_layout as $row): ?>
                                <div class="pfb-layout-row">
                                    <?php foreach ($row as $field_id): ?>
                                        <?php if (isset($all_billing_fields[$field_id])): ?>
                                            <div class="pfb-layout-field" data-field="<?php echo esc_attr($field_id); ?>" draggable="true">
                                                <?php echo esc_html($all_billing_fields[$field_id]); ?>
                                                <span class="pfb-remove-field">×</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <button type="button" class="pfb-add-row">+ Add Row</button>
                        </div>
                        <input type="hidden" name="pfb_billing_layout" id="pfb-billing-layout-input" value="<?php echo esc_attr(json_encode($billing_layout)); ?>">
                        <input type="hidden" name="pfb_billing_fields" id="pfb-billing-fields-input" value="<?php echo esc_attr(implode(',', $billing_fields)); ?>">
                    </div>
                </div>
            </div>

            <h3>Shipping Information</h3>
            <table class="form-table">
                <tr>
                    <th>Enable Shipping Form</th>
                    <td>
                        <label>
                            <input type="checkbox" name="pfb_enable_shipping" value="1"
                                <?php checked($enable_shipping); ?> id="pfb-enable-shipping"
                                <?php echo !$enable_billing ? 'disabled' : ''; ?>>
                            Allow customers to enter shipping information
                        </label>
                        <p class="description">When enabled, customers can provide shipping information different from billing.</p>
                    </td>
                </tr>
                <tr>
                    <th>Same as Billing Option</th>
                    <td>
                        <label>
                            <input type="checkbox" name="pfb_enable_same_as_billing" value="1"
                                <?php checked($enable_same_as_billing); ?>
                                <?php echo !$enable_shipping ? 'disabled' : ''; ?>>
                            Show "Same as billing address" option
                        </label>
                        <p class="description">When enabled, customers can choose to use their billing address for shipping.</p>
                    </td>
                </tr>
            </table>

            <div id="pfb-shipping-fields-container" <?php echo !$enable_shipping ? 'style="display:none;"' : ''; ?>>
                <h4>Shipping Fields Layout</h4>
                <p>Drag and drop fields to rearrange them. Place fields side by side to create a two-column layout.</p>

                <div class="pfb-field-builder">
                    <div class="pfb-available-fields">
                        <h4>Available Fields</h4>
                        <ul id="pfb-available-shipping-fields">
                            <?php foreach ($all_shipping_fields as $field_id => $field_label): ?>
                                <?php if (!in_array($field_id, $shipping_fields)): ?>
                                    <li data-field="<?php echo esc_attr($field_id); ?>" draggable="true" class="draggable-field">
                                        <?php echo esc_html($field_label); ?>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="pfb-field-layout">
                        <h4>Form Layout</h4>
                        <div id="pfb-shipping-layout" class="pfb-layout-container">
                            <?php foreach ($shipping_layout as $row): ?>
                                <div class="pfb-layout-row">
                                    <?php foreach ($row as $field_id): ?>
                                        <?php if (isset($all_shipping_fields[$field_id])): ?>
                                            <div class="pfb-layout-field" data-field="<?php echo esc_attr($field_id); ?>" draggable="true">
                                                <?php echo esc_html($all_shipping_fields[$field_id]); ?>
                                                <span class="pfb-remove-field">×</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <button type="button" class="pfb-add-row">+ Add Row</button>
                        </div>
                        <input type="hidden" name="pfb_shipping_layout" id="pfb-shipping-layout-input" value="<?php echo esc_attr(json_encode($shipping_layout)); ?>">
                        <input type="hidden" name="pfb_shipping_fields" id="pfb-shipping-fields-input" value="<?php echo esc_attr(implode(',', $shipping_fields)); ?>">
                    </div>
                </div>
            </div>

            <?php submit_button('Save Billing Settings'); ?>
        </form>

        <style>
            .pfb-field-builder {
                display: flex;
                margin-bottom: 30px;
            }

            .pfb-available-fields {
                width: 25%;
                margin-right: 20px;
            }

            .pfb-field-layout {
                width: 70%;
            }

            #pfb-available-billing-fields li,
            #pfb-available-shipping-fields li {
                padding: 10px;
                background: #f5f5f5;
                border: 1px solid #ddd;
                margin-bottom: 5px;
                cursor: move;
                list-style: none;
            }

            .pfb-layout-container {
                border: 1px solid #ddd;
                padding: 15px;
                background: #f9f9f9;
                min-height: 200px;
            }

            .pfb-layout-row {
                display: flex;
                margin-bottom: 10px;
                min-height: 40px;
                background: #fff;
                border: 1px dashed #ccc;
                padding: 5px;
            }

            .pfb-layout-field {
                background: #e0f7fa;
                border: 1px solid #4dd0e1;
                padding: 8px 10px;
                margin-right: 10px;
                border-radius: 3px;
                position: relative;
                cursor: move;
                flex: 1;
                max-width: 48%;
            }

            .pfb-remove-field {
                position: absolute;
                right: 5px;
                top: 5px;
                cursor: pointer;
                color: #f44336;
                font-weight: bold;
            }

            .pfb-add-row {
                text-align: center;
                padding: 10px;
                background: #f0f0f0;
                cursor: pointer;
                margin-top: 10px;
                width: 100%;
                border: none;
            }

            .pfb-add-row:hover {
                background: #e0e0e0;
            }

            .pfb-layout-row-placeholder {
                border: 1px dashed #2196F3;
                background: #E3F2FD;
                height: 40px;
                margin-bottom: 10px;
            }

            .pfb-layout-field-placeholder {
                border: 1px dashed #4CAF50;
                background: #E8F5E9;
                height: 36px;
                width: 150px;
                margin-right: 10px;
            }

            .dragging {
                opacity: 0.5;
            }

            .drag-over {
                border: 2px dashed #4CAF50;
            }
        </style>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                console.log('Billing settings JS initialized');

                // DOM Elements
                const enableBillingCheckbox = document.getElementById('pfb-enable-billing');
                const enableShippingCheckbox = document.getElementById('pfb-enable-shipping');
                const sameAsBillingCheckbox = document.querySelector('input[name="pfb_enable_same_as_billing"]');
                const billingFieldsContainer = document.getElementById('pfb-billing-fields-container');
                const shippingFieldsContainer = document.getElementById('pfb-shipping-fields-container');

                // Hidden inputs for form data
                const billingLayoutInput = document.getElementById('pfb-billing-layout-input');
                const billingFieldsInput = document.getElementById('pfb-billing-fields-input');
                const shippingLayoutInput = document.getElementById('pfb-shipping-layout-input');
                const shippingFieldsInput = document.getElementById('pfb-shipping-fields-input');

                // Toggle billing fields container visibility
                enableBillingCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        billingFieldsContainer.style.display = 'block';
                        enableShippingCheckbox.disabled = false;
                    } else {
                        billingFieldsContainer.style.display = 'none';
                        enableShippingCheckbox.checked = false;
                        enableShippingCheckbox.disabled = true;
                        shippingFieldsContainer.style.display = 'none';
                        sameAsBillingCheckbox.disabled = true;
                    }
                });

                // Toggle shipping fields container visibility
                enableShippingCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        sameAsBillingCheckbox.disabled = false;

                        // If same-as-billing is checked, hide the shipping fields container
                        if (sameAsBillingCheckbox.checked) {
                            shippingFieldsContainer.style.display = 'none';
                        } else {
                            shippingFieldsContainer.style.display = 'block';
                        }
                    } else {
                        shippingFieldsContainer.style.display = 'none';
                        sameAsBillingCheckbox.disabled = true;
                    }
                });

                // Toggle shipping fields container when same-as-billing changes
                sameAsBillingCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        shippingFieldsContainer.style.display = 'none';
                    } else {
                        if (enableShippingCheckbox.checked) {
                            shippingFieldsContainer.style.display = 'block';
                        }
                    }
                });

                // Initial state check for same-as-billing
                if (enableShippingCheckbox.checked && sameAsBillingCheckbox.checked) {
                    shippingFieldsContainer.style.display = 'none';
                }

                // Drag and Drop functionality
                let draggedItem = null;

                // Add event listeners for draggable items
                function initDraggable() {
                    // Available fields
                    const draggableFields = document.querySelectorAll('.draggable-field');
                    draggableFields.forEach(field => {
                        field.addEventListener('dragstart', handleDragStart);
                        field.addEventListener('dragend', handleDragEnd);
                    });

                    // Layout fields
                    const layoutFields = document.querySelectorAll('.pfb-layout-field');
                    layoutFields.forEach(field => {
                        field.addEventListener('dragstart', handleDragStart);
                        field.addEventListener('dragend', handleDragEnd);
                    });

                    // Layout rows
                    const layoutRows = document.querySelectorAll('.pfb-layout-row');
                    layoutRows.forEach(row => {
                        row.addEventListener('dragover', handleDragOver);
                        row.addEventListener('dragenter', handleDragEnter);
                        row.addEventListener('dragleave', handleDragLeave);
                        row.addEventListener('drop', handleDrop);
                    });
                }

                // Initialize draggable items
                initDraggable();

                // Drag event handlers
                function handleDragStart(e) {
                    draggedItem = this;
                    this.classList.add('dragging');

                    // Set data transfer
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', this.dataset.field);

                    console.log('Drag started:', this.dataset.field);
                }

                function handleDragEnd(e) {
                    this.classList.remove('dragging');

                    // Remove drag-over class from all rows
                    document.querySelectorAll('.pfb-layout-row').forEach(row => {
                        row.classList.remove('drag-over');
                    });

                    console.log('Drag ended');
                }

                function handleDragOver(e) {
                    e.preventDefault();
                    return false;
                }

                function handleDragEnter(e) {
                    e.preventDefault();
                    this.classList.add('drag-over');
                }

                function handleDragLeave(e) {
                    this.classList.remove('drag-over');
                }

                function handleDrop(e) {
                    e.preventDefault();
                    this.classList.remove('drag-over');

                    const fieldId = e.dataTransfer.getData('text/plain');
                    console.log('Drop event:', fieldId);

                    // Check if the dragged item is from the available fields list
                    if (draggedItem.classList.contains('draggable-field')) {
                        // Create a new layout field
                        const fieldLabel = draggedItem.textContent.trim();
                        const newField = document.createElement('div');
                        newField.className = 'pfb-layout-field';
                        newField.setAttribute('data-field', fieldId);
                        newField.setAttribute('draggable', 'true');
                        newField.innerHTML = fieldLabel + '<span class="pfb-remove-field">×</span>';

                        // Add event listeners to the new field
                        newField.addEventListener('dragstart', handleDragStart);
                        newField.addEventListener('dragend', handleDragEnd);

                        // Add the new field to the row
                        this.appendChild(newField);

                        // Remove the field from the available fields list
                        draggedItem.remove();

                        // Update the inputs
                        updateLayoutInputs();
                    } else if (draggedItem.classList.contains('pfb-layout-field')) {
                        // Moving an existing layout field
                        if (this !== draggedItem.parentNode) {
                            // Moving to a different row
                            this.appendChild(draggedItem);
                        }

                        // Update the inputs
                        updateLayoutInputs();
                    }

                    return false;
                }

                // Add new row
                const addRowButtons = document.querySelectorAll('.pfb-add-row');
                addRowButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        console.log('Add row clicked');

                        // Create a new row
                        const newRow = document.createElement('div');
                        newRow.className = 'pfb-layout-row';

                        // Add event listeners to the new row
                        newRow.addEventListener('dragover', handleDragOver);
                        newRow.addEventListener('dragenter', handleDragEnter);
                        newRow.addEventListener('dragleave', handleDragLeave);
                        newRow.addEventListener('drop', handleDrop);

                        // Insert the new row before the add row button
                        this.parentNode.insertBefore(newRow, this);

                        // Update the inputs
                        updateLayoutInputs();
                    });
                });

                // Remove field
                document.addEventListener('click', function(e) {
                    if (e.target.classList.contains('pfb-remove-field')) {
                        console.log('Remove field clicked');

                        const field = e.target.parentNode;
                        const fieldId = field.dataset.field;
                        const container = field.closest('.pfb-layout-container');
                        const isBilling = container.id === 'pfb-billing-layout';

                        console.log('Removing field:', fieldId, 'from', isBilling ? 'billing' : 'shipping');

                        // Add back to available fields list
                        const fieldLabel = field.textContent.replace('×', '').trim();
                        const availableList = isBilling ?
                            document.getElementById('pfb-available-billing-fields') :
                            document.getElementById('pfb-available-shipping-fields');

                        const newItem = document.createElement('li');
                        newItem.className = 'draggable-field';
                        newItem.setAttribute('data-field', fieldId);
                        newItem.setAttribute('draggable', 'true');
                        newItem.textContent = fieldLabel;

                        // Add event listeners to the new item
                        newItem.addEventListener('dragstart', handleDragStart);
                        newItem.addEventListener('dragend', handleDragEnd);

                        availableList.appendChild(newItem);

                        // Remove the field from the layout
                        field.remove();

                        // Remove empty rows
                        const rows = container.querySelectorAll('.pfb-layout-row');
                        rows.forEach(row => {
                            if (row.children.length === 0) {
                                row.remove();
                            }
                        });

                        // Update the inputs
                        updateLayoutInputs();
                    }
                });

                // Function to update hidden inputs with layout data
                function updateLayoutInputs() {
                    console.log('Updating layout inputs...');

                    // Update billing layout
                    const billingLayout = [];
                    document.querySelectorAll('#pfb-billing-layout .pfb-layout-row').forEach(row => {
                        const rowFields = [];
                        row.querySelectorAll('.pfb-layout-field').forEach(field => {
                            rowFields.push(field.dataset.field);
                        });
                        if (rowFields.length > 0) {
                            billingLayout.push(rowFields);
                        }
                    });
                    billingLayoutInput.value = JSON.stringify(billingLayout);
                    console.log('Billing layout:', billingLayout);

                    // Update billing fields
                    const billingFields = [];
                    document.querySelectorAll('#pfb-billing-layout .pfb-layout-field').forEach(field => {
                        billingFields.push(field.dataset.field);
                    });
                    billingFieldsInput.value = billingFields.join(',');
                    console.log('Billing fields:', billingFields);

                    // Update shipping layout
                    const shippingLayout = [];
                    document.querySelectorAll('#pfb-shipping-layout .pfb-layout-row').forEach(row => {
                        const rowFields = [];
                        row.querySelectorAll('.pfb-layout-field').forEach(field => {
                            rowFields.push(field.dataset.field);
                        });
                        if (rowFields.length > 0) {
                            shippingLayout.push(rowFields);
                        }
                    });
                    shippingLayoutInput.value = JSON.stringify(shippingLayout);
                    console.log('Shipping layout:', shippingLayout);

                    // Update shipping fields
                    const shippingFields = [];
                    document.querySelectorAll('#pfb-shipping-layout .pfb-layout-field').forEach(field => {
                        shippingFields.push(field.dataset.field);
                    });
                    shippingFieldsInput.value = shippingFields.join(',');
                    console.log('Shipping fields:', shippingFields);
                }
            });
        </script>
    <?php
    }

    /**
     * Get default billing layout
     * 
     * @return array Default billing layout
     */
    private function get_default_billing_layout()
    {
        return [
            ['first_name', 'last_name'],
            ['company'],
            ['address_1'],
            ['address_2'],
            ['city', 'state'],
            ['postcode', 'country'],
            ['phone', 'email']
        ];
    }

    /**
     * Get default shipping layout
     * 
     * @return array Default shipping layout
     */
    private function get_default_shipping_layout()
    {
        return [
            ['first_name', 'last_name'],
            ['company'],
            ['address_1'],
            ['address_2'],
            ['city', 'state'],
            ['postcode', 'country'],
            ['phone']
        ];
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

        // Check for email_sent column in submissions table
        if ($submissions_exists) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$submissions_table} LIKE 'email_sent'");
            if (empty($column_exists)) {
                $result = $wpdb->query("ALTER TABLE {$submissions_table} ADD COLUMN email_sent TINYINT(1) DEFAULT 0 AFTER payment_status");
                if ($result !== false) {
                    $updates[] = 'Added "email_sent" column';
                } else {
                    $success = false;
                    $updates[] = 'Failed to add "email_sent" column: ' . $wpdb->last_error;
                }
            }

            // Check for mode column in submissions table
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

    public function handle_css_cache_clear()
    {
        if (
            isset($_POST['pfb_clear_css_cache']) &&
            isset($_POST['pfb_css_cache_nonce']) &&
            wp_verify_nonce($_POST['pfb_css_cache_nonce'], 'pfb_clear_css_cache')
        ) {
            wp_cache_delete('pfb_global_css', 'options');
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>CSS cache has been cleared.</p></div>';
            });
        }
    }


    public function render_global_css_settings()
    {
        // Get the saved CSS directly from the database without cache
        global $wpdb;
        $global_css = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'pfb_global_css'
            )
        );

        // Unserialize if needed (WordPress serializes option values)
        $global_css = maybe_unserialize($global_css);

        // Register the setting if not already registered
        if (!get_registered_settings()['pfb_global_css']) {
            register_setting('pfb_global_css_settings', 'pfb_global_css', array(
                'type' => 'string',
                'sanitize_callback' => 'wp_strip_all_tags',
                'default' => '',
            ));
        }

        // Display the editor
        echo '<form method="post" action="options.php">';
        settings_fields('pfb_global_css_settings');
        do_settings_sections('pfb_global_css_settings');

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="pfb_global_css">' . __('Custom CSS', 'payment-form-builder') . '</label></th>';
        echo '<td>';
        wp_editor($global_css, 'pfb_global_css', array(
            'textarea_name' => 'pfb_global_css',
            'textarea_rows' => 15,
            'media_buttons' => false,
            'teeny' => true,
            'tinymce' => false, // Disable visual editor
            'quicktags' => array('buttons' => 'strong,em,link,block,del,ins,img,code'),
        ));
        echo '<p class="description">' . __('Add custom CSS here to override the styling of the payment form. Changes will take effect immediately.', 'payment-form-builder') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        submit_button(__('Save CSS', 'payment-form-builder'));
        echo '</form>';

        // Add a clear cache button
        echo '<form method="post" action="">';
        wp_nonce_field('pfb_clear_css_cache', 'pfb_css_cache_nonce');
        echo '<input type="hidden" name="pfb_clear_css_cache" value="1">';
        submit_button(__('Clear CSS Cache', 'payment-form-builder'), 'secondary');
        echo '</form>';
    }



    public function register_ajax_handlers()
    {
        add_action('wp_ajax_pfb_repair_database', array($this, 'ajax_repair_database'));
    }

    public function ajax_repair_database()
    {
        check_ajax_referer('pfb_repair_database', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = $this->repair_database();
        wp_send_json_success($result);
    }

    public function render_orders_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Include the orders class
        require_once PFB_PLUGIN_DIR . 'admin/class-orders.php';

        $run_check_url = admin_url('admin.php?page=pfb-run-payment-check');

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

            <a href="<?php echo esc_url($run_check_url); ?>" class="run-payment-title-action">Run Payment Check</a>

            <form id="orders-filter" method="get">
                <?php $orders_table->display(); ?>
            </form>
        </div>
<?php
    }

    public function run_payment_check_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        echo '<div class="wrap">';
        echo '<h1>Run Payment Check</h1>';

        if (isset($_POST['run_check']) && wp_verify_nonce($_POST['_wpnonce'], 'run_payment_check')) {
            // Run the check
            $plugin = payment_form_builder();
            if (method_exists($plugin, 'check_pending_payments')) {
                $plugin->check_pending_payments();
                echo '<div class="notice notice-success"><p>Payment check completed. Check the error log for details.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Payment check method not found.</p></div>';
            }
        }

        echo '<form method="post">';
        wp_nonce_field('run_payment_check');
        echo '<p>This will manually run the payment status check that normally runs via cron job.</p>';
        echo '<p><input type="submit" name="run_check" class="button button-primary" value="Run Payment Check"></p>';
        echo '</form>';
        echo '</div>';
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

        add_submenu_page(
            null, // No parent - hidden page
            'Run Payment Check',
            'Run Payment Check',
            'manage_options',
            'pfb-run-payment-check',
            array($this, 'run_payment_check_page')
        );
    }



    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'pfb-settings') !== false || $hook == 'payment_form_page_pfb-settings') {
            // Enqueue jQuery UI
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-draggable');
            wp_enqueue_script('jquery-ui-droppable');
            wp_enqueue_script('jquery-ui-sortable');

            wp_enqueue_style('pfb-admin', PFB_PLUGIN_URL . 'admin/css/admin.css', array(), PFB_VERSION);
            return;
        }


        // Load scripts for settings page
        if ($hook == 'payment_form_page_pfb-settings') {
            // Enqueue jQuery UI
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-draggable');
            wp_enqueue_script('jquery-ui-droppable');
            wp_enqueue_script('jquery-ui-sortable');

            wp_enqueue_style('pfb-admin', PFB_PLUGIN_URL . 'admin/css/admin.css', array(), PFB_VERSION);
            wp_add_inline_style('pfb-admin', '
        .nav-tab-wrapper {
            margin-bottom: 20px;
        }
        .form-table th {
            width: 200px;
        }
        .pfb-billing-builder, .pfb-shipping-builder {
            display: flex;
            margin-bottom: 20px;
        }
        .pfb-billing-fields-list, .pfb-shipping-fields-list,
        .pfb-billing-fields-selected, .pfb-shipping-fields-selected {
            width: 48%;
            margin-right: 2%;
        }
        #pfb-available-billing-fields li, #pfb-available-shipping-fields li {
            padding: 10px;
            background: #f5f5f5;
            border: 1px solid #ddd;
            margin-bottom: 5px;
            cursor: pointer;
            list-style: none;
        }
        #pfb-available-billing-fields li.selected, #pfb-available-shipping-fields li.selected {
            background: #e0f7fa;
            border-color: #4dd0e1;
        }
        .required {
            color: red;
        }
        ');
            return; // Return early to avoid loading the post edit scripts
        }

        // Load scripts for post edit pages
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
