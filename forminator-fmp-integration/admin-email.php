<?php

/*
Package Name: Forminator / Claris FileMaker Api Integration
*/

if ( ! defined( 'ABSPATH' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
  die();
}

class AdminEmail{

  private static $admin_emails = [];

  public static function get_emails(){
   // get all admin emails from WP users
    $users = get_users('role=Administrator');
    foreach ($users as $user) {
      $emails[] = $user->user_email;
    }
    return $emails;
 }

 public static function build_email($routine, $submission_id, $form_id, $formname, $customeremail, $error){
   $domain = get_site_url();
   $datetime=date('m/d/Y h:i:s a', time());
   $body = sprintf("An FMP submission has failed with the following details: \n\n Domain: %s \n Date: %s \n Form ID: %s \n Form Name: %s \n Submission ID: %s \n On Routine: %s \n Customer Email: %s \n Error Details: %s ", $domain, $datetime, $form_id, $formname, $submission_id, $routine, $customeremail, $error);
   self::send_email($body);
 }

 public static function send_email($body){
   self::$admin_emails = self::get_emails();
   foreach (self::$admin_emails as $email){
     wp_mail( $email, 'FMP Error Notice', $body);
   }
   return;
 }

} // end class
