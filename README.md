# Zoho Integration

**Contributors:** wptopd3v  
**Tags:** woocommerce, zoho, campaigns, newsletter, email marketing, integration, oauth  
**Requires at least:** 5.0  
**Tested up to:** 6.4  
**Requires PHP:** 7.4  
**WC requires at least:** 5.0  
**WC tested up to:** 8.0  
**Stable tag:** 1.1.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Integrate WooCommerce with Zoho Campaigns for automatic newsletter subscriptions. Features OAuth 2.0 authentication, newsletter checkbox in registration form, and auto-subscribe functionality.

## Description

Zoho Integration is a powerful WordPress plugin that seamlessly connects your WooCommerce store with Zoho Campaigns, enabling automatic newsletter subscriptions and email marketing automation.

### Key Features

* **OAuth 2.0 Authentication** - Secure connection with Zoho Campaigns
* **WooCommerce Integration** - Automatic user subscription during registration and checkout
* **Newsletter Checkbox** - Pre-checked subscription option in registration form
* **Admin Dashboard** - Easy configuration and management
* **Debug Logging** - Comprehensive logging for troubleshooting
* **Canada Data Center Support** - Full support for zohocloud.ca
* **AJAX List Management** - Dynamic list loading and management
* **Customizable Settings** - Flexible configuration options

### How It Works

1. **Setup**: Configure your Zoho Campaigns API credentials in the admin panel
2. **Authorization**: Use OAuth 2.0 to securely connect with Zoho
3. **List Selection**: Choose which Zoho list to subscribe users to
4. **Auto-Subscription**: Users are automatically added to your chosen list when they register or checkout
5. **Management**: Monitor and manage subscriptions through the admin dashboard

### Requirements

* WordPress 5.0 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* Zoho Campaigns account
* Valid Zoho API credentials

## Installation

1. Upload the plugin files to the `/wp-content/plugins/zoho-integration` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Zoho Integration to configure the plugin
4. Enter your Zoho Campaigns API credentials
5. Authorize the connection with Zoho
6. Select your mailing list
7. Enable the newsletter checkbox if desired

## Frequently Asked Questions

### Do I need a Zoho Campaigns account?

Yes, you need a valid Zoho Campaigns account and API credentials to use this plugin.

### How do I get Zoho API credentials?

1. Log in to your Zoho Campaigns account
2. Go to Settings > API & Integrations
3. Create a new application
4. Note down your Client ID and Client Secret
5. Set the Redirect URI to: `https://yoursite.com/zoho-callback`

### Can I use this with Zoho's Canada data center?

Yes, the plugin fully supports Zoho's Canada data center (zohocloud.ca).

### How do I enable debug logging?

Go to Settings > Zoho Integration and check "Enable Debug Logging" to view detailed logs for troubleshooting.

### Can I customize the newsletter checkbox text?

Yes, you can customize the checkbox text in the plugin settings.

## Screenshots

1. Plugin settings page with OAuth configuration
2. Newsletter checkbox in WooCommerce registration form
3. Debug information and test functionality
4. List management and selection

## Changelog

### 1.1.0
* Initial release
* OAuth 2.0 authentication
* WooCommerce integration
* Newsletter checkbox in registration form
* Admin dashboard with debug options
* Canada data center support
* AJAX list management

## Upgrade Notice

### 1.1.0
Initial release of Zoho Integration plugin.

## Support

For support, please visit [Wptopd3v](https://wptopd3v.com) or create an issue on the plugin's GitHub repository.

## Privacy Policy

This plugin does not collect or store any personal data. All data is processed through Zoho Campaigns' secure API.
