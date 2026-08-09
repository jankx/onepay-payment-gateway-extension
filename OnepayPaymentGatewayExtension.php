<?php
namespace Jankx\Extensions\Onepay;

use Jankx\Extensions\AbstractExtension;
use Jankx\Extensions\Onepay\Gateways\DomesticCardGateway;
use Jankx\Extensions\Onepay\Gateways\InternationalCardGateway;
use Jankx\Extensions\Onepay\Rest\OnePayIpnController;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

/**
 * OnePay Payment Gateway Extension.
 *
 * Registers two OnePay gateway channels with the payment-system extension:
 *
 *  - `onepay`          : international cards (Visa, Mastercard, JCB)
 *  - `onepay_domestic` : domestic ATM / Napas cards
 *
 * Both channels use the OnePay VPC hosted-redirect flow:
 *
 *  1. purchase()          -> redirects the customer to the OnePay payment page
 *  2. completePurchase()  -> verifies the vpc_SecureHash on the return URL
 *  3. queryStatus()       -> OnePay QueryDR API (status reconciliation)
 *  4. IPN endpoint        -> server-to-server payment confirmation
 *
 * @package Jankx\Extensions\Onepay
 */
class OnepayPaymentGatewayExtension extends AbstractExtension
{
    protected static $instance;

    public function __construct()
    {
        $this->register_autoloader();
        parent::__construct();
    }

    protected function register_autoloader()
    {
        spl_autoload_register(function ($class) {
            $prefix = 'Jankx\\Extensions\\Onepay\\';
            $base_dir = __DIR__ . '/src/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    public function init(): void
    {
        self::$instance = $this;
    }

    public static function get_instance(): ?self
    {
        return self::$instance;
    }

    public function register_hooks(): void
    {
        // Register the OnePay channels when the payment-system gateway
        // manager is booted (it fires this action during its register_hooks,
        // which runs after this extension has registered its listener).
        add_action('jankx/payment/register_gateways', [$this, 'registerGateways']);

        // Default configuration per channel (test mode with OnePay MTF creds).
        add_filter('jankx/payment/gateway/onepay/default_config', [$this, 'defaultInternationalConfig']);
        add_filter('jankx/payment/gateway/onepay_domestic/default_config', [$this, 'defaultDomesticConfig']);

        // Server-to-server IPN endpoint.
        (new OnePayIpnController())->init();

        // Make the per-gateway settings form (options.php) persist. The
        // payment-system only registers its own option, so each gateway must
        // register the jankx_payment_gateway_{slug} option explicitly.
        add_action('admin_init', [$this, 'registerGatewaySettings']);
    }

    public function registerGatewaySettings(): void
    {
        foreach (['onepay', 'onepay_domestic'] as $slug) {
            register_setting('jankx_payment', "jankx_payment_gateway_{$slug}", [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitizeGatewaySettings'],
            ]);
        }
    }

    public function sanitizeGatewaySettings($value): array
    {
        $value = is_array($value) ? $value : [];
        foreach ($value as $key => $item) {
            $value[$key] = is_string($item) ? sanitize_text_field($item) : $item;
        }
        return $value;
    }

    public function registerGateways(): void
    {
        $manager = GatewayManager::getInstance();
        $manager->register('onepay', InternationalCardGateway::class);
        $manager->register('onepay_domestic', DomesticCardGateway::class);
    }

    public function defaultInternationalConfig(array $config): array
    {
        return $this->defaultConfig($config, 'INTERNATIONAL');
    }

    public function defaultDomesticConfig(array $config): array
    {
        return $this->defaultConfig($config, 'DOMESTIC');
    }

    protected function defaultConfig(array $config, string $cardList): array
    {
        return array_merge($config, [
            'testMode'            => '1',
            'locale'              => 'vn',
            'card_list'           => $cardList,
            'sandbox_merchant'    => 'TESTONEPAY',
            'sandbox_access_code' => '6BEB2546',
            'sandbox_secure_hash' => '6D0870CDE5F24F34F3915FB0045120DB',
            'sandbox_user'        => 'op01',
            'sandbox_password'    => 'op123456',
        ]);
    }
}
