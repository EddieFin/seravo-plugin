<?php
/*
Plugin Name: WP-Palvelu plugin SSL-Client Certificate Login
Version: 1.0
Description: Authenticating with a SSL Client Certificate by using the email address.
Author: Onni Hakala / Seravo Oy
Author URI: http://wp-palvelu.fi
*/

/*
 * Your web server (nginx) needs to add:
 *   - HTTP_X_SSL_CLIENT_VERIFY
 *   - HTTP_X_SSL_CLIENT_S_DN
 * headers in order for this to work.
 *
 * You can disable this plugin by adding this into your theme:
 * add_filter('wpp_do_client_certificate_login', '__return_false');
 *
 * If you want to use this outside of wp-palvelu:
 * define('HTTPS_CLIENT_CERTIFICATE_DOMAIN','your-secured-subdomain.example.com'
 */

class ClientCertificateAuthenticationPlugin {
  private static $_single; // Let's make this a singleton.

  public function __construct() {
    if (isset(self::$_single)) { return; }
    self::$_single       = $this; // Singleton set.

    add_action('init',array($this,'client_certificate_login'));
  }

  /*
   * Checks conditions and logs in with client certs if possible
   */
  public function client_certificate_login() {
    if(apply_filters('wpp_do_client_certificate_login',true)) {

      // Login using earlier generated token
      $this->client_certificate_login_if_possible();

      // Make sure that the endpoint being requested matches a valid endpoint '/wpp-login'.
      if (isset( $_SERVER['REQUEST_URI'] ) && strpos( stripslashes( $_SERVER['REQUEST_URI'] ), '/wpp-login' ) === false ) {
        return;
      }

      // Redirect into secure subdomain for the login
      $secure_domain = $this->client_certificate_secured_domain();
      if ($secure_domain && $_SERVER["HTTP_HOST"] != $secure_domain ) {
        wp_redirect('https://'.$secure_domain.$_SERVER['REQUEST_URI'], 302 );
        exit;
      }

      // If SSL client authentication was successful from nginx login to main site
      if (isset($_SERVER['HTTP_X_SSL_CLIENT_VERIFY']) && $_SERVER['HTTP_X_SSL_CLIENT_VERIFY'] == 'SUCCESS') {
        // Login to site using detauls from certificate
        $this->client_certificate_login_create_token();
      } elseif(!is_user_logged_in()) {
        // If non logged user tries to use /wpp-login without a certificate just return an error
        $error = new WP_Error('certificate_not_provided', __("<strong>ERROR</strong>: You can't use this login without providing a https client certificate."));
        wp_die($error);
      } else {
        // If already logged in user accidentally uses this endpoint
        wp_redirect(get_admin_url(), 302 );
        exit;
      }
    }
  }

  /*
   * Creates temporary token for logging into mainsite
   */
  private function client_certificate_login_create_token(){
    $user = $this->get_remote_user();

    if ($user && !is_wp_error($user)) {

      // Check if this is url of the main site and just login if it is
      if($_SERVER["HTTP_HOST"] == parse_url(get_admin_url())['host']) {
        // log user in using the $user object properties
        wp_set_current_user( $user->ID, $user->user_login );
        wp_set_auth_cookie( $user->ID );
        do_action( 'wp_login', $user->user_login );
      } else {
        // Set hash for token which we can use to authenticate in other domain
        $random_hash = bin2hex(openssl_random_pseudo_bytes(16));

        // Save already authenticated user in random hash which we can then use in different domain
        // Set low expiration date so that it minimizes the damage when accidentally giving away the link
        set_transient($random_hash,$user->ID,20);
        wp_redirect(get_admin_url()."?wpp-cert-login={$random_hash}", 302 );
        exit;
      }
    }
  }

  /*
   * Uses temporary token to login if possible
   */
  private function client_certificate_login_if_possible(){
    if (is_ssl() && isset($_GET['wpp-cert-login'])) {
      $transient = $_GET['wpp-cert-login'];
      $user_id = get_transient($transient);
      delete_transient($transient);
      $user = get_user_by( 'id', $user_id ); 
      if ( $user ) {
        // log user in using the $user object properties
        wp_set_current_user( $user->ID, $user->user_login );
        wp_set_auth_cookie( $user->ID );
        do_action( 'wp_login', $user->user_login );
      }
      wp_redirect(get_admin_url('/'), 302 );
      exit;
    }
  }


  /*
   * We have setted up secure subdomain for each application which uses client certificates automatically
   * It is in '$name'-secure-login.wp-palvelu.fi
   */
  private function client_certificate_secured_domain(){
    if(defined('HTTPS_DOMAIN_ALIAS')){
      $domain_array = explode('.',HTTPS_DOMAIN_ALIAS);
      $domain_array[0] = $domain_array[0].'-secure-login';
      $secure_domain = implode('.',$domain_array);
      return $secure_domain;
    }elseif(defined('HTTPS_CLIENT_CERTIFICATE_DOMAIN')) {
      return HTTPS_CLIENT_CERTIFICATE_DOMAIN;
    }
    return false;
  }

  /*
   * Rest of functions are helpers
   */

  /*
   * If the SSL_CLIENT_S_DN evironment variable is set, use it
   * as the login. This assumes that you have externally authenticated the user.
   */
  private function get_remote_user(){
    // SSL-verification is done externally
    $parsed_dn_lines = explode('/', $_SERVER['HTTP_X_SSL_CLIENT_S_DN']);
    $dn_values = array();
    foreach ($parsed_dn_lines as $line) {
      // check if its in form 'cn=Your Name'
      $row = explode('=', $line);
      if (count($row) === 2) {
        // skip empty or error containing lines
        $dn_values[$row[0]] = $row[1];
      }
    }

    // Fail if certificate details are wrong
    if(! isset($dn_values['emailAddress'])) {
      $error = new WP_Error('certificate_error', __("<strong>ERROR</strong>: Certificate authentication was successful but it doesn't provide 'emailAddress'. <pre>{$_SERVER['HTTP_X_SSL_CLIENT_S_DN']}</pre>"));
      wp_die($error);
    }

    // Use email from certificate
    $email = $dn_values['emailAddress'];

    // Get certificate user
    $user = get_user_by('email', $email);

    // Fail if user doesn't exist
    if ($user == false || ! $user->exists()) {

      // Create the user if it is @seravo.fi admin user
      if (strpos($email, '@seravo.fi') !== false) {
        $user = $this->create_user($email);
      } else {
        $error = new WP_Error('user_not_found', __("<strong>ERROR</strong>: Your SSL-certificate provided user: <b>{$dn_values['emailAddress']}</b> which was not found in database."));
        wp_die($error);
      }
    }
    return $user;
  }

  /*
   * Create a new WordPress account for the specified email.
   */
  private function create_user($email) {
    // Create random password
    $password = wp_generate_password();

    // Explode the username part before '@seravo.fi'
    $original = $username = reset(explode('@', $email));

    // Loop through different usernames until finds a available one
    $i = 1;
    while(true) {
      $user = get_user_by('login', $username);

      if(!$user) { break; } // break if finds available one

      $username = "{$original}{++$i}";
    }

    // Create new user
    $user_id = wp_create_user($username, $password, $email);
    // Take only firstname of email (in case it is first.last@seravo.fi)
    $firstname = ucfirst(reset(explode('.', $username)));
    $lastname = __("Seravo Ylläpito");
    wp_update_user( array( 'ID' => $user_id, 'role' => 'administrator', 'last_name' => $lastname, 'first_name' => $firstname ) );
    $user = get_user_by('id', $user_id);

    return $user;
  }
}

// Load the plugin hooks, etc.
$client_certificate_authentication_plugin = new ClientCertificateAuthenticationPlugin();
?>