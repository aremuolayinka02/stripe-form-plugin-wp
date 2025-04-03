<?php
class PFB_Public
{
    public function __construct()
    {
        add_shortcode('payment_form', array($this, 'render_form'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }



    private function get_fresh_settings()
    {
        global $wpdb;

        // Get all relevant options directly from the database
        $options = [
            'pfb_enable_billing' => get_option('pfb_enable_billing'),
            'pfb_enable_shipping' => get_option('pfb_enable_shipping'),
            'pfb_enable_same_as_billing' => get_option('pfb_enable_same_as_billing'),
            'pfb_billing_layout' => get_option('pfb_billing_layout'),
            'pfb_shipping_layout' => get_option('pfb_shipping_layout')
        ];

        error_log('Raw options from database: ' . print_r($options, true));

        // Initialize settings with proper type conversion
        $settings = [
            'enable_billing' => filter_var($options['pfb_enable_billing'], FILTER_VALIDATE_BOOLEAN),
            'enable_shipping' => filter_var($options['pfb_enable_shipping'], FILTER_VALIDATE_BOOLEAN),
            'enable_same_as_billing' => filter_var($options['pfb_enable_same_as_billing'], FILTER_VALIDATE_BOOLEAN),
            'billing_layout' => null,
            'shipping_layout' => null
        ];

        // Try to decode billing layout
        $billing_layout = json_decode($options['pfb_billing_layout'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($billing_layout)) {
            $settings['billing_layout'] = $billing_layout;
        } else {
            error_log('Failed to decode billing layout: ' . json_last_error_msg());
            error_log('Raw billing layout: ' . print_r($options['pfb_billing_layout'], true));
        }

        // Try to decode shipping layout
        $shipping_layout = json_decode($options['pfb_shipping_layout'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($shipping_layout)) {
            $settings['shipping_layout'] = $shipping_layout;
        } else {
            error_log('Failed to decode shipping layout: ' . json_last_error_msg());
            error_log('Raw shipping layout: ' . print_r($options['pfb_shipping_layout'], true));
        }

        // Set default layouts if needed
        if (empty($settings['billing_layout'])) {
            $settings['billing_layout'] = [
                ['first_name', 'last_name'],
                ['company'],
                ['address_1'],
                ['address_2'],
                ['city', 'state'],
                ['postcode', 'country'],
                ['phone'],
                ['email']
            ];
        }

        if (empty($settings['shipping_layout'])) {
            $settings['shipping_layout'] = [
                ['first_name', 'last_name'],
                ['company'],
                ['address_1'],
                ['address_2'],
                ['city', 'state'],
                ['postcode', 'country'],
                ['phone']
            ];
        }

        error_log('Final processed settings: ' . print_r($settings, true));

        return $settings;
    }



    /**
     * Render billing and shipping fields
     * 
     * @param int $form_id Form ID
     * @return string HTML for billing and shipping fields
     */
    public function render_billing_shipping_fields($form_id)
    {
        // Get fresh settings
        $settings = $this->get_fresh_settings();


        error_log('Rendering billing/shipping fields with settings: ' . print_r($settings, true));

        if (!$settings['enable_billing']) {
            return '';
        }

        // All available fields with labels
        $all_billing_fields = [
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

        $all_shipping_fields = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'company' => 'Company',
            'address_1' => 'Address Line 1',
            'address_2' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State/Province',
            'postcode' => 'Postal Code',
            'country' => 'Country',
            'phone' => 'Phone'
        ];

        error_log('Settings: ' . print_r($settings['enable_billing'], true)); // Debugging line

        ob_start();
?>
        <div class="pfb-billing-shipping-container">
            <?php foreach ($settings['billing_layout'] as $row): ?>
                <div class="pfb-form-row">
                    <?php foreach ((array)$row as $field_id): ?>
                        <?php if (isset($all_billing_fields[$field_id])): ?>
                            <div class="pfb-form-col">
                                <div class="pfb-form-field">
                                    <label for="billing_<?php echo esc_attr($field_id); ?>">
                                        <?php echo esc_html($all_billing_fields[$field_id]); ?>
                                        <span class="required">*</span>
                                    </label>
                                    <input type="text"
                                        name="billing_<?php echo esc_attr($field_id); ?>"
                                        id="billing_<?php echo esc_attr($field_id); ?>"
                                        required>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($settings['enable_billing'] && $settings['enable_shipping']): ?>
                <div class="pfb-same-as-billing">
                    <label>
                        <input type="checkbox"
                            name="shipping_same_as_billing"
                            id="shipping_same_as_billing"
                            <?php checked($settings['enable_same_as_billing']); ?>>
                        Use my billing address for shipping
                    </label>
                </div>

                <div class="pfb-shipping-fields" <?php echo $settings['enable_same_as_billing'] ? 'style="display:none;"' : ''; ?>>
                    <h3>Shipping Information</h3>
                    <?php foreach ($settings['shipping_layout'] as $row): ?>
                        <div class="pfb-form-row">
                            <?php foreach ((array)$row as $field_id): ?>
                                <?php if (isset($all_shipping_fields[$field_id])): ?>
                                    <div class="pfb-form-col">
                                        <div class="pfb-form-field">
                                            <label for="shipping_<?php echo esc_attr($field_id); ?>">
                                                <?php echo esc_html($all_shipping_fields[$field_id]); ?>
                                                <span class="required">*</span>
                                            </label>
                                            <input type="text"
                                                name="shipping_<?php echo esc_attr($field_id); ?>"
                                                id="shipping_<?php echo esc_attr($field_id); ?>"
                                                required>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const shippingSameAsBilling = document.getElementById('shipping_same_as_billing');
                const shippingFields = document.querySelector('.pfb-shipping-fields');
                const shippingInputs = shippingFields.querySelectorAll('input[type="text"]');

                function copyBillingToShipping() {
                    shippingInputs.forEach(input => {
                        const fieldName = input.name.replace('shipping_', '');
                        const billingInput = document.querySelector(`input[name="billing_${fieldName}"]`);
                        if (billingInput) {
                            input.value = billingInput.value;
                        }
                    });
                }

                function toggleShippingFields() {
                    if (shippingSameAsBilling.checked) {
                        shippingFields.style.display = 'none';
                        copyBillingToShipping();
                    } else {
                        shippingFields.style.display = 'block';
                        // Clear shipping fields when unchecked
                        shippingInputs.forEach(input => input.value = '');
                    }
                }

                // Initialize on page load
                if (shippingSameAsBilling.checked) {
                    shippingFields.style.display = 'none';
                    copyBillingToShipping();
                }

                // Handle checkbox changes
                shippingSameAsBilling.addEventListener('change', toggleShippingFields);

                // Copy billing to shipping when billing fields change and checkbox is checked
                document.querySelectorAll('input[name^="billing_"]').forEach(input => {
                    input.addEventListener('change', () => {
                        if (shippingSameAsBilling.checked) {
                            copyBillingToShipping();
                        }
                    });
                });

                // Handle form submission
                const form = document.querySelector('.payment-form');
                form.addEventListener('submit', function(event) {
                    if (shippingSameAsBilling.checked) {
                        copyBillingToShipping();
                    }
                });
            });
        </script>
    <?php
        return ob_get_clean();
    }


    public function render_form($atts)
    {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts);

        if (!$atts['id']) return '';

        global $wpdb;
        $form_fields_table = $wpdb->prefix . 'pfb_form_fields';

        // Check if the table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$form_fields_table'") == $form_fields_table;

        if ($table_exists) {
            // Get form fields from the database
            $form_fields_json = $wpdb->get_var($wpdb->prepare(
                "SELECT field_data FROM $form_fields_table WHERE form_id = %d",
                $atts['id']
            ));

            $form_fields = $form_fields_json ? json_decode($form_fields_json, true) : array();
        } else {
            // Fall back to post meta if table doesn't exist
            $form_fields = get_post_meta($atts['id'], '_form_fields', true);
        }

        $amount = get_post_meta($atts['id'], '_payment_amount', true);
        $currency = get_post_meta($atts['id'], '_payment_currency', true);

        ob_start();
    ?>
        <form id="payment-form-<?php echo $atts['id']; ?>" class="payment-form">
            <?php foreach ($form_fields as $field): ?>
                <?php if ($field['type'] === 'two-column'): ?>
                    <!-- Render two-column fields -->
                    <div class="two-column-container">
                        <div class="column">
                            <label>
                                <?php echo esc_html($field['label'][0]); ?>
                                <?php if ($field['required'][0]): ?>
                                    <span class="required">*</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="<?php echo esc_attr($field['label'][0]); ?>"
                                <?php echo $field['required'][0] ? 'required' : ''; ?>>
                        </div>
                        <div class="column">
                            <label>
                                <?php echo esc_html($field['label'][1]); ?>
                                <?php if ($field['required'][1]): ?>
                                    <span class="required">*</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="<?php echo esc_attr($field['label'][1]); ?>"
                                <?php echo $field['required'][1] ? 'required' : ''; ?>>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Render regular fields -->
                    <div class="form-field">
                        <label>
                            <?php echo esc_html($field['label']); ?>
                            <?php if ($field['required']): ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($field['type'] === 'textarea'): ?>
                            <textarea name="<?php echo esc_attr($field['label']); ?>"
                                <?php echo $field['required'] ? 'required' : ''; ?>></textarea>
                        <?php else: ?>
                            <input type="<?php echo esc_attr($field['type']); ?>"
                                name="<?php echo esc_attr($field['label']); ?>"
                                <?php echo $field['required'] ? 'required' : ''; ?>>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            <?php endforeach; ?>



            <?php
            // Add billing and shipping fields here
            echo $this->render_billing_shipping_fields($atts['id']);
            ?>



            <div class="payment-section">
                <div id="card-element"></div>
                <div id="card-errors"></div>
            </div>

            <button type="submit">Pay <?php echo esc_html($amount . ' ' . strtoupper($currency)); ?></button>
        </form>
<?php
        return ob_get_clean();
    }



    public function enqueue_scripts()
    {
        if (!is_singular()) return;

        global $post;
        if (!has_shortcode($post->post_content, 'payment_form')) return;

        wp_enqueue_style(
            'pfb-public',
            PFB_PLUGIN_URL . 'public/css/public.css',
            array(),
            PFB_VERSION
        );

        wp_enqueue_style(
            'payment-form-builder-public',
            plugin_dir_url(__FILE__) . 'css/payment-form-builder-public.css',
            array(),
            $this->version,
            'all'
        );

        $test_mode = get_option('pfb_test_mode', true);
        $public_key = $test_mode
            ? get_option('pfb_test_public_key')
            : get_option('pfb_live_public_key');

        wp_enqueue_script(
            'stripe-js',
            'https://js.stripe.com/v3/',
            array(),
            null
        );



        wp_enqueue_script(
            'pfb-public',
            PFB_PLUGIN_URL . 'public/js/public.js',
            array('jquery', 'stripe-js'),
            PFB_VERSION,
            true
        );

        wp_localize_script('pfb-public', 'pfbData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'publicKey' => $public_key,
            'nonce' => wp_create_nonce('process_payment_form')
        ));
    }
}
