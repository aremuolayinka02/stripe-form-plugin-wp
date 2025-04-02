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

        // Get customer email if specified and if Stripe emails are enabled
        $customer_email = null;
        $enable_stripe_emails = get_option('pfb_enable_stripe_emails', false);

        if ($enable_stripe_emails) {
            $customer_email_field = get_post_meta($form_id, '_customer_email_field', true);

            if (!empty($customer_email_field)) {
                // Find the corresponding label for this field ID
                $form_fields = get_post_meta($form_id, '_form_fields', true);
                if (is_array($form_fields)) {
                    foreach ($form_fields as $field) {
                        $field_id = sanitize_title($field['label']);
                        if ($field_id === $customer_email_field && isset($form_data[$field['label']])) {
                            $customer_email = sanitize_email($form_data[$field['label']]);
                            error_log('Customer email found: ' . $customer_email);
                            break;
                        }
                    }
                }
            }
        }

        try {
            // Create payment intent with customer email
            $payment_intent = $this->stripe->create_payment_intent($amount, $currency, $customer_email);

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

    // Add this method to your PFB_Form_Handler class
    public function send_admin_notification($submission_id, $form_id, $form_data)
    {
        error_log("Starting email notification process for submission ID: $submission_id, form ID: $form_id");


        // Check if admin notification is enabled for this form
        $admin_email_enabled = get_post_meta($form_id, '_admin_email_enabled', true);

        if (!$admin_email_enabled) {
            error_log("Admin email notifications are disabled for form ID: $form_id");
            return false;
        }

        // Get email settings
        $subject = get_post_meta($form_id, '_admin_email_subject', true) ?: 'New payment received';
        $recipients = get_post_meta($form_id, '_admin_email_recipients', true);

        if (empty($recipients)) {
            $recipients = get_option('admin_email');
            error_log("Using default admin email as recipient: $recipients");
        } else {
            error_log("Using custom recipients: $recipients");
        }

        // Get form details
        $form_title = get_the_title($form_id);
        $amount = get_post_meta($form_id, '_payment_amount', true);
        $currency = get_post_meta($form_id, '_payment_currency', true) ?: 'usd';


        error_log("Email details - Form: $form_title, Amount: $amount $currency");

        // Build email content
        $message = "A new payment has been received.\n\n";
        $message .= "Form: " . $form_title . "\n";
        $message .= "Amount: " . $amount . " " . strtoupper($currency) . "\n\n";
        $message .= "Form Data:\n";

        foreach ($form_data as $field => $value) {
            $message .= $field . ": " . $value . "\n";
        }

        $message .= "\n\nView this submission in the WordPress admin: " . admin_url('edit.php?post_type=payment_form&page=pfb-orders');


        error_log("Email message prepared with " . count($form_data) . " form fields");

        // Send email
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        $result = wp_mail($recipients, $subject, $message, $headers);

        if ($result) {
            error_log("Email successfully sent to $recipients");
        } else {
            error_log("Failed to send email to $recipients");

            // Check for WordPress mail errors
            global $phpmailer;
            if (isset($phpmailer) && $phpmailer->ErrorInfo) {
                error_log("PHPMailer error: " . $phpmailer->ErrorInfo);
            }
        }

        return $result;
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
