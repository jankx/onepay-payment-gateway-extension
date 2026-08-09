<?php
namespace Jankx\Extensions\Onepay\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\Onepay\Gateways\OnePayGateway;

class TestOnePayGateway extends OnePayGateway
{
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getConfigValue(): array
    {
        return $this->config;
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function exposeComputeHash(array $fields): string
    {
        return $this->computeHash($fields);
    }

    public function exposeBuildMerchTxnRef(string $transactionId): string
    {
        return $this->buildMerchTxnRef($transactionId);
    }

    public function exposeNormalizeOrderInfo(string $description, string $merchTxnRef): string
    {
        return $this->normalizeOrderInfo($description, $merchTxnRef);
    }

    public function exposeGetLocale(): string
    {
        return $this->getLocale();
    }

    public function exposeGetClientIp(): string
    {
        return $this->getClientIp();
    }

    public function exposeGetResponseCodeMessage(string $code): string
    {
        return $this->getResponseCodeMessage($code);
    }

    public function exposeIsTestMode(): bool
    {
        return $this->isTestMode();
    }

    public function exposeGetPaymentUrl(): string
    {
        return $this->getPaymentUrl();
    }

    public function exposeGetQueryUrl(): string
    {
        return $this->getQueryUrl();
    }

    public function exposePersistMerchTxnRef(string $transactionId, string $merchTxnRef): void
    {
        $this->persistMerchTxnRef($transactionId, $merchTxnRef);
    }
}

class OnePayGatewayTest extends TestCase
{
    const TEST_MERCHANT = 'TESTONEPAY';
    const TEST_ACCESS_CODE = '6BEB2546';
    const TEST_SECURE_HASH = '6D0870CDE5F24F34F3915FB0045120DB';
    const TEST_USER = 'op01';
    const TEST_PASSWORD = 'op123456';

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
        $_SERVER = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    protected function sandboxConfig(array $overrides = []): array
    {
        return array_merge([
            'testMode' => true,
            'locale' => 'vn',
            'card_list' => '',
            'sandbox_merchant' => self::TEST_MERCHANT,
            'sandbox_access_code' => self::TEST_ACCESS_CODE,
            'sandbox_secure_hash' => self::TEST_SECURE_HASH,
            'sandbox_user' => self::TEST_USER,
            'sandbox_password' => self::TEST_PASSWORD,
        ], $overrides);
    }

    protected function newGateway(): TestOnePayGateway
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize($this->sandboxConfig());
        return $gateway;
    }

    /**
     * Independent re-implementation of the OnePay hash spec (doc II.8) to
     * verify the gateway produces the exact expected value.
     */
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

    // ------------------------------------------------------------------
    // initialize()
    // ------------------------------------------------------------------

    public function test_initialize_merges_defaults()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize([
            'testMode' => true,
            'sandbox_merchant' => 'MERCH-X',
        ]);

        $config = $gateway->getConfigValue();
        $this->assertEquals('vn', $config['locale']);
        $this->assertEquals('', $config['card_list']);
        $this->assertEquals('MERCH-X', $config['sandbox_merchant']);
    }

    public function test_initialize_sandbox_mode_uses_sandbox_credentials()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize($this->sandboxConfig(['testMode' => true]));

        $this->assertEquals([
            'merchant' => self::TEST_MERCHANT,
            'access_code' => self::TEST_ACCESS_CODE,
            'secure_hash' => self::TEST_SECURE_HASH,
            'user' => self::TEST_USER,
            'password' => self::TEST_PASSWORD,
        ], $gateway->getCredentials());
    }

    public function test_initialize_production_mode_uses_production_credentials()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize($this->sandboxConfig([
            'testMode' => false,
            'production_merchant' => 'PROD-MERCHANT',
            'production_access_code' => 'PROD-ACCESS',
            'production_secure_hash' => 'PROD-HASH',
            'production_user' => 'prod-user',
            'production_password' => 'prod-pass',
        ]));

        $this->assertEquals([
            'merchant' => 'PROD-MERCHANT',
            'access_code' => 'PROD-ACCESS',
            'secure_hash' => 'PROD-HASH',
            'user' => 'prod-user',
            'password' => 'prod-pass',
        ], $gateway->getCredentials());
    }

    public function test_initialize_missing_credentials_defaults_to_empty_string()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize(['testMode' => true]);

        $this->assertEquals([
            'merchant' => '',
            'access_code' => '',
            'secure_hash' => '',
            'user' => '',
            'password' => '',
        ], $gateway->getCredentials());
    }

    // ------------------------------------------------------------------
    // isAvailable()
    // ------------------------------------------------------------------

    public function test_isAvailable_true_when_all_required_credentials_present()
    {
        $this->assertTrue($this->newGateway()->isAvailable());
    }

    public function test_isAvailable_false_when_merchant_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['merchant' => '', 'access_code' => 'X', 'secure_hash' => 'Y']);
        $this->assertFalse($gateway->isAvailable());
    }

    public function test_isAvailable_false_when_access_code_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['merchant' => 'X', 'access_code' => '', 'secure_hash' => 'Y']);
        $this->assertFalse($gateway->isAvailable());
    }

    public function test_isAvailable_false_when_secure_hash_missing()
    {
        $gateway = $this->newGateway();
        $gateway->setCredentials(['merchant' => 'X', 'access_code' => 'Y', 'secure_hash' => '']);
        $this->assertFalse($gateway->isAvailable());
    }

    // ------------------------------------------------------------------
    // getName()
    // ------------------------------------------------------------------

    public function test_getName_returns_display_name()
    {
        $gateway = new TestOnePayGateway();
        $this->assertEquals('OnePay', $gateway->getName());
    }

    // ------------------------------------------------------------------
    // purchase()
    // ------------------------------------------------------------------

    public function test_purchase_builds_expected_redirect_payload()
    {
        $gateway = $this->newGateway();
        $result = $gateway->purchase([
            'transactionId' => '42',
            'amount' => 100000.00,
            'returnUrl' => 'https://example.com/payment/return/42',
            'description' => 'Order #42',
        ]);

        $this->assertEquals('redirect', $result['status']);
        $this->assertEquals('GET', $result['redirectMethod']);
        $this->assertSame($result['transactionId'], $result['redirectData']['vpc_MerchTxnRef']);

        $fields = $result['redirectData'];
        $this->assertEquals('2', $fields['vpc_Version']);
        $this->assertEquals('VND', $fields['vpc_Currency']);
        $this->assertEquals('pay', $fields['vpc_Command']);
        $this->assertEquals(self::TEST_ACCESS_CODE, $fields['vpc_AccessCode']);
        $this->assertEquals(self::TEST_MERCHANT, $fields['vpc_Merchant']);
        $this->assertEquals('vn', $fields['vpc_Locale']);
        $this->assertEquals('https://example.com/payment/return/42', $fields['vpc_ReturnURL']);
        $this->assertEquals('10000000', $fields['vpc_Amount']);
    }

    public function test_purchase_amount_is_converted_to_minor_units()
    {
        $gateway = $this->newGateway();
        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 99.99]);
        $this->assertEquals('9999', $result['redirectData']['vpc_Amount']);

        $result = $gateway->purchase(['transactionId' => '2', 'amount' => 1500]);
        $this->assertEquals('150000', $result['redirectData']['vpc_Amount']);
    }

    public function test_purchase_adds_card_list_from_config()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize($this->sandboxConfig(['card_list' => 'QR']));
        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10]);

        $this->assertEquals('QR', $result['redirectData']['vpc_CardList']);
    }

    public function test_purchase_adds_customer_fields()
    {
        $gateway = $this->newGateway();
        $result = $gateway->purchase([
            'transactionId' => '1',
            'amount' => 10,
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+84 912 345 678',
            'customer_name' => 'Nguyen Van A',
        ]);

        $fields = $result['redirectData'];
        $this->assertEquals('customer@example.com', $fields['vpc_Customer_Email']);
        $this->assertEquals('84912345678', $fields['vpc_Customer_Phone']);
        $this->assertEquals('Nguyen Van A', $fields['user_customer_name']);
    }

    public function test_purchase_includes_againlink_and_title_but_not_in_hash()
    {
        $gateway = $this->newGateway();
        $result = $gateway->purchase([
            'transactionId' => '1',
            'amount' => 10,
            'cancelUrl' => 'https://example.com/payment/cancel/1',
        ]);

        $this->assertStringContainsString(rawurlencode('https://example.com/payment/cancel/1'), $result['redirectUrl']);
        $this->assertStringContainsString('Title=', $result['redirectUrl']);

        // AgainLink / Title must not participate in the signature.
        $hashData = $result['redirectData'];
        $this->assertArrayNotHasKey('AgainLink', $hashData);
        $this->assertArrayNotHasKey('Title', $hashData);
    }

    public function test_purchase_computes_valid_secure_hash()
    {
        $gateway = $this->newGateway();
        $result = $gateway->purchase([
            'transactionId' => '42',
            'amount' => 100000.00,
            'returnUrl' => 'https://example.com/return',
            'description' => 'Order #42',
        ]);

        $expected = $this->expectedHash($result['redirectData'], self::TEST_SECURE_HASH);
        $this->assertEquals($expected, $result['redirectData']['vpc_SecureHash']);
    }

    public function test_purchase_persists_merchant_reference()
    {
        $gateway = $this->newGateway();

        $result = $gateway->purchase([
            'transactionId' => '42',
            'amount' => 10,
            'returnUrl' => 'https://example.com/return',
        ]);

        $this->assertEquals(
            $result['transactionId'],
            $GLOBALS['__post_meta']['_transaction_id']
        );
    }

    public function test_purchase_redirects_to_test_payment_url_in_test_mode()
    {
        $gateway = $this->newGateway();
        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10]);
        $this->assertStringStartsWith(OnePayGateway::PAYMENT_URL_TEST, $result['redirectUrl']);
    }

    public function test_purchase_redirects_to_production_url_in_production_mode()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize($this->sandboxConfig([
            'testMode' => false,
            'production_merchant' => 'P',
            'production_access_code' => 'A',
            'production_secure_hash' => 'H',
        ]));
        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10]);
        $this->assertStringStartsWith(OnePayGateway::PAYMENT_URL_PROD, $result['redirectUrl']);
    }

    public function test_purchase_merch_txn_ref_is_valid_unique_alphanumeric()
    {
        $gateway = $this->newGateway();

        $refA = $gateway->exposeBuildMerchTxnRef('42');
        $refB = $gateway->exposeBuildMerchTxnRef('42');

        $this->assertLessThanOrEqual(40, strlen($refA));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,40}$/', $refA);
        $this->assertStringStartsWith('JX', $refA);
        $this->assertNotSame($refA, $refB);
    }

    public function test_purchase_order_info_is_sanitized_and_truncated()
    {
        $gateway = $this->newGateway();

        $info = $gateway->exposeNormalizeOrderInfo(str_repeat('A', 100), 'JX123');
        $this->assertLessThanOrEqual(34, strlen($info));

        $this->assertSame('Order JX123', $gateway->exposeNormalizeOrderInfo('', 'JX123'));

        $stripped = $gateway->exposeNormalizeOrderInfo('Pay & <b>#1</b>', 'JX123');
        $this->assertStringNotContainsString('&', $stripped);
        $this->assertStringNotContainsString('<', $stripped);
        $this->assertStringNotContainsString('>', $stripped);
    }

    public function test_purchase_ticket_no_uses_client_ip()
    {
        $_SERVER['REMOTE_ADDR'] = '123.45.67.89';
        $gateway = $this->newGateway();
        $result = $gateway->purchase(['transactionId' => '1', 'amount' => 10]);
        $this->assertEquals('123.45.67.89', $result['redirectData']['vpc_TicketNo']);
    }

    // ------------------------------------------------------------------
    // completePurchase()
    // ------------------------------------------------------------------

    public function test_completePurchase_success()
    {
        $gateway = $this->newGateway();
        $params = $this->signedParams([
            'vpc_TxnResponseCode' => '0',
            'vpc_TransactionNo' => 'VPC12345',
            'vpc_MerchTxnRef' => 'JX00000042abcdef',
        ]);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('VPC12345', $result['transactionId']);
    }

    public function test_completePurchase_success_with_request_params_wrapper()
    {
        $gateway = $this->newGateway();
        $params = $this->signedParams([
            'vpc_TxnResponseCode' => '0',
            'vpc_TransactionNo' => 'VPC12345',
        ]);

        $result = $gateway->completePurchase(['request_params' => $params]);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('VPC12345', $result['transactionId']);
    }

    public function test_completePurchase_failed_code()
    {
        $gateway = $this->newGateway();
        $params = $this->signedParams([
            'vpc_TxnResponseCode' => '5',
            'vpc_Message' => '',
        ]);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('5', $result['code']);
        $this->assertStringContainsString('cancelled', strtolower($result['message']));
    }

    public function test_completePurchase_uses_vpc_message_when_present()
    {
        $gateway = $this->newGateway();
        $params = $this->signedParams([
            'vpc_TxnResponseCode' => '99',
            'vpc_Message' => 'System error custom',
        ]);

        $result = $gateway->completePurchase($params);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('System error custom', $result['message']);
    }

    public function test_completePurchase_rejects_invalid_hash()
    {
        $gateway = $this->newGateway();
        $params = $this->signedParams(['vpc_TxnResponseCode' => '0']);
        $params['vpc_Amount'] = '9999';

        $result = $gateway->completePurchase($params);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('HASH_MISMATCH', $result['code']);
    }

    // ------------------------------------------------------------------
    // verifyHash() / computeHash()
    // ------------------------------------------------------------------

    public function test_verifyHash_accepts_valid_signature()
    {
        $gateway = $this->newGateway();
        $params = $this->signedParams(['vpc_Amount' => '100000']);
        $this->assertTrue($gateway->verifyHash($params));
    }

    public function test_verifyHash_rejects_tampered_params()
    {
        $gateway = $this->newGateway();
        $params = $this->signedParams(['vpc_Amount' => '100000']);
        $params['vpc_Amount'] = '100001';
        $this->assertFalse($gateway->verifyHash($params));
    }

    public function test_verifyHash_rejects_when_signature_missing()
    {
        $gateway = $this->newGateway();
        $params = ['vpc_Amount' => '100000'];
        $this->assertFalse($gateway->verifyHash($params));
    }

    public function test_verifyHash_rejects_when_secure_hash_key_empty()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize(['testMode' => true, 'sandbox_secure_hash' => '']);
        $params = $this->signedParams([]);
        $this->assertFalse($gateway->verifyHash($params));
    }

    public function test_computeHash_excludes_vpc_SecureHash_and_secure_hash_type()
    {
        $gateway = $this->newGateway();
        $fields = [
            'vpc_Amount' => '100000',
            'vpc_SecureHash' => 'SOMEHASH',
            'vpc_SecureHashType' => 'SHA256',
            'user_customer_name' => 'A',
            'AgainLink' => 'https://x.test', // not hashed
        ];

        $hash = $gateway->exposeComputeHash($fields);
        $expected = $this->expectedHash($fields, self::TEST_SECURE_HASH);
        $this->assertEquals($expected, $hash);
    }

    public function test_computeHash_is_uppercase_64_hex_chars()
    {
        $gateway = $this->newGateway();
        $hash = $gateway->exposeComputeHash(['vpc_Amount' => '100000']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $hash);
    }

    // ------------------------------------------------------------------
    // refund()
    // ------------------------------------------------------------------

    public function test_refund_returns_unsupported()
    {
        $result = $this->newGateway()->refund([]);
        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('does not support', strtolower($result['message']));
    }

    // ------------------------------------------------------------------
    // queryStatus()
    // ------------------------------------------------------------------

    protected function signedQueryResponse(array $overrides = [], $code = '0')
    {
        $result = array_merge([
            'vpc_Command' => 'queryDR',
            'vpc_Version' => '2',
            'vpc_MerchTxnRef' => 'JX00000042abcdef',
            'vpc_Merchant' => self::TEST_MERCHANT,
            'vpc_AccessCode' => self::TEST_ACCESS_CODE,
            'vpc_User' => self::TEST_USER,
            'vpc_Password' => self::TEST_PASSWORD,
            'vpc_DRExists' => 'Y',
            'vpc_TxnResponseCode' => $code,
            'vpc_TransactionNo' => 'VPC12345',
        ], $overrides);

        $result['vpc_SecureHash'] = $this->expectedHash($result, self::TEST_SECURE_HASH);
        return http_build_query($result, '', '&');
    }

    protected function mockRemoteQuery($body)
    {
        Functions\when('wp_remote_post')->justReturn(['body' => $body]);
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '';
        });
        Functions\when('is_wp_error')->justReturn(false);
    }

    public function test_queryStatus_returns_failed_without_query_credentials()
    {
        $gateway = new TestOnePayGateway();
        $gateway->initialize($this->sandboxConfig(['sandbox_user' => '', 'sandbox_password' => '']));
        $this->assertEquals('failed', $gateway->queryStatus('JX00000042abcdef'));
    }

    public function test_queryStatus_returns_failed_on_wp_error()
    {
        $gateway = $this->newGateway();
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_error', 'boom'));
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);

        $this->assertEquals('failed', $gateway->queryStatus('JX00000042abcdef'));
    }

    public function test_queryStatus_returns_failed_on_invalid_response_hash()
    {
        $gateway = $this->newGateway();
        $body = $this->signedQueryResponse();
        $body = str_replace('vpc_TxnResponseCode=0', 'vpc_TxnResponseCode=99', $body);
        $this->mockRemoteQuery($body);

        $this->assertEquals('failed', $gateway->queryStatus('JX00000042abcdef'));
    }

    public function test_queryStatus_returns_failed_when_dr_exists_not_y()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteQuery($this->signedQueryResponse(['vpc_DRExists' => 'N']));
        $this->assertEquals('failed', $gateway->queryStatus('JX00000042abcdef'));
    }

    public function test_queryStatus_returns_completed_on_code_0()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteQuery($this->signedQueryResponse([], '0'));
        $this->assertEquals('completed', $gateway->queryStatus('JX00000042abcdef'));
    }

    public function test_queryStatus_returns_pending_on_code_100_and_300()
    {
        $gateway = $this->newGateway();

        $this->mockRemoteQuery($this->signedQueryResponse([], '100'));
        $this->assertEquals('pending', $gateway->queryStatus('JX00000042abcdef'));

        $this->mockRemoteQuery($this->signedQueryResponse([], '300'));
        $this->assertEquals('pending', $gateway->queryStatus('JX00000042abcdef'));
    }

    public function test_queryStatus_returns_failed_on_other_code()
    {
        $gateway = $this->newGateway();
        $this->mockRemoteQuery($this->signedQueryResponse([], '99'));
        $this->assertEquals('failed', $gateway->queryStatus('JX00000042abcdef'));
    }

    public function test_queryStatus_posts_to_query_dr_endpoint()
    {
        $gateway = $this->newGateway();
        $captured = null;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$captured) {
            $captured = ['url' => $url, 'args' => $args];
            return ['body' => ''];
        });
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);

        $gateway->queryStatus('JX00000042abcdef');

        $this->assertNotNull($captured);
        $this->assertEquals(OnePayGateway::QUERY_URL_TEST, $captured['url']);
        $this->assertStringContainsString('vpc_Command=queryDR', $captured['args']['body']);
        $this->assertStringContainsString('vpc_SecureHash=', $captured['args']['body']);
    }

    // ------------------------------------------------------------------
    // getSettingsFields()
    // ------------------------------------------------------------------

    public function test_getSettingsFields_contains_all_expected_keys()
    {
        $fields = $this->newGateway()->getSettingsFields();

        foreach ([
            'testMode', 'locale', 'card_list',
            'sandbox_merchant', 'sandbox_access_code', 'sandbox_secure_hash',
            'sandbox_user', 'sandbox_password',
            'production_merchant', 'production_access_code', 'production_secure_hash',
            'production_user', 'production_password',
        ] as $key) {
            $this->assertArrayHasKey($key, $fields);
        }
    }

    public function test_getSettingsFields_field_types()
    {
        $fields = $this->newGateway()->getSettingsFields();

        $this->assertEquals('checkbox', $fields['testMode']['type']);
        $this->assertEquals('select', $fields['locale']['type']);
        $this->assertEquals('text', $fields['card_list']['type']);
        $this->assertEquals('text', $fields['sandbox_merchant']['type']);
        $this->assertEquals('password', $fields['sandbox_password']['type']);
        $this->assertEquals('password', $fields['production_password']['type']);
    }

    public function test_getSettingsFields_sandbox_defaults()
    {
        $fields = $this->newGateway()->getSettingsFields();

        $this->assertEquals('1', $fields['testMode']['default']);
        $this->assertEquals('vn', $fields['locale']['default']);
        $this->assertEquals(self::TEST_MERCHANT, $fields['sandbox_merchant']['default']);
        $this->assertEquals(self::TEST_ACCESS_CODE, $fields['sandbox_access_code']['default']);
        $this->assertEquals(self::TEST_SECURE_HASH, $fields['sandbox_secure_hash']['default']);
        $this->assertEquals(self::TEST_USER, $fields['sandbox_user']['default']);
        $this->assertEquals(self::TEST_PASSWORD, $fields['sandbox_password']['default']);
    }

    public function test_getSettingsFields_production_fields_have_no_defaults()
    {
        $fields = $this->newGateway()->getSettingsFields();

        $this->assertArrayNotHasKey('default', $fields['production_merchant']);
        $this->assertArrayNotHasKey('default', $fields['production_secure_hash']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function signedParams(array $overrides = []): array
    {
        $params = array_merge([
            'vpc_Version' => '2',
            'vpc_Currency' => 'VND',
            'vpc_Command' => 'pay',
            'vpc_AccessCode' => self::TEST_ACCESS_CODE,
            'vpc_Merchant' => self::TEST_MERCHANT,
            'vpc_Locale' => 'vn',
            'vpc_ReturnURL' => 'https://example.com/return',
            'vpc_MerchTxnRef' => 'JX00000042abcdef',
            'vpc_OrderInfo' => 'Order JX00000042abcdef',
            'vpc_Amount' => '10000000',
        ], $overrides);

        $params['vpc_SecureHash'] = $this->expectedHash($params, self::TEST_SECURE_HASH);
        return $params;
    }
}
