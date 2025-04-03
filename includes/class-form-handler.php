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

                add_action('wp_ajax_save_payment_order', array($this, 'save_payment_order'));
                add_action('wp_ajax_nopriv_save_payment_order', array($this, 'save_payment_order'));
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

    private function validate_billing_fields($form_data)
    {
        $errors = [];

        // Get the actual billing layout from options
        $billing_layout_option = get_option('pfb_billing_layout', '');
        $billing_layout = !empty($billing_layout_option) ? json_decode($billing_layout_option, true) : [];

        // Create a flat array of all fields in the layout
        $active_fields = [];
        foreach ($billing_layout as $row) {
            foreach ($row as $field) {
                $active_fields[] = $field;
            }
        }

        // Only validate fields that are in the layout
        foreach ($active_fields as $field) {
            $field_key = 'billing_' . $field;
            if (empty($form_data[$field_key])) {
                $label = $this->get_field_label($field);
                $errors[] = "Billing $label is required.";
            }

            // Special validation for email
            if ($field === 'email' && !empty($form_data[$field_key]) && !is_email($form_data[$field_key])) {
                $errors[] = "Invalid billing email address.";
            }
        }

        return $errors;
    }

    private function validate_shipping_fields($form_data)
    {
        $errors = [];

        // First check if shipping is enabled in settings
        $enable_shipping = get_option('pfb_enable_shipping', false);
        if (!$enable_shipping) {
            return $errors;
        }

        // Check if "Use my billing address for shipping" is selected
        $shipping_same_as_billing = isset($form_data['shipping_same_as_billing']) &&
            ($form_data['shipping_same_as_billing'] === '1' || $form_data['shipping_same_as_billing'] === 'on');

        if ($shipping_same_as_billing) {
            // Copy billing address to shipping fields
            $billing_fields = [
                'first_name',
                'last_name',
                'company',
                'address_1',
                'address_2',
                'city',
                'state',
                'postcode',
                'country',
                'phone'
            ];

            foreach ($billing_fields as $field) {
                $billing_key = 'billing_' . $field;
                $shipping_key = 'shipping_' . $field;
                if (isset($form_data[$billing_key])) {
                    $form_data[$shipping_key] = $form_data[$billing_key];
                }
            }

            return $errors; // No validation needed as we copied billing details
        }

        // Get shipping layout from options
        $shipping_layout_option = get_option('pfb_shipping_layout', '');
        $shipping_layout = !empty($shipping_layout_option) ? json_decode($shipping_layout_option, true) : [];

        // Create a flat array of required fields from the layout
        $required_fields = [];
        foreach ($shipping_layout as $row) {
            foreach ($row as $field_id) {
                // Skip optional fields like company, address_2, phone
                if (!in_array($field_id, ['company', 'address_2', 'phone'])) {
                    $required_fields['shipping_' . $field_id] = ucfirst(str_replace('_', ' ', $field_id));
                }
            }
        }

        // If no shipping layout is defined, use default required fields
        if (empty($required_fields)) {
            $required_fields = [
                'shipping_first_name' => 'First Name',
                'shipping_last_name' => 'Last Name',
                'shipping_address_1' => 'Address Line 1',
                'shipping_city' => 'City',
                'shipping_state' => 'State',
                'shipping_postcode' => 'Postal Code',
                'shipping_country' => 'Country'
            ];
        }

        // Validate required fields
        foreach ($required_fields as $field => $label) {
            if (!isset($form_data[$field]) || trim($form_data[$field]) === '') {
                $errors[] = "Shipping $label is required.";
            }
        }

        // Additional validation for specific fields
        if (isset($form_data['shipping_postcode']) && !empty($form_data['shipping_postcode'])) {
            // Add postal code format validation if needed
            // Example: if (!preg_match('/^\d{5}(-\d{4})?$/', $form_data['shipping_postcode'])) {
            //     $errors[] = "Invalid shipping postal code format.";
            // }
        }

        if (isset($form_data['shipping_country']) && !empty($form_data['shipping_country'])) {
            // Add country code validation if needed
            // Example: if (!in_array($form_data['shipping_country'], $allowed_countries)) {
            //     $errors[] = "Invalid shipping country selected.";
            // }
        }

        return $errors;
    }

    private function get_field_label($field)
    {
        $labels = [
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

        return isset($labels[$field]) ? $labels[$field] : ucfirst(str_replace('_', ' ', $field));
    }

    private function validate_form_data($form_data)
    {
        $errors = [];

        // Check if billing is enabled
        $enable_billing = get_option('pfb_enable_billing', false);
        if ($enable_billing) {
            $billing_errors = $this->validate_billing_fields($form_data);
            if (!empty($billing_errors)) {
                $errors = array_merge($errors, $billing_errors);
            }

            // Check if shipping is enabled
            $enable_shipping = get_option('pfb_enable_shipping', false);
            if ($enable_shipping) {
                // Always validate shipping fields since we've already copied billing data to shipping
                // when shipping_same_as_billing is true
                $shipping_errors = $this->validate_shipping_fields($form_data);
                if (!empty($shipping_errors)) {
                    $errors = array_merge($errors, $shipping_errors);
                }
            }
        }

        return $errors;
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

        error_log('Raw form data received: ' . print_r($form_data, true));

        // Check shipping settings
        $enable_shipping = get_option('pfb_enable_shipping', false);
        $enable_same_as_billing = get_option('pfb_enable_same_as_billing', true);

        // Normalize shipping_same_as_billing value
        $shipping_same_as_billing = isset($form_data['shipping_same_as_billing']) &&
            ($form_data['shipping_same_as_billing'] === '1' ||
                $form_data['shipping_same_as_billing'] === 'on' ||
                $form_data['shipping_same_as_billing'] === true);

        // Handle shipping fields
        if ($enable_shipping) {
            if ($shipping_same_as_billing) {
                // Define fields to copy from billing to shipping
                $fields_to_copy = [
                    'first_name',
                    'last_name',
                    'company',
                    'address_1',
                    'address_2',
                    'city',
                    'state',
                    'postcode',
                    'country',
                    'phone'
                ];

                // Copy billing values to shipping fields
                foreach ($fields_to_copy as $field) {
                    $billing_key = 'billing_' . $field;
                    $shipping_key = 'shipping_' . $field;
                    if (isset($form_data[$billing_key])) {
                        $form_data[$shipping_key] = $form_data[$billing_key];
                    }
                }
            }
        }

        // Validate form data
        $validation_errors = $this->validate_form_data($form_data);
        if (!empty($validation_errors)) {
            error_log('Form validation errors: ' . print_r($validation_errors, true));
            wp_send_json_error([
                'message' => 'Please check the form for errors.',
                'errors' => $validation_errors
            ]);
            return;
        }

        // Extract and sanitize billing data
        $billing_data = [];
        foreach ($form_data as $key => $value) {
            if (strpos($key, 'billing_') === 0) {
                $field = str_replace('billing_', '', $key);
                $billing_data[$field] = sanitize_text_field($value);
            }
        }

        // Extract and sanitize shipping data
        $shipping_data = [];
        if ($enable_shipping) {
            foreach ($form_data as $key => $value) {
                if (strpos($key, 'shipping_') === 0 && $key !== 'shipping_same_as_billing') {
                    $field = str_replace('shipping_', '', $key);
                    $shipping_data[$field] = sanitize_text_field($value);
                }
            }
        }

        // Add metadata to form data
        $form_data['_billing'] = $billing_data;
        $form_data['_shipping'] = $shipping_data;
        $form_data['_shipping_same_as_billing'] = $shipping_same_as_billing;
        $form_data['_enable_shipping'] = $enable_shipping;

        error_log('Processed billing data: ' . print_r($billing_data, true));
        error_log('Processed shipping data: ' . print_r($shipping_data, true));

        // Get payment details
        $amount = floatval(get_post_meta($form_id, '_payment_amount', true));
        $currency = get_post_meta($form_id, '_payment_currency', true) ?: 'usd';

        if (!$amount) {
            error_log('Invalid amount');
            wp_send_json_error('Invalid payment amount');
            return;
        }

        // Get customer email
        $customer_email = $this->get_customer_email($form_id, $form_data, $billing_data);

        try {
            // Create payment intent with customer email
            $payment_intent = $this->stripe->create_payment_intent($amount, $currency, $customer_email);

            if (is_wp_error($payment_intent)) {
                error_log('Stripe error: ' . $payment_intent->get_error_message());
                wp_send_json_error($payment_intent->get_error_message());
                return;
            }

            // Store form submission WITH payment intent ID
            // $submission_id = $this->store_submission($form_id, $form_data, $payment_intent->id);
            // error_log('Stored submission with ID: ' . $submission_id . ' and payment intent: ' . $payment_intent->id);

            wp_send_json_success(array(
                'client_secret' => $payment_intent->client_secret,
                'submission_id' => $submission_id
            ));
        } catch (Exception $e) {
            error_log('Payment processing error: ' . $e->getMessage());
            wp_send_json_error('Payment processing failed: ' . $e->getMessage());
        }
    }

    public function save_payment_order()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'process_payment_form')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Get data
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        $form_data = isset($_POST['form_data']) ? json_decode(stripslashes($_POST['form_data']), true) : array();
        $payment_intent_id = isset($_POST['payment_intent_id']) ? sanitize_text_field($_POST['payment_intent_id']) : '';
        $payment_status = isset($_POST['payment_status']) ? sanitize_text_field($_POST['payment_status']) : '';

        if (!$form_id || empty($form_data) || empty($payment_intent_id) || $payment_status !== 'succeeded') {
            wp_send_json_error('Invalid order data');
            return;
        }

        try {
            // Store the order
            $submission_id = $this->store_submission($form_id, $form_data, $payment_intent_id);

            if (!$submission_id) {
                throw new Exception('Failed to save order');
            }

            wp_send_json_success(array(
                'submission_id' => $submission_id
            ));
        } catch (Exception $e) {
            wp_send_json_error('Failed to save order: ' . $e->getMessage());
        }
    }

    private function get_customer_email($form_id, $form_data, $billing_data)
    {
        $customer_email = null;
        $enable_stripe_emails = get_option('pfb_enable_stripe_emails', false);

        if ($enable_stripe_emails) {
            // First try to get email from customer email field setting
            $customer_email_field = get_post_meta($form_id, '_customer_email_field', true);
            if (!empty($customer_email_field)) {
                $form_fields = get_post_meta($form_id, '_form_fields', true);
                if (is_array($form_fields)) {
                    foreach ($form_fields as $field) {
                        $field_id = sanitize_title($field['label']);
                        if ($field_id === $customer_email_field && isset($form_data[$field['label']])) {
                            $customer_email = sanitize_email($form_data[$field['label']]);
                            break;
                        }
                    }
                }
            }

            // If no customer email was found, try billing email
            if (empty($customer_email) && !empty($billing_data['email'])) {
                $customer_email = sanitize_email($billing_data['email']);
            }
        }

        return $customer_email;
    }


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
        $message .= "Fields submitted\n\n:";

        foreach ($form_data as $field => $value) {
            $message .= $field . ": " . $value . "\n";
        }

        // Add billing and shipping information to the email
        if (!empty($submission_data['billing'])) {
            $message .= "\n\nBilling Information:\n";
            $message .= "------------------------\n";

            foreach ($submission_data['billing'] as $field => $value) {
                $label = $this->get_field_label($field);
                $message .= "$label: $value\n";
            }
        }

        if (!empty($submission_data['shipping'])) {
            $message .= "\n\nShipping Information:\n";
            $message .= "------------------------\n";

            if (isset($submission_data['shipping_same_as_billing']) && $submission_data['shipping_same_as_billing']) {
                $message .= "Same as billing address\n";
            } else {
                foreach ($submission_data['shipping'] as $field => $value) {
                    $label = $this->get_field_label($field);
                    $message .= "$label: $value\n";
                }
            }
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

    private function store_submission($form_id, $form_data, $payment_intent_id = null, $payment_status = 'pending')
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
            'payment_status' => $payment_status, // Use the provided payment status
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
