<?php
namespace Jankx\Extensions\Onepay\Rest;

use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

/**
 * OnePay IPN (server-to-server) endpoint.
 *
 * OnePay calls the configured IPN URL after a payment is processed. The
 * merchant must set this URL to the OnePay IPN setting so the server can
 * confirm the payment independently of the customer's return redirect.
 *
 * Endpoint:  POST/GET /wp-json/jankx/v1/onepay/ipn/{gateway}
 * Response:  plain text `responsecode=1&desc=confirm-success`
 *
 * @package Jankx\Extensions\Onepay
 */
class OnePayIpnController
{
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('jankx/v1', '/onepay/ipn/(?P<gateway>[a-zA-Z0-9_-]+)', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'gateway' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gatewayName = $request->get_param('gateway');
        $params = $request->get_params();

        $gateway = GatewayManager::getInstance()->get($gatewayName);
        if (!$gateway || !$gateway->verifyHash($params)) {
            return $this->respond(0, 'invalid-hash');
        }

        $merchTxnRef = (string) ($params['vpc_MerchTxnRef'] ?? '');
        $transaction = $merchTxnRef !== '' ? Transaction::find($merchTxnRef, $gatewayName) : null;

        if ($transaction && (string) ($params['vpc_TxnResponseCode'] ?? '') === '0') {
            $transaction->setTransactionId((string) ($params['vpc_TransactionNo'] ?? ''));
            $transaction->setRawResponse(json_encode($params));
            $transaction->setStatus(Transaction::STATUS_COMPLETED);
            do_action('jankx/payment/webhook_processed', $transaction, $params);
        }

        // Always confirm to OnePay so it stops retrying.
        return $this->respond(1, 'confirm-success');
    }

    protected function respond(int $code, string $desc): \WP_REST_Response
    {
        return new \WP_REST_Response(
            "responsecode={$code}&desc={$desc}",
            200,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }
}
