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

    public function create_payment_intent($amount, $currency = 'usd', $customer_email = null)
    {
        if (!$this->is_ready()) {
            return new WP_Error('stripe_not_ready', 'Stripe is not properly configured: ' . implode(', ', $this->errors));
        }

        try {
            if (!is_numeric($amount) || $amount <= 0) {
                return new WP_Error('invalid_amount', 'Invalid payment amount');
            }

            $params = [
                'amount' => (int)($amount * 100), // Convert to cents
                'currency' => strtolower($currency),
            ];

            // Add customer email if provided
            if (!empty($customer_email)) {
                error_log('Adding customer email to payment intent: ' . $customer_email);

                // Create or retrieve a customer
                $customers = \Stripe\Customer::all([
                    'email' => $customer_email,
                    'limit' => 1
                ]);

                if (count($customers->data) > 0) {
                    $customer = $customers->data[0];
                    error_log('Using existing Stripe customer: ' . $customer->id);
                } else {
                    $customer = \Stripe\Customer::create([
                        'email' => $customer_email
                    ]);
                    error_log('Created new Stripe customer: ' . $customer->id);
                }

                $params['customer'] = $customer->id;
                $params['receipt_email'] = $customer_email;
            }

            return \Stripe\PaymentIntent::create($params);
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

        // Check if this payment is already in the target status
        $table_name = $wpdb->prefix . 'pfb_submissions';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE payment_intent = %s",
            $payment_intent_id
        ));

        if (!$existing) {
            error_log("No existing record found for payment intent: $payment_intent_id");
            return false;
        }

        $old_status = $existing->payment_status;
        error_log("Found record ID: {$existing->id} with current status: {$old_status}");

        // If already in the target status, skip update but still check if email needs to be sent
        $status_changed = ($old_status !== $status);
        if (!$status_changed) {
            error_log("Payment $payment_intent_id already has status $status - skipping update");

            // Check if this is a completed payment that might need an email
            if ($status === 'completed') {
                $this->maybe_send_completion_email($existing);
            }

            return true;
        }

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

        // Add a flag to track if email was sent
        if ($status === 'completed') {
            $email_sent = get_post_meta($existing->form_id, '_payment_' . $existing->id . '_email_sent', true);
            if (!$email_sent) {
                $data['email_sent'] = 1;
            }
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
            return false;
        } else {
            error_log("Successfully updated payment status for intent: $payment_intent_id from: $old_status to: $status");

            // If payment is completed, send admin notification
            if ($status === 'completed') {
                $this->maybe_send_completion_email($existing);
            }
        }

        return $result;
    }

    /**
     * Helper method to send completion email if needed
     */
    private function maybe_send_completion_email($payment_record)
    {
        // Check if we've already sent an email for this payment
        $email_sent = get_post_meta($payment_record->form_id, '_payment_' . $payment_record->id . '_email_sent', true);

        if ($email_sent) {
            error_log("Email already sent for payment ID: {$payment_record->id} - skipping");
            return;
        }

        error_log("Payment completed, attempting to send admin notification");

        // Check if form handler class exists
        if (!class_exists('PFB_Form_Handler')) {
            error_log("PFB_Form_Handler class not found");
            require_once PFB_PLUGIN_DIR . 'includes/class-form-handler.php';
        }

        $form_handler = new PFB_Form_Handler();
        $submission_data = json_decode($payment_record->submission_data, true);

        if (empty($submission_data)) {
            error_log("Warning: Empty submission data for payment ID: {$payment_record->id}");
        }

        // Check if the method exists
        if (method_exists($form_handler, 'send_admin_notification')) {
            error_log("Calling send_admin_notification method");
            $email_result = $form_handler->send_admin_notification($payment_record->id, $payment_record->form_id, $submission_data);

            if ($email_result) {
                error_log("Email sent successfully for payment ID: {$payment_record->id}");
                // Mark this payment as having received an email
                update_post_meta($payment_record->form_id, '_payment_' . $payment_record->id . '_email_sent', '1');
            } else {
                error_log("Failed to send email for payment ID: {$payment_record->id}");
            }
        } else {
            error_log("Error: send_admin_notification method not found in PFB_Form_Handler class");
        }
    }

    /**
     * Check the status of a payment intent and update the database
     * 
     * @param string $payment_intent_id The payment intent ID
     * @return bool True if the status was updated, false otherwise
     */
    public function check_payment_status($payment_intent_id)
    {
        if (!$this->is_ready()) {
            error_log('Stripe is not properly configured');
            return false;
        }

        try {
            // Retrieve the payment intent from Stripe
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

            if (!$payment_intent) {
                error_log("Payment intent not found: $payment_intent_id");
                return false;
            }

            error_log("Retrieved payment intent: $payment_intent_id with status: {$payment_intent->status}");

            // Check if the status has changed
            if ($payment_intent->status === 'succeeded') {
                // Update the payment status in the database
                global $wpdb;
                $table_name = $wpdb->prefix . 'pfb_submissions';

                // Get the current payment record
                $payment = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE payment_intent = %s",
                    $payment_intent_id
                ));

                if (!$payment) {
                    error_log("Payment record not found for intent: $payment_intent_id");
                    return false;
                }

                // Only update if the status is different
                if ($payment->payment_status !== 'completed') {
                    $result = $wpdb->update(
                        $table_name,
                        [
                            'payment_status' => 'completed',
                            'amount' => $payment_intent->amount / 100,
                            'currency' => $payment_intent->currency,
                            'updated_at' => current_time('mysql')
                        ],
                        ['payment_intent' => $payment_intent_id],
                        ['%s', '%f', '%s', '%s'],
                        ['%s']
                    );

                    if ($result !== false) {
                        error_log("Updated payment status to completed for intent: $payment_intent_id");

                        // Send email notification if needed
                        if (class_exists('PFB_Form_Handler')) {
                            $form_handler = new PFB_Form_Handler();
                            if (method_exists($form_handler, 'send_admin_notification')) {
                                $submission_data = json_decode($payment->submission_data, true);
                                $form_handler->send_admin_notification($payment->id, $payment->form_id, $submission_data);
                            }
                        }

                        return true;
                    } else {
                        error_log("Failed to update payment status: " . $wpdb->last_error);
                    }
                } else {
                    error_log("Payment already marked as completed: $payment_intent_id");
                }
            } else if ($payment_intent->status === 'canceled' || $payment_intent->status === 'payment_failed') {
                // Update to failed status
                global $wpdb;
                $table_name = $wpdb->prefix . 'pfb_submissions';

                $result = $wpdb->update(
                    $table_name,
                    [
                        'payment_status' => 'failed',
                        'updated_at' => current_time('mysql')
                    ],
                    ['payment_intent' => $payment_intent_id],
                    ['%s', '%s'],
                    ['%s']
                );

                if ($result !== false) {
                    error_log("Updated payment status to failed for intent: $payment_intent_id");
                    return true;
                }
            } else {
                error_log("Payment intent $payment_intent_id has status: {$payment_intent->status} - no action needed");
            }

            return false;
        } catch (Exception $e) {
            error_log("Error checking payment status: " . $e->getMessage());
            return false;
        }
    }
}
