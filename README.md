# OnePay Payment Gateway

OnePay (Napas) payment gateway for the `payment-system` extension. Registers two
gateway channels that appear in checkout:

| Gateway slug      | Channel                   | `vpc_CardList` |
| ----------------- | ------------------------- | -------------- |
| `onepay`          | International cards       | `INTERNATIONAL` |
| `onepay_domestic` | Domestic ATM / Napas cards | `DOMESTIC`      |

Both channels use the OnePay VPC hosted-redirect flow (integration spec ver 2.4):

1. `purchase()` builds the HTTPS-redirect to `/paygate/vpcpay.op`.
2. `completePurchase()` verifies the `vpc_SecureHash` + `vpc_TxnResponseCode`
   when the customer returns.
3. `queryStatus()` reconciles status via the QueryDR API.
4. An IPN endpoint confirms payments server-to-server.

## Requirements

- `payment-system` extension (loaded before this one alphabetically).
- PHP >= 8.0.

## Configuration

Admin → Jankx Theme Options → Payments → Gateways → **onepay** / **onepay_domestic**.

- `testMode`: enable to use the OnePay MTF test environment (default ON).
- `locale`: `vn` or `en` payment page language.
- `card_list`: override `vpc_CardList` (e.g. `INTERNATIONAL`, `DOMESTIC`, `QR`,
  `BNPL`, or a bank BIN like `970436`). Empty = allow all.
- `sandbox_*` / `production_*`: merchant, access code, secure hash key and
  QueryDR credentials for each environment.

Test credentials are pre-filled from the OnePay spec:

- Merchant: `TESTONEPAY`, Access code: `6BEB2546`, Hash key:
  `6D0870CDE5F24F34F3915FB0045120DB`, QueryDR user/password: `op01` / `op123456`.

## Endpoints

| Endpoint | Method | Purpose |
| -------- | ------ | ------- |
| `/wp-json/jankx/v1/onepay/ipn/{gateway}` | GET / POST | OnePay IPN callback |

Point the OnePay portal's IPN URL at the endpoint above (replace `{gateway}` with
`onepay` or `onepay_domestic`). The server replies with
`responsecode=1&desc=confirm-success` as required by OnePay.

## Flow notes

- `vpc_Amount` = amount (VND) × 100, no decimals.
- `vpc_MerchTxnRef` is a unique 40-char alphanumeric reference derived from the
  transaction ID.
- The secure hash is computed over all `vpc_*` / `user_*` params (excluding
  `vpc_SecureHash`), sorted alphabetically, joined `key=value&key=value` with
  RAW values, then HMAC-SHA256 (uppercase hex). `AgainLink` and `Title` are
  excluded from the hash.
- Refunds are not supported via the OnePay VPC API; refunds are done manually in
  the OnePay merchant portal.
