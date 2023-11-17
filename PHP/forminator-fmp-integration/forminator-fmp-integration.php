<?php

/**
 * Plugin Name:       Forminator / Claris FileMaker Api Integration
 * Description:       Send Claris FileMaker Data API call for Forminator submissions
 * Version:           1.0.4
 * Author:            Lee Kierstead, Kierstead WordPress Developments
 * Author URI:        https://studio6am.com
 * License:           GPL 3
 */

 if ( ! defined( 'ABSPATH' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
 	die();
 }

  if ( ! class_exists( 'Forminator_FileMaker_Integration' ) ) {

    include_once('admin-email.php');
    include_once('error-log.php');

    class Forminator_FileMaker_Integration {

      // set FileMaker host variables
      private $host = '***'; // your server hostname
      private $dbname = '***'; // your database name
      private $layout = '***'; // your layout name
      private $user = '***'; // your username
      private $pass = '***'; // your password
      private $script = ['script'=>'***']; // your script name as value of 'script' key

      // set local variables

      private $formids=array(***, ***, ***); // add the Forminator Form Id(s) you wish to process, separated by commas
      private $customeremail;
      private $formname;
      private $submissionid;
      private $formid;
      private $autologout = true; // setting this to true will autodestroy the session token and require a new token to be generated on every submission - Use if you run into issues with FMS sessions not deleting after specified timeout.
      private $token;
      private $has_token = false;
      private static $instance = null;
      private $errorlog;

      //self-instantiate object
      public static function get_instance() {

  			if( is_null( self::$instance ) ) {
  				self::$instance = new Forminator_FileMaker_Integration();
  			}

        return self::$instance;

  		}

      // set required Hooks on object instantiation
      private function __construct(){

        add_action('forminator_custom_form_submit_before_set_fields', array($this, 'prepare_forminator_fields'), 10, 3);

      }

      // intercept and prepare all Forminator Data upon submission
      public function prepare_forminator_fields($entry, $form_id, $field_data_array  ) {

        if (!in_array($form_id, $this->formids)){ // if the Forminator form_id does not match the one you define in $formids array exit script
         return;
       }

       $forminator = Forminator_API::get_form($form_id); //dynamically grab the form name from the native Forminator API for FM record build
       $this->formname = $forminator->name;
       $this->submissionid = $entry->entry_id;
       $this->formid = $form_id;

       // prep Forminator field array in key => values in prep for FM jSON
       $new_array_keys = array_column($field_data_array, 'name');
       $new_array_values = array_column($field_data_array, 'value');
       $prepped_fields = array_combine($new_array_keys, $new_array_values);
       $prepped_fields['_forminator_form_entry_id'] = $entry->entry_id; //add form entry id to array
       $this->customeremail = $prepped_fields['email-1']; // assigned for error notification detials
       $this->prep_FM_record($prepped_fields, $form_id); // call FM record prep

      }

      //prepare FM record submission
      private function prep_FM_record($prepped_fields, $form_id){

        // create an array for the form data
        $form_encoded = base64_encode ( json_encode ( $prepped_fields)) ; // encode form data for transfer
        $record['form_title'] = $this->formname;
        $record['form_id'] = $form_id;
        $record['form_data'] = $form_encoded;
        $data['fieldData'] =  $record;
        $json = json_encode (array_merge($data, $this->script));
        $this->get_token(); //get FMS authorization token, either from transient or new call
        if($this->has_token == true){
          $this->initiate_api($json);
          $this->destroy_session();
        }
      }

      private function get_token(){

        //first check if token is set in transient, if not, set it
        $this->token = get_transient('FMS_token');
        if($this->token){
          $this->has_token=true;
        }
        if(!$this->token){
          $this->set_token();
        }


      }

      private function set_token(){

        // get a token from the host
      	$url = 'https://'.$this->host.'/fmi/data/v1/databases/'.rawurlencode($this->dbname).'/sessions';
      	$ch = curl_init();
      	curl_setopt($ch, CURLOPT_URL,$url);
      	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array ( 'Content-Type: application/json', 'Authorization: Basic ' . base64_encode ($this->user . ':' . $this->pass)));
        curl_setopt($ch, CURLOPT_HEADER, 0);
      	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
      	curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,'{}');
      	curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); /// Needs to be set to bypass ssl certificate issue
      	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      	$login_result = curl_exec($ch);
        curl_close ($ch);

      	$login_result = json_decode ($login_result, true);
        // var_dump($login_result);

      	// Check for Error Message
        $routine = 'Token Request';
        $errorCode = $login_result['messages'][0]['code'];
      	$errorMessage = $login_result['messages'][0]['message'];

      	if ($errorCode !== '0' && !is_null($login_result)) {
      		// Login Error

      		$errorResult = 'Login Error: '. $errorMessage. ' (' . $errorCode . ')';
      	  AdminEmail::build_email($routine, $this->submissionid, $this->formid, $this->formname, $this->customeremail, $errorResult);
          ErrorLog::build_log($routine, $this->submissionid, $this->formid, $errorResult);

      	} elseif (is_null($login_result)){
          $errorResult = 'Login Error: Could not connect to FMP Server';
          AdminEmail::build_email($routine, $this->submissionid, $this->formid, $this->formname, $this->customeremail, $errorResult);
          ErrorLog::build_log($routine, $this->submissionid, $this->formid, $errorResult);
        }

        else {
      		$errorResult = '';
      		$this->token = $login_result['response']['token'];
          set_transient( 'FMS_token', $this->token); // save it to a WP transient for reuse
          $this->has_token=true;
      	}

      }

      private function initiate_api($json){

        // create record
      	$url = 'https://'.$this->host.'/fmi/data/v1/databases/'.rawurlencode($this->dbname).'/layouts/'.rawurlencode($this->layout).'/records';
      	$ch = curl_init();
      	curl_setopt($ch, CURLOPT_URL,$url);
      	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
      	curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json","Authorization: Bearer ".$this->token));
      	curl_setopt($ch, CURLOPT_POST, 1);
      	curl_setopt($ch, CURLOPT_POSTFIELDS,	$json);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
      	curl_setopt($ch, CURLOPT_VERBOSE, 1);
      	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); /// Needs to be set to bypass ssl certificate issue
      	$create_result = curl_exec($ch);
      	curl_close ($ch);

      	$create_result = json_decode ($create_result, true);
        // var_dump($create_result);

        $routine = "Submit Record";
        $errorCode = $create_result['messages'][0]['code'];
      	$errorMessage = $create_result['messages'][0]['message'];

        if ($errorCode !='0' && $errorCode !='952'){
          $errorResult = 'Submit Record Error: '. $errorMessage. ' (' . $errorCode . ')';
      	  AdminEmail::build_email($routine, $this->submissionid, $this->formid, $this->formname, $this->customeremail, $errorResult);
          ErrorLog::build_log($routine, $this->submissionid, $this->formid, $errorResult);
        }

        // check if result has invalid token error and handle it
        if ($errorCode == '952'){ // this is the FMS error code for 'Invalid FileMaker Data API token (*)'

          $this->set_token(); // set new token
          $this->initiate_api($json); //resubmit record
        }

      }

      private function destroy_session(){

        // logout if $autologout set to true
        if($this->autologout !== true){
          return;
        }

        $url = 'https://'.$this->host.'/fmi/data/v1/databases/'.rawurlencode($this->dbname).'/sessions/'.$this->token;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); 
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POSTFIELDS,'');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); /// Needs to be set to bypass ssl certificate issue
        $logout_result = curl_exec($ch);
        curl_close ($ch);

        $logout_result = json_decode ($logout_result, true);

        // Check for Error Message
        $routine = 'Session Close';
        $errorCode = $logout_result['messages'][0]['code'];
      	$errorMessage = $logout_result['messages'][0]['message'];

        if ($errorCode !== '0'){
          $errorResult = 'Logout Error: '. $errorMessage. ' (' . $errorCode . ')';
      	  

        }
        delete_transient( 'FMS_token' );
        $this->has_token = false;


      }

    } //end class

    add_action( 'plugins_loaded', array( 'Forminator_FileMaker_Integration', 'get_instance' ), );



  } // if class_exists
