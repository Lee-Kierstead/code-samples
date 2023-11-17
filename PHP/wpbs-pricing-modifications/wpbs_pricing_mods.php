<?php

/**
 * Plugin Name: WPBS Pricing MODS
 * Description: Modifications to WordPerfect Booking System Plugin Pricing to integrate Custom Post Type Meta Data
 * Author: Lee Kierstead, Kierstead WordPress Developments
 * Author URI: https://studio6am.com/about/
 * License: GPLv3
 */



 add_action( 'init', function() {

 	class WPBS_Pricing_Mods {

 		private static $instance;
    private static $debug = FALSE;
    private static $blackout_duration = '2';
    private $post_obj;
    private $allowed_ids = [**, **];  // CPT IDs - TODO create method to dynamically populate this array for scalability
    private $calendar_id;
    private $blackout_legend_id;
    private $available_legend_id;
    private $priced_date_interval_meta;
    private $first_priced_date;
    private $last_priced_date;

 		public function __construct() {

      add_action('wp', [$this, 'set_todays_price'], 10);

      if (self::$debug == TRUE){
        // (is_admin()) ? add_action('admin_notices', [$this,'init_updates'],10) : add_action('template_redirect', [$this,'init_updates'],10);
        add_action('admin_notices', [$this,'init_updates'],10);
      } else {
        add_action('save_post', [$this,'init_updates'],100);
      }

    }

    public function init_updates(){

      global $current_screen;
      $this->post_obj = self::get_post_obj();
      if($this->post_obj == NULL){
        return;
      }

      // dont run on anything other than valid cottage post
      if (!in_array($this->post_obj->ID, $this->allowed_ids) || is_null($this->post_obj->ID) || (is_admin() && $current_screen->base == 'edit' || $this->post_obj->post_type !='cottages')){
        return $this->post_obj->ID;
      }

      // security checks for save_post submit ($debug mode off)
      if(self::$debug != TRUE){
        if(!isset($_POST['_wpnonce']) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_page', $this->post_obj->ID) || !in_array($this->post_obj->ID, $this->allowed_ids) ){
          return $this->post_obj->ID;
        }
      }

      $this->calendar_id = self::get_calendar_id($this->post_obj->ID);
      $this->blackout_legend_id = self::get_blackout_legend_id($this->calendar_id);
      $this->available_legend_id = self::get_available_legend_id($this->calendar_id);
      $this->priced_date_interval_meta = self::get_priced_date_interval_meta($this->post_obj->ID);

      // bail if no meta data for CPT
      if($this->priced_date_interval_meta == NULL){
        return;
      }
      $this->first_priced_date = self::get_first_priced_date($this->priced_date_interval_meta);
      $this->last_priced_date = self::get_last_priced_date($this->priced_date_interval_meta);

      $this->set_wpbs_events();
      $this->delete_wpbs_events();
      // self::debug_dump($this);
    }



    private function set_wpbs_events(){

      //create date object foreach $priced_date_interval_meta
      foreach($this->priced_date_interval_meta as $priced_interval){

        $date_period_object=$this->create_date_period_object($priced_interval['start_date'], $priced_interval['end_date']);

        foreach($date_period_object as $event_date){

          $event_data = $this->prepare_wpbs_event_data($event_date, $this->available_legend_id, $priced_interval['price']);
          $this->process_wpbs_event_data($event_data);

        }
      }
    }

    private function delete_wpbs_events(){
      global $wpdb;
      $results = $wpdb->get_results($wpdb->prepare( "SELECT * FROM wp_a9_wpbs_events WHERE booking_id = %d", 0 ));
        foreach ($results as $event){
          $event_date = new DateTime("$event->date_year-$event->date_month-$event->date_day");
          if($event_date < $this->first_priced_date || $event_date > $this->last_priced_date){
            wpbs_delete_event($event->id);
          }
        }
    }

    private function prepare_wpbs_event_data($event_date, $legend_id, $price){
      $event_data = [
        'calendar_id' => $this->calendar_id,
        'legend_item_id' => $legend_id,
        'date_year' => $event_date->format('Y'),
        'date_month' => $event_date->format('m'),
        'date_day' => $event_date->format('d'),
        'price' => $price,
      ];
      return $event_data;
    }

    private function process_wpbs_event_data($event_data){

      // Check if there's already an event present for the date
      $events = wpbs_get_events(array('calendar_id' => $event_data['calendar_id'], 'date_year' => $event_data['date_year'], 'date_month' => $event_data['date_month'], 'date_day' => $event_data['date_day']));

      $event = (!empty($events) ? $events[0] : null);

      //typecast Protected object for check on booking id
      if(!is_null($event)){
      $check_is_booked=self::getProtectedValue($event, 'booking_id');
        if ($check_is_booked !=0 ){
          return;
        }
      }

      // Decide what to do with the $event_data array based on check (either insert or update DB)
      if (is_null($event)) {
        wpbs_insert_event($event_data);
      } else {
        wpbs_update_event($event->get('id'), $event_data);
      }
    }



    private static function get_priced_date_interval_meta($id){
      $price_meta_array=[];
      $price_meta_grouped_array=[];
      foreach(get_post_meta($id) as $key=>$value){ // grab required meta from standardized indexed AFC field name
        if (strpos($key, "slcr_pricing_") === 0 ) {
          $price_meta_array[$key]=$value[0];
        }
      }
      foreach ($price_meta_array as $key=>$value){ // group price_meta by indexed AFC field name @ position 13 ie slcr_pricing_*
        $price_meta_grouped_array[$key[13]][self::truncate_key($key)] = $value;
      }

      return $price_meta_grouped_array;
    }

    private static function get_first_priced_date($priced_date_interval_meta){ //get first date for retro non booked date clear

      foreach ($priced_date_interval_meta as $interval){
        $startdates[] = $interval['start_date'];
      }
      return new DateTime(min($startdates));
    }

    private static function get_last_priced_date($priced_date_interval_meta){ //get latest date for future-pricing blackout

      foreach ($priced_date_interval_meta as $interval){
        $enddates[] = $interval['end_date'];
      }
      return new DateTime(max($enddates));
    }

    public function set_todays_price(){
      $post_id = self::get_post_obj()->ID;
      $priced_date_interval_meta = self::get_priced_date_interval_meta($post_id);

      if(empty($priced_date_interval_meta)){
        return;
      }

      $today = new DateTime();
      foreach ($priced_date_interval_meta as $date_interval){
        if (($today >= new DateTime($date_interval['start_date'])) && ($today <= new DateTime($date_interval['end_date']))){
          update_post_meta( $post_id, 'slcr_todays_price', $date_interval['price'] );
          wpbs_update_calendar_meta(self::get_calendar_id($post_id), 'default_price', $date_interval['price']);

        }
      }
    }


    // Helper Methods

    // create object with required dates for iteration between start and end
    private function create_date_period_object($start_date, $end_date){
      return new DatePeriod(new DateTime($start_date), DateInterval::createFromDateString('1 day'), new DateTime($end_date . '00:00:01')); // add 1 second to include the end date in the returned date interval object
    }

    private static function get_post_obj(){
      global $post;
      return (is_null($post))? NULL : $post;
    }

    private static function get_calendar_id($id){
      return get_post_meta( $id, 'slcr_wp_booking_system_calendar_id', true );
    }

    private static function get_blackout_legend_id($id){
      global $wpdb;
      $col_name = "Pricing Unavailable";
      return $wpdb->get_row($wpdb->prepare( "SELECT id FROM wp_a9_wpbs_legend_items WHERE calendar_id = %s AND name = %s", $id, $col_name))->id;
    }

    private static function get_available_legend_id($id){
      global $wpdb;
      $col_name = "Available";
      return $wpdb->get_row($wpdb->prepare( "SELECT id FROM wp_a9_wpbs_legend_items WHERE calendar_id = %s AND name = %s", $id, $col_name))->id;
    }

    // Typecast protected object properties into an array to access data
    private static function getProtectedValue( $object, $prop_name ) {
            $array = ( array ) $object;
            $prefix = chr( 0 ) . '*' . chr( 0 );
            return $array[ $prefix . $prop_name ];
        }

    private static function truncate_key($key){
      $key = substr($key, 15);
      return $key;
    }

    private static function debug_dump($var){
      if(self::$debug == TRUE){
        self::kwp_dump($var);
      }
    }

    private static function kwp_dump($var){
			print '<pre>' . print_r($var, true) . '</pre>';
		}

    public static function instantiate() {
      if ( null === self::$instance ) {
        self::$instance = new self();
      }
      return self::$instance;
    }
  

 	} // end class WPBS_Mods

 		WPBS_Pricing_Mods::instantiate();

 }, 10, 1);
