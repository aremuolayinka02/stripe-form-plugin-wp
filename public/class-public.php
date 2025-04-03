<?php
class PFB_Public
{
    public function __construct()
    {
        add_shortcode('payment_form', array($this, 'render_form'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }


    /**
     * Render billing and shipping fields
     * 
     * @param int $form_id Form ID
     * @return string HTML for billing and shipping fields
     */
    public function render_billing_shipping_fields($form_id)
    {
        // Get billing and shipping settings
        $enable_billing = get_option('pfb_enable_billing', false);
        $enable_shipping = get_option('pfb_enable_shipping', false);
        $enable_same_as_billing = get_option('pfb_enable_same_as_billing', true);

        if (!$enable_billing) {
            return '';
        }

        // Get saved field layouts
        $billing_layout_option = get_option('pfb_billing_layout', '');
        $billing_layout = !empty($billing_layout_option) ? json_decode($billing_layout_option, true) : [];

        $shipping_layout_option = get_option('pfb_shipping_layout', '');
        $shipping_layout = !empty($shipping_layout_option) ? json_decode($shipping_layout_option, true) : [];

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
            <?php if ($enable_billing): ?>
                <div class="pfb-billing-fields">
                    <h3>Billing Information</h3>
                    <?php foreach ($billing_layout as $row): ?>
                        <div class="pfb-form-row">
                            <?php foreach ($row as $field_id): ?>
                                <?php if (isset($all_billing_fields[$field_id])): ?>
                                    <div class="pfb-form-col">
                                        <div class="pfb-form-field">
                                            <label for="billing_<?php echo esc_attr($field_id); ?>">
                                                <?php echo esc_html($all_billing_fields[$field_id]); ?>
                                                <span class="required">*</span>
                                            </label>
                                            <input type="text" name="billing_<?php echo esc_attr($field_id); ?>" id="billing_<?php echo esc_attr($field_id); ?>" required>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($enable_billing && $enable_shipping): ?>
                <div class="pfb-same-as-billing">
                    <label>
                        <input type="checkbox" name="shipping_same_as_billing" id="shipping_same_as_billing" <?php checked($enable_same_as_billing); ?>>
                        Use my billing address for shipping
                    </label>
                </div>

                <div class="pfb-shipping-fields" <?php echo $enable_same_as_billing ? 'style="display:none;"' : ''; ?>>
                    <h3>Shipping Information</h3>
                    <?php foreach ($shipping_layout as $row): ?>
                        <div class="pfb-form-row">
                            <?php foreach ($row as $field_id): ?>
                                <?php if (isset($all_shipping_fields[$field_id])): ?>
                                    <div class="pfb-form-col">
                                        <div class="pfb-form-field">
                                            <label for="shipping_<?php echo esc_attr($field_id); ?>">
                                                <?php echo esc_html($all_shipping_fields[$field_id]); ?>
                                                <span class="required">*</span>
                                            </label>
                                            <input type="text" name="shipping_<?php echo esc_attr($field_id); ?>" id="shipping_<?php echo esc_attr($field_id); ?>" required>
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
            jQuery(document).ready(function($) {
                // Toggle shipping fields visibility based on "Same as billing" checkbox
                $('#shipping_same_as_billing').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('.pfb-shipping-fields').hide();
                    } else {
                        $('.pfb-shipping-fields').show();
                    }
                });
            });
        </script>
    <?php
        return ob_get_clean();
    }





    /**
     * Get default billing layout
     * 
     * @return array Default billing layout
     */
    private function get_default_billing_layout()
    {
        return [
            [
                'first_name',
                'last_name'
            ],
            [
                'company'
            ],
            [
                'address_1'
            ],
            [
                'address_2'
            ],
            [
                'city',
                'state'
            ],
            [
                'postcode',
                'country'
            ],
            [
                'phone',
                'email'
            ]
        ];
    }

    /**
     * Get default shipping layout
     * 
     * @return array Default shipping layout
     */
    private function get_default_shipping_layout()
    {
        return [
            [
                'first_name',
                'last_name'
            ],
            [
                'company'
            ],
            [
                'address_1'
            ],
            [
                'address_2'
            ],
            [
                'city',
                'state'
            ],
            [
                'postcode',
                'country'
            ],
            [
                'phone'
            ]
        ];
    }

    /**
     * Render an address field
     * 
     * @param string $id Field ID
     * @param string $field Field name
     * @param bool $required Whether the field is required
     * @return string HTML for the field
     */
    private function render_address_field($field_name, $field_id, $required = false)
    {
        $field_label = $this->get_field_label($field_id);
        $output = '';

        $output .= '<div class="pfb-form-field">';
        $output .= '<label for="' . esc_attr($field_name) . '">';
        $output .= esc_html($field_label);
        if ($required) {
            $output .= ' <span class="required">*</span>';
        }
        $output .= '</label>';

        if ($field_id === 'country') {
            $output .= '<select name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . ($required ? ' required' : '') . '>';
            $output .= '<option value="">Select Country</option>';

            $countries = $this->get_countries();
            foreach ($countries as $code => $name) {
                $output .= '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
            }

            $output .= '</select>';
        } elseif ($field_id === 'state') {
            $output .= '<input type="text" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . ($required ? ' required' : '') . ' placeholder="' . esc_attr($field_label) . '">';
        } elseif ($field_id === 'email') {
            $output .= '<input type="email" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . ($required ? ' required' : '') . ' placeholder="' . esc_attr($field_label) . '">';
        } else {
            $output .= '<input type="text" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '"' . ($required ? ' required' : '') . ' placeholder="' . esc_attr($field_label) . '">';
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
    private function get_field_label($field_id)
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

        return isset($labels[$field_id]) ? $labels[$field_id] : ucfirst(str_replace('_', ' ', $field_id));
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
            'country' => 'select'
            // Removed 'state' => 'select' to make it a text field
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
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'company' => 'Company (optional)',
            'address_1' => 'Street Address',
            'address_2' => 'Apt, Suite, Unit, etc. (optional)',
            'city' => 'City',
            'state' => 'State/Province',
            'postcode' => 'Postal/Zip Code',
            'phone' => 'Phone Number',
            'email' => 'Email Address'
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
