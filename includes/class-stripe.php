<?php

/**
 * Stripe integration class
 */
class PFB_Stripe
{
    private $stripe;
    private $initialized = false;
    private $errors = array();

    public function __construct()
    {
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

            // Register webhook endpoint
            add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            error_log('Payment Form Builder Stripe initialization error: ' . $e->getMessage());
        }
    }

    public function is_ready()
    {
        return $this->initialized && empty($this->errors);
    }

    public function get_errors()
    {
        return $this->errors;
    }

    public function create_payment_intent($amount, $currency = 'usd')
    {
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

    /**
     * Register the webhook endpoint
     */
    public function register_webhook_endpoint()
    {
        register_rest_route('pfb/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Handle Stripe webhook events
     */
    public function handle_webhook()
    {
        error_log('Webhook received: ' . print_r($_SERVER, true));

        if (!$this->is_ready()) {
            error_log('Stripe not ready');
            return new WP_Error('stripe_not_ready', 'Stripe is not properly configured');
        }

        $webhook_secret = get_option('pfb_webhook_secret');

        try {
            $payload = @file_get_contents('php://input');
            error_log('Webhook payload: ' . $payload);

            $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
            error_log('Webhook signature: ' . $sig_header);

            // Verify webhook signature if secret is set
            if (!empty($webhook_secret) && !empty($sig_header)) {
                $event = \Stripe\Webhook::constructEvent(
                    $payload,
                    $sig_header,
                    $webhook_secret
                );
            } else {
                // If no webhook secret is set, just decode the event
                $event = json_decode($payload);
                if (!$event) {
                    error_log('Invalid JSON payload');
                    throw new Exception('Invalid JSON payload');
                }
            }

            // Log the event type
            error_log('Received Stripe webhook: ' . $event->type);

            // Handle payment_intent.succeeded event
            if ($event->type === 'payment_intent.succeeded') {
                $payment_intent = $event->data->object;
                $this->update_payment_status(
                    $payment_intent->id,
                    'completed',
                    $payment_intent->amount / 100,
                    $payment_intent->currency
                );
                error_log('Payment succeeded: ' . $payment_intent->id);
            }

            // Handle payment_intent.payment_failed event
            if ($event->type === 'payment_intent.payment_failed') {
                $payment_intent = $event->data->object;
                $this->update_payment_status($payment_intent->id, 'failed');
                error_log('Payment failed: ' . $payment_intent->id);
            }

            return new WP_REST_Response(['status' => 'success'], 200);
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            error_log('Webhook error: ' . $e->getMessage());
            return new WP_Error('invalid_payload', $e->getMessage(), array('status' => 400));
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            error_log('Webhook signature verification failed: ' . $e->getMessage());
            return new WP_Error('invalid_signature', $e->getMessage(), array('status' => 400));
        } catch (Exception $e) {
            // Generic error
            error_log('Webhook error: ' . $e->getMessage());
            return new WP_Error('webhook_error', $e->getMessage(), array('status' => 400));
        }
    }

    /**
     * Update payment status in the database
     */
    private function update_payment_status($payment_intent_id, $status, $amount = null, $currency = null)
    {
        global $wpdb;

        error_log("Updating payment status for intent: $payment_intent_id to status: $status");

        // First, check if we have a record with this payment intent
        $table_name = $wpdb->prefix . 'pfb_submissions';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE payment_intent = %s",
            $payment_intent_id
        ));

        if (!$existing) {
            error_log("No record found for payment intent: $payment_intent_id");
            return false;
        }

        error_log("Found record ID: {$existing->id} with current status: {$existing->payment_status}");

        $data = [
            'payment_status' => $status,
            'updated_at' => current_time('mysql')
        ];

        $format = ['%s', '%s'];

        if ($amount !== null) {
            $data['amount'] = $amount;
            $format[] = '%f';
        }

        if ($currency !== null) {
            $data['currency'] = $currency;
            $format[] = '%s';
        }

        $result = $wpdb->update(
            $table_name,
            $data,
            ['payment_intent' => $payment_intent_id],
            $format,
            ['%s']
        );

        if ($result === false) {
            error_log('Failed to update payment status: ' . $wpdb->last_error);
        } else {
            error_log("Successfully updated payment status for intent: $payment_intent_id to: $status");
        }

        return $result;
    }
}
