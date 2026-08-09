<?php
namespace Jankx\Extensions\Onepay\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Jankx\Extensions\Onepay\Gateways\InternationalCardGateway;
use Jankx\Extensions\Onepay\Gateways\OnePayGateway;

class InternationalCardGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        onepay_test_stub_wp_functions();
        $GLOBALS['__post_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__post_meta']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_getName_returns_channel_display_name()
    {
        $gateway = new InternationalCardGateway();
        $this->assertSame('OnePay – International cards (Visa/Master/JCB)', $gateway->getName());
    }

    public function test_is_instance_of_onepay_gateway()
    {
        $this->assertInstanceOf(OnePayGateway::class, new InternationalCardGateway());
    }

    public function test_purchase_defaults_to_international_card_list()
    {
        $gateway = new InternationalCardGateway();
        $gateway->initialize([
            'testMode' => true,
            'sandbox_merchant' => 'TESTONEPAY',
            'sandbox_access_code' => '6BEB2546',
            'sandbox_secure_hash' => '6D0870CDE5F24F34F3915FB0045120DB',
        ]);

        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10]);
        $this->assertEquals('INTERNATIONAL', $result['redirectData']['vpc_CardList']);
    }

    public function test_purchase_config_card_list_overrides_default()
    {
        $gateway = new InternationalCardGateway();
        $gateway->initialize([
            'testMode' => true,
            'card_list' => 'QR',
            'sandbox_merchant' => 'TESTONEPAY',
            'sandbox_access_code' => '6BEB2546',
            'sandbox_secure_hash' => '6D0870CDE5F24F34F3915FB0045120DB',
        ]);

        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10]);
        $this->assertEquals('QR', $result['redirectData']['vpc_CardList']);
    }

    public function test_getSettingsFields_defaults_card_list_to_international()
    {
        $fields = (new InternationalCardGateway())->getSettingsFields();
        $this->assertEquals('INTERNATIONAL', $fields['card_list']['default']);
    }
}
