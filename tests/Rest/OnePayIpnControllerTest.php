<?php
namespace Jankx\Extensions\Onepay\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\Onepay\Rest\OnePayIpnController;
use Jankx\Extensions\Onepay\Gateways\InternationalCardGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

class OnePayIpnControllerTest extends TestCase
{
    const TEST_MERCHANT = 'TESTONEPAY';
    const TEST_ACCESS_CODE = '6BEB2546';
    const TEST_SECURE_HASH = '6D0870CDE5F24F34F3915FB0045120DB';

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

    protected function registerGateway()
    {
        $GLOBALS['__options']['jankx_payment_gateway_onepay'] = [
            'testMode' => '1',
            'locale' => 'vn',
            'card_list' => 'INTERNATIONAL',
            'sandbox_merchant' => self::TEST_MERCHANT,
            'sandbox_access_code' => self::TEST_ACCESS_CODE,
            'sandbox_secure_hash' => self::TEST_SECURE_HASH,
            'sandbox_user' => 'op01',
            'sandbox_password' => 'op123456',
        ];

        GatewayManager::getInstance()->register('onepay', InternationalCardGateway::class);
    }

    protected function expectedHash(array $fields, string $key): string
    {
        $hashData = [];
        foreach ($fields as $k => $v) {
            if ($k === 'vpc_SecureHash' || $k === 'vpc_SecureHashType') {
                continue;
            }
            if (strpos($k, 'vpc_') !== 0 && strpos($k, 'user_') !== 0) {
                continue;
            }
            $hashData[$k] = (string) $v;
        }
        ksort($hashData);
        $pairs = [];
        foreach ($hashData as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return strtoupper(hash_hmac('sha256', implode('&', $pairs), $key));
    }

    protected function signedIpnParams(array $overrides = []): array
    {
        $params = array_merge([
            'vpc_Version' => '2',
            'vpc_Currency' => 'VND',
            'vpc_Command' => 'pay',
            'vpc_AccessCode' => self::TEST_ACCESS_CODE,
            'vpc_Merchant' => self::TEST_MERCHANT,
            'vpc_MerchTxnRef' => 'JX00000042abcdef',
            'vpc_OrderInfo' => 'Order JX00000042abcdef',
            'vpc_Amount' => '10000000',
            'vpc_TransactionNo' => 'VPC99999',
            'vpc_TxnResponseCode' => '0',
        ], $overrides);

        $params['vpc_SecureHash'] = $this->expectedHash($params, self::TEST_SECURE_HASH);
        return $params;
    }

    protected function buildRequest(array $params): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/jankx/v1/onepay/ipn/onepay');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_param('gateway', 'onepay');
        return $request;
    }

    // ------------------------------------------------------------------
    // init() / registerRoutes()
    // ------------------------------------------------------------------

    public function test_init_registers_rest_api_hook()
    {
        $controller = new OnePayIpnController();

        $captured = [];
        Functions\when('add_action')->alias(function ($tag, $callback) use (&$captured) {
            $captured[] = ['tag' => $tag, 'callback' => $callback];
            return true;
        });

        $controller->init();

        $this->assertNotEmpty($captured);
        $found = false;
        foreach ($captured as $entry) {
            if ($entry['tag'] === 'rest_api_init' && $entry['callback'] === [$controller, 'registerRoutes']) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_registerRoutes_registers_ipn_route()
    {
        $controller = new OnePayIpnController();

        $captured = [];
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$captured) {
            $captured[] = ['ns' => $ns, 'route' => $route, 'args' => $args];
            return true;
        });

        $controller->registerRoutes();

        $this->assertCount(1, $captured);
        $this->assertEquals('jankx/v1', $captured[0]['ns']);
        $this->assertStringContainsString('onepay/ipn', $captured[0]['route']);
        $this->assertEquals(['GET', 'POST'], $captured[0]['args']['methods']);
        $this->assertSame([$controller, 'handle'], $captured[0]['args']['callback']);
        $this->assertSame('__return_true', $captured[0]['args']['permission_callback']);
    }

    // ------------------------------------------------------------------
    // handle()
    // ------------------------------------------------------------------

    public function test_handle_returns_invalid_hash_when_gateway_not_registered()
    {
        $controller = new OnePayIpnController();
        $request = $this->buildRequest($this->signedIpnParams());

        $response = $controller->handle($request);

        $this->assertEquals(200, $response->get_status());
        $this->assertEquals('responsecode=0&desc=invalid-hash', $response->get_data());
    }

    public function test_handle_returns_invalid_hash_on_tampered_params()
    {
        $this->registerGateway();
        $controller = new OnePayIpnController();

        $params = $this->signedIpnParams();
        $params['vpc_Amount'] = '9999';
        $response = $controller->handle($this->buildRequest($params));

        $this->assertEquals('responsecode=0&desc=invalid-hash', $response->get_data());
    }

    public function test_handle_confirms_success_and_completes_transaction()
    {
        $this->registerGateway();
        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 7, 'post_date' => '2026-08-09 00:00:00'])];

        $controller = new OnePayIpnController();
        $params = $this->signedIpnParams(['vpc_TxnResponseCode' => '0']);
        $request = $this->buildRequest($params);
        $response = $controller->handle($request);

        $this->assertEquals('responsecode=1&desc=confirm-success', $response->get_data());

        $this->assertEquals(Transaction::STATUS_COMPLETED, $GLOBALS['__post_meta']['_status']);
        $this->assertEquals('VPC99999', $GLOBALS['__post_meta']['_transaction_id']);
        $this->assertEquals(json_encode($request->get_params()), $GLOBALS['__post_meta']['_raw_response']);
    }

    public function test_handle_does_not_complete_transaction_on_non_zero_code()
    {
        $this->registerGateway();
        \WP_Query::$mock_posts = [new \WP_Post(['ID' => 7, 'post_date' => '2026-08-09 00:00:00'])];

        $controller = new OnePayIpnController();
        $params = $this->signedIpnParams(['vpc_TxnResponseCode' => '99']);
        $response = $controller->handle($this->buildRequest($params));

        $this->assertEquals('responsecode=1&desc=confirm-success', $response->get_data());
        $this->assertArrayNotHasKey('_status', $GLOBALS['__post_meta']);
    }

    public function test_handle_confirms_when_transaction_not_found()
    {
        $this->registerGateway();
        \WP_Query::$mock_posts = [];

        $controller = new OnePayIpnController();
        $response = $controller->handle($this->buildRequest($this->signedIpnParams()));

        $this->assertEquals('responsecode=1&desc=confirm-success', $response->get_data());
        $this->assertArrayNotHasKey('_status', $GLOBALS['__post_meta']);
    }

    public function test_handle_uses_gateway_from_url()
    {
        $this->registerGateway();
        \WP_Query::$mock_posts = [];

        $controller = new OnePayIpnController();
        $params = $this->signedIpnParams();
        $request = $this->buildRequest($params);
        $request->set_param('gateway', 'onepay');

        $response = $controller->handle($request);
        $this->assertEquals('responsecode=1&desc=confirm-success', $response->get_data());
    }
}
