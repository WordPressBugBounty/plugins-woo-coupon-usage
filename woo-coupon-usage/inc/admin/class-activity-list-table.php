<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class for Activity/Visits List Table
 *
 */
if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}
class wcusage_activity_List_Table extends WP_List_Table {

    function __construct(){
        global $status, $page;

        //Set parent defaults
        parent::__construct( array(
            'singular'  => 'activity',
            'plural'    => 'click',
            'ajax'      => false
        ) );

    }

    function column_default($item, $column_name){

		$options = get_option( 'wcusage_options' );

      switch($column_name){
        default:
            return $item[$column_name];
        case 'id':
            return $item[$column_name];
        case 'event':
            $event_message = wcusage_activity_message($item[$column_name], $item['event_id'], $item['info']);
            return $event_message;
        case 'user_id':
            $user = get_userdata( $item[$column_name] );
            if($user) {
                return '<a href="'.get_edit_user_link($item[$column_name]).'" title="'.$user->user_login.'" target="_blank">'.$user->first_name.' '.$user->last_name.'</a>';
            } else {
                return 'Guest';
            }
        case 'date':
            $date = date_i18n( 'F j, Y (H:i)', strtotime($item[$column_name]) );
            return $date;
      }

    }

    function column_title($item){

        //Build row actions
        $actions = array();

        //Return the title contents
        return sprintf('%1$s <span style="color:silver">(id:%2$s)</span>%3$s',
            /*$1%s*/ $item['title'],
            /*$2%s*/ $item['ID'],
            /*$3%s*/ $this->row_actions($actions)
        );
    }

    function column_cb($item){
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            /*$1%s*/ $this->_args['singular'],
            /*$2%s*/ $item['ID']
        );
    }

    function get_columns(){

        $columns = array(
            //'cb'        => '<input type="checkbox" />', //Render a checkbox instead of text
            'id'     => esc_html__( 'ID', 'woo-coupon-usage' ),
      			'date'  => esc_html__( 'Date', 'woo-coupon-usage' ),
						'user_id'  => esc_html__( 'User', 'woo-coupon-usage' ),
						'event'  => esc_html__( 'Event', 'woo-coupon-usage' ),
        );
        return $columns;

    }

    function get_sortable_columns() {
      $sortable_columns = array(
			'date'  => array('date',false),
        );
        return $sortable_columns;
    }

    function prepare_items() {

        global $wpdb; //This is used only if making any database queries

        $per_page = 20;

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->process_bulk_action();

        $table_name = $wpdb->prefix . 'wcusage_activity';

        if (isset($_GET['status'])) {
            $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE status = %s ORDER BY id DESC", sanitize_text_field($_GET['status']));
        } else {
            $sql = "SELECT * FROM $table_name ORDER BY id DESC";
        }
        $data = $wpdb->get_results($sql, ARRAY_A);        

        $current_page = $this->get_pagenum();

        $total_items = count($data);

        $data = array_slice($data,(($current_page-1)*$per_page),$per_page);

        $this->items = $data;

        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
        ) );

    }

}
?>
