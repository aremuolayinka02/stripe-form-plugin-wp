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

        // Get billing fields
        $billing_fields_option = get_option('pfb_billing_fields', '');
        $billing_fields = !empty($billing_fields_option) ? explode(',', $billing_fields_option) : [
            'first_name',
            'last_name',
            'address_1',
            'city',
            'state',
            'postcode',
            'country',
            'email'
        ];

        // Get shipping fields
        $shipping_fields_option = get_option('pfb_shipping_fields', '');
        $shipping_fields = !empty($shipping_fields_option) ? explode(',', $shipping_fields_option) : [
            'first_name',
            'last_name',
            'address_1',
            'city',
            'state',
            'postcode',
            'country'
        ];

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
