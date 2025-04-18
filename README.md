# Stripe Payment Form

## Installation
1. Download the plugin zip file
2. Go to WordPress admin > Plugins > Add New > Upload Plugin
3. Upload the zip file and activate the plugin
4. Go to Payment Forms > Settings to configure your Stripe API keys

## Requirements
- PHP 7.4 or higher
- WordPress 5.0 or higher
- SSL certificate (required for live mode)
- Stripe account

## Configuration
1. Get your Stripe API keys from your Stripe Dashboard
2. Go to Payment Forms > Settings
3. Enter your API keys (test and live)
4. Configure webhook endpoint in your Stripe Dashboard:
   - Endpoint URL: https://your-site.com/wp-json/pfb/v1/webhook
   - Events to send: payment_intent.succeeded, payment_intent.payment_failed# stripe-form-plugin-wp

//Note
1. The occasional webhook failures are normal with Stripe's system, but these improvements will make your plugin more resilient to those issues.
2. Yes, when Stripe retries a webhook, it will update your table automatically as long as your webhook endpoint is working correctly. The webhook handler in your class-stripe.php file processes the event and calls update_payment_status() which updates your database table.
