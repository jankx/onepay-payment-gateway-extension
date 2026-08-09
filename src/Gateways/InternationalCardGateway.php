<?php
namespace Jankx\Extensions\Onepay\Gateways;

/**
 * OnePay international cards channel (Visa, Mastercard, JCB).
 *
 * Registers as the `onepay` gateway in the payment-system manager.
 *
 * @package Jankx\Extensions\Onepay
 */
class InternationalCardGateway extends OnePayGateway
{
    protected $gatewaySlug = 'onepay';

    protected $displayName = 'OnePay – International cards (Visa/Master/JCB)';

    protected $defaultCardList = 'INTERNATIONAL';
}
