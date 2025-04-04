<?php

/**
 * Plugin Name: Payment Form Builder
 * Description: Create custom forms with Stripe payments
 * Version: 1.0.0
 * Author: Olayinka Aremu
 * Text Domain: payment-form-builder
 */

if (!defined('ABSPATH')) exit;

class Payment_Form_Builder
{
    private static $instance = null;
    private $errors = array();
    private $admin;
    private $public;
    private $form_handler;
    private $customer_emails;
    private $stripe;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        try {
            // Define constants
            $this->define_constants();

            // Check requirements before proceeding
            if (!$this->check_requirements()) {
                return;
            }

            // Add activation hook
            register_activation_hook(__FILE__, array($this, 'activate'));

            // Add deactivation hook
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));

            register_activation_hook(__FILE__, array($this, 'create_tables'));

            // Initialize plugin
            add_action('plugins_loaded', array($this, 'init'));

            // Add admin notices
            add_action('admin_notices', array($this, 'display_admin_notices'));

            add_filter('cron_schedules', array($this, 'add_cron_interval'));
        } catch (Exception $e) {
            $this->errors[] = 'Plugin initialization error: ' . $e->getMessage();
            error_log('Payment Form Builder initialization error: ' . $e->getMessage());
        }
    }

    // Register the cron schedule on plugin activation
    public function register_cron_schedules()
    {
        if (!wp_next_scheduled('pfb_check_missed_emails')) {
            wp_schedule_event(time(), 'hourly', 'pfb_check_missed_emails');
        }
    }

    public function add_cron_interval($schedules)
    {
        $schedules['three_minutes'] = array(
            'interval' => 180, // 3 minutes in seconds
            'display'  => esc_html__('Every 3 Minutes', 'payment-form-builder'),
        );
        return $schedules;
    }

    // Unregister the cron schedule on plugin deactivation
    public function unregister_cron_schedules()
    {
        $timestamp = wp_next_scheduled('pfb_check_missed_emails');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pfb_check_missed_emails');
        }
    }


     private function format_currency($amount, $currency) {
            $symbols = [
                'usd' => '$',
                'eur' => '€',
                'gbp' => '£',
                'jpy' => '¥',
                // Add more currencies as needed
            ];
            
            $currency = strtolower($currency);
            $symbol = isset($symbols[$currency]) ? $symbols[$currency] : $currency . ' ';
            
            return $symbol . number_format($amount, 2);
        }

    // Process missed emails
    public function process_missed_emails()
    {
        global $wpdb;

        error_log('Running scheduled check for missed payment emails');

        // Find completed payments without emails sent
        $table_name = $wpdb->prefix . 'pfb_submissions';
        $completed_payments = $wpdb->get_results(
            "SELECT * FROM $table_name 
        WHERE payment_status = 'completed' 
        AND (email_sent = 0 OR email_sent IS NULL)
        LIMIT 50"  // Process in batches to avoid timeouts
        );

        if (empty($completed_payments)) {
            error_log('No missed emails found');
            return;
        }

        error_log('Found ' . count($completed_payments) . ' payments missing email notifications');

        // Load the form handler class
        if (!class_exists('PFB_Form_Handler')) {
            require_once PFB_PLUGIN_DIR . 'includes/class-form-handler.php';
        }

        $form_handler = new PFB_Form_Handler();

        foreach ($completed_payments as $payment) {
            error_log("Processing missed email for payment ID: {$payment->id}");

            // Skip if no submission data
            if (empty($payment->submission_data)) {
                error_log("No submission data for payment ID: {$payment->id}, skipping");
                $wpdb->update(
                    $table_name,
                    ['email_sent' => 1],  // Mark as processed to avoid repeated attempts
                    ['id' => $payment->id],
                    ['%d'],
                    ['%d']
                );
                continue;
            }

            $submission_data = json_decode($payment->submission_data, true);

            // Send the email
            if (method_exists($form_handler, 'send_admin_notification')) {
                $email_result = $form_handler->send_admin_notification($payment->id, $payment->form_id, $submission_data);

                if ($email_result) {
                    error_log("Successfully sent missed email for payment ID: {$payment->id}");
                } else {
                    error_log("Failed to send missed email for payment ID: {$payment->id}");
                }
            }

            // Mark as processed even if email fails to avoid repeated attempts
            $wpdb->update(
                $table_name,
                ['email_sent' => 1],
                ['id' => $payment->id],
                ['%d'],
                ['%d']
            );
        }
    }

    /**
     * Check pending payments and update their status
     */
    public function check_pending_payments()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pfb_submissions';

        // Get all pending payments older than 5 minutes
        $pending_payments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
            WHERE payment_status = %s 
            AND created_at < %s",
                'pending',
                date('Y-m-d H:i:s', strtotime('-3 minutes'))
            )
        );

        if (empty($pending_payments)) {
            error_log('No pending payments found to check.');
            return;
        }

        error_log('Found ' . count($pending_payments) . ' pending payments to check.');

        foreach ($pending_payments as $payment) {
            $payment_intent_id = $payment->payment_intent;

            if (empty($payment_intent_id)) {
                error_log('Payment ID ' . $payment->id . ' has no payment intent ID. Skipping.');
                continue;
            }

            error_log('Checking payment intent: ' . $payment_intent_id);

            // Get the Stripe instance
            $stripe = new PFB_Stripe();

            // Check the payment status
            $status = $stripe->check_payment_status($payment_intent_id) ? 'completed' : 'failed';

            if ($status && $status !== $payment->payment_status) {
                error_log('Payment ' . $payment_intent_id . ' status changed from ' . $payment->payment_status . ' to ' . $status);

                // Update the payment status - CORRECTED FORMAT SPECIFIERS
                $update_result = $wpdb->update(
                    $table_name,
                    array(
                        'payment_status' => $status,
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $payment->id),
                    array('%s', '%s'),  // Use %s for string (status) and datetime
                    array('%d')         // Use %d for integer (id)
                );

                if ($update_result === false) {
                    error_log('Failed to update payment status. DB Error: ' . $wpdb->last_error);
                }

                // If payment is completed, send email notification if not already sent
                if ($status === 'completed' && $payment->email_sent != 1) {
                    error_log('Sending email notification for payment ' . $payment_intent_id);

                    // Get the form handler instance
                    $form_handler = new PFB_Form_Handler();

                    // Get the submission data
                    $submission_data = json_decode($payment->submission_data, true);

                    // Send the email notification
                    $email_sent = $form_handler->send_admin_notification(
                        $payment->id,
                        $payment->form_id,
                        $submission_data
                    );

                    // Update the email_sent column
                    if ($email_sent) {
                        error_log('Email sent successfully for payment ' . $payment_intent_id);
                        $wpdb->update(
                            $table_name,
                            array('email_sent' => 1),
                            array('id' => $payment->id),
                            array('%d'),  // Use %d for integer (email_sent)
                            array('%d')   // Use %d for integer (id)
                        );
                    } else {
                        error_log('Failed to send email for payment ' . $payment_intent_id);
                    }
                }
            } else {
                error_log('Payment ' . $payment_intent_id . ' status unchanged: ' . $payment->payment_status);
            }
        }
    }

    private function check_requirements()
    {
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $this->errors[] = 'Payment Form Builder requires PHP 7.4 or higher.';
            return false;
        }

        // Check WordPress version
        if (version_compare($GLOBALS['wp_version'], '5.0', '<')) {
            $this->errors[] = 'Payment Form Builder requires WordPress 5.0 or higher.';
            return false;
        }

        // Check if Composer autoload exists
        if (!file_exists(PFB_PLUGIN_DIR . 'vendor/autoload.php')) {
            $this->errors[] = 'Required dependencies are missing. Please run "composer install" in the plugin directory.';
            return false;
        }

        return true;
    }

    public function display_admin_notices()
    {
        foreach ($this->errors as $error) {
            echo '<div class="error"><p>' . esc_html($error) . '</p></div>';
        }
    }

    private function define_constants()
    {
        define('PFB_VERSION', '1.0.0');
        define('PFB_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('PFB_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    public function activate()
    {
        try {
            // Check requirements
            if (!$this->check_requirements()) {
                throw new Exception('Plugin requirements not met.');
            }

            // Create database tables
            $this->create_tables();

            // Set default options
            $this->set_default_options();

            // Register cron schedule
            $this->register_cron_schedules();

            // Schedule payment status check
            if (!wp_next_scheduled('pfb_check_pending_payments')) {
                wp_schedule_event(time(), 'three_minutes', 'pfb_check_pending_payments');
            }

            // Flush rewrite rules
            flush_rewrite_rules();
        } catch (Exception $e) {
            error_log('Payment Form Builder activation error: ' . $e->getMessage());
            wp_die('Error activating plugin: ' . esc_html($e->getMessage()));
        }
    }

    public function deactivate()
    {
        // Unregister cron schedule
        $this->unregister_cron_schedules();

        // Clear payment check schedule
        $timestamp = wp_next_scheduled('pfb_check_pending_payments');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pfb_check_pending_payments');
        }

        flush_rewrite_rules();
    }

    private function set_default_options()
    {
        if (get_option('pfb_test_mode') === false) {
            add_option('pfb_test_mode', true);
        }
        if (get_option('pfb_webhook_secret') === false) {
            add_option('pfb_webhook_secret', '');
        }
    }

    public function create_tables()
    {

        try {
            global $wpdb;
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');



            $charset_collate = $wpdb->get_charset_collate();
            $submissions_table = $wpdb->prefix . 'pfb_submissions';
            $form_fields_table = $wpdb->prefix . 'pfb_form_fields';

            // Create submissions table if it doesn't exist
            if ($wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") != $submissions_table) {
                $submissions_sql = "CREATE TABLE $submissions_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                payment_intent varchar(255) NOT NULL,
                payment_status varchar(50) NOT NULL,
                email_sent TINYINT(1) DEFAULT 0,
                amount decimal(10,2),
                currency varchar(3),
                mode varchar(10),
                submission_data longtext NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY form_id (form_id),
                KEY payment_status (payment_status),
                KEY payment_intent (payment_intent),
                KEY mode (mode)
            ) $charset_collate;";

                dbDelta($submissions_sql);

                // Check if table was created
                if ($wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") != $submissions_table) {
                    throw new Exception('Failed to create submissions table.');
                }
            } else {
                // Check if mode column exists and add it if not
                $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$submissions_table} LIKE 'mode'");
                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE {$submissions_table} ADD COLUMN mode varchar(10) AFTER currency");
                    $wpdb->query("ALTER TABLE {$submissions_table} ADD INDEX mode (mode)");
                }

                // Check if payment_intent index exists and add it if not
                $index_exists = $wpdb->get_results("SHOW INDEX FROM {$submissions_table} WHERE Key_name = 'payment_intent'");
                if (empty($index_exists)) {
                    $wpdb->query("ALTER TABLE {$submissions_table} ADD INDEX payment_intent (payment_intent)");
                }
            }

            // Create form fields table if it doesn't exist
            if ($wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") != $form_fields_table) {
                $form_fields_sql = "CREATE TABLE $form_fields_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                field_data longtext NOT NULL,
                PRIMARY KEY  (id),
                KEY form_id (form_id)
            ) $charset_collate;";

                dbDelta($form_fields_sql);

                // Check if table was created
                if ($wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") != $form_fields_table) {
                    throw new Exception('Failed to create form fields table.');
                }
            }
        } catch (Exception $e) {
            error_log('Payment Form Builder table creation error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function init()
    {
        try {
            if (!$this->check_requirements()) {
                return;
            }

            $this->load_dependencies();

            // Initialize components
            if (is_admin()) {
                new PFB_Admin();
            }

            // Store the instance instead of creating a new one
            $this->public = new PFB_Public();
            $this->form_handler = new PFB_Form_Handler();
            $this->customer_emails = new PFB_Customer_Emails();
            $this->stripe = new PFB_Stripe();

            add_action('pfb_check_pending_payments', array($this, 'check_pending_payments'));

            // Register cron hook
            add_action('pfb_check_missed_emails', array($this, 'process_missed_emails'));
        } catch (Exception $e) {
            $this->errors[] = 'Plugin initialization error: ' . $e->getMessage();
            error_log('Payment Form Builder initialization error: ' . $e->getMessage());
        }
    }

    private function load_dependencies()
    {
        $required_files = array(
            'vendor/autoload.php' => 'Composer autoload file',
            'admin/class-admin.php' => 'Admin class file',
            'public/class-public.php' => 'Public class file',
            'includes/class-stripe.php' => 'Stripe class file',
            'includes/class-form-handler.php' => 'Form handler class file',
            'includes/class-customer-emails.php' => 'Customer emails class file'
        );

        foreach ($required_files as $file => $description) {
            $path = PFB_PLUGIN_DIR . $file;
            if (!file_exists($path)) {
                throw new Exception("Required $description is missing: $file");
            }
            require_once $path;
        }
    }
}

// Initialize plugin
if (!function_exists('payment_form_builder')) {
    function payment_form_builder()
    {
        return Payment_Form_Builder::get_instance();
    }
}


// Wrap initialization in try-catch
try {
    payment_form_builder();
} catch (Exception $e) {
    error_log('Payment Form Builder fatal error: ' . $e->getMessage());
    add_action('admin_notices', function () use ($e) {
        echo '<div class="error"><p>Payment Form Builder error: ' . esc_html($e->getMessage()) . '</p></div>';
    });
}
