<?php
/**
 * Infynipay API client — the integration / business layer.
 *
 * All HTTP communication with the Infynipay services lives here, isolated
 * from the plugin's event handling and from any presentation (HTML/CSS/JS).
 * The class is constructed with the active environment's base URLs + app
 * credentials and a logger callback, and returns plain data structures.
 */

declare(strict_types=1);

namespace J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;

final class ApiClient
{
    /** @var callable */
    private $logger;

    /**
     * @param string   $appApiUrl     Base URL for the app/license API (PAYMENT_APP_API_URL).
     * @param string   $paymentApiUrl Base URL for the payment API (PAYMENT_API_URL).
     * @param string   $appId         Active app id (sandbox or live).
     * @param string   $token         Active bearer token (sandbox or live).
     * @param callable $logger        function(string $message, int $priority): void
     */
    public function __construct(
        private string $appApiUrl,
        private string $paymentApiUrl,
        private string $appId,
        private string $token,
        callable $logger
    ) {
        $this->logger = $logger;
    }

    /** True when the active credentials are missing. */
    public function needsSetup(): bool
    {
        return $this->appId === '' || $this->token === '';
    }

    /**
     * Fetch the configured checkout methods (with provider icons inlined).
     *
     * @return array Decoded method groups, or [] on any error.
     */
    public function fetchCheckoutMethods(): array
    {
        if ($this->needsSetup()) {
            return [];
        }

        $apiUrl = rtrim($this->appApiUrl, '/')
            . '/api/apps/' . urlencode($this->appId) . '/payment-profiles/payment-checkout-methods';

        try {
            $response = HttpFactory::getHttp()->get(
                $apiUrl,
                ['accept' => 'text/plain', 'Authorization' => 'Bearer ' . $this->token],
                15
            );

            if ($response->code !== 200) {
                $this->log('fetchCheckoutMethods: HTTP ' . $response->code, Log::WARNING);
                return [];
            }

            $data = json_decode((string) $response->body, true);

            return is_array($data) ? $this->inlineCheckoutMethodIcons($data) : [];
        } catch (\RuntimeException $e) {
            $this->log('fetchCheckoutMethods: ' . $e->getMessage(), Log::WARNING);
            return [];
        }
    }

    /**
     * Retrieve an existing payment session by order reference id.
     *
     * @return array|null Decoded payment object, or null on any error/non-200.
     */
    public function getPaymentByReference(string $orderId, int $timeout = 15): ?array
    {
        if ($this->needsSetup() || $orderId === '') {
            return null;
        }

        $url = rtrim($this->paymentApiUrl, '/')
            . '/api/apps/' . urlencode($this->appId) . '/payments/referenceId:' . urlencode($orderId);

        try {
            $response = HttpFactory::getHttp()->get(
                $url,
                ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->token],
                $timeout
            );

            if ($response->code !== 200) {
                return null;
            }

            $data = json_decode((string) $response->body, true);

            return is_array($data) ? $data : null;
        } catch (\RuntimeException $e) {
            $this->log('getPaymentByReference: ' . $e->getMessage(), Log::WARNING);
            return null;
        }
    }

    /**
     * Create a hosted payment session for an order.
     *
     * @return string|null One of: the redirect URL, the sentinel 'APP_INACTIVE',
     *                     the sentinel 'ALREADY_PAID', or null on failure.
     */
    public function createHostedPayment(
        object $order,
        float $amount,
        string $currency,
        string $returnUrl,
        string $webhookUrl
    ): ?string {
        $orderId = (string) $order->order_id;

        // J2Commerce surfaces customer info on the order row + order info table.
        $orderInfo = method_exists($order, 'getOrderInformation') ? $order->getOrderInformation() : null;
        $info      = is_object($orderInfo) ? $orderInfo : new \stdClass();

        $firstName = (string) ($info->billing_first_name ?? $order->first_name ?? '');
        $lastName  = (string) ($info->billing_last_name  ?? $order->last_name  ?? '');
        $email     = (string) ($order->user_email ?? $info->billing_email ?? '');
        $phone     = (string) ($info->billing_phone_1 ?? $info->billing_mobile_phone ?? '');
        $address1  = (string) ($info->billing_address_1 ?? '');
        $address2  = (string) ($info->billing_address_2 ?? '');
        $zip       = (string) ($info->billing_zip ?? '');
        $city      = (string) ($info->billing_city ?? '');
        $country   = (string) ($info->billing_country_name ?? '');
        $countryCd = (string) ($info->billing_country_code ?? '');
        $userId    = (int) ($order->user_id ?? 0);

        // Infynipay's ValidateAmount requires the line items to reconcile with
        // the amount breakdown: total + totalTax == SUM((item.amount + item.tax) * qty).
        // We send the order as a SINGLE net line item built from the same breakdown,
        // so that equality holds exactly regardless of J2Commerce per-line rounding.
        $amountBreakdown = $this->buildOrderAmountBreakdown($order, $amount);

        $items = [[
            'name'        => 'Order ' . $orderId,
            'description' => '',
            'amount'      => $amountBreakdown['total'],
            'quantity'    => 1,
            'tax'         => $amountBreakdown['totalTax'],
            'sku'         => $orderId,
            'category'    => 'PhysicalGoods',
        ]];

        $payload = [
            'referenceId'                 => $orderId,
            'amount'                      => round($amount, 2),
            'currency'                    => $currency,
            'autoCapture'                 => true,
            'showPayRecurringInCheckout'  => false,
            'webhookUrl'                  => $webhookUrl,
            'returnUrl'                   => $returnUrl,
            'customerOrder'               => [
                'customer' => [
                    'customerId'         => $userId > 0 ? (string) $userId : $this->generateUuid(),
                    'accountCreatedTime' => null,
                    'firstName'          => $firstName,
                    'lastName'           => $lastName,
                    'email'              => $email,
                    'phoneNumber'        => $phone,
                    'mobileNumber'       => $phone,
                    'address'            => [
                        'streetAddressLine1' => $address1,
                        'streetAddressLine2' => $address2,
                        'zipCode'            => $zip,
                        'city'               => $city,
                        'state'              => '',
                        'country'            => $country,
                        'countryCode'        => $countryCd,
                    ],
                ],
                'orderId'     => $orderId,
                'description' => 'Order ' . $orderId,
                'customId'    => null,
                'amount'      => $amountBreakdown,
                'items'       => $items,
            ],
        ];

        $apiUrl = rtrim($this->paymentApiUrl, '/')
            . '/api/apps/' . urlencode($this->appId) . '/payments/hosted-page';

        $this->log(sprintf('[Order %s] POST %s amount=%.2f %s', $orderId, $apiUrl, $amount, $currency));

        try {
            $response = HttpFactory::getHttp()->post(
                $apiUrl,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $this->token,
                ],
                30
            );

            if ($response->code < 200 || $response->code >= 300) {
                $this->log(sprintf('[Order %s] Hosted payment failed. HTTP=%d body=%s',
                    $orderId, $response->code, substr((string) $response->body, 0, 2000)), Log::ERROR);

                if ($response->code === 400) {
                    $errorBody = json_decode((string) $response->body, true);
                    if (is_array($errorBody) && ($errorBody['TypeName'] ?? '') === 'AppInactiveException') {
                        return 'APP_INACTIVE';
                    }

                    $existing = $this->fetchExistingPaymentRedirectUrl($orderId);
                    if ($existing !== null) {
                        return $existing;
                    }
                }

                return null;
            }

            $data        = json_decode((string) $response->body, true);
            $redirectUrl = is_array($data) ? (string) ($data['redirectUrl'] ?? '') : '';

            if ($redirectUrl === '') {
                $this->log(sprintf('[Order %s] No redirectUrl in response.', $orderId), Log::ERROR);
                return null;
            }

            $this->log(sprintf('[Order %s] Hosted payment created. paymentId=%s',
                $orderId, $data['paymentId'] ?? '?'));

            return $redirectUrl;
        } catch (\RuntimeException $e) {
            $this->log(sprintf('[Order %s] Hosted payment exception: %s', $orderId, $e->getMessage()), Log::ERROR);
            return null;
        }
    }

    /**
     * Recover the redirect URL for an order that already has a payment session.
     *
     * @return string|null The redirect URL, 'ALREADY_PAID' when captured, or null.
     */
    public function fetchExistingPaymentRedirectUrl(string $orderId): ?string
    {
        $data = $this->getPaymentByReference($orderId);

        if ($data === null) {
            return null;
        }

        $state       = strtolower((string) ($data['paymentState'] ?? ''));
        $redirectUrl = (string) ($data['redirectUrl'] ?? '');

        if ($state === 'captured') {
            return 'ALREADY_PAID';
        }

        return $redirectUrl !== '' ? $redirectUrl : null;
    }

    /**
     * Build the customerOrder.amount breakdown so it passes Infynipay's
     * server-side ValidateAmount check, which enforces TWO equalities:
     *
     *   (1) amount.total + amount.totalTax
     *         == SUM over items of (item.amount + item.tax) * item.quantity
     *   (2) <payment amount>
     *         == total + totalTax + shipping + insurance + handling
     *            - discount - shippingDiscount
     *
     * J2Commerce stores flat per-order totals already in the order currency. The
     * authoritative number is the charge (order total); we derive the NET items
     * subtotal from it so rule (2) holds exactly. The caller emits a single line
     * item equal to this net subtotal (+ tax) so rule (1) holds exactly too. Using
     * one consolidated line avoids per-line rounding drift that would break rule (1).
     *
     * @return array{total:float,handling:int,insurance:int,discount:float,shipping:float,shippingDiscount:int,totalTax:float}
     */
    public function buildOrderAmountBreakdown(object $order, float $paymentAmount): array
    {
        $paymentAmount = round($paymentAmount, 2);
        $tax           = round((float) ($order->order_tax ?? 0), 2);
        $shipping      = round((float) ($order->order_shipping ?? 0), 2);
        $discount      = round(abs((float) ($order->order_discount ?? 0)), 2);

        // Net goods subtotal (excluding tax), derived so rule (2) is exact:
        //   payment = netSubtotal + tax + shipping - discount
        $netSubtotal = round($paymentAmount - $tax - $shipping + $discount, 2);

        // Guard against unusual totals (e.g. a discount larger than the goods) that
        // would make the subtotal negative. Fall back to a single net line equal to
        // the full charge with no tax/shipping/discount split - still passes both rules.
        if ($netSubtotal < 0) {
            return [
                'total'            => $paymentAmount,
                'handling'         => 0,
                'insurance'        => 0,
                'discount'         => 0,
                'shipping'         => 0,
                'shippingDiscount' => 0,
                'totalTax'         => 0,
            ];
        }

        return [
            'total'            => $netSubtotal,
            'handling'         => 0,
            'insurance'        => 0,
            'discount'         => $discount,
            'shipping'         => $shipping,
            'shippingDiscount' => 0,
            'totalTax'         => $tax,
        ];
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /** Inline each provider's SVG icons as base64 data URIs (CDN MIME workaround). */
    private function inlineCheckoutMethodIcons(array $methods): array
    {
        foreach ($methods as &$group) {
            if (!is_array($group) || empty($group['paymentCheckoutMethodItems'])) {
                continue;
            }

            foreach ($group['paymentCheckoutMethodItems'] as &$item) {
                if (!is_array($item) || empty($item['paymentProfile']['paymentProvider'])) {
                    continue;
                }

                foreach (['iconUri1', 'iconUri2'] as $key) {
                    $item['paymentProfile']['paymentProvider'][$key] = $this->inlineSvgIcon(
                        (string) ($item['paymentProfile']['paymentProvider'][$key] ?? '')
                    );
                }
            }
            unset($item);
        }
        unset($group);

        return $methods;
    }

    private function inlineSvgIcon(string $iconUri): string
    {
        static $cache = [];

        if ($iconUri === '' || stripos($iconUri, '.svg') === false || str_starts_with($iconUri, 'data:image/')) {
            return $iconUri;
        }

        if (isset($cache[$iconUri])) {
            return $cache[$iconUri];
        }

        try {
            $response = HttpFactory::getHttp()->get($iconUri, ['Accept' => 'image/svg+xml,*/*'], 10);
        } catch (\RuntimeException $e) {
            return $cache[$iconUri] = $iconUri;
        }

        $svg = trim((string) $response->body);

        if ($response->code !== 200 || stripos($svg, '<svg') === false) {
            return $cache[$iconUri] = $iconUri;
        }

        return $cache[$iconUri] = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function log(string $message, int $priority = Log::INFO): void
    {
        ($this->logger)($message, $priority);
    }
}
