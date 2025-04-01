<?php
class PFB_Admin {
    public function __construct() {
        // Register custom post type
    add_action('init', array($this, 'register_form_post_type'));
    
    // Add meta boxes
    add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
    
    // Save post meta
    add_action('save_post', array($this, 'save_form_meta'));
    
    // Add settings page
    add_action('admin_menu', array($this, 'add_admin_menu'));
    
    // Register plugin settings
    add_action('admin_init', array($this, 'register_settings'));
    
    // Enqueue admin scripts
    add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function register_form_post_type() {
    $labels = array(
        'name'               => 'Payment Forms',
        'singular_name'      => 'Payment Form',
        'menu_name'         => 'Payment Forms',
        'add_new'           => 'Add New Form',
        'add_new_item'      => 'Add New Payment Form',
        'edit_item'         => 'Edit Payment Form',
        'new_item'          => 'New Payment Form',
        'view_item'         => 'View Payment Form',
        'search_items'      => 'Search Payment Forms',
        'not_found'         => 'No payment forms found',
        'not_found_in_trash'=> 'No payment forms found in Trash'
    );

    $args = array(
        'labels'              => $labels,
        'public'              => true,  // Change to true
        'publicly_queryable'  => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'payment-form'),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => 30,
        'menu_icon'          => 'dashicons-money-alt', // Changed icon to be more payment-related
        'supports'           => array('title')
    );

    register_post_type('payment_form', $args);
}

     public function add_meta_boxes() {
        add_meta_box(
            'form_builder',           // Unique ID
            'Form Builder',           // Box title
            array($this, 'render_form_builder'),  // Content callback, must be of type callable
            'payment_form',           // Post type
            'normal',                 // Context
            'high'                    // Priority
        );

        add_meta_box(
            'payment_settings',
            'Payment Settings',
            array($this, 'render_payment_settings'),
            'payment_form',
            'normal',
            'high'
        );

        add_meta_box(
            'shortcode_info',
            'Shortcode',
            array($this, 'render_shortcode_info'),
            'payment_form',
            'side',
            'high'
        );
    }

    public function register_settings() {
        register_setting('pfb_settings', 'pfb_test_mode');
        register_setting('pfb_settings', 'pfb_test_public_key');
        register_setting('pfb_settings', 'pfb_test_secret_key');
        register_setting('pfb_settings', 'pfb_live_public_key');
        register_setting('pfb_settings', 'pfb_live_secret_key');
        register_setting('pfb_settings', 'pfb_webhook_secret');
    }

    public function render_form_builder($post) {
        wp_nonce_field('save_form_builder', 'form_builder_nonce');
        
        $form_fields = get_post_meta($post->ID, '_form_fields', true);
        ?>
        <div class="form-builder-container">
            <div class="field-types">
                <button type="button" class="add-field" data-type="text">Add Text Field</button>
                <button type="button" class="add-field" data-type="email">Add Email Field</button>
                <button type="button" class="add-field" data-type="textarea">Add Textarea</button>
            </div>

            <div class="form-fields-container">
                <?php
                if (is_array($form_fields)) {
                    foreach ($form_fields as $field) {
                        $this->render_field_row($field);
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_field_row($field = array()) {
        ?>
        <div class="field-row">
            <input type="hidden" name="field_type[]" value="<?php echo esc_attr($field['type'] ?? 'text'); ?>">
            <input type="text" name="field_label[]" placeholder="Field Label" 
                   value="<?php echo esc_attr($field['label'] ?? ''); ?>">
            <label>
                <input type="checkbox" name="field_required[]" value="1" 
                       <?php checked(isset($field['required']) && $field['required']); ?>>
                Required
            </label>
            <button type="button" class="remove-field">Remove</button>
        </div>
        <?php
    }

    public function render_payment_settings($post) {
        $amount = get_post_meta($post->ID, '_payment_amount', true);
        $currency = get_post_meta($post->ID, '_payment_currency', true) ?: 'usd';
        ?>
        <div class="payment-settings">
            <p>
                <label>Payment Amount:</label>
                <input type="number" name="payment_amount" 
                       value="<?php echo esc_attr($amount); ?>" 
                       step="0.01" min="0">
            </p>
            <p>
                <label>Currency:</label>
                <select name="payment_currency">
                    <option value="usd" <?php selected($currency, 'usd'); ?>>USD</option>
                    <option value="eur" <?php selected($currency, 'eur'); ?>>EUR</option>
                    <option value="gbp" <?php selected($currency, 'gbp'); ?>>GBP</option>
                </select>
            </p>
        </div>
        <?php
    }

    public function render_shortcode_info($post) {
        ?>
        <div class="shortcode-info">
            <p>Use this shortcode to display the form:</p>
            <code>[payment_form id="<?php echo $post->ID; ?>"]</code>
        </div>
        <?php
    }

    public function save_form_meta($post_id) {
        if (get_post_type($post_id) !== 'payment_form') {
            return;
        }

        if (!isset($_POST['form_builder_nonce']) || 
            !wp_verify_nonce($_POST['form_builder_nonce'], 'save_form_builder')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Save form fields
        $fields = array();
        if (isset($_POST['field_type']) && is_array($_POST['field_type'])) {
            foreach ($_POST['field_type'] as $index => $type) {
                $fields[] = array(
                    'type' => sanitize_text_field($type),
                    'label' => sanitize_text_field($_POST['field_label'][$index] ?? ''),
                    'required' => isset($_POST['field_required'][$index])
                );
            }
        }
        update_post_meta($post_id, '_form_fields', $fields);

        // Save payment settings
        if (isset($_POST['payment_amount'])) {
            update_post_meta($post_id, '_payment_amount', 
                floatval($_POST['payment_amount']));
        }
        if (isset($_POST['payment_currency'])) {
            update_post_meta($post_id, '_payment_currency', 
                sanitize_text_field($_POST['payment_currency']));
        }
    }

   public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h2>Payment Form Builder Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('pfb_settings');
                do_settings_sections('pfb_settings');
                wp_nonce_field('pfb_settings_nonce', 'pfb_settings_nonce');
                ?>
                <table class="form-table">
                    <tr>
                        <th>Test Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="pfb_test_mode" value="1" 
                                    <?php checked(get_option('pfb_test_mode', true)); ?>>
                                Enable Test Mode
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Test Public Key</th>
                        <td>
                            <input type="text" name="pfb_test_public_key" 
                                   value="<?php echo esc_attr(get_option('pfb_test_public_key')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Test Secret Key</th>
                        <td>
                            <input type="password" name="pfb_test_secret_key" 
                                   value="<?php echo esc_attr(get_option('pfb_test_secret_key')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Live Public Key</th>
                        <td>
                            <input type="text" name="pfb_live_public_key" 
                                   value="<?php echo esc_attr(get_option('pfb_live_public_key')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Live Secret Key</th>
                        <td>
                            <input type="password" name="pfb_live_secret_key" 
                                   value="<?php echo esc_attr(get_option('pfb_live_secret_key')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
    <th>Webhook Secret</th>
    <td>
        <input type="password" name="pfb_webhook_secret" 
               value="<?php echo esc_attr(get_option('pfb_webhook_secret')); ?>" 
               class="regular-text">
        <p class="description">Enter your Stripe webhook signing secret</p>
    </td>
</tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=payment_form',
            'Settings',
            'Settings',
            'manage_options',
            'pfb-settings',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_scripts($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        if (get_post_type() !== 'payment_form') {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('pfb-admin', 
            PFB_PLUGIN_URL . 'admin/css/admin.css', 
            array(), 
            PFB_VERSION
        );

        wp_enqueue_script('pfb-admin', 
            PFB_PLUGIN_URL . 'admin/js/admin.js', 
            array('jquery', 'jquery-ui-sortable'), 
            PFB_VERSION, 
            true
        );
    }
}