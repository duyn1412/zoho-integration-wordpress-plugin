<?php
/**
 * Plugin Name: Zoho Integration
 * Plugin URI: https://wptopd3v.com/zoho-integration
 * Description: Integrate WooCommerce with Zoho Campaigns for automatic newsletter subscriptions. Features OAuth 2.0 authentication, newsletter checkbox in registration form, and auto-subscribe functionality.
 * Version: 1.1.0
 * Author: Wptopd3v
 * Author URI: https://wptopd3v.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zoho-integration
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Network: false
 * Tags: woocommerce, zoho, campaigns, newsletter, email marketing, integration, oauth
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZOHO_INTEGRATION_VERSION', '1.1.0');
define('ZOHO_INTEGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZOHO_INTEGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));

// Zoho API Constants
define('ZOHO_API_BASE_URL', 'https://campaigns.zohocloud.ca/api/v1.1/');
define('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.com/oauth/v2/');
define('ZOHO_ACCOUNTS_URL_CA', 'https://accounts.zoho.ca/oauth/v2/');

class ZohoIntegration {
    
    private static $instance = null;
    
    /**
     * Get singleton instance (avoids duplicate hook registration)
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('woocommerce_created_customer', array($this, 'auto_subscribe_user'), 10, 3);
        add_action('woocommerce_checkout_order_processed', array($this, 'checkout_subscribe_user'), 10, 1);
        add_action('template_redirect', array($this, 'handle_oauth_callback'));
        
        // Add checkbox to registration form
        add_action('woocommerce_register_form', array($this, 'add_registration_checkbox'));
        add_action('woocommerce_created_customer', array($this, 'save_registration_checkbox'), 5, 3);
        
        // Add newsletter checkbox to checkout page
        add_action('woocommerce_checkout_billing', array($this, 'add_checkout_checkbox'));
        add_action('woocommerce_checkout_process', array($this, 'save_checkout_checkbox'));
        
        // CheckoutWC compatibility - add checkbox to additional fields
        add_action('woocommerce_after_checkout_billing_form', array($this, 'add_checkout_checkbox'));
        add_action('woocommerce_checkout_after_customer_details', array($this, 'add_checkout_checkbox'));
        
        // CheckoutWC specific hook
        add_action('cfw_after_customer_info_account_details', array($this, 'add_checkout_checkbox'));
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
    }
    
    public function init() {
        // Load text domain
        load_plugin_textdomain('zoho-integration', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Add AJAX handlers
        add_action('wp_ajax_get_zoho_lists', array($this, 'ajax_get_zoho_lists'));
        
        // Add admin notice for CheckoutWC compatibility
        add_action('admin_notices', array($this, 'checkoutwc_compatibility_notice'));
    }
    
    /**
     * Show CheckoutWC compatibility notice
     */
    public function checkoutwc_compatibility_notice() {
        if ($this->is_checkoutwc_active() && current_user_can('manage_options')) {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'settings_page_zoho-integration') {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>CheckoutWC Detected!</strong> Your newsletter checkbox will be automatically styled to match CheckoutWC\'s design. The checkbox will appear after customer info account details using the <code>cfw_after_customer_info_account_details</code> hook. <strong>Note:</strong> Checkbox only shows for non-logged in users and only new users will be added to Zoho list.</p>';
                echo '</div>';
            }
        }
    }
    
    /**
     * Check if debug logging is enabled
     */
    private function is_debug_enabled() {
        $settings = get_option('zoho_integration_settings', array());
        return isset($settings['enable_debug']) && $settings['enable_debug'];
    }
    
    /**
     * Debug log function
     */
    private function debug_log($message) {
        if ($this->is_debug_enabled()) {
            error_log('[ZOHO_INTEGRATION] ' . $message);
        }
    }
    
    /**
     * AJAX handler to get Zoho lists
     */
    public function ajax_get_zoho_lists() {
        // Check nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'zoho_integration_nonce')) {
            wp_die('Security check failed');
        }
        
        $this->debug_log('AJAX get_zoho_lists called');
        
        // Check if we have valid token
        $token_data = get_option('zoho_token_data', array());
        if (empty($token_data['access_token'])) {
            $this->debug_log('No access token found');
            wp_send_json_error('No access token. Please authorize with Zoho first.');
            return;
        }
        
        $lists = $this->get_zoho_lists();
        
        if ($lists !== false) {
            $this->debug_log('AJAX success: ' . count($lists) . ' lists found');
            wp_send_json_success($lists);
        } else {
            $this->debug_log('AJAX failed: get_zoho_lists returned false');
            wp_send_json_error('Failed to retrieve lists. Check debug log for details.');
        }
    }
    
    /**
     * Handle OAuth callback from Zoho
     */
    public function handle_oauth_callback() {
        // Check if this is our OAuth callback
        if (isset($_GET['code']) && isset($_GET['state']) && $_GET['state'] == 'zoho_oauth') {
            $code = sanitize_text_field($_GET['code']);
            $this->debug_log('OAuth callback received. Code: ' . substr($code, 0, 20) . '...');
            
            $result = $this->handle_authorization_callback($code);
            
            if ($result) {
                $this->debug_log('Authorization successful');
                // Redirect to admin page with success message
                wp_redirect(admin_url('options-general.php?page=zoho-integration&oauth=success'));
                exit;
            } else {
                $this->debug_log('Authorization failed');
                // Redirect to admin page with error message
                wp_redirect(admin_url('options-general.php?page=zoho-integration&oauth=error'));
                exit;
            }
        }
    }
    
    /**
     * Get valid access token (refresh if expired)
     */
    private function get_valid_access_token() {
        $token_data = get_option('zoho_token_data', array());
        
        $this->debug_log('Checking access token validity');
        if (isset($token_data['scope'])) {
            $this->debug_log('Current scope: ' . $token_data['scope']);
        }
        
        if (empty($token_data['access_token'])) {
            $this->debug_log('No access token found');
            return false;
        }
        
        // Check if token is expired (1 hour = 3600 seconds)
        $current_time = time();
        $token_time = isset($token_data['req_time']) ? $token_data['req_time'] : 0;
        
        if (($current_time - $token_time) >= 3600) {
            $this->debug_log('Access token expired, refreshing...');
            return $this->refresh_access_token($token_data['refresh_token']);
        }
        
        return $token_data['access_token'];
    }
    
    /**
     * Refresh access token using refresh token
     */
    private function refresh_access_token($refresh_token) {
        $accounts_url = $this->get_accounts_url() . 'token';
        $zoho_settings = get_option('zoho_integration_settings', array());
        
        $client_id = isset($zoho_settings['client_id']) ? $zoho_settings['client_id'] : '';
        $client_secret = isset($zoho_settings['client_secret']) ? $zoho_settings['client_secret'] : '';
        
        $this->debug_log('Refresh token - Client ID: ' . ($client_id ? substr($client_id, 0, 10) . '...' : 'EMPTY'));
        $this->debug_log('Refresh token URL: ' . $accounts_url);
        
        $refresh_params = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        );
        
        $response = wp_remote_post($accounts_url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => $refresh_params
        ));
        
        if (is_wp_error($response)) {
            $this->debug_log('Refresh token error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code == 200) {
            $token_data = json_decode($response_body, true);
            if (isset($token_data['access_token'])) {
                $token_data['req_time'] = time();
                $token_data['refresh_token'] = $refresh_token; // Keep the refresh token
                update_option('zoho_token_data', $token_data);
                $this->debug_log('Access token refreshed successfully');
                return $token_data['access_token'];
            }
        }
        
        $this->debug_log('Failed to refresh access token: ' . $response_body);
        return false;
    }
    
    /**
     * Auto subscribe user when they register
     */
    public function auto_subscribe_user($customer_id, $new_customer_data, $password_generated) {
        $this->debug_log('auto_subscribe_user called for user ID: ' . $customer_id);
        
        // Check if user opted in for newsletter (from registration form)
        if (isset($_POST['zoho_newsletter_subscription']) && $_POST['zoho_newsletter_subscription'] == '1') {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            $this->debug_log('User opted in for newsletter during registration');
            $this->add_user_to_zoho_list($customer_id);
        } else {
            // Check existing user meta (in case checkbox was saved earlier)
            $newsletter_subscription = get_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            if ($newsletter_subscription) {
                $this->debug_log('User has existing newsletter subscription preference');
                $this->add_user_to_zoho_list($customer_id);
            } else {
                $this->debug_log('User did not opt in for newsletter');
            }
        }
    }
    
    /**
     * Checkout subscribe user - only for new users
     */
    public function checkout_subscribe_user($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $customer_id = $order->get_customer_id();
        if (!$customer_id) return;
        
        $this->debug_log('checkout_subscribe_user called for user ID: ' . $customer_id);
        
        // Check if this is a new user (created during this checkout)
        $user = get_user_by('id', $customer_id);
        if (!$user) return;
        
        // Check if user was created recently (within last 5 minutes)
        $user_created = strtotime($user->user_registered);
        $current_time = time();
        $is_new_user = (($current_time - $user_created) < 300); // 5 minutes
        
        if (!$is_new_user) {
            $this->debug_log('User is not new, skipping Zoho subscription');
            return;
        }
        
        // Check if user opted in for newsletter from checkout checkbox
        $newsletter_subscription = WC()->session->get('zoho_newsletter_subscription');
        if ($newsletter_subscription == '1') {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            $this->debug_log('New user opted in for newsletter during checkout');
            $this->add_user_to_zoho_list($customer_id);
            
            // Clear session data
            WC()->session->set('zoho_newsletter_subscription', null);
        } else {
            $this->debug_log('New user did not opt in for newsletter during checkout');
        }
    }
    
    /**
     * Get Zoho lists from API
     */
    public function get_zoho_lists() {
        $access_token = $this->get_valid_access_token();
        if (!$access_token) {
            $this->debug_log('No valid access token for getting lists');
            return false;
        }
        
        $campaigns_url = $this->get_campaigns_url();
        $this->debug_log('Using campaigns URL: ' . $campaigns_url);
        
        // Use the correct endpoint according to Zoho documentation
        $endpoints_to_try = array(
            '/api/v1.1/getmailinglists' // Correct endpoint to get all mailing lists
        );
        
        $api_url = $campaigns_url . $endpoints_to_try[0];
        $this->debug_log('Trying endpoint: ' . $api_url);
        
        $headers = array(
            'Authorization' => 'Zoho-oauthtoken ' . $access_token,
            'Content-Type' => 'application/x-www-form-urlencoded'
        );
        
        // Try to get all lists without requiring listkey
        $api_params = array(
            'resfmt' => 'JSON'
        );
        
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => http_build_query($api_params),
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            $this->debug_log('Get lists API request failed: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->debug_log('Get lists API response code: ' . $response_code);
        $this->debug_log('Get lists API response body: ' . $response_body);
        
        if ($response_code != 200) {
            $this->debug_log('HTTP error ' . $response_code . '. Trying next endpoint.');
            return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, 1);
        }
        
        $response_data = json_decode($response_body, true);
        
        // Check for getmailinglists format
        if (isset($response_data['list_of_details']) && is_array($response_data['list_of_details'])) {
            $this->debug_log('Lists retrieved successfully from getmailinglists');
            $lists = array();
            foreach ($response_data['list_of_details'] as $list) {
                $lists[] = array(
                    'listkey' => $list['listkey'] ?? '',
                    'listname' => $list['listname'] ?? ''
                );
            }
            return $lists;
        }
        
        // Check for other list formats
        if (isset($response_data['lists']) && is_array($response_data['lists'])) {
            $this->debug_log('Lists retrieved successfully from lists');
            $lists = array();
            foreach ($response_data['lists'] as $list) {
                $lists[] = array(
                    'listkey' => $list['listkey'] ?? $list['id'] ?? '',
                    'listname' => $list['listname'] ?? $list['name'] ?? ''
                );
            }
            return $lists;
        }
        
        // Check for getlistadvanceddetails format (with listkey)
        if (isset($response_data['list_details'])) {
            $this->debug_log('Lists retrieved successfully from getlistadvanceddetails');
            $lists = array();
            if (isset($response_data['list_details']['listname'])) {
                $lists[] = array(
                    'listkey' => isset($response_data['list_details']['listkey']) ? $response_data['list_details']['listkey'] : '',
                    'listname' => $response_data['list_details']['listname']
                );
            }
            return $lists;
        }
        
        // Check for new emailapi/v2/recipients format
        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $this->debug_log('Lists retrieved successfully from emailapi/v2/recipients');
            $lists = array();
            foreach ($response_data['data'] as $list) {
                $lists[] = array(
                    'listkey' => $list['id'],
                    'listname' => $list['name']
                );
            }
            return $lists;
        }
        
        // Check old API format
        if (isset($response_data['code']) && $response_data['code'] == '0') {
            if (isset($response_data['list_of_details'])) {
                $this->debug_log('Lists retrieved successfully from list_of_details');
                return $response_data['list_of_details'];
            } elseif (isset($response_data['lists'])) {
                $this->debug_log('Lists retrieved successfully from lists');
                return $response_data['lists'];
            } elseif (isset($response_data['list_details'])) {
                $this->debug_log('Lists retrieved successfully from list_details');
                return $response_data['list_details'];
            } else {
                $this->debug_log('Success response but no lists found. Response structure: ' . print_r($response_data, true));
                return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, 1);
            }
        }
        
        $this->debug_log('Failed to get lists: ' . (isset($response_data['message']) ? $response_data['message'] : 'Unknown error'));
        return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, 1);
    }
    
    /**
     * Get Zoho Accounts OAuth URL based on domain setting
     */
    private function get_accounts_url() {
        $zoho_settings = get_option('zoho_integration_settings', array());
        $domain = isset($zoho_settings['domain']) ? $zoho_settings['domain'] : 'com';
        
        if ($domain === 'ca') {
            return 'https://accounts.zohocloud.ca/oauth/v2/';
        }
        return 'https://accounts.zoho.com/oauth/v2/';
    }
    
    /**
     * Get Zoho Campaigns base URL based on domain setting
     */
    private function get_campaigns_url() {
        $zoho_settings = get_option('zoho_integration_settings', array());
        $domain = isset($zoho_settings['domain']) ? $zoho_settings['domain'] : 'com';
        
        if ($domain === 'ca') {
            return 'https://campaigns.zohocloud.ca';
        }
        return 'https://campaigns.zoho.com';
    }
    
    /**
     * Get Zoho API base URL based on domain setting
     */
    private function get_api_base_url() {
        return $this->get_campaigns_url() . '/api/v1.1/';
    }
    
    /**
     * Test if a listkey is valid
     */
    private function test_listkey($access_token, $campaigns_url, $listkey) {
        $test_url = $campaigns_url . '/api/v1.1/getlistadvanceddetails';
        
        $headers = array(
            'Authorization' => 'Zoho-oauthtoken ' . $access_token,
            'Content-Type' => 'application/x-www-form-urlencoded'
        );
        
        $test_params = array(
            'listkey' => $listkey,
            'resfmt' => 'JSON'
        );
        
        $response = wp_remote_post($test_url, array(
            'method' => 'POST',
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => http_build_query($test_params),
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            if (isset($response_data['code']) && $response_data['code'] == '0') {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Create a default list if none exists
     */
    private function create_default_list($access_token, $campaigns_url) {
        $this->debug_log('Attempting to create default list');
        
        $create_url = $campaigns_url . '/api/v1.1/createlist';
        
        $headers = array(
            'Authorization' => 'Zoho-oauthtoken ' . $access_token,
            'Content-Type' => 'application/x-www-form-urlencoded'
        );
        
        $create_params = array(
            'listname' => 'WordPress Integration List',
            'resfmt' => 'JSON'
        );
        
        $response = wp_remote_post($create_url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => http_build_query($create_params),
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            $this->debug_log('Create list request failed: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->debug_log('Create list response code: ' . $response_code);
        $this->debug_log('Create list response body: ' . $response_body);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            if (isset($response_data['code']) && $response_data['code'] == '0' && isset($response_data['listkey'])) {
                $this->debug_log('List created successfully with key: ' . $response_data['listkey']);
                return $response_data['listkey'];
            }
        }
        
        $this->debug_log('Failed to create list');
        return false;
    }
    
    /**
     * Try alternative endpoints to get lists
     */
    private function try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, $index) {
        if ($index >= count($endpoints_to_try)) {
            $this->debug_log('All endpoints failed');
            return false;
        }
        
        $api_url = $campaigns_url . $endpoints_to_try[$index];
        $this->debug_log('Trying alternative endpoint: ' . $api_url);
        
        $headers = array(
            'Authorization' => 'Zoho-oauthtoken ' . $access_token,
            'Content-Type' => 'application/x-www-form-urlencoded'
        );
        
        $api_params = array(
            'listkey' => 'default',
            'resfmt' => 'JSON'
        );
        
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => http_build_query($api_params),
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            $this->debug_log('Alternative endpoint request failed: ' . $response->get_error_message());
            return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, $index + 1);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->debug_log('Alternative endpoint response code: ' . $response_code);
        $this->debug_log('Alternative endpoint response body: ' . $response_body);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            if (isset($response_data['code']) && $response_data['code'] == '0') {
                if (isset($response_data['list_of_details'])) {
                    $this->debug_log('Lists retrieved successfully from alternative endpoint');
                    return $response_data['list_of_details'];
                } elseif (isset($response_data['lists'])) {
                    $this->debug_log('Lists retrieved successfully from alternative endpoint');
                    return $response_data['lists'];
                } elseif (isset($response_data['list_details'])) {
                    $this->debug_log('Lists retrieved successfully from alternative endpoint');
                    return $response_data['list_details'];
                }
            }
        }
        
        return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, $index + 1);
    }

    /**
     * Add user to Zoho Campaigns list
     */
    public function add_user_to_zoho_list($user_id) {
        $this->debug_log('add_user_to_zoho_list called for user ID: ' . $user_id);
        
        // Get user data
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            $this->debug_log('User not found with ID: ' . $user_id);
            return false;
        }
        
        // Get valid access token
        $access_token = $this->get_valid_access_token();
        if (!$access_token) {
            $this->debug_log('No valid access token available');
            return false;
        }
        
        // Get Zoho settings
        $zoho_settings = get_option('zoho_integration_settings', array());
        if (empty($zoho_settings['list_key'])) {
            $this->debug_log('List key not configured');
            return false;
        }
        
        // Build URL with parameters as per Zoho documentation (GET request)
        $api_params = array(
            'listkey' => $zoho_settings['list_key'],
            'resfmt' => 'JSON',
            'emailids' => $user->user_email
        );
        
        $api_base_url = $this->get_api_base_url();
        $api_url = $api_base_url . 'addlistsubscribersinbulk?' . http_build_query($api_params);
        
        $this->debug_log('API URL: ' . $api_url);
        $this->debug_log('Access token: ' . substr($access_token, 0, 20) . '...');
        
        $headers = array(
            'Authorization' => 'Zoho-oauthtoken ' . $access_token
        );
        
        // Use GET request as per Zoho documentation
        $response = wp_remote_get($api_url, array(
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            $this->debug_log('WP_Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->debug_log('Response code: ' . $response_code);
        $this->debug_log('Response body: ' . $response_body);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            if (isset($response_data['code']) && $response_data['code'] == '0') {
                $this->debug_log('SUCCESS: User added to Zoho list');
                return true;
            } else {
                $this->debug_log('FAILED: ' . (isset($response_data['message']) ? $response_data['message'] : 'Unknown error'));
                return false;
            }
        } else {
            $this->debug_log('FAILED: HTTP ' . $response_code);
            return false;
        }
    }
    
    /**
     * Get authorization URL for OAuth flow
     */
    public function get_authorization_url() {
        $zoho_settings = get_option('zoho_integration_settings', array());
        $client_id = isset($zoho_settings['client_id']) ? $zoho_settings['client_id'] : '';
        
        $accounts_url = $this->get_accounts_url() . 'auth';
        $redirect_uri = home_url('/zoho-callback');
        
        // Validate client_id
        if (empty($client_id)) {
            $this->debug_log('Client ID is empty');
            return false;
        }
        
        $this->debug_log('Client ID: ' . substr($client_id, 0, 10) . '...');
        $this->debug_log('Redirect URI: ' . $redirect_uri);
        $this->debug_log('Accounts URL: ' . $accounts_url);
        
        $auth_params = array(
            'client_id' => $client_id,
            'response_type' => 'code',
            'scope' => 'ZohoCampaigns.contact.CREATE,ZohoCampaigns.contact.READ',
            'redirect_uri' => $redirect_uri,
            'access_type' => 'offline',
            'state' => 'zoho_oauth'
        );
        
        $auth_url = $accounts_url . '?' . http_build_query($auth_params);
        $this->debug_log('Generated auth URL: ' . $auth_url);
        
        return $auth_url;
    }
    
    /**
     * Handle authorization callback
     */
    public function handle_authorization_callback($code) {
        $zoho_settings = get_option('zoho_integration_settings', array());
        $client_id = isset($zoho_settings['client_id']) ? $zoho_settings['client_id'] : '';
        $client_secret = isset($zoho_settings['client_secret']) ? $zoho_settings['client_secret'] : '';
        
        $accounts_url = $this->get_accounts_url() . 'token';
        $redirect_uri = home_url('/zoho-callback');
        
        $token_params = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri
        );
        
        $this->debug_log('Token request URL: ' . $accounts_url);
        
        $response = wp_remote_post($accounts_url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => $token_params
        ));
        
        if (is_wp_error($response)) {
            $this->debug_log('Token request error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code == 200) {
            $token_data = json_decode($response_body, true);
            if (isset($token_data['access_token'])) {
                $token_data['req_time'] = time();
                update_option('zoho_token_data', $token_data);
                $this->debug_log('Access token saved successfully');
                return true;
            }
        }
        
        $this->debug_log('Failed to get access token: ' . $response_body);
        return false;
    }
    
    /**
     * Add checkbox to WooCommerce registration form
     */
    public function add_registration_checkbox() {
        $settings = get_option('zoho_integration_settings', array());
        
        if (!isset($settings['enable_registration_checkbox']) || !$settings['enable_registration_checkbox']) {
            return;
        }
        
        $checkbox_text = isset($settings['checkbox_text']) ? $settings['checkbox_text'] : 'I consent to receiving marketing emails from us';
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form__label-for-checkbox-inline">
                <input class="woocommerce-form__input woocommerce-form__input-checkbox" type="checkbox" name="zoho_newsletter_subscription" value="1" checked />
                <span><?php echo esc_html($checkbox_text); ?></span>
            </label>
        </p>
        <?php
    }
    
    /**
     * Enqueue frontend styles for CheckoutWC compatibility
     */
    public function enqueue_frontend_styles() {
        if (is_checkout() || is_account_page()) {
            wp_add_inline_style('woocommerce-general', '
                .zoho-newsletter-checkbox {
                    background: #f8f9fa;
                    border: 1px solid #e9ecef;
                    border-radius: 8px;
                    padding: 15px;
                    margin: 15px 0;
                }
                .zoho-newsletter-checkbox label {
                    display: flex !important;
                    align-items: center !important;
                    gap: 8px !important;
                    font-weight: 500 !important;
                    margin: 0 !important;
                    cursor: pointer;
                }
                .zoho-newsletter-checkbox input[type="checkbox"] {
                    margin: 0 !important;
                    transform: scale(1.1);
                }
                .zoho-newsletter-checkbox span {
                    color: #495057;
                    line-height: 1.4;
                }
                /* CheckoutWC specific styles */
                .cfw-checkout .zoho-newsletter-checkbox,
                .zoho-newsletter-checkbox.cfw-compatible {
                    background: rgba(255, 255, 255, 0.9);
                    border: 1px solid #d1d5db;
                    border-radius: 12px;
                    padding: 20px;
                    margin: 20px 0;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
                }
                .cfw-checkout .zoho-newsletter-checkbox label,
                .zoho-newsletter-checkbox.cfw-compatible label {
                    color: #374151;
                    font-size: 14px;
                    line-height: 1.5;
                }
                .cfw-checkout .zoho-newsletter-checkbox input[type="checkbox"],
                .zoho-newsletter-checkbox.cfw-compatible input[type="checkbox"] {
                    transform: scale(1.2);
                    accent-color: #3b82f6;
                }
                /* CheckoutWC dark mode support */
                .cfw-checkout.dark .zoho-newsletter-checkbox,
                .zoho-newsletter-checkbox.cfw-compatible.dark {
                    background: rgba(31, 41, 55, 0.8);
                    border-color: #4b5563;
                }
                .cfw-checkout.dark .zoho-newsletter-checkbox label,
                .zoho-newsletter-checkbox.cfw-compatible.dark label {
                    color: #f3f4f6;
                }
            ');
        }
    }
    
    /**
     * Check if CheckoutWC is active
     */
    private function is_checkoutwc_active() {
        return class_exists('CheckoutWC') || function_exists('cfw_get_checkout_url');
    }
    
    /**
     * Add newsletter subscription checkbox to WooCommerce checkout page
     */
    public function add_checkout_checkbox() {
        $settings = get_option('zoho_integration_settings', array());
        
        if (!isset($settings['enable_checkout_checkbox']) || !$settings['enable_checkout_checkbox']) {
            return;
        }
        
        // Only show checkbox for non-logged in users
        if (is_user_logged_in()) {
            return;
        }
        
        // Prevent duplicate display
        static $checkbox_displayed = false;
        if ($checkbox_displayed) {
            return;
        }
        $checkbox_displayed = true;
        
        $checkbox_text = isset($settings['checkbox_text']) ? $settings['checkbox_text'] : 'I consent to receiving marketing emails from us';
        $is_checkoutwc = $this->is_checkoutwc_active();
        
        // Different styling for CheckoutWC vs standard WooCommerce
        $container_class = $is_checkoutwc ? 'zoho-newsletter-checkbox cfw-compatible' : 'zoho-newsletter-checkbox';
        
        // Check if this is called from CheckoutWC hook
        $current_action = current_action();
        $is_cfw_hook = ($current_action === 'cfw_after_customer_info_account_details');
        
        if ($is_cfw_hook) {
            // CheckoutWC specific styling
            ?>
            <div class="<?php echo esc_attr($container_class); ?>" style="margin: 20px 0;">
                <p class="form-row form-row-wide" style="margin: 0;">
                    <label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form__label-for-checkbox-inline" style="display: flex; align-items: center; gap: 12px; font-weight: 500; margin: 0; cursor: pointer;">
                        <input class="woocommerce-form__input woocommerce-form__input-checkbox" type="checkbox" name="zoho_newsletter_subscription" value="1" checked style="margin: 0; transform: scale(1.2); accent-color: #3b82f6;" />
                        <span style="color: #374151; font-size: 14px; line-height: 1.5;"><?php echo esc_html($checkbox_text); ?></span>
                    </label>
                </p>
            </div>
            <?php
        } else {
            // Standard WooCommerce styling
            ?>
            <div class="<?php echo esc_attr($container_class); ?>" style="margin: 15px 0;">
                <p class="form-row form-row-wide">
                    <label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form__label-for-checkbox-inline" style="display: flex; align-items: center; gap: 8px; font-weight: 500;">
                        <input class="woocommerce-form__input woocommerce-form__input-checkbox" type="checkbox" name="zoho_newsletter_subscription" value="1" checked style="margin: 0;" />
                        <span><?php echo esc_html($checkbox_text); ?></span>
                    </label>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Save registration checkbox value
     */
    public function save_registration_checkbox($customer_id, $new_customer_data, $password_generated) {
        if (isset($_POST['zoho_newsletter_subscription']) && $_POST['zoho_newsletter_subscription'] == '1') {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            $this->debug_log('User ' . $customer_id . ' opted in for newsletter during registration');
        } else {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', false);
            $this->debug_log('User ' . $customer_id . ' opted out for newsletter during registration');
        }
    }
    
    /**
     * Save checkout checkbox value
     */
    public function save_checkout_checkbox() {
        if (isset($_POST['zoho_newsletter_subscription']) && $_POST['zoho_newsletter_subscription'] == '1') {
            // Store in session to be processed after order creation
            WC()->session->set('zoho_newsletter_subscription', '1');
            $this->debug_log('Newsletter subscription checkbox checked during checkout');
        }
    }
    
    /**
     * Test function for manual testing
     */
    public function test_add_user($user_id) {
        $this->debug_log('========== TEST ADD USER ' . $user_id . ' ==========');
        $result = $this->add_user_to_zoho_list($user_id);
        $this->debug_log('Test result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        $this->debug_log('========== TEST COMPLETED ==========');
        return $result;
    }
}

// Initialize the plugin (singleton to avoid duplicate hook registration)
$GLOBALS['zoho_integration'] = ZohoIntegration::get_instance();

// Admin settings page
add_action('admin_menu', 'zoho_integration_admin_menu');
add_action('admin_enqueue_scripts', 'zoho_integration_admin_styles');

function zoho_integration_admin_styles($hook) {
    if ($hook == 'settings_page_zoho-integration') {
        wp_enqueue_style('zoho-integration-admin', ZOHO_INTEGRATION_PLUGIN_URL . 'admin.css', array(), ZOHO_INTEGRATION_VERSION);
    }
}

function zoho_integration_admin_menu() {
    add_options_page(
        'Zoho Integration Settings',
        'Zoho Integration',
        'manage_options',
        'zoho-integration',
        'zoho_integration_admin_page'
    );
}

function zoho_integration_admin_page() {
    // Get the singleton instance
    $zoho = ZohoIntegration::get_instance();
    
    if (isset($_POST['submit'])) {
        // Verify nonce and capability
        if (!isset($_POST['zoho_settings_nonce']) || !wp_verify_nonce($_POST['zoho_settings_nonce'], 'zoho_save_settings') || !current_user_can('manage_options')) {
            wp_die('Security check failed.');
        }
        
        $settings = array(
            'list_key' => sanitize_text_field($_POST['list_key']),
            'domain' => sanitize_text_field($_POST['domain']),
            'client_id' => sanitize_text_field($_POST['client_id']),
            'client_secret' => sanitize_text_field($_POST['client_secret']),
            'enable_registration_checkbox' => isset($_POST['enable_registration_checkbox']) ? 1 : 0,
            'enable_checkout_checkbox' => isset($_POST['enable_checkout_checkbox']) ? 1 : 0,
            'checkbox_text' => sanitize_text_field($_POST['checkbox_text']),
            'enable_debug' => isset($_POST['enable_debug']) ? 1 : 0
        );
        update_option('zoho_integration_settings', $settings);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    if (isset($_POST['authorize'])) {
        // Verify nonce and capability
        if (!isset($_POST['zoho_auth_nonce']) || !wp_verify_nonce($_POST['zoho_auth_nonce'], 'zoho_authorize') || !current_user_can('manage_options')) {
            wp_die('Security check failed.');
        }
        
        $auth_url = $zoho->get_authorization_url();
        
        if ($auth_url === false) {
            echo '<div class="notice notice-error"><p>Please enter Client ID and Client Secret before authorizing.</p></div>';
        } else {
            echo '<script>window.open("' . esc_url($auth_url) . '", "_blank");</script>';
            echo '<div class="notice notice-info"><p>Authorization window opened. Please complete the OAuth flow in the new window.</p></div>';
        }
    }
    
    // Handle OAuth success/error messages
    if (isset($_GET['oauth'])) {
        if ($_GET['oauth'] == 'success') {
            echo '<div class="notice notice-success"><p>Authorization successful! Access token saved.</p></div>';
        } elseif ($_GET['oauth'] == 'error') {
            echo '<div class="notice notice-error"><p>Authorization failed. Please try again.</p></div>';
            
            // Debug information — redact sensitive token data
            echo '<div class="notice notice-info">';
            echo '<h4>Debug Information:</h4>';
            echo '<p><strong>Current Settings:</strong></p>';
            $settings = get_option('zoho_integration_settings', array());
            echo '<ul>';
            echo '<li>Client ID: ' . (isset($settings['client_id']) && $settings['client_id'] ? substr($settings['client_id'], 0, 10) . '...' : 'Not set') . '</li>';
            echo '<li>Client Secret: ' . (isset($settings['client_secret']) && $settings['client_secret'] ? 'Set (hidden)' : 'Not set') . '</li>';
            echo '<li>Domain: ' . esc_html(isset($settings['domain']) ? $settings['domain'] : 'com') . '</li>';
            echo '<li>List Key: ' . (isset($settings['list_key']) && $settings['list_key'] ? esc_html($settings['list_key']) : 'Not set') . '</li>';
            echo '</ul>';
            
            echo '<p><strong>Token Data:</strong></p>';
            $token_data = get_option('zoho_token_data', array());
            if (!empty($token_data)) {
                // Redact sensitive fields
                $safe_data = array();
                foreach ($token_data as $key => $value) {
                    if (in_array($key, array('access_token', 'refresh_token', 'client_secret'))) {
                        $safe_data[$key] = substr($value, 0, 10) . '...(redacted)';
                    } else {
                        $safe_data[$key] = $value;
                    }
                }
                echo '<pre>' . esc_html(print_r($safe_data, true)) . '</pre>';
            } else {
                echo '<p>No token data found.</p>';
            }
            echo '</div>';
        }
    }
    
    $settings = get_option('zoho_integration_settings', array());
    ?>
    <div class="wrap zoho-integration-admin">
        <h1>Zoho Integration Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('zoho_save_settings', 'zoho_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Client ID</th>
                    <td><input type="text" name="client_id" value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Client Secret</th>
                    <td><input type="password" name="client_secret" value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Select List</th>
                    <td>
                        <select id="list_key" name="list_key" class="regular-text">
                            <option value="">-- Select a list --</option>
                            <?php
                            $lists = $zoho->get_zoho_lists();
                            if ($lists && is_array($lists)) {
                                foreach ($lists as $list) {
                                    $selected = (isset($settings['list_key']) && $settings['list_key'] == $list['listkey']) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($list['listkey']) . '" ' . $selected . '>' . esc_html($list['listname']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <button type="button" id="refresh-lists" class="button button-secondary">Refresh Lists</button>
                        <p class="description">Select the Zoho Campaigns list where users will be subscribed.</p>
                        <div id="lists-debug" style="margin-top: 10px; padding: 10px; background: #f1f1f1; border-radius: 3px; display: none;">
                            <h4>Debug Information:</h4>
                            <div id="debug-content"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Domain</th>
                    <td>
                        <select name="domain">
                            <option value="com" <?php selected($settings['domain'] ?? 'com', 'com'); ?>>.com</option>
                            <option value="ca" <?php selected($settings['domain'] ?? 'com', 'ca'); ?>>.ca</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable Registration Checkbox</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_registration_checkbox" value="1" <?php checked($settings['enable_registration_checkbox'] ?? 0, 1); ?> />
                            Add newsletter subscription checkbox to WooCommerce registration form
                        </label>
                        <p class="description">When enabled, users will see a pre-checked checkbox during account creation.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable Checkout Checkbox</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_checkout_checkbox" value="1" <?php checked($settings['enable_checkout_checkbox'] ?? 0, 1); ?> />
                            Add newsletter subscription checkbox to WooCommerce checkout page
                        </label>
                        <p class="description">When enabled, users will see a pre-checked checkbox during checkout process. <strong>Note:</strong> Checkbox only appears for non-logged in users and only new users will be added to Zoho list.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Checkbox Text</th>
                    <td>
                        <input type="text" name="checkbox_text" value="<?php echo esc_attr($settings['checkbox_text'] ?? 'I consent to receiving marketing emails from us'); ?>" class="regular-text" />
                        <p class="description">Text to display next to the newsletter subscription checkbox.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable Debug Logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_debug" value="1" <?php checked($settings['enable_debug'] ?? 0, 1); ?> />
                            Enable debug logging to WordPress debug.log
                        </label>
                        <p class="description">When enabled, detailed debug information will be logged to help troubleshoot issues.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
        
        <h2>OAuth Authorization</h2>
        <form method="post" action="" id="zoho-auth-form">
            <?php wp_nonce_field('zoho_authorize', 'zoho_auth_nonce'); ?>
            <p>Click the button below to authorize with Zoho Campaigns. This will open a new window for OAuth authorization.</p>
            <button type="button" id="zoho-authorize-btn" class="button button-secondary">Authorize with Zoho</button>
        </form>
        
        <h3>Zoho App Configuration</h3>
        <div class="zoho-config-section">
            <h4>1. Redirect URI to add in Zoho App:</h4>
            <div class="redirect-uri-box">
                <input type="text" id="redirect-uri" value="<?php echo esc_url(home_url('/zoho-callback')); ?>" readonly style="width: 100%; padding: 8px; font-family: monospace; background: #f1f1f1;">
                <button type="button" onclick="copyRedirectUri()" class="button button-secondary">Copy Redirect URI</button>
            </div>
            <p><em>Copy this URL and paste it in your Zoho app's "Redirect URI" field.</em></p>
            
            <?php
            // Only show debug info if debug is enabled
            if (isset($settings['enable_debug']) && $settings['enable_debug']) {
            ?>
            <h4>2. Debug Information:</h4>
            <p><strong>Current Domain:</strong> <?php echo home_url(); ?></p>
            <p><strong>Redirect URI:</strong> <?php echo esc_url(home_url('/zoho-callback')); ?></p>
            <p><strong>Debug Logging:</strong> <span style="color: green;">Enabled</span></p>
            <p><strong>Encoded URI:</strong> <?php echo urlencode(home_url('/zoho-callback')); ?></p>
            <?php } ?>
        </div>
        
        <?php
        // Only show debug sections if debug is enabled
        $settings = get_option('zoho_integration_settings', array());
        // Generate the OAuth URL for use by the authorize button (always, not just debug mode)
        $oauth_url = $zoho->get_authorization_url();
        
        if (isset($settings['enable_debug']) && $settings['enable_debug']) {
        ?>
        <h3>Debug Information</h3>
        <?php
        if ($oauth_url) {
            echo '<p><strong>Generated OAuth URL:</strong><br>';
            echo '<a href="' . esc_url($oauth_url) . '" target="_blank">' . esc_html($oauth_url) . '</a></p>';
        } else {
            echo '<p><strong>Error:</strong> Could not generate authorization URL. Check Client ID and Client Secret.</p>';
        }
        ?>
        <?php } else { ?>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #0073aa;">
            <p><strong>Debug Information section is hidden.</strong></p>
            <p>Enable "Debug Logging" in the settings above to view debug information.</p>
        </div>
        <?php } ?>
        
        <script>
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        
        function copyRedirectUri() {
            var redirectUriInput = document.getElementById('redirect-uri');
            redirectUriInput.select();
            redirectUriInput.setSelectionRange(0, 99999); // For mobile devices
            
            try {
                document.execCommand('copy');
                alert('Redirect URI copied to clipboard!');
            } catch (err) {
                // Fallback for modern browsers
                navigator.clipboard.writeText(redirectUriInput.value).then(function() {
                    alert('Redirect URI copied to clipboard!');
                }).catch(function(err) {
                    alert('Please manually copy the Redirect URI from the text field.');
                });
            }
        }
        
        document.getElementById('zoho-authorize-btn').addEventListener('click', function() {
            <?php if ($oauth_url) : ?>
            // Use the pre-generated OAuth URL from PHP
            window.open('<?php echo esc_url($oauth_url); ?>', '_blank');
            <?php else : ?>
            // No OAuth URL available — submit the form to trigger server-side generation
            var form = document.getElementById('zoho-auth-form');
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'authorize';
            input.value = '1';
            form.appendChild(input);
            form.submit();
            <?php endif; ?>
        });
        
        // Refresh lists button
        document.getElementById('refresh-lists').addEventListener('click', function() {
            var button = this;
            var select = document.getElementById('list_key');
            var debugDiv = document.getElementById('lists-debug');
            var debugContent = document.getElementById('debug-content');
            
            button.disabled = true;
            button.textContent = 'Loading...';
            debugDiv.style.display = 'block';
            debugContent.innerHTML = 'Making AJAX request...';
            
            // AJAX request to get lists
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    button.disabled = false;
                    button.textContent = 'Refresh Lists';
                    
                    debugContent.innerHTML = 'Response received:<br>';
                    debugContent.innerHTML += 'Status: ' + xhr.status + '<br>';
                    debugContent.innerHTML += 'Response: ' + xhr.responseText + '<br>';
                    
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            debugContent.innerHTML += 'Parsed response: ' + JSON.stringify(response, null, 2) + '<br>';
                            
                            if (response.success) {
                                // Clear existing options
                                select.innerHTML = '<option value="">-- Select a list --</option>';
                                
                                // Add new options
                                if (response.data && response.data.length > 0) {
                                    response.data.forEach(function(list) {
                                        var option = document.createElement('option');
                                        option.value = list.listkey;
                                        option.textContent = list.listname;
                                        select.appendChild(option);
                                    });
                                    
                                    alert('Lists refreshed successfully! Found ' + response.data.length + ' lists.');
                                } else {
                                    alert('No lists found in response.');
                                }
                            } else {
                                alert('Failed to refresh lists: ' + response.data);
                            }
                        } catch (e) {
                            debugContent.innerHTML += 'JSON Parse Error: ' + e.message + '<br>';
                            alert('Error parsing response: ' + e.message);
                        }
                    } else {
                        alert('Error refreshing lists. Status: ' + xhr.status);
                    }
                }
            };
            
            var postData = 'action=get_zoho_lists&nonce=' + '<?php echo wp_create_nonce('zoho_integration_nonce'); ?>';
            debugContent.innerHTML += 'Sending: ' + postData + '<br>';
            xhr.send(postData);
        });
        </script>
        
        <?php
        // Only show test section if debug is enabled
        if (isset($settings['enable_debug']) && $settings['enable_debug']) {
        ?>
        <div class="test-section">
            <h2>Test Integration</h2>
            <form method="post" action="">
                <?php wp_nonce_field('zoho_test_user', 'zoho_test_nonce'); ?>
                <input type="hidden" name="test_user" value="1" />
                <p>
                    <label>Test User ID: <input type="number" name="test_user_id" value="78805" /></label>
                    <input type="submit" class="button" value="Test Add User" />
                </p>
            </form>
        </div>
        
        <?php
        if (isset($_POST['test_user'])) {
            // Verify nonce and capability
            if (!isset($_POST['zoho_test_nonce']) || !wp_verify_nonce($_POST['zoho_test_nonce'], 'zoho_test_user') || !current_user_can('manage_options')) {
                wp_die('Security check failed.');
            }
            
            $test_user_id = intval($_POST['test_user_id']);
            $result = $zoho->test_add_user($test_user_id);
            echo '<div class="notice notice-info"><p>Test completed. Check debug logs for details.</p></div>';
        }
        ?>
        <?php } else { ?>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #0073aa;">
            <p><strong>Test Integration section is hidden.</strong></p>
            <p>Enable "Debug Logging" in the settings above to access test functionality.</p>
        </div>
        <?php } ?>
    </div>
    <?php
}

// Activation hook
register_activation_hook(__FILE__, 'zoho_integration_activate');

function zoho_integration_activate() {
    // Create default settings
    $default_settings = array(
        'client_id' => '',
        'client_secret' => '',
        'list_key' => '',
        'domain' => 'com'
    );
    add_option('zoho_integration_settings', $default_settings);
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'zoho_integration_deactivate');

function zoho_integration_deactivate() {
    // Clean up if needed
}