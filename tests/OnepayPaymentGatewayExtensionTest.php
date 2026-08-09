<?php
namespace Jankx\Extensions\Onepay\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\Onepay\OnepayPaymentGatewayExtension;
use Jankx\Extensions\Onepay\Gateways\DomesticCardGateway;
use Jankx\Extensions\Onepay\Gateways\InternationalCardGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class OnepayPaymentGatewayExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        onepay_test_stub_wp_functions();

        $ref = new \ReflectionProperty(GatewayManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__options'], $GLOBALS['__post_meta']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_instance_returns_singleton()
    {
        $instance = new OnepayPaymentGatewayExtension();
        $this->assertSame($instance, OnepayPaymentGatewayExtension::get_instance());
    }

    public function test_register_hooks_wires_all_hooks()
    {
        $extension = new OnepayPaymentGatewayExtension();

        $actions = [];
        $filters = [];
        Functions\when('add_action')->alias(function ($tag, $callback) use (&$actions) {
            $actions[] = ['tag' => $tag, 'callback' => $callback];
            return true;
        });
        Functions\when('add_filter')->alias(function ($tag, $callback) use (&$filters) {
            $filters[] = ['tag' => $tag, 'callback' => $callback];
            return true;
        });

        $extension->register_hooks();

        $this->assertContains(
            ['tag' => 'jankx/payment/register_gateways', 'callback' => [$extension, 'registerGateways']],
            $actions
        );
        $this->assertContains(
            ['tag' => 'admin_init', 'callback' => [$extension, 'registerGatewaySettings']],
            $actions
        );
        $this->assertContains(
            ['tag' => 'jankx/payment/gateway/onepay/default_config', 'callback' => [$extension, 'defaultInternationalConfig']],
            $filters
        );
        $this->assertContains(
            ['tag' => 'jankx/payment/gateway/onepay_domestic/default_config', 'callback' => [$extension, 'defaultDomesticConfig']],
            $filters
        );

        // IPN controller must register its rest_api_init listener.
        $restTags = array_column($actions, 'tag');
        $this->assertContains('rest_api_init', $restTags);
    }

    public function test_registerGateways_registers_both_channels()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $extension->registerGateways();

        $manager = GatewayManager::getInstance();
        $this->assertTrue($manager->hasGateway('onepay'));
        $this->assertTrue($manager->hasGateway('onepay_domestic'));
    }

    public function test_registerGateways_registers_correct_classes()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $extension->registerGateways();

        $gateways = GatewayManager::getInstance()->getAll();
        $this->assertSame(InternationalCardGateway::class, $gateways['onepay']);
        $this->assertSame(DomesticCardGateway::class, $gateways['onepay_domestic']);
    }

    public function test_default_international_config()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $config = $extension->defaultInternationalConfig(['testMode' => false]);

        $this->assertEquals('1', $config['testMode']);
        $this->assertEquals('vn', $config['locale']);
        $this->assertEquals('INTERNATIONAL', $config['card_list']);
        $this->assertEquals('TESTONEPAY', $config['sandbox_merchant']);
        $this->assertEquals('6BEB2546', $config['sandbox_access_code']);
        $this->assertEquals('6D0870CDE5F24F34F3915FB0045120DB', $config['sandbox_secure_hash']);
        $this->assertEquals('op01', $config['sandbox_user']);
        $this->assertEquals('op123456', $config['sandbox_password']);
    }

    public function test_default_domestic_config()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $config = $extension->defaultDomesticConfig(['testMode' => false]);

        $this->assertEquals('1', $config['testMode']);
        $this->assertEquals('vn', $config['locale']);
        $this->assertEquals('DOMESTIC', $config['card_list']);
        $this->assertEquals('TESTONEPAY', $config['sandbox_merchant']);
        $this->assertEquals('6BEB2546', $config['sandbox_access_code']);
    }

    public function test_default_config_preserves_custom_keys()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $config = $extension->defaultInternationalConfig([
            'testMode' => false,
            'custom_key' => 'custom-value',
        ]);

        $this->assertEquals('custom-value', $config['custom_key']);
    }

    public function test_sanitize_gateway_settings_strips_text_fields()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $sanitized = $extension->sanitizeGatewaySettings([
            'locale' => "  vn  ",
            'sandbox_merchant' => "  TESTONEPAY \n",
            'card_list' => 'INTERNATIONAL',
        ]);

        $this->assertEquals('vn', $sanitized['locale']);
        $this->assertEquals('TESTONEPAY', $sanitized['sandbox_merchant']);
        $this->assertEquals('INTERNATIONAL', $sanitized['card_list']);
    }

    public function test_sanitize_gateway_settings_handles_non_array()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $this->assertEquals([], $extension->sanitizeGatewaySettings('not-an-array'));
    }

    public function test_sanitize_gateway_settings_keeps_non_string_values()
    {
        $extension = new OnepayPaymentGatewayExtension();
        $sanitized = $extension->sanitizeGatewaySettings(['testMode' => 1, 'enabled' => true]);
        $this->assertSame(1, $sanitized['testMode']);
        $this->assertTrue($sanitized['enabled']);
    }

    public function test_register_gateway_settings_registers_both_options()
    {
        $extension = new OnepayPaymentGatewayExtension();

        $captured = [];
        Functions\when('register_setting')->alias(function ($group, $name, $args) use (&$captured) {
            $captured[] = ['group' => $group, 'name' => $name, 'args' => $args];
            return true;
        });

        $extension->registerGatewaySettings();

        $names = array_column($captured, 'name');
        $this->assertContains('jankx_payment_gateway_onepay', $names);
        $this->assertContains('jankx_payment_gateway_onepay_domestic', $names);

        foreach ($captured as $entry) {
            $this->assertEquals('jankx_payment', $entry['group']);
            $this->assertEquals('array', $entry['args']['type']);
            $this->assertSame([$extension, 'sanitizeGatewaySettings'], $entry['args']['sanitize_callback']);
        }
    }
}
