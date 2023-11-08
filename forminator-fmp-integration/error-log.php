<?php

/*
Package Name: Forminator / Claris FileMaker Api Integration
*/

if ( ! defined( 'ABSPATH' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
  die();
}

class ErrorLog{

 public static $errorlog;


 public static function build_log($routine, $submission_id, $form_id, $error){
   $datetime=date('m/d/Y h:i:s a', time());
   $body = sprintf("Date: %s - Form ID: %s - Submission ID: %s - On Routine: %s - Error Details: %s\n", $datetime, $form_id, $submission_id, $routine, $error);
   self::write_log($body);
 }

 public static function write_log($body){
   $filepath = plugin_dir_path( __FILE__ ) . '/errorlog.txt';
   self::$errorlog = fopen($filepath, "a");
   fwrite(self::$errorlog, $body);
   fclose(self::$errorlog);
   return;
 }

} // end class
