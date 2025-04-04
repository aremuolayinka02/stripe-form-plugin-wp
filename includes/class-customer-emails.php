<?php
class PFB_Customer_Emails
{

    public function __construct()
    {
        add_action('pfb_payment_completed', array($this, 'send_success_email'), 10, 2);
        add_action('pfb_payment_failed', array($this, 'send_failed_email'), 10, 2);
        add_action('pfb_payment_status_updated', array($this, 'handle_payment_status_update'), 10, 2);
    }


    /**
     * Handle payment status updates and send appropriate emails
     */
    public function handle_payment_status_update($payment_record, $new_status)
    {
        // Get form fields to check for customer email field
        $customer_email = $this->get_customer_email($payment_record);

        if (empty($customer_email)) {
            error_log('No customer email found for payment ID: ' . $payment_record->id);
            return;
        }

        if ($new_status === 'completed') {
            $this->send_success_email($payment_record, $customer_email);
        } elseif ($new_status === 'failed') {
            $this->send_failed_email($payment_record, $customer_email);
        }
    }

    /**
     * Get customer email from form fields
     */
    private function get_customer_email($payment_record)
    {
        global $wpdb;
        $form_fields_table = $wpdb->prefix . 'pfb_form_fields';

        // Get form fields
        $form_fields = $wpdb->get_var($wpdb->prepare(
            "SELECT field_data FROM $form_fields_table WHERE form_id = %d",
            $payment_record->form_id
        ));

        if (empty($form_fields)) {
            return null;
        }

        $fields = json_decode($form_fields, true);
        if (!is_array($fields)) {
            return null;
        }

        // Find the email field marked as customer_email
        $email_field_label = null;
        foreach ($fields as $field) {
            if (
                isset($field['type']) && $field['type'] === 'email'
                && isset($field['customer_email']) && $field['customer_email'] === true
            ) {
                $email_field_label = $field['label'];
                break;
            }
        }

        if (!$email_field_label) {
            return null;
        }

        // Get the submission data
        $submission_data = json_decode($payment_record->submission_data, true);
        if (!is_array($submission_data)) {
            return null;
        }

        // Find the email value in submission data
        foreach ($submission_data as $field_label => $value) {
            if ($field_label === $email_field_label) {
                return sanitize_email($value);
            }
        }

        return null;
    }

    /**
     * Send success email to customer
     */
    private function send_success_email($payment_record, $customer_email)
    {
        $enabled = get_post_meta($payment_record->form_id, '_customer_success_email_enabled', true);
        if (!$enabled) {
            return;
        }

        $subject = get_post_meta($payment_record->form_id, '_customer_success_email_subject', true);
        $template = get_post_meta($payment_record->form_id, '_customer_success_email_template', true);

        if (empty($template)) {
            $template = $this->get_default_success_template();
        }

        $this->send_email($payment_record, $customer_email, $subject, $template);
    }

    /**
     * Send failed payment email to customer
     */
    private function send_failed_email($payment_record, $customer_email)
    {
        $enabled = get_post_meta($payment_record->form_id, '_customer_failed_email_enabled', true);
        if (!$enabled) {
            return;
        }

        $subject = get_post_meta($payment_record->form_id, '_customer_failed_email_subject', true);
        $template = get_post_meta($payment_record->form_id, '_customer_failed_email_template', true);

        if (empty($template)) {
            $template = $this->get_default_failed_template();
        }

        $this->send_email($payment_record, $customer_email, $subject, $template);
    }

    /**
     * Send email with template
     */
    private function send_email($payment_record, $customer_email, $subject, $template)
    {
        // Get customer name and shipping details from submission data
        $submission_data = json_decode($payment_record->submission_data, true);
        $customer_name = '';
        $shipping_details = array();
        $shipping_amount = floatval(get_post_meta($payment_record->form_id, '_shipping_amount', true));

        if (is_array($submission_data)) {
            // Get customer name
            foreach ($submission_data as $field_label => $value) {
                if (stripos($field_label, 'name') !== false) {
                    $customer_name = $value;
                    break;
                }
            }

            // Get shipping details if available
            $shipping_fields = array(
                'shipping_address_1' => 'Address',
                'shipping_address_2' => 'Address 2',
                'shipping_city' => 'City',
                'shipping_state' => 'State',
                'shipping_postcode' => 'Postal Code',
                'shipping_country' => 'Country'
            );

            foreach ($shipping_fields as $field_key => $label) {
                if (isset($submission_data[$field_key])) {
                    $shipping_details[$label] = $submission_data[$field_key];
                }
            }
        }

        // Calculate totals
        $subtotal = floatval($payment_record->amount);
        $shipping_cost = $shipping_amount;
        $total = $subtotal + $shipping_cost;

        // Format shipping address
        $shipping_address = '';
        if (!empty($shipping_details)) {
            $shipping_address = '<h3>Shipping Address:</h3><p>';
            foreach ($shipping_details as $label => $value) {
                if (!empty($value)) {
                    $shipping_address .= $label . ': ' . $value . '<br>';
                }
            }
            $shipping_address .= '</p>';
        }

        // Replace variables in subject and template
        $variables = array(
            '{customer_name}' => $customer_name,
            '{subtotal}' => number_format($subtotal, 2),
            '{shipping_cost}' => number_format($shipping_cost, 2),
            '{total_amount}' => number_format($total, 2),
            '{order_id}' => $payment_record->id,
            '{payment_date}' => date_i18n(get_option('date_format'), strtotime($payment_record->created_at)),
            '{form_title}' => get_the_title($payment_record->form_id),
            '{shipping_address}' => $shipping_address,
            '{currency}' => strtoupper($payment_record->currency)
        );

        $subject = str_replace(array_keys($variables), array_values($variables), $subject);
        $message = str_replace(array_keys($variables), array_values($variables), $template);

        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($customer_email, $subject, $message, $headers);
    }

    /**
     * Get default success email template
     */
    private function get_default_success_template()
    {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                .order-details {
                    margin: 20px 0;
                    padding: 15px;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                }
                .order-summary {
                    margin-top: 20px;
                    border-top: 2px solid #eee;
                    padding-top: 10px;
                }
                .amount-row {
                    display: flex;
                    justify-content: space-between;
                    margin: 5px 0;
                }
                .total-row {
                    font-weight: bold;
                    border-top: 1px solid #ddd;
                    padding-top: 5px;
                    margin-top: 5px;
                }
            </style>
        </head>
        <body>
            <p>Dear {customer_name},</p>
            
            <p>Thank you for your order! Your payment has been successfully processed.</p>
            
            <div class="order-details">
                <h3>Order Details:</h3>
                <p>Order ID: {order_id}</p>
                <p>Date: {payment_date}</p>
                
                {shipping_address}
                
                <div class="order-summary">
                    <h3>Order Summary:</h3>
                    <div class="amount-row">
                        <span>Subtotal:</span>
                        <span>{currency} {subtotal}</span>
                    </div>
                    <div class="amount-row">
                        <span>Shipping:</span>
                        <span>{currency} {shipping_cost}</span>
                    </div>
                    <div class="amount-row total-row">
                        <span>Total:</span>
                        <span>{currency} {total_amount}</span>
                    </div>
                </div>
            </div>
            
            <p>Thank you for your business!</p>
            
            <p>If you have any questions about your order, please contact us.</p>
        </body>
        </html>
        ';
    }

    /**
     * Get default failed email template
     */
    private function get_default_failed_template()
    {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                .order-details {
                    margin: 20px 0;
                    padding: 15px;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                }
                .order-summary {
                    margin-top: 20px;
                    border-top: 2px solid #eee;
                    padding-top: 10px;
                }
                .amount-row {
                    display: flex;
                    justify-content: space-between;
                    margin: 5px 0;
                }
                .total-row {
                    font-weight: bold;
                    border-top: 1px solid #ddd;
                    padding-top: 5px;
                    margin-top: 5px;
                }
            </style>
        </head>
        <body>
            <p>Dear {customer_name},</p>
            
            <p>We\'re sorry, but your payment has failed to process.</p>
            
            <div class="order-details">
                <h3>Order Details:</h3>
                <p>Order ID: {order_id}</p>
                <p>Date: {payment_date}</p>
                
                <div class="order-summary">
                    <h3>Payment Summary:</h3>
                    <div class="amount-row">
                        <span>Subtotal:</span>
                        <span>{currency} {subtotal}</span>
                    </div>
                    <div class="amount-row">
                        <span>Shipping:</span>
                        <span>{currency} {shipping_cost}</span>
                    </div>
                    <div class="amount-row total-row">
                        <span>Total:</span>
                        <span>{currency} {total_amount}</span>
                    </div>
                </div>
            </div>
            
            <p>Please try the following:</p>
            <ul>
                <li>Check your payment details and try again</li>
                <li>Make sure your card has sufficient funds</li>
                <li>Contact your bank if the problem persists</li>
            </ul>
            
            <p>If you need assistance, please don\'t hesitate to contact us.</p>
        </body>
        </html>
        ';
    }
}
