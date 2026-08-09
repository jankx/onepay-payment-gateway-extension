<?php
namespace Jankx\Extensions\Onepay\Gateways;

/**
 * OnePay domestic cards channel (ATM / Napas).
 *
 * Registers as the `onepay_domestic` gateway in the payment-system manager.
 *
 * @package Jankx\Extensions\Onepay
 */
class DomesticCardGateway extends OnePayGateway
{
    protected $gatewaySlug = 'onepay_domestic';

    protected $displayName = 'OnePay – Domestic ATM/Napas cards';

    protected $defaultCardList = 'DOMESTIC';
}
