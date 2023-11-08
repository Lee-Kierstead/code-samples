
/*
* Plugin Name:   Forminator API Integration with Brevo (formally Sendinblue)
* Description:   Send contact information from Forminator to Brevo Contacts
* Version:       1.0
* Author:        Lee Kierstead, Kierstead WordPress Developments
* Author URI:    https://studio6am.com
*/


function submit_forminator_data_sendinblue($entry, $form_id, $field_data_array){

// ** right now this will action on any forminator form submitted.  You could add a conditional to check for a specific form id and return if no match ie -
// $target_form_id = 1234; //whatever the form id is
// if ($target_form_id != $form_id){ return;} // if the form_id does not match the one you define as $target_form_id, nothing will happen

// convert forminator array to key => values in prep for sendinblue array
  $new_array_keys = array_column($field_data_array, 'name');
  $new_array_values = array_column($field_data_array, 'value');
  $new_array = array_combine($new_array_keys, $new_array_values);

// create Sendinblue Json variables - these will be dependent on the fields in your form
  $sibfirstname=$new_array['name-1'];
  $siblastname=$new_array['name-2'];
  $sibemail=$new_array["email-1"];
  $sibphone=$new_array["phone-1"];
  $sibstreet=$new_array["address-1"]["street_address"];
  $sibcity=$new_array["address-1"]["city"];
  $sibpostal=$new_array["address-1"]["zip"];
  $sibip=$new_array["_forminator_user_ip"];
  $sibcoupon=$new_array["hidden-2"];
  $siboptin=$new_array["gdprcheckbox-1"];
  $siblistid=3;  //make sure this matches your sendinblue list id

// *********** Initialize and Submit to BRevo Via API *****************
// ***** modify the CURLOPT_POSTFILEDS to match your Brevo Contact Attribute fields

$curl = curl_init();
curl_setopt_array($curl, array(
  CURLOPT_URL => "https://api.sendinblue.com/v3/contacts",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS => "{\"listIds\":[$siblistid],\"email\":\"$sibemail\",\"attributes\":{\"FIRSTNAME\":\"$sibfirstname\", \"LASTNAME\":\"$siblastname\", \"PHONE\":\"$sibphone\", \"STREET\":\"$sibstreet\", \"IP\":\"$sibip\", \"CITY\":\"$sibcity\", \"POSTAL_CODE\":\"$sibpostal\", \"CONSENT\":\"$siboptin\", \"COUPON\":\"$sibcoupon\"}}",
  CURLOPT_HTTPHEADER => array(
    "accept: application/json",
    "api-key: xkeysib-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX", /// add your own API key
    "content-type: application/json"
  ),
));

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

// Debug API submission to SendinBlue
// if ($err) {
// echo "cURL Error #:" . $err;
// } else {
// echo $response. '<br />';
// }


}

add_action('forminator_custom_form_submit_before_set_fields', 'submit_forminator_data_sendinblue', 10, 3);
