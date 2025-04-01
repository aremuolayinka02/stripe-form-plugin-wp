<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class PFB_Orders_Table extends WP_List_Table {
    
    public function __construct() {
        parent::__construct([
            'singular' => 'order',
            'plural'   => 'orders',
            'ajax'     => true
        ]);
    }
    
    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = [$columns, $hidden, $sortable];
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $mode = isset($_REQUEST['mode']) ? sanitize_text_field($_REQUEST['mode']) : '';
        $status = isset($_REQUEST['status']) ? sanitize_text_field($_REQUEST['status']) : '';
        
        $args = [
            'per_page' => $per_page,
            'offset' => $offset,
            'search' => $search,
            'mode' => $mode,
            'status' => $status,
            'orderby' => isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'created_at',
            'order' => isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'DESC'
        ];
        
        $this->items = $this->get_orders($args);
        
        $total_items = $this->get_orders_count($args);
        
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }
    
    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'id'            => __('ID', 'payment-form-builder'),
            'form'          => __('Form', 'payment-form-builder'),
            'amount'        => __('Amount', 'payment-form-builder'),
            'payment_intent' => __('Transaction ID', 'payment-form-builder'),
            'status'        => __('Status', 'payment-form-builder'),
            'mode'          => __('Mode', 'payment-form-builder'),
            'date'          => __('Date', 'payment-form-builder')
        ];
    }
    
    public function get_sortable_columns() {
        return [
            'id'     => ['id', false],
            'form'   => ['form_id', false],
            'amount' => ['amount', false],
            'status' => ['payment_status', false],
            'date'   => ['created_at', true]
        ];
    }
    
    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return $item->id;
            case 'payment_intent':
                return $item->payment_intent;
            case 'mode':
                return $item->mode ? '<span class="mode-' . esc_attr($item->mode) . '">' . ucfirst($item->mode) . '</span>' : 'Unknown';
            case 'date':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at));
            default:
                return isset($item->$column_name) ? $item->$column_name : '';
        }
    }
    
    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="order_id[]" value="%s" />', $item->id
        );
    }
    
    protected function column_form($item) {
        $form_title = get_the_title($item->form_id);
        return $form_title ? $form_title : 'Form #' . $item->form_id;
    }
    
    protected function column_amount($item) {
        if (!$item->amount) {
            return 'N/A';
        }
        
        return number_format($item->amount, 2) . ' ' . strtoupper($item->currency);
    }
    
    protected function column_status($item) {
        $statuses = [
            'pending'   => '<span class="status-pending">Pending</span>',
            'completed' => '<span class="status-completed">Completed</span>',
            'failed'    => '<span class="status-failed">Failed</span>'
        ];
        
        return isset($statuses[$item->payment_status]) ? $statuses[$item->payment_status] : $item->payment_status;
    }
    
    private function get_orders($args) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pfb_submissions';
        
        $where = '1=1';
        $values = [];
        
        // Search by transaction ID
        if (!empty($args['search'])) {
            $where .= ' AND payment_intent LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        
        // Filter by mode
        if (!empty($args['mode'])) {
            $where .= ' AND mode = %s';
            $values[] = $args['mode'];
        }
        
        // Filter by status
        if (!empty($args['status'])) {
            $where .= ' AND payment_status = %s';
            $values[] = $args['status'];
        }
        
        // Order by
        $orderby = !empty($args['orderby']) ? $args['orderby'] : 'created_at';
        $order = !empty($args['order']) ? $args['order'] : 'DESC';
        
        $limit = '';
        if ($args['per_page'] > 0) {
            $limit = $wpdb->prepare(' LIMIT %d OFFSET %d', $args['per_page'], $args['offset']);
        }
        
        $query = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby $order $limit";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        return $wpdb->get_results($query);
    }
    
    private function get_orders_count($args) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pfb_submissions';
        
        $where = '1=1';
        $values = [];
        
        // Search by transaction ID
        if (!empty($args['search'])) {
            $where .= ' AND payment_intent LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        
        // Filter by mode
        if (!empty($args['mode'])) {
            $where .= ' AND mode = %s';
            $values[] = $args['mode'];
        }
        
        // Filter by status
        if (!empty($args['status'])) {
            $where .= ' AND payment_status = %s';
            $values[] = $args['status'];
        }
        
        $query = "SELECT COUNT(*) FROM $table_name WHERE $where";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        return $wpdb->get_var($query);
    }
}