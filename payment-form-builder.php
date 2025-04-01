<?php

/**
 * Plugin Name: Payment Form Builder
 * Description: Create custom forms with Stripe payments
 * Version: 1.2.0
 * Author: Olayinka Aremu
 * Text Domain: payment-form-builder
 */

if (!defined('ABSPATH')) exit;

class Payment_Form_Builder
{
    private static $instance = null;
    private $errors = array();

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

            // Initialize plugin
            add_action('plugins_loaded', array($this, 'init'));

            // Add admin notices
            add_action('admin_notices', array($this, 'display_admin_notices'));
        } catch (Exception $e) {
            $this->errors[] = 'Plugin initialization error: ' . $e->getMessage();
            error_log('Payment Form Builder initialization error: ' . $e->getMessage());
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

            // Flush rewrite rules
            flush_rewrite_rules();
        } catch (Exception $e) {
            error_log('Payment Form Builder activation error: ' . $e->getMessage());
            wp_die('Error activating plugin: ' . esc_html($e->getMessage()));
        }
    }

    public function deactivate()
    {
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

    private function create_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        try {
            $table_name = $wpdb->prefix . 'pfb_submissions';

            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                submission_data longtext NOT NULL,
                payment_status varchar(50) NOT NULL,
                payment_intent varchar(255),
                amount decimal(10,2),
                currency varchar(3),
                mode varchar(10),
                created_at datetime NOT NULL,
                updated_at datetime,
                PRIMARY KEY  (id),
                KEY form_id (form_id),
                KEY payment_status (payment_status),
                KEY payment_intent (payment_intent),
                KEY mode (mode),
                KEY created_at (created_at)
            ) $charset_collate;";

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);

                // Check if table was created
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                    throw new Exception('Failed to create database table.');
                }
            } else {
                // Check if mode column exists and add it if not
                $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'mode'");
                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN mode varchar(10) AFTER currency");
                    $wpdb->query("ALTER TABLE {$table_name} ADD INDEX mode (mode)");
                }

                // Check if payment_intent index exists and add it if not
                $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'payment_intent'");
                if (empty($index_exists)) {
                    $wpdb->query("ALTER TABLE {$table_name} ADD INDEX payment_intent (payment_intent)");
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
            new PFB_Public();
            new PFB_Form_Handler();
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
            'includes/class-form-handler.php' => 'Form handler class file'
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
