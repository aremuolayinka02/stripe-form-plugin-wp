<?php
/**
 * Stripe integration class
 */
class PFB_Stripe {
    private $stripe;
    private $initialized = false;
    private $errors = array();

    public function __construct() {
        try {
            // Check if vendor directory exists
            if (!file_exists(PFB_PLUGIN_DIR . 'vendor/autoload.php')) {
                throw new Exception('Stripe PHP SDK not found. Please run composer install.');
            }

            // Include the Composer autoloader
            if (!file_exists(PFB_PLUGIN_DIR . 'vendor/autoload.php')) {
                throw new Exception('Stripe PHP SDK not found. Please run "composer require stripe/stripe-php".');
            }
            require_once PFB_PLUGIN_DIR . 'vendor/autoload.php';

            // Verify Stripe class exists
            if (!class_exists('\Stripe\Stripe')) {
                throw new Exception(
                    'Stripe PHP SDK not properly loaded. Please ensure the "stripe/stripe-php" library is installed and autoloaded. ' .
                    'Check if the "vendor/autoload.php" file exists and is correctly included.'
                );
            }

            // Get API keys
            $test_mode = get_option('pfb_test_mode', true);
            $secret_key = $test_mode 
                ? get_option('pfb_test_secret_key')
                : get_option('pfb_live_secret_key');

            if (empty($secret_key)) {
                throw new Exception('Stripe API key not configured.');
            }

            // Initialize Stripe
            \Stripe\Stripe::setApiKey($secret_key);
            
            // Set app info
            \Stripe\Stripe::setAppInfo(
                'Payment Form Builder',
                PFB_VERSION,
                'https://wordpress.org'
            );

            $this->initialized = true;

        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            error_log('Payment Form Builder Stripe initialization error: ' . $e->getMessage());
        }
    }

    public function is_ready() {
        return $this->initialized && empty($this->errors);
    }

    public function get_errors() {
        return $this->errors;
    }

    public function create_payment_intent($amount, $currency = 'usd') {
        if (!$this->is_ready()) {
            return new WP_Error('stripe_not_ready', 'Stripe is not properly configured: ' . implode(', ', $this->errors));
        }

        try {
            if (!is_numeric($amount) || $amount <= 0) {
                return new WP_Error('invalid_amount', 'Invalid payment amount');
            }

            return \Stripe\PaymentIntent::create([
                'amount' => (int)($amount * 100), // Convert to cents
                'currency' => strtolower($currency),
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            return new WP_Error('stripe_card_error', $e->getMessage());
        } catch (\Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage());
        }
    }
}