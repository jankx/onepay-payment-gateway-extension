<?php
namespace Jankx\Extensions\Onepay\Gateways;

use Jankx\Extensions\PaymentSystem\Gateways\GatewayInterface;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

/**
 * Base OnePay VPC gateway.
 *
 * Implements the OnePay integration spec (hosted redirect):
 *
 *  - Payment request : HTTPS redirect GET to /paygate/vpcpay.op
 *  - Return flow     : verify vpc_SecureHash (HMAC-SHA256) + vpc_TxnResponseCode
 *  - Status query    : QueryDR API /msp/api/v1/vpc/invoices/queries
 *
 * Hash rule (doc II.8): take every `vpc_*` / `user_*` parameter (excluding
 * vpc_SecureHash / vpc_SecureHashType), sort the keys alphabetically, join as
 * `key=value&key=value` using RAW (non URL-encoded) values, then HMAC-SHA256
 * with the secure hash key.
 *
 * @package Jankx\Extensions\Onepay
 */
abstract class OnePayGateway implements GatewayInterface
{
    const PAYMENT_URL_TEST = 'https://mtf.onepay.vn/paygate/vpcpay.op';
    const PAYMENT_URL_PROD = 'https://onepay.vn/paygate/vpcpay.op';

    const QUERY_URL_TEST = 'https://mtf.onepay.vn/msp/api/v1/vpc/invoices/queries';
    const QUERY_URL_PROD = 'https://onepay.vn/msp/api/v1/vpc/invoices/queries';

    /**
     * Gateway slug registered in the GatewayManager.
     *
     * @var string
     */
    protected $gatewaySlug = 'onepay';

    /**
     * Human readable name shown in checkout / admin.
     *
     * @var string
     */
    protected $displayName = 'OnePay';

    /**
     * Default vpc_CardList for this channel (INTERNATIONAL / DOMESTIC).
     *
     * @var string
     */
    protected $defaultCardList = '';

    /**
     * Resolved gateway configuration.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Resolved live credentials after initialize().
     *
     * @var array
     */
    protected $credentials = [
        'merchant'     => '',
        'access_code'  => '',
        'secure_hash'  => '',
        'user'         => '',
        'password'     => '',
    ];

    public function getName(): string
    {
        return $this->displayName;
    }

    public function initialize(array $parameters): void
    {
        $this->config = wp_parse_args($parameters, [
            'testMode'   => false,
            'locale'     => 'vn',
            'card_list'  => '',
            'sandbox_merchant'    => '',
            'sandbox_access_code' => '',
            'sandbox_secure_hash' => '',
            'sandbox_user'        => '',
            'sandbox_password'    => '',
            'production_merchant'    => '',
            'production_access_code' => '',
            'production_secure_hash' => '',
            'production_user'        => '',
            'production_password'    => '',
        ]);

        $isTest = !empty($this->config['testMode']);
        $prefix = $isTest ? 'sandbox' : 'production';

        $this->credentials = [
            'merchant'    => (string) ($this->config["{$prefix}_merchant"] ?? ''),
            'access_code' => (string) ($this->config["{$prefix}_access_code"] ?? ''),
            'secure_hash' => (string) ($this->config["{$prefix}_secure_hash"] ?? ''),
            'user'        => (string) ($this->config["{$prefix}_user"] ?? ''),
            'password'    => (string) ($this->config["{$prefix}_password"] ?? ''),
        ];
    }

    /**
     * Whether the gateway can be used (both channels need a merchant,
     * access code and secure hash key).
     */
    public function isAvailable(): bool
    {
        return $this->credentials['merchant'] !== ''
            && $this->credentials['access_code'] !== ''
            && $this->credentials['secure_hash'] !== '';
    }

    /**
     * Build the OnePay payment request and return a redirect payload.
     *
     * @return array{status: string, redirectUrl: string, redirectMethod: string, redirectData: array, transactionId: string}
     */
    public function purchase(array $parameters): array
    {
        $transactionId = (string) ($parameters['transactionId'] ?? '');
        $merchTxnRef = $this->buildMerchTxnRef($transactionId);
        $amount = (float) ($parameters['amount'] ?? 0);
        $amountMinor = (int) round($amount * 100);

        $fields = [
            'vpc_Version'     => '2',
            'vpc_Currency'    => 'VND',
            'vpc_Command'     => 'pay',
            'vpc_AccessCode'  => $this->credentials['access_code'],
            'vpc_Merchant'    => $this->credentials['merchant'],
            'vpc_Locale'      => $this->getLocale(),
            'vpc_ReturnURL'   => (string) ($parameters['returnUrl'] ?? ''),
            'vpc_MerchTxnRef' => $merchTxnRef,
            'vpc_OrderInfo'   => $this->normalizeOrderInfo($parameters['description'] ?? '', $merchTxnRef),
            'vpc_Amount'      => (string) $amountMinor,
            'vpc_TicketNo'    => $this->getClientIp(),
        ];

        // Restrict the payment page to this channel's card list.
        $cardList = (string) ($this->config['card_list'] ?? '');
        if ($cardList === '') {
            $cardList = $this->defaultCardList;
        }
        if ($cardList !== '') {
            $fields['vpc_CardList'] = $cardList;
        }

        if (!empty($parameters['customer_email'])) {
            $fields['vpc_Customer_Email'] = substr(sanitize_email((string) $parameters['customer_email']), 0, 24);
        }
        if (!empty($parameters['customer_phone'])) {
            $fields['vpc_Customer_Phone'] = substr(preg_replace('/[^0-9]/', '', (string) $parameters['customer_phone']), 0, 16);
        }
        if (!empty($parameters['customer_name'])) {
            $fields['user_customer_name'] = substr($this->stripUnsafeChars((string) $parameters['customer_name']), 0, 64);
        }

        $fields['vpc_SecureHash'] = $this->computeHash($fields);

        // AgainLink and Title are NOT prefixed with vpc_ and are excluded
        // from the hash computation (doc II.8).
        $redirectUrl = $this->getPaymentUrl() . '?' . http_build_query(array_merge($fields, [
            'AgainLink' => (string) ($parameters['cancelUrl'] ?? home_url('/')),
            'Title'     => $this->getTitle(),
        ]), '', '&');

        // Persist the OnePay merchant reference on the transaction so the
        // ApiTracker (QueryDR) and IPN flows can look it up later.
        $this->persistMerchTxnRef($transactionId, $merchTxnRef);

        return [
            'status'         => 'redirect',
            'redirectUrl'    => $redirectUrl,
            'redirectMethod' => 'GET',
            'redirectData'   => $fields,
            'transactionId'  => $merchTxnRef,
        ];
    }

    /**
     * Verify the OnePay return URL response and map it to a status.
     *
     * @return array{status: string, transactionId: string, message: string, code?: string, raw?: array}
     */
    public function completePurchase(array $parameters): array
    {
        $params = is_array($parameters['request_params'] ?? null) ? $parameters['request_params'] : $parameters;

        if (!$this->verifyHash($params)) {
            return [
                'status'        => 'failed',
                'transactionId' => (string) ($params['vpc_MerchTxnRef'] ?? ''),
                'message'       => __('Invalid vpc_SecureHash from OnePay.', 'jankx'),
                'code'          => 'HASH_MISMATCH',
                'raw'           => $params,
            ];
        }

        $responseCode = (string) ($params['vpc_TxnResponseCode'] ?? '');
        $message = (string) ($params['vpc_Message'] ?? '');

        if ($responseCode === '0') {
            return [
                'status'        => 'success',
                'transactionId' => (string) ($params['vpc_TransactionNo'] ?? ''),
                'message'       => __('Payment successful.', 'jankx'),
                'raw'           => $params,
            ];
        }

        return [
            'status'        => 'failed',
            'transactionId' => (string) ($params['vpc_TransactionNo'] ?? ''),
            'message'       => $message !== '' ? $message : $this->getResponseCodeMessage($responseCode),
            'code'          => $responseCode,
            'raw'           => $params,
        ];
    }

    /**
     * OnePay VPC does not provide a refund API in the hosted-redirect flow.
     */
    public function refund(array $parameters): array
    {
        return [
            'status'  => 'failed',
            'message' => __('OnePay VPC does not support refunds via API. Please refund manually in the OnePay merchant portal.', 'jankx'),
        ];
    }

    /**
     * QueryDR API: reconcile the payment status.
     *
     * @return string 'completed' | 'pending' | 'failed'
     */
    public function queryStatus(string $transactionId): string
    {
        if ($this->credentials['user'] === '' || $this->credentials['password'] === '') {
            return 'failed';
        }

        $fields = [
            'vpc_Command'     => 'queryDR',
            'vpc_Version'     => '2',
            'vpc_MerchTxnRef' => $transactionId,
            'vpc_Merchant'    => $this->credentials['merchant'],
            'vpc_AccessCode'  => $this->credentials['access_code'],
            'vpc_User'        => $this->credentials['user'],
            'vpc_Password'    => $this->credentials['password'],
        ];
        $fields['vpc_SecureHash'] = $this->computeHash($fields);

        $response = wp_remote_post($this->getQueryUrl(), [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => http_build_query($fields, '', '&'),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return 'failed';
        }

        parse_str(wp_remote_retrieve_body($response), $result);

        if (!$this->verifyHash($result)) {
            return 'failed';
        }

        $exists = (string) ($result['vpc_DRExists'] ?? '');
        if ($exists !== 'Y') {
            return 'failed';
        }

        $code = (string) ($result['vpc_TxnResponseCode'] ?? '');
        if ($code === '0') {
            return 'completed';
        }
        if (in_array($code, ['100', '300'], true)) {
            return 'pending';
        }

        return 'failed';
    }

    /**
     * Admin settings fields (keys map to the saved option array).
     */
    public function getSettingsFields(): array
    {
        return [
            'testMode' => [
                'label'       => __('Test mode (MTF)', 'jankx'),
                'type'        => 'checkbox',
                'description' => __('Enable to use the OnePay MTF test environment.', 'jankx'),
                'default'     => '1',
            ],
            'locale' => [
                'label'   => __('Payment page language', 'jankx'),
                'type'    => 'select',
                'options' => ['vn' => 'Tiếng Việt', 'en' => 'English'],
                'default' => 'vn',
            ],
            'card_list' => [
                'label'       => __('Card list (vpc_CardList)', 'jankx'),
                'type'        => 'text',
                'description' => __('INTERNATIONAL, DOMESTIC, QR, BNPL or a bank BIN (e.g. 970436). Empty = allow all.', 'jankx'),
                'default'     => $this->defaultCardList,
            ],
            'sandbox_merchant' => [
                'label'   => __('Merchant ID (test)', 'jankx'),
                'type'    => 'text',
                'default' => 'TESTONEPAY',
            ],
            'sandbox_access_code' => [
                'label'   => __('Access Code (test)', 'jankx'),
                'type'    => 'text',
                'default' => '6BEB2546',
            ],
            'sandbox_secure_hash' => [
                'label'   => __('Secure Hash Key (test)', 'jankx'),
                'type'    => 'text',
                'default' => '6D0870CDE5F24F34F3915FB0045120DB',
            ],
            'sandbox_user' => [
                'label'   => __('QueryDR User (test)', 'jankx'),
                'type'    => 'text',
                'default' => 'op01',
            ],
            'sandbox_password' => [
                'label'   => __('QueryDR Password (test)', 'jankx'),
                'type'    => 'password',
                'default' => 'op123456',
            ],
            'production_merchant' => [
                'label' => __('Merchant ID (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_access_code' => [
                'label' => __('Access Code (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_secure_hash' => [
                'label' => __('Secure Hash Key (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_user' => [
                'label' => __('QueryDR User (production)', 'jankx'),
                'type'  => 'text',
            ],
            'production_password' => [
                'label' => __('QueryDR Password (production)', 'jankx'),
                'type'  => 'password',
            ],
        ];
    }

    /**
     * Compute the OnePay vpc_SecureHash (doc II.8).
     *
     * @param array $fields Parameter set (vpc_* / user_* only). vpc_SecureHash
     *                      and vpc_SecureHashType are excluded automatically.
     */
    protected function computeHash(array $fields): string
    {
        $hashData = [];

        foreach ($fields as $key => $value) {
            if ($key === 'vpc_SecureHash' || $key === 'vpc_SecureHashType') {
                continue;
            }
            if (strpos($key, 'vpc_') !== 0 && strpos($key, 'user_') !== 0) {
                continue;
            }
            $hashData[$key] = (string) $value;
        }

        ksort($hashData);

        $pairs = [];
        foreach ($hashData as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return strtoupper(hash_hmac('sha256', implode('&', $pairs), $this->credentials['secure_hash']));
    }

    /**
     * Verify an incoming OnePay callback signature.
     */
    public function verifyHash(array $params): bool
    {
        $expected = strtoupper((string) ($params['vpc_SecureHash'] ?? ''));
        if ($expected === '' || $this->credentials['secure_hash'] === '') {
            return false;
        }

        return hash_equals($expected, $this->computeHash($params));
    }

    protected function getPaymentUrl(): string
    {
        return $this->isTestMode() ? self::PAYMENT_URL_TEST : self::PAYMENT_URL_PROD;
    }

    protected function getQueryUrl(): string
    {
        return $this->isTestMode() ? self::QUERY_URL_TEST : self::QUERY_URL_PROD;
    }

    protected function isTestMode(): bool
    {
        return !empty($this->config['testMode']);
    }

    protected function getLocale(): string
    {
        return (string) ($this->config['locale'] ?? 'vn') === 'en' ? 'en' : 'vn';
    }

    protected function getTitle(): string
    {
        return substr(__('OnePay Secure Payment', 'jankx'), 0, 64);
    }

    /**
     * Build a unique vpc_MerchTxnRef (max 40 chars, alphanumeric only).
     */
    protected function buildMerchTxnRef(string $transactionId): string
    {
        $ref = 'JX' . str_pad((string) $transactionId, 8, '0', STR_PAD_LEFT) . substr(md5(uniqid('', true)), 0, 10);
        return substr($ref, 0, 40);
    }

    protected function normalizeOrderInfo(string $description, string $merchTxnRef): string
    {
        $info = $this->stripUnsafeChars($description);
        if ($info === '') {
            $info = 'Order ' . $merchTxnRef;
        }
        return substr($info, 0, 34);
    }

    protected function stripUnsafeChars(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9 _.-]/', '', $value) ?? '';
    }

    protected function getClientIp(): string
    {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return substr($ip, 0, 15);
    }

    protected function persistMerchTxnRef(string $transactionId, string $merchTxnRef): void
    {
        if (!is_numeric($transactionId) || (int) $transactionId <= 0) {
            return;
        }

        $transaction = new Transaction((int) $transactionId);
        if ($transaction->getId()) {
            $transaction->setTransactionId($merchTxnRef);
        }
    }

    protected function getResponseCodeMessage(string $code): string
    {
        $messages = [
            '0'  => __('Success.', 'jankx'),
            '1'  => __('Payment failed.', 'jankx'),
            '2'  => __('Payment failed.', 'jankx'),
            '3'  => __('Card not supported.', 'jankx'),
            '4'  => __('Payment failed.', 'jankx'),
            '5'  => __('Payment cancelled by customer.', 'jankx'),
            '6'  => __('Payment failed.', 'jankx'),
            '7'  => __('Payment failed.', 'jankx'),
            '8'  => __('Payment failed.', 'jankx'),
            '9'  => __('Payment failed.', 'jankx'),
            '99' => __('Temporary system error. Please try again later.', 'jankx'),
        ];

        return $messages[$code] ?? __('Payment failed.', 'jankx');
    }
}
