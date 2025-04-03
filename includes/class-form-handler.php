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

        // Extract billing and shipping data
        $billing_data = [];
        $shipping_data = [];
        $shipping_same_as_billing = false;

        foreach ($form_data as $key => $value) {
            if (strpos($key, 'billing_') === 0) {
                $field = str_replace('billing_', '', $key);
                $billing_data[$field] = sanitize_text_field($value);
            } elseif (strpos($key, 'shipping_') === 0) {
                $field = str_replace('shipping_', '', $key);
                $shipping_data[$field] = sanitize_text_field($value);
            } elseif ($key === 'shipping_same_as_billing') {
                $shipping_same_as_billing = ($value === '1');
            }
        }

        // If shipping is same as billing, copy billing data to shipping
        if ($shipping_same_as_billing) {
            foreach ($billing_data as $field => $value) {
                $shipping_data[$field] = $value;
            }
        }

        // Add billing and shipping data to the form data
        $form_data['_billing'] = $billing_data;
        $form_data['_shipping'] = $shipping_data;
        $form_data['_shipping_same_as_billing'] = $shipping_same_as_billing;

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

            // If no customer email was found but we have billing email, use that
            if (empty($customer_email) && !empty($billing_data['email'])) {
                $customer_email = sanitize_email($billing_data['email']);
                error_log('Using billing email as customer email: ' . $customer_email);
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

    /**
     * Render billing and shipping fields
     * 
     * @param int $form_id The form ID
     * @return string HTML for billing and shipping fields
     */
    public function render_billing_shipping_fields($form_id)
    {
        $enable_billing = get_option('pfb_enable_billing', false);

        if (!$enable_billing) {
            return '';
        }

        $enable_shipping = get_option('pfb_enable_shipping', false);
        $enable_same_as_billing = get_option('pfb_enable_same_as_billing', true);
        $billing_fields = get_option('pfb_billing_fields', []);
        $shipping_fields = get_option('pfb_shipping_fields', []);

        if (empty($billing_fields)) {
            $billing_fields = [
                'first_name',
                'last_name',
                'address_1',
                'city',
                'state',
                'postcode',
                'country',
                'email'
            ];
        } else {
            $billing_fields = explode(',', $billing_fields);
        }

        if (empty($shipping_fields)) {
            $shipping_fields = [
                'first_name',
                'last_name',
                'address_1',
                'city',
                'state',
                'postcode',
                'country'
            ];
        } else {
            $shipping_fields = explode(',', $shipping_fields);
        }

        $output = '<div class="pfb-billing-shipping-container">';

        // Billing Fields
        $output .= '<div class="pfb-billing-fields">';
        $output .= '<h3>Billing Information</h3>';

        foreach ($billing_fields as $field) {
            $required = in_array($field, ['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'email']);
            $output .= $this->render_address_field('billing_' . $field, $field, $required);
        }

        $output .= '</div>';

        // Shipping Fields
        if ($enable_shipping) {
            $output .= '<div class="pfb-shipping-fields">';
            $output .= '<h3>Shipping Information</h3>';

            if ($enable_same_as_billing) {
                $output .= '<div class="pfb-same-as-billing">';
                $output .= '<label>';
                $output .= '<input type="checkbox" name="shipping_same_as_billing" id="shipping-same-as-billing" value="1" checked>';
                $output .= ' Same as billing address';
                $output .= '</label>';
                $output .= '</div>';
            }

            $output .= '<div id="pfb-shipping-fields-container" style="display: none;">';

            foreach ($shipping_fields as $field) {
                $required = in_array($field, ['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country']);
                $output .= $this->render_address_field('shipping_' . $field, $field, $required);
            }

            $output .= '</div>';
            $output .= '</div>';
        }

        $output .= '</div>';

        // Add JavaScript for toggling shipping fields
        $output .= '<script>
        jQuery(document).ready(function($) {
            $("#shipping-same-as-billing").on("change", function() {
                if ($(this).is(":checked")) {
                    $("#pfb-shipping-fields-container").hide();
                } else {
                    $("#pfb-shipping-fields-container").show();
                }
            });
        });
    </script>';

        return $output;
    }

    /**
     * Render an address field
     * 
     * @param string $id Field ID
     * @param string $field Field name
     * @param bool $required Whether the field is required
     * @return string HTML for the field
     */
    private function render_address_field($id, $field, $required = false)
    {
        $label = $this->get_field_label($field);
        $type = $this->get_field_type($field);
        $placeholder = $this->get_field_placeholder($field);

        $output = '<div class="pfb-form-field pfb-address-field pfb-' . esc_attr($field) . '">';
        $output .= '<label for="' . esc_attr($id) . '">' . esc_html($label);

        if ($required) {
            $output .= ' <span class="required">*</span>';
        }

        $output .= '</label>';

        if ($type === 'select') {
            $output .= $this->render_select_field($id, $field, $required);
        } else {
            $output .= '<input type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" placeholder="' . esc_attr($placeholder) . '"';

            if ($required) {
                $output .= ' required';
            }

            $output .= '>';
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Render a select field for country or state
     * 
     * @param string $id Field ID
     * @param string $field Field name
     * @param bool $required Whether the field is required
     * @return string HTML for the select field
     */
    private function render_select_field($id, $field, $required = false)
    {
        $output = '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '"';

        if ($required) {
            $output .= ' required';
        }

        $output .= '>';

        if ($field === 'country') {
            $output .= $this->get_country_options();
        } elseif ($field === 'state') {
            $output .= '<option value="">Select State/Province</option>';
            // States will be populated via JavaScript based on country selection
        }

        $output .= '</select>';

        return $output;
    }

    /**
     * Get country options for select field
     * 
     * @return string HTML options for countries
     */
    private function get_country_options()
    {
        $countries = $this->get_countries();
        $output = '<option value="">Select Country</option>';

        foreach ($countries as $code => $name) {
            $output .= '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
        }

        return $output;
    }

    /**
     * Get field label
     * 
     * @param string $field Field name
     * @return string Field label
     */
    private function get_field_label($field)
    {
        $labels = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'company' => 'Company',
            'address_1' => 'Address',
            'address_2' => 'Apartment, suite, etc.',
            'city' => 'City',
            'state' => 'State/Province',
            'postcode' => 'Postal Code',
            'country' => 'Country',
            'phone' => 'Phone',
            'email' => 'Email'
        ];

        return isset($labels[$field]) ? $labels[$field] : ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Get field type
     * 
     * @param string $field Field name
     * @return string Field type
     */
    private function get_field_type($field)
    {
        $types = [
            'email' => 'email',
            'phone' => 'tel',
            'country' => 'select',
            'state' => 'select'
        ];

        return isset($types[$field]) ? $types[$field] : 'text';
    }

    /**
     * Get field placeholder
     * 
     * @param string $field Field name
     * @return string Field placeholder
     */
    private function get_field_placeholder($field)
    {
        $placeholders = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company' => 'Company (optional)',
            'address_1' => '123 Main St',
            'address_2' => 'Apt, Suite, Unit, etc. (optional)',
            'city' => 'New York',
            'postcode' => '10001',
            'phone' => '(555) 555-5555',
            'email' => 'email@example.com'
        ];

        return isset($placeholders[$field]) ? $placeholders[$field] : '';
    }

    /**
     * Get countries list
     * 
     * @return array Countries
     */
    private function get_countries()
    {
        return [
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'JP' => 'Japan',
            'CN' => 'China',
            // Add more countries as needed
        ];
    }
}
