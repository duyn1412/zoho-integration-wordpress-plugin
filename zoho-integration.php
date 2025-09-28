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
    
    private $client_id = '1000.8A8C5537F06D2AA'; // Replace with your actual client ID
    private $client_secret = 'your_client_secret_here'; // Replace with your actual client secret
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('woocommerce_created_customer', array($this, 'auto_subscribe_user'), 10, 3);
        add_action('woocommerce_checkout_order_processed', array($this, 'checkout_subscribe_user'), 10, 1);
        add_action('template_redirect', array($this, 'handle_oauth_callback'));
        
        // Add checkbox to registration form
        add_action('woocommerce_register_form', array($this, 'add_registration_checkbox'));
        add_action('woocommerce_created_customer', array($this, 'save_registration_checkbox'), 5, 3);
    }
    
    public function init() {
        // Load text domain
        load_plugin_textdomain('zoho-integration', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Add AJAX handlers
        add_action('wp_ajax_get_zoho_lists', array($this, 'ajax_get_zoho_lists'));
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
            error_log('[ZOHO_INTEGRATION] OAuth callback received. Code: ' . substr($code, 0, 20) . '...');
            error_log('[ZOHO_INTEGRATION] State: ' . $_GET['state']);
            
            $result = $this->handle_authorization_callback($code);
            
            if ($result) {
                error_log('[ZOHO_INTEGRATION] Authorization successful');
                // Redirect to admin page with success message
                wp_redirect(admin_url('options-general.php?page=zoho-integration&oauth=success'));
                exit;
            } else {
                error_log('[ZOHO_INTEGRATION] Authorization failed');
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
        
        error_log('[ZOHO_INTEGRATION] Current token data: ' . print_r($token_data, true));
        if (isset($token_data['scope'])) {
            error_log('[ZOHO_INTEGRATION] Current scope: ' . $token_data['scope']);
        }
        
        if (empty($token_data['access_token'])) {
            $this->debug_log('No access token found');
            return false;
        }
        
        // Check if token is expired (1 hour = 3600 seconds)
        $current_time = time();
        $token_time = isset($token_data['req_time']) ? $token_data['req_time'] : 0;
        
        if (($current_time - $token_time) >= 3600) {
            error_log('[ZOHO_INTEGRATION] Access token expired, refreshing...');
            return $this->refresh_access_token($token_data['refresh_token']);
        }
        
        return $token_data['access_token'];
    }
    
    /**
     * Refresh access token using refresh token
     */
    private function refresh_access_token($refresh_token) {
        $zoho_settings = get_option('zoho_integration_settings', array());
        $domain = isset($zoho_settings['domain']) ? $zoho_settings['domain'] : 'com';
        
        // Use correct Zoho Accounts URL format based on web search
        $accounts_url = 'https://accounts.zohocloud.ca/oauth/v2/token';
        if ($domain == 'ca') {
            $accounts_url = 'https://accounts.zohocloud.ca/oauth/v2/token';
        }
        
        $client_id = isset($zoho_settings['client_id']) ? $zoho_settings['client_id'] : '';
        $client_secret = isset($zoho_settings['client_secret']) ? $zoho_settings['client_secret'] : '';
        
        error_log('[ZOHO_INTEGRATION] Refresh token - Client ID: ' . ($client_id ? substr($client_id, 0, 10) . '...' : 'EMPTY'));
        error_log('[ZOHO_INTEGRATION] Refresh token - Client Secret: ' . ($client_secret ? substr($client_secret, 0, 10) . '...' : 'EMPTY'));
        
        $refresh_params = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        );
        
        error_log('[ZOHO_INTEGRATION] Refresh token URL: ' . $accounts_url);
        
        $response = wp_remote_post($accounts_url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => $refresh_params
        ));
        
        if (is_wp_error($response)) {
            error_log('[ZOHO_INTEGRATION] Refresh token error: ' . $response->get_error_message());
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
                error_log('[ZOHO_INTEGRATION] Access token refreshed successfully');
                return $token_data['access_token'];
            }
        }
        
        error_log('[ZOHO_INTEGRATION] Failed to refresh access token: ' . $response_body);
        return false;
    }
    
    /**
     * Auto subscribe user when they register
     */
    public function auto_subscribe_user($customer_id, $new_customer_data, $password_generated) {
        error_log('[ZOHO_INTEGRATION] auto_subscribe_user called for user ID: ' . $customer_id);
        
        // Check if user opted in for newsletter (from registration form)
        if (isset($_POST['zoho_newsletter_subscription']) && $_POST['zoho_newsletter_subscription'] == '1') {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            error_log('[ZOHO_INTEGRATION] User opted in for newsletter during registration');
            $this->add_user_to_zoho_list($customer_id);
        } else {
            // Check existing user meta (in case checkbox was saved earlier)
            $newsletter_subscription = get_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            if ($newsletter_subscription) {
                error_log('[ZOHO_INTEGRATION] User has existing newsletter subscription preference');
                $this->add_user_to_zoho_list($customer_id);
            } else {
                error_log('[ZOHO_INTEGRATION] User did not opt in for newsletter');
            }
        }
    }
    
    /**
     * Checkout subscribe user
     */
    public function checkout_subscribe_user($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $customer_id = $order->get_customer_id();
        if (!$customer_id) return;
        
        error_log('[ZOHO_INTEGRATION] checkout_subscribe_user called for user ID: ' . $customer_id);
        
        // Check if user opted in for newsletter
        if (isset($_POST['zc_optin_checkbox']) && $_POST['zc_optin_checkbox'] == 'on') {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            $this->add_user_to_zoho_list($customer_id);
        }
    }
    
    /**
     * Get Zoho lists from API
     */
    public function get_zoho_lists() {
        $access_token = $this->get_valid_access_token();
        if (!$access_token) {
            error_log('[ZOHO_INTEGRATION] No valid access token for getting lists');
            return false;
        }
        
        $zoho_settings = get_option('zoho_integration_settings', array());
        $domain = isset($zoho_settings['domain']) ? $zoho_settings['domain'] : 'com';
        
        // Use correct Zoho Campaigns URL format - force Canada data center
        $campaigns_url = 'https://campaigns.zohocloud.ca';
        error_log('[ZOHO_INTEGRATION] Using Canada data center: ' . $campaigns_url);
        
        // Use the correct endpoint according to Zoho documentation
        $endpoints_to_try = array(
            '/api/v1.1/getmailinglists' // Correct endpoint to get all mailing lists
        );
        
        $api_url = $campaigns_url . $endpoints_to_try[0];
        error_log('[ZOHO_INTEGRATION] Trying endpoint: ' . $api_url);
        
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
            error_log('[ZOHO_INTEGRATION] Get lists API request failed: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('[ZOHO_INTEGRATION] Get lists API response code: ' . $response_code);
        error_log('[ZOHO_INTEGRATION] Get lists API response body: ' . $response_body);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            
            // Check for getmailinglists format
            if (isset($response_data['list_of_details']) && is_array($response_data['list_of_details'])) {
                error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from getmailinglists');
                // Convert format to match expected structure
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
                error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from lists');
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
                error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from getlistadvanceddetails');
                // Convert format to match expected structure
                $lists = array();
                if (isset($response_data['list_details']['listname'])) {
                    $lists[] = array(
                        'listkey' => $listkey ?? '', // Use the listkey we used
                        'listname' => $response_data['list_details']['listname']
                    );
                }
                return $lists;
            }
            
            // Check for new emailapi/v2/recipients format
            if (isset($response_data['data']) && is_array($response_data['data'])) {
                error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from emailapi/v2/recipients');
                // Convert format to match expected structure
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
                // Check different response structures
                if (isset($response_data['list_of_details'])) {
                    error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from list_of_details');
                    return $response_data['list_of_details'];
                } elseif (isset($response_data['lists'])) {
                    error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from lists');
                    return $response_data['lists'];
                } elseif (isset($response_data['list_details'])) {
                    error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from list_details');
                    return $response_data['list_details'];
                } else {
                    error_log('[ZOHO_INTEGRATION] Success response but no lists found. Response structure: ' . print_r($response_data, true));
                    // Try next endpoint
                    return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, 1);
                }
            } else {
                error_log('[ZOHO_INTEGRATION] Failed to get lists: ' . (isset($response_data['message']) ? $response_data['message'] : 'Unknown error'));
                // Try next endpoint
                return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, 1);
            }
        } else {
            error_log('[ZOHO_INTEGRATION] HTTP error ' . $response_code . '. Trying next endpoint.');
            // Try next endpoint
            return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, 1);
        }
        
        return false;
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
            // Check if response is successful (not error 1003 or 2402)
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
        error_log('[ZOHO_INTEGRATION] Attempting to create default list');
        
        // Try to create a list using Zoho API
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
            error_log('[ZOHO_INTEGRATION] Create list request failed: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('[ZOHO_INTEGRATION] Create list response code: ' . $response_code);
        error_log('[ZOHO_INTEGRATION] Create list response body: ' . $response_body);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            if (isset($response_data['code']) && $response_data['code'] == '0' && isset($response_data['listkey'])) {
                error_log('[ZOHO_INTEGRATION] List created successfully with key: ' . $response_data['listkey']);
                return $response_data['listkey'];
            }
        }
        
        error_log('[ZOHO_INTEGRATION] Failed to create list');
        return false;
    }
    
    /**
     * Try alternative endpoints to get lists
     */
    private function try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, $index) {
        if ($index >= count($endpoints_to_try)) {
            error_log('[ZOHO_INTEGRATION] All endpoints failed');
            return false;
        }
        
        $api_url = $campaigns_url . $endpoints_to_try[$index];
        error_log('[ZOHO_INTEGRATION] Trying alternative endpoint: ' . $api_url);
        
        $headers = array(
            'Authorization' => 'Zoho-oauthtoken ' . $access_token,
            'Content-Type' => 'application/x-www-form-urlencoded'
        );
        
        $api_params = array(
            'listkey' => 'default', // Try with default listkey
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
            error_log('[ZOHO_INTEGRATION] Alternative endpoint request failed: ' . $response->get_error_message());
            return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, $index + 1);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('[ZOHO_INTEGRATION] Alternative endpoint response code: ' . $response_code);
        error_log('[ZOHO_INTEGRATION] Alternative endpoint response body: ' . $response_body);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            if (isset($response_data['code']) && $response_data['code'] == '0') {
                // Check different response structures
                if (isset($response_data['list_of_details'])) {
                    error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from alternative endpoint');
                    return $response_data['list_of_details'];
                } elseif (isset($response_data['lists'])) {
                    error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from alternative endpoint');
                    return $response_data['lists'];
                } elseif (isset($response_data['list_details'])) {
                    error_log('[ZOHO_INTEGRATION] Lists retrieved successfully from alternative endpoint');
                    return $response_data['list_details'];
                }
            }
        }
        
        // Try next endpoint
        return $this->try_alternative_endpoints($campaigns_url, $access_token, $endpoints_to_try, $index + 1);
    }

    /**
     * Add user to Zoho Campaigns list
     */
    public function add_user_to_zoho_list($user_id) {
        error_log('[ZOHO_INTEGRATION] add_user_to_zoho_list called for user ID: ' . $user_id);
        
        // Get user data
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            error_log('[ZOHO_INTEGRATION] User not found with ID: ' . $user_id);
            return false;
        }
        
        // Get valid access token
        $access_token = $this->get_valid_access_token();
        if (!$access_token) {
            error_log('[ZOHO_INTEGRATION] No valid access token available');
            return false;
        }
        
        // Get Zoho settings
        $zoho_settings = get_option('zoho_integration_settings', array());
        if (empty($zoho_settings['list_key'])) {
            error_log('[ZOHO_INTEGRATION] List key not configured');
            return false;
        }
        
        // Build URL with parameters as per Zoho documentation (GET request)
        $api_params = array(
            'listkey' => $zoho_settings['list_key'],
            'resfmt' => 'JSON',
            'emailids' => $user->user_email
        );
        
        $api_url = ZOHO_API_BASE_URL . 'addlistsubscribersinbulk?' . http_build_query($api_params);
        
        error_log('[ZOHO_INTEGRATION] API URL: ' . $api_url);
        error_log('[ZOHO_INTEGRATION] API params: ' . print_r($api_params, true));
        error_log('[ZOHO_INTEGRATION] Access token: ' . substr($access_token, 0, 20) . '...');
        
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
            error_log('[ZOHO_INTEGRATION] WP_Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('[ZOHO_INTEGRATION] Response code: ' . $response_code);
        error_log('[ZOHO_INTEGRATION] Response body: ' . $response_body);
        
        if ($response_code == 200) {
            $response_data = json_decode($response_body, true);
            if (isset($response_data['code']) && $response_data['code'] == '0') {
                error_log('[ZOHO_INTEGRATION] SUCCESS: User added to Zoho list');
                return true;
            } else {
                error_log('[ZOHO_INTEGRATION] FAILED: ' . (isset($response_data['message']) ? $response_data['message'] : 'Unknown error'));
                return false;
            }
        } else {
            error_log('[ZOHO_INTEGRATION] FAILED: HTTP ' . $response_code);
            return false;
        }
    }
    
    /**
     * Get authorization URL for OAuth flow
     */
    public function get_authorization_url() {
        $zoho_settings = get_option('zoho_integration_settings', array());
        $domain = isset($zoho_settings['domain']) ? $zoho_settings['domain'] : 'com';
        $client_id = isset($zoho_settings['client_id']) ? $zoho_settings['client_id'] : $this->client_id;
        
        // Use correct Zoho Accounts URL format based on web search
        $accounts_url = 'https://accounts.zoho.com/oauth/v2/auth';
        if ($domain == 'ca') {
            $accounts_url = 'https://accounts.zohocloud.ca/oauth/v2/auth';
        }
        
        // Force Canada data center since app was created there
        $accounts_url = 'https://accounts.zohocloud.ca/oauth/v2/auth';
        
        // Use simpler redirect URI
        $redirect_uri = home_url('/zoho-callback');
        
        // Validate client_id
        if (empty($client_id)) {
            error_log('[ZOHO_INTEGRATION] Client ID is empty');
            return false;
        }
        
        error_log('[ZOHO_INTEGRATION] Client ID: ' . $client_id);
        error_log('[ZOHO_INTEGRATION] Redirect URI: ' . $redirect_uri);
        error_log('[ZOHO_INTEGRATION] Accounts URL: ' . $accounts_url);
        
        $auth_params = array(
            'client_id' => $client_id,
            'response_type' => 'code',
            'scope' => 'ZohoCampaigns.contact.CREATE,ZohoCampaigns.contact.READ',
            'redirect_uri' => $redirect_uri,
            'access_type' => 'offline',
            'state' => 'zoho_oauth'
        );
        
        error_log('[ZOHO_INTEGRATION] Auth params: ' . print_r($auth_params, true));
        
        $query_string = http_build_query($auth_params);
        error_log('[ZOHO_INTEGRATION] Query string: ' . $query_string);
        
        $auth_url = $accounts_url . '?' . $query_string;
        error_log('[ZOHO_INTEGRATION] Generated auth URL: ' . $auth_url);
        
        return $auth_url;
    }
    
    /**
     * Handle authorization callback
     */
    public function handle_authorization_callback($code) {
        $zoho_settings = get_option('zoho_integration_settings', array());
        $domain = isset($zoho_settings['domain']) ? $zoho_settings['domain'] : 'com';
        $client_id = isset($zoho_settings['client_id']) ? $zoho_settings['client_id'] : $this->client_id;
        $client_secret = isset($zoho_settings['client_secret']) ? $zoho_settings['client_secret'] : $this->client_secret;
        
        // Use correct Zoho Accounts URL format based on web search
        $accounts_url = 'https://accounts.zohocloud.ca/oauth/v2/token';
        if ($domain == 'ca') {
            $accounts_url = 'https://accounts.zohocloud.ca/oauth/v2/token';
        }
        
        // Force Canada data center since app was created there
        $accounts_url = 'https://accounts.zohocloud.ca/oauth/v2/token';
        
        // Use simpler redirect URI
        $redirect_uri = home_url('/zoho-callback');
        
        $token_params = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri
        );
        
        error_log('[ZOHO_INTEGRATION] Token request URL: ' . $accounts_url);
        error_log('[ZOHO_INTEGRATION] Token params: ' . print_r($token_params, true));
        
        $response = wp_remote_post($accounts_url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => $token_params
        ));
        
        if (is_wp_error($response)) {
            error_log('[ZOHO_INTEGRATION] Token request error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code == 200) {
            $token_data = json_decode($response_body, true);
            if (isset($token_data['access_token'])) {
                $token_data['req_time'] = time();
                update_option('zoho_token_data', $token_data);
                error_log('[ZOHO_INTEGRATION] Access token saved successfully');
                return true;
            }
        }
        
        error_log('[ZOHO_INTEGRATION] Failed to get access token: ' . $response_body);
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
     * Save registration checkbox value
     */
    public function save_registration_checkbox($customer_id, $new_customer_data, $password_generated) {
        if (isset($_POST['zoho_newsletter_subscription']) && $_POST['zoho_newsletter_subscription'] == '1') {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', true);
            error_log('[ZOHO_INTEGRATION] User ' . $customer_id . ' opted in for newsletter during registration');
        } else {
            update_user_meta($customer_id, 'zoho_newsletter_subscription', false);
            error_log('[ZOHO_INTEGRATION] User ' . $customer_id . ' opted out for newsletter during registration');
        }
    }
    
    /**
     * Test function for manual testing
     */
    public function test_add_user($user_id) {
        error_log('[ZOHO_INTEGRATION] ========== TEST ADD USER ' . $user_id . ' ==========');
        $result = $this->add_user_to_zoho_list($user_id);
        error_log('[ZOHO_INTEGRATION] Test result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        error_log('[ZOHO_INTEGRATION] ========== TEST COMPLETED ==========');
        return $result;
    }
}

// Initialize the plugin
new ZohoIntegration();

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
    if (isset($_POST['submit'])) {
        $settings = array(
            'list_key' => sanitize_text_field($_POST['list_key']),
            'domain' => sanitize_text_field($_POST['domain']),
            'client_id' => sanitize_text_field($_POST['client_id']),
            'client_secret' => sanitize_text_field($_POST['client_secret']),
            'enable_registration_checkbox' => isset($_POST['enable_registration_checkbox']) ? 1 : 0,
            'checkbox_text' => sanitize_text_field($_POST['checkbox_text']),
            'enable_debug' => isset($_POST['enable_debug']) ? 1 : 0
        );
        update_option('zoho_integration_settings', $settings);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    if (isset($_POST['authorize'])) {
        $zoho = new ZohoIntegration();
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
            
            // Debug information
            echo '<div class="notice notice-info">';
            echo '<h4>Debug Information:</h4>';
            echo '<p><strong>Current Settings:</strong></p>';
            $settings = get_option('zoho_integration_settings', array());
            echo '<ul>';
            echo '<li>Client ID: ' . (isset($settings['client_id']) ? $settings['client_id'] : 'Not set') . '</li>';
            echo '<li>Client Secret: ' . (isset($settings['client_secret']) ? 'Set' : 'Not set') . '</li>';
            echo '<li>Domain: ' . (isset($settings['domain']) ? $settings['domain'] : 'com') . '</li>';
            echo '<li>List Key: ' . (isset($settings['list_key']) ? $settings['list_key'] : 'Not set') . '</li>';
            echo '</ul>';
            
            echo '<p><strong>Token Data:</strong></p>';
            $token_data = get_option('zoho_token_data', array());
            if (!empty($token_data)) {
                echo '<pre>' . print_r($token_data, true) . '</pre>';
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
                            $zoho = new ZohoIntegration();
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
        if (isset($settings['enable_debug']) && $settings['enable_debug']) {
        ?>
        <h3>Debug Information</h3>
        <?php
        $zoho = new ZohoIntegration();
        $debug_url = $zoho->get_authorization_url();
        if ($debug_url) {
            echo '<p><strong>Generated OAuth URL (Canada Data Center):</strong><br>';
            echo '<a href="' . esc_url($debug_url) . '" target="_blank">' . esc_html($debug_url) . '</a></p>';
            echo '<p><em>This URL uses Canada data center (zohocloud.ca) to match your app.</em></p>';
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
            // Get the current OAuth URL from the debug section
            var oauthLink = document.querySelector('a[href*="accounts.zohocloud.ca"]');
            if (oauthLink) {
                // Open the correct OAuth URL directly
                window.open(oauthLink.href, '_blank');
            } else {
                // Fallback to form submission
                var form = document.getElementById('zoho-auth-form');
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'authorize';
                input.value = '1';
                form.appendChild(input);
                form.submit();
            }
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
                <input type="hidden" name="test_user" value="1" />
                <p>
                    <label>Test User ID: <input type="number" name="test_user_id" value="78805" /></label>
                    <input type="submit" class="button" value="Test Add User" />
                </p>
            </form>
        </div>
        
        <?php
        if (isset($_POST['test_user'])) {
            $test_user_id = intval($_POST['test_user_id']);
            $zoho = new ZohoIntegration();
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