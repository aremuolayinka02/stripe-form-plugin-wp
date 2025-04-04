<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('PFB_Public')):


    class PFB_Public
    {
        public function __construct()
        {
            $this->version = PFB_VERSION;
            add_shortcode('payment_form', array($this, 'render_form'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        }


        private function get_option_without_cache($option_name)
        {
            global $wpdb;

            // Fetch the option directly from the database
            $option_value = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                    $option_name
                )
            );

            // If the option exists, unserialize it (WordPress stores serialized data for arrays/objects)
            if (!is_null($option_value)) {
                return maybe_unserialize($option_value);
            }

            // Return false if the option does not exist
            return false;
        }



        private function get_fresh_settings()
        {
            // Get settings directly from database without cache
            $settings = array(
                'enable_billing' => $this->get_option_without_cache('pfb_enable_billing', true),
                'enable_shipping' => $this->get_option_without_cache('pfb_enable_shipping', false),
                'enable_same_as_billing' => $this->get_option_without_cache('pfb_enable_same_as_billing', true),
                'billing_layout' => json_decode($this->get_option_without_cache('pfb_billing_layout', ''), true),
                'shipping_layout' => json_decode($this->get_option_without_cache('pfb_shipping_layout', ''), true)
            );

            // If billing layout is empty or invalid, use default
            if (empty($settings['billing_layout']) || !is_array($settings['billing_layout'])) {
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

            // If shipping layout is empty or invalid, use default
            if (empty($settings['shipping_layout']) || !is_array($settings['shipping_layout'])) {
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

            // Ensure boolean values
            $settings['enable_billing'] = filter_var($settings['enable_billing'], FILTER_VALIDATE_BOOLEAN);
            $settings['enable_shipping'] = filter_var($settings['enable_shipping'], FILTER_VALIDATE_BOOLEAN);
            $settings['enable_same_as_billing'] = filter_var($settings['enable_same_as_billing'], FILTER_VALIDATE_BOOLEAN);

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
                                class="pfb-same-as-billing-subtext"
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


        private function format_currency($amount, $currency) {
            $symbols = [
                'usd' => '$',
                'eur' => '€',
                'gbp' => '£',
                'jpy' => '¥',
                // Add more currencies as needed
            ];
            
            $currency = strtolower($currency);
            $symbol = isset($symbols[$currency]) ? $symbols[$currency] : $currency . ' ';
            
            return $symbol . number_format($amount, 2);
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

            $amount = floatval(get_post_meta($atts['id'], '_payment_amount', true));
            $currency = get_post_meta($atts['id'], '_payment_currency', true);
            $shipping_amount = floatval(get_post_meta($atts['id'], '_shipping_amount', true));

            ob_start();
        ?>
            <div class="payment-form-container">
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
                        <div class="pfb-form-field">
                            <label>Card Information <span class="required">*</span></label>
                            <div id="card-element"></div>
                        </div>

                        <div id="card-errors"></div>
                    </div>


                    <div class="payment-summary">
                        <div class="summary-item delivery-cost">
                            <strong>Delivery Cost</strong>
                            <span><?php echo esc_html($this->format_currency($shipping_amount, $currency)); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>Total + Delivery Cost</strong>
                            <span><?php echo esc_html($this->format_currency($amount + $shipping_amount, $currency)); ?></span>
                        </div>
                    </div>

                    <button type="submit">Pay <?php echo esc_html($this->format_currency($amount + $shipping_amount, $currency)); ?></button>
                </form>
            </div>
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

            // Get and add global CSS
            global $wpdb;
            $global_css = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                    'pfb_global_css'
                )
            );

            if (!empty($global_css)) {
                // Wrap the CSS in the form container selector
                $wrapped_css = ".payment-form-container {
            " . wp_strip_all_tags($global_css) . "
        }";

                error_log('Adding global CSS: ' . $wrapped_css);

                // Add the CSS inline
                wp_add_inline_style('payment-form-builder-public', $wrapped_css);
            }

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

endif;
