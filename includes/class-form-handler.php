<?php
class PFB_Form_Handler
{
    private $stripe;
    private $initialized = false; // Explicitly declare the property

    public function __construct()
    {
        try {
            $this->stripe = new PFB_Stripe();

            if ($this->stripe->is_ready()) {
                $this->initialized = true;
                add_action('wp_ajax_process_payment_form', array($this, 'process_form'));
                add_action('wp_ajax_nopriv_process_payment_form', array($this, 'process_form'));
            } else {
                add_action('admin_notices', array($this, 'display_stripe_errors'));
            }
        } catch (Exception $e) {
            error_log('Payment Form Builder Form Handler initialization error: ' . $e->getMessage());
        }
    }

    public function display_stripe_errors()
    {
        if ($this->stripe) {
            $errors = $this->stripe->get_errors();
            foreach ($errors as $error) {
                echo '<div class="error"><p>Payment Form Builder: ' . esc_html($error) . '</p></div>';
            }
        }
    }

    public function process_form()
    {
        if (!$this->initialized) {
            wp_send_json_error('Payment system not properly configured');
            return;
        }

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'process_payment_form')) {
            error_log('Nonce verification failed');
            wp_send_json_error('Invalid security token');
            return;
        }

        // Verify form ID
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        if (!$form_id) {
            error_log('Invalid form ID');
            wp_send_json_error('Invalid form ID');
            return;
        }

        // Get form data
        $form_data = isset($_POST['form_data']) ? json_decode(stripslashes($_POST['form_data']), true) : array();
        if (empty($form_data)) {
            error_log('Empty form data');
            wp_send_json_error('Form data is required');
            return;
        }

        error_log('Form data received: ' . print_r($form_data, true));

        // Get payment details
        $amount = floatval(get_post_meta($form_id, '_payment_amount', true));
        $currency = get_post_meta($form_id, '_payment_currency', true) ?: 'usd';

        if (!$amount) {
            error_log('Invalid amount');
            wp_send_json_error('Invalid payment amount');
            return;
        }

        try {
            // Create payment intent
            $payment_intent = $this->stripe->create_payment_intent($amount, $currency);

            if (is_wp_error($payment_intent)) {
                error_log('Stripe error: ' . $payment_intent->get_error_message());
                wp_send_json_error($payment_intent->get_error_message());
                return;
            }

            // Store form submission WITH payment intent ID
            $submission_id = $this->store_submission($form_id, $form_data, $payment_intent->id);
            error_log('Stored submission with ID: ' . $submission_id . ' and payment intent: ' . $payment_intent->id);

            wp_send_json_success(array(
                'client_secret' => $payment_intent->client_secret,
                'submission_id' => $submission_id
            ));
        } catch (Exception $e) {
            error_log('Payment processing error: ' . $e->getMessage());
            wp_send_json_error('Payment processing failed: ' . $e->getMessage());
        }
    }

    private function store_submission($form_id, $form_data, $payment_intent_id = null)
    {
        global $wpdb;

        // Get the current mode (test or live)
        $test_mode = get_option('pfb_test_mode', true);
        $mode = $test_mode ? 'test' : 'live';

        // Get payment details
        $amount = floatval(get_post_meta($form_id, '_payment_amount', true));
        $currency = get_post_meta($form_id, '_payment_currency', true) ?: 'usd';

        $data = array(
            'form_id' => $form_id,
            'submission_data' => json_encode($form_data),
            'payment_status' => 'pending',
            'mode' => $mode,
            'amount' => $amount,
            'currency' => $currency,
            'created_at' => current_time('mysql')
        );

        $format = array('%d', '%s', '%s', '%s', '%f', '%s', '%s');

        // Add payment intent if available
        if ($payment_intent_id) {
            $data['payment_intent'] = $payment_intent_id;
            $format[] = '%s';

            error_log('Adding payment intent to submission: ' . $payment_intent_id);
        }

        $wpdb->insert(
            $wpdb->prefix . 'pfb_submissions',
            $data,
            $format
        );

        return $wpdb->insert_id;
    }
}
