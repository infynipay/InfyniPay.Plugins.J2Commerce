<?php
/**
 * Infynipay Payment Plugin for J2Commerce 6.x.
 *
 * Joomla 4 / 5 / 6 - namespace: J2Commerce\Plugin\J2Commerce\PaymentInfynipay
 *               class: Infynipay (\J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Extension\Infynipay)
 *
 * Plugin group : j2commerce
 * Element      : payment_infynipay
 *
 * J2Commerce events handled
 * -------------------------
 * onJ2CommerceAcceptSubscriptionPayment  - returns false (no subscriptions)
 * onJ2CommerceCalculateFees              - no-op
 * onJ2CommerceGetPaymentOptions          - geozone + subtotal gate
 * onJ2CommerceGetPaymentPlugins          - advertise this plugin in the picker
 * onJ2CommercePrePayment                 - render the Infynipay payment card
 * onJ2CommercePostPayment                - dispatch on paction (display/process/cancel/callback)
 * onJ2CommerceProcessWebhook             - server-to-server IPN handler
 * onAjaxInfynipay                      - OAuth callback / checkout-methods JSON / pay-now redirect
 * onBeforeRender                         - inject the small provider-icon strip on the checkout page
 */

declare(strict_types=1);

namespace J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Extension;

\defined('_JEXEC') or die;


use J2Commerce\Component\J2commerce\Administrator\Helper\OrderHistoryHelper;
use J2Commerce\Component\J2commerce\Administrator\Library\Plugins\Payment;
use J2Commerce\Component\J2commerce\Administrator\Library\Plugins\PluginLayoutTrait;
use J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Service\ApiClient;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

final class Infynipay extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use PluginLayoutTrait;

    protected $autoloadLanguage = true;

    protected $_name = 'payment_infynipay';

    protected $_type = 'j2commerce';

    /** Single source of truth for Infynipay service URLs - also referenced by Field classes. */
    public const PANEL_URL           = 'https://console.infynipay.com';
    public const PAYMENT_APP_API_URL = 'https://appapi.infynipay.com';
    public const PAYMENT_API_URL     = 'https://api.infynipay.com';

    private ?Payment $payment = null;

    private DispatcherInterface $pluginDispatcher;

    private array $pluginConfig;

    public function __construct(
        DispatcherInterface $dispatcher,
        array $config,
        private Language $language,
        DatabaseInterface $db
    ) {
        parent::__construct($dispatcher, $config);

        $this->pluginDispatcher = $dispatcher;
        $this->pluginConfig     = $config;
        $this->setDatabase($db);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onJ2CommerceAcceptSubscriptionPayment' => 'onAcceptSubscriptionPayment',
            'onJ2CommerceCalculateFees'             => 'onCalculateFees',
            'onJ2CommerceGetPaymentOptions'         => 'onGetPaymentOptions',
            'onJ2CommerceGetPaymentPlugins'         => 'onGetPaymentPlugins',
            'onJ2CommercePrePayment'                => 'onPrePayment',
            'onJ2CommercePostPayment'               => 'onPostPayment',
            'onJ2CommerceProcessWebhook'            => 'onProcessWebhook',
            'onJ2CommerceAfterAdminOrderPayment'     => 'onAfterAdminOrderPayment',
            'onAjaxInfynipay'                     => 'onAjaxInfynipay',
            'onBeforeRender'                        => 'onBeforeRender',
        ];
    }

    // -----------------------------------------------------------------------
    // J2Commerce lifecycle events
    // -----------------------------------------------------------------------

    public function onAcceptSubscriptionPayment(Event $event): void
    {
        if (($event->getArguments()[0] ?? null) !== $this->_name) {
            return;
        }
        $event->setArgument('result', false);
    }

    public function onCalculateFees(Event $event): void
    {
        // Infynipay does not add surcharges; this is intentionally a no-op.
        if (($event->getArguments()[0] ?? null) !== $this->_name) {
            return;
        }
    }

    public function onGetPaymentOptions(Event $event): void
    {
        $args    = $event->getArguments();
        $element = $args[0] ?? null;
        $order   = $args[1] ?? null;

        if ($element !== $this->_name || $order === null) {
            return;
        }

        $found = true;

        $geozoneId = (int) $this->params->get('geozone_id', 0);

        if ($geozoneId > 0) {
            $order->setAddress();
            $address = $order->getBillingAddress();
            $found   = $this->checkGeozone($geozoneId, $address);
        }

        if ($found) {
            $found = $this->checkSubtotalLimits((float) $order->order_subtotal);
        }

        $event->setArgument('result', $found);
    }

    public function onGetPaymentPlugins(Event $event): void
    {
        // Logo shown next to the method name in the checkout payment list.
        // Falls back to the bundled Infynipay logo when no custom image is set.
        $image = (string) $this->params->get('display_image', '');

        if ($image === '') {
            $image = Uri::root() . 'plugins/j2commerce/payment_infynipay/images/logos/infynipay-logo-light.png';
        }

        $result   = $event->getArgument('result', []);
        $result[] = [
            'element' => $this->_name,
            'name'    => Text::_($this->params->get('display_name', 'PLG_J2COMMERCE_PAYMENT_INFYNIPAY')),
            'image'   => $image,
        ];
        $event->setArgument('result', $result);
    }

    public function onPrePayment(Event $event): void
    {
        $args    = $event->getArguments();
        $element = $args[0] ?? '';
        $data    = $args[1] ?? [];

        if ($element !== $this->_name) {
            return;
        }

        $result   = $event->getArgument('result', []);
        $result[] = $this->prePayment((array) $data);
        $event->setArgument('result', $result);
    }

    public function onPostPayment(Event $event): void
    {
        $args    = $event->getArguments();
        $element = $args[0] ?? '';
        $data    = $args[1] ?? [];

        if ($element !== $this->_name) {
            return;
        }

        $result   = $event->getArgument('result', []);
        $result[] = $this->postPayment((object) $data);
        $event->setArgument('result', $result);
    }

    public function onProcessWebhook(Event $event): void
    {
        $args    = $event->getArguments();
        $element = $args[0] ?? '';

        if ($element !== $this->_name) {
            return;
        }

        $this->handleWebhook();
    }

    /**
     * Admin order detail: render a Infynipay card under the order's payment
     * section, showing the Infynipay logo + payment id and a button that opens
     * the payment directly in the Infynipay console. Only renders for orders
     * paid with Infynipay that already have a payment session.
     *
     * Styling uses J2Commerce's own admin card/Bootstrap classes (no injected
     * CSS); the only inline style is the logo's intrinsic sizing.
     */
    public function onAfterAdminOrderPayment(Event $event): void
    {
        $order = $event->getArguments()[0] ?? null;

        if (!is_object($order) || ($order->orderpayment_type ?? '') !== $this->_name || $this->needsSetup()) {
            return;
        }

        $orderId = (string) ($order->order_id ?? '');

        if ($orderId === '') {
            return;
        }

        // The Infynipay payment id is loaded in the browser (skeleton -> value)
        // so the order page never blocks on the API call.
        $infoUrl = Uri::base()
            . 'index.php?option=com_ajax&plugin=infynipay&group=j2commerce&format=raw'
            . '&action=orderpaymentinfo&order_id=' . urlencode($orderId)
            . '&' . Session::getFormToken() . '=1';

        $logoBase  = Uri::root() . 'plugins/j2commerce/payment_infynipay/images/logos/';
        $logoLight = htmlspecialchars($logoBase . 'infynipay-logo-light.png', ENT_QUOTES, 'UTF-8');
        $logoDark  = htmlspecialchars($logoBase . 'infynipay-logo-dark.png', ENT_QUOTES, 'UTF-8');
        $safeInfo  = htmlspecialchars($infoUrl, ENT_QUOTES, 'UTF-8');
        $idLabel   = htmlspecialchars(Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_PAYMENT_ID'), ENT_QUOTES, 'UTF-8');
        $viewLabel = htmlspecialchars(Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_VIEW_IN_CONSOLE'), ENT_QUOTES, 'UTF-8');

        // Logo + shimmer-skeleton styling, registered in the document head (not
        // inline in the card body). Light variant (dark artwork) for light themes;
        // dark variant (light artwork) for OS / Bootstrap / Joomla dark mode.
        Factory::getDocument()->addStyleDeclaration(
            '.ph-od-logo{height:52px;width:auto;max-width:230px;object-fit:contain;vertical-align:middle}'
            . '.ph-od-logo--dk{display:none}'
            . '@media(prefers-color-scheme:dark){.ph-od-logo--lt{display:none}.ph-od-logo--dk{display:inline-block}}'
            . '[data-bs-theme="dark"] .ph-od-logo--lt,.joomla-color-scheme-dark .ph-od-logo--lt{display:none}'
            . '[data-bs-theme="dark"] .ph-od-logo--dk,.joomla-color-scheme-dark .ph-od-logo--dk{display:inline-block}'
            . '.ph-od-sk{position:relative;overflow:hidden;display:inline-block;background:#e2e8f0;border-radius:6px;vertical-align:middle}'
            . '.ph-od-sk::after{content:"";position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,rgba(255,255,255,0),rgba(255,255,255,.65) 50%,rgba(255,255,255,0));animation:ph-od-sweep 1.2s infinite}'
            . '@keyframes ph-od-sweep{100%{transform:translateX(100%)}}'
            . '[data-bs-theme="dark"] .ph-od-sk,.joomla-color-scheme-dark .ph-od-sk{background:#334155}'
            . '@media(prefers-color-scheme:dark){.ph-od-sk{background:#334155}.ph-od-sk::after{background:linear-gradient(90deg,rgba(255,255,255,0),rgba(148,163,184,.28) 50%,rgba(255,255,255,0))}}'
        );

        $html = '<div id="ph-od-card" data-url="' . $safeInfo . '" class="j2c-detail-card-payment j2c-detail-card d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 mb-3">'
            . '<div class="d-flex align-items-center">'
            . '<div class="me-3">'
            . '<img src="' . $logoLight . '" alt="Infynipay" class="ph-od-logo ph-od-logo--lt">'
            . '<img src="' . $logoDark . '" alt="" aria-hidden="true" class="ph-od-logo ph-od-logo--dk">'
            . '</div>'
            . '<div>'
            . '<div class="fw-bold fs-6">Infynipay</div>'
            . '<div class="text-muted small">' . $idLabel . ': '
            . '<strong class="text-body" id="ph-od-id"><span class="ph-od-sk" style="width:160px;height:12px"></span></strong></div>'
            . '</div>'
            . '</div>'
            . '<span id="ph-od-btn-sk" class="ph-od-sk" style="width:120px;height:31px;border-radius:6px"></span>'
            . '<a id="ph-od-btn" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary rounded-1" style="display:none">'
            . $viewLabel . '</a>'
            . '</div>';

        Factory::getDocument()->addScriptDeclaration(<<<'JS'
(function () {
    function run() {
        var card = document.getElementById('ph-od-card');
        if (!card || card.dataset.phDone) { return; }
        card.dataset.phDone = '1';
        function fail() { card.style.display = 'none'; }
        fetch(card.getAttribute('data-url'), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.paymentId) { fail(); return; }
                var idEl = document.getElementById('ph-od-id');
                if (idEl) { idEl.textContent = d.paymentId; }
                var sk = document.getElementById('ph-od-btn-sk');
                if (sk && sk.parentNode) { sk.parentNode.removeChild(sk); }
                var btn = document.getElementById('ph-od-btn');
                if (btn) { btn.href = d.consoleUrl || '#'; btn.style.display = ''; }
            })
            .catch(fail);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
JS);

        $result   = $event->getArgument('result', []);
        $result[] = $html;
        $event->setArgument('result', $result);
    }

    /**
     * Admin-only JSON endpoint: return the Infynipay payment id + console URL
     * for an order, used by the order-detail card to fill in after a skeleton.
     */
    private function handleAjaxOrderPaymentInfo(): void
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);

        if (!$app->isClient('administrator') || !Session::checkToken('get') || $this->needsSetup()) {
            echo json_encode(['paymentId' => '']);
            $app->close();
        }

        $orderId   = (string) $app->getInput()->getString('order_id', '');
        $payment   = $orderId !== '' ? $this->api()->getPaymentByReference($orderId) : null;
        $paymentId = is_array($payment) ? (string) ($payment['paymentId'] ?? '') : '';

        if ($paymentId === '') {
            echo json_encode(['paymentId' => '']);
            $app->close();
        }

        $creds      = $this->getActiveCredentials();
        $consoleUrl = rtrim(self::PANEL_URL, '/') . '/' . urlencode($creds['app_id'])
            . '/payments/detail?appId=' . urlencode($creds['app_id'])
            . '&paymentId=' . urlencode($paymentId);

        echo json_encode(['paymentId' => $paymentId, 'consoleUrl' => $consoleUrl]);
        $app->close();
    }

    // -----------------------------------------------------------------------
    // Pre / Post payment
    // -----------------------------------------------------------------------

    private function prePayment(array $data): string
    {
        if ($this->needsSetup()) {
            $this->log('Pre-payment requested but plugin is not connected to Infynipay.', Log::ERROR);

            return $this->renderServiceUnavailable();
        }

        $orderId = (string) ($data['order_id'] ?? '');
        $amount  = (float) ($data['orderpayment_amount'] ?? 0.0);

        $order = $this->loadOrder($orderId);

        if ($order === null) {
            $this->log('Pre-payment: order not found. orderId=' . $orderId, Log::ERROR);

            return $this->renderServiceUnavailable();
        }

        $currency = strtoupper((string) ($order->currency_code ?? $order->order_currency_code ?? 'USD'));

        if ($amount <= 0) {
            $amount = (float) ($order->order_total ?? 0);
        }

        $payUrl = Uri::base()
            . 'index.php?option=com_ajax&plugin=infynipay&group=j2commerce&format=raw'
            . '&action=pay&order_id=' . urlencode($orderId);

        // Fetch the supported providers server-side and render their tiles directly
        // in the card, so they always show without depending on client-side JS.
        $checkoutMethods = $this->api()->fetchCheckoutMethods();

        $hash = $this->getPayment()->generateHash($order);

        $vars       = new \stdClass();
        $vars->html = $this->buildPaymentMethodSelectionForm(
            $checkoutMethods,
            $payUrl . '&hash=' . urlencode($hash),
            $amount,
            $currency,
            $orderId
        );

        return $this->renderLayout('prepayment', $vars);
    }

    private function postPayment(object $data): string
    {
        $app     = Factory::getApplication();
        $input   = $app->getInput();
        // Default to '' so an ABSENT paction (the normal buyer return) maps to the
        // display handler. getString() returns null when the param is missing, and
        // match() uses strict comparison - null would NOT equal '' and would wrongly
        // fall through to the error branch.
        $paction = (string) $input->getString('paction', '');

        $this->log('PostPayment dispatch: paction="' . $paction . '" order_id="'
            . $input->getString('order_id', '') . '" uri=' . Uri::getInstance()->toString());

        try {
            return match ($paction) {
                '', 'display' => $this->postPaymentDisplay(),
                'callback'    => $this->postPaymentCallback(),
                'cancel'      => $this->postPaymentCancel(),
                'process'     => $this->postPaymentDisplay(),
                default       => $this->postPaymentError($paction),
            };
        } catch (\Throwable $e) {
            $this->log('PostPayment EXCEPTION: ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine(), Log::ERROR);

            $vars          = new \stdClass();
            $vars->message = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_INVALID_REQUEST');

            return $this->renderLayout('message', $vars);
        }
    }

    private function postPaymentDisplay(): string
    {
        $app     = Factory::getApplication();
        $input   = $app->getInput();
        $orderId = (string) $input->getString('order_id', '');
        $phError = (string) $input->getString('ph_error', '');

        if ($phError === 'inactive') {
            return $this->renderServiceUnavailable();
        }

        $order = $this->loadOrder($orderId);

        if ($order === null) {
            $vars          = new \stdClass();
            $vars->message = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_ORDER_NOT_FOUND');

            return $this->renderLayout('message', $vars);
        }

        if ($phError === 'create_failed') {
            $retryUrl = Uri::base()
                . 'index.php?option=com_ajax&plugin=infynipay&group=j2commerce&format=raw'
                . '&action=pay&order_id=' . urlencode($orderId)
                . '&hash=' . urlencode($this->getPayment()->generateHash($order));

            $vars               = new \stdClass();
            $vars->service_card = $this->buildPaymentErrorCard($retryUrl);

            return $this->renderLayout('postpayment', $vars);
        }

        $paymentState  = 'pending';
        $redirectUrl   = '';
        $phPaymentId   = 0;
        $transactionId = '';

        $apiData = $this->api()->getPaymentByReference($orderId);

        if (is_array($apiData)) {
            $paymentState  = strtolower((string) ($apiData['paymentState'] ?? 'pending'));
            $redirectUrl   = (string) ($apiData['redirectUrl'] ?? '');
            $phPaymentId   = (int) ($apiData['paymentId'] ?? 0);
            $transactionId = (string) ($apiData['transactionId'] ?? '');
        }

        $this->log('Result page: API state=' . $paymentState . ' orderId=' . $orderId . ' phPaymentId=' . $phPaymentId);

        // Best-effort terminal-only status sync (in case the webhook was missed)
        $this->syncPaymentStatus($order, $paymentState, $phPaymentId, $transactionId, 'ReturnFallback', true);

        $vars                 = new \stdClass();
        $vars->order_id       = $orderId;
        $vars->amount         = number_format((float) ($order->order_total ?? 0), 2);
        $vars->currency_code  = strtoupper((string) ($order->currency_code ?? 'USD'));
        $vars->date           = !empty($order->created_on) ? date('M j, Y', strtotime((string) $order->created_on)) : '';
        $vars->payment_state  = $paymentState;
        $vars->redirect_url   = $redirectUrl;
        $vars->is_sandbox     = (int) $this->params->get('sandbox', 1) === 1;
        $vars->confirmation_card = $this->buildConfirmationCard(
            $orderId,
            $vars->amount,
            $vars->currency_code,
            $vars->date,
            $paymentState,
            $redirectUrl
        );
        $vars->onafterpayment_text = (string) $this->params->get('onafterpayment', '');

        return $this->renderLayout('postpayment', $vars);
    }

    private function postPaymentCallback(): string
    {
        // J2Commerce calls this on the return URL when paction=callback.
        // Mirror display behaviour but keep a separate log entry for traceability.
        $this->log('Return callback hit (paction=callback) - delegating to display handler.');

        return $this->postPaymentDisplay();
    }

    private function postPaymentCancel(): string
    {
        $vars          = new \stdClass();
        $vars->message = Text::_($this->params->get(
            'oncancelpayment',
            'PLG_J2COMMERCE_PAYMENT_INFYNIPAY_CANCELLED_BY_BUYER'
        ));

        $app     = Factory::getApplication();
        $orderId = (string) $app->getInput()->getString('order_id', '');

        if ($orderId !== '') {
            $order = $this->loadOrder($orderId);
            if ($order !== null) {
                $cancelStatus = (int) $this->params->get('payment_status_cancelled', 6);
                if ((int) $order->order_state_id !== $cancelStatus) {
                    $order->order_state_id = $cancelStatus;
                    $order->store();
                    OrderHistoryHelper::add(
                        orderId: (string) $order->order_id,
                        comment: Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_ORDER_HISTORY_CANCELLED'),
                        orderStateId: $cancelStatus,
                    );
                }
            }
        }

        return $this->renderLayout('message', $vars);
    }

    private function postPaymentError(string $paction = ''): string
    {
        $input = Factory::getApplication()->getInput();

        $this->log('PostPayment ERROR branch (showing "Invalid payment request"): '
            . 'unexpected paction="' . $paction . '" order_id="' . $input->getString('order_id', '')
            . '" ph_error="' . $input->getString('ph_error', '') . '" uri=' . Uri::getInstance()->toString(),
            Log::WARNING);

        $vars          = new \stdClass();
        $vars->message = $this->params->get(
            'onerrorpayment',
            Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_INVALID_REQUEST')
        );

        return $this->renderLayout('message', $vars);
    }

    // -----------------------------------------------------------------------
    // Webhook handler
    // -----------------------------------------------------------------------

    private function handleWebhook(): void
    {
        $this->log('Webhook received.');

        $isSandbox     = (int) $this->params->get('sandbox', 1) === 1;
        $webhookSecret = $isSandbox
            ? (string) $this->params->get('sandbox_webhook_secret', '')
            : (string) $this->params->get('live_webhook_secret', '');

        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($authHeader === '' && function_exists('getallheaders')) {
            $headers    = getallheaders() ?: [];
            $authHeader = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }

        $receivedToken = stripos($authHeader, 'Bearer ') === 0 ? substr($authHeader, 7) : '';

        // Diagnostic: always logged so mismatches are visible (helps debug proxy header-stripping)
        $this->log(
            'Webhook secret check: storedLen=' . strlen($webhookSecret)
            . ' receivedLen=' . strlen($receivedToken)
            . ' authHeaderPresent=' . ($authHeader !== '' ? 'yes' : 'NO'),
            Log::WARNING
        );

        if ($webhookSecret === '' || !hash_equals($webhookSecret, $receivedToken)) {
            http_response_code(401);
            $this->log('Webhook rejected - invalid token.', Log::WARNING);
            return;
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            http_response_code(400);
            $this->log('Webhook rejected - empty body.', Log::WARNING);
            return;
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            http_response_code(400);
            $this->log('Webhook rejected - invalid JSON.', Log::WARNING);
            return;
        }

        $payload       = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : $data;
        $orderId       = (string) ($payload['referenceId'] ?? $payload['order_id'] ?? '');
        $phPaymentId   = (int) ($payload['paymentId'] ?? 0);
        $transactionId = (string) ($payload['transactionId'] ?? $payload['id'] ?? '');

        if ($orderId === '') {
            http_response_code(400);
            $this->log('Webhook rejected - orderId missing. phPaymentId=' . $phPaymentId, Log::WARNING);
            return;
        }

        // Verify by calling the API directly - never trust the webhook body for state.
        $apiData = $this->api()->getPaymentByReference($orderId, 30);

        if (!is_array($apiData)) {
            http_response_code(502);
            $this->log('Webhook rejected - could not verify payment via API. orderId=' . $orderId, Log::ERROR);
            return;
        }

        $paymentState  = strtolower((string) ($apiData['paymentState'] ?? ''));
        $transactionId = (string) ($apiData['transactionId'] ?? $transactionId);

        $order = $this->loadOrder($orderId);
        if ($order === null) {
            http_response_code(404);
            $this->log('Webhook: order not found. orderId=' . $orderId, Log::WARNING);
            return;
        }

        $result = $this->syncPaymentStatus($order, $paymentState, $phPaymentId, $transactionId, 'Webhook process', false);

        http_response_code(200);
        echo json_encode([
            'received' => true,
            'order_id' => $orderId,
            'result'   => $result,
        ]);
    }

    // -----------------------------------------------------------------------
    // com_ajax handler (OAuth callback / checkout-methods JSON / pay redirect)
    // -----------------------------------------------------------------------

    public function onAjaxInfynipay(Event $event): void
    {
        $app    = Factory::getApplication();
        $input  = $app->getInput();
        $action = $input->getString('action', '');

        $this->log('AJAX endpoint hit. action=' . $action . ' uri=' . Uri::getInstance()->toString());

        switch ($action) {
            case 'checkoutmethods':
                $this->handleAjaxCheckoutMethods();
                break;

            case 'pay':
                $this->handleAjaxPay();
                break;

            case 'webhook':
                // Server-to-server IPN from Infynipay. Authenticated by its own
                // Bearer-token check inside handleWebhook() - NOT by a Joomla CSRF
                // token (an external caller can't carry one).
                $this->handleWebhook();
                $app->close();
                break;

            case 'orderpaymentinfo':
                $this->handleAjaxOrderPaymentInfo();
                break;

            default:
                // OAuth activation return - must validate CSRF
                if (!Session::checkToken('get')) {
                    $this->log('Activation: CSRF token mismatch.', Log::WARNING);
                    $this->jsRedirect(
                        Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=j2commerce',
                        Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_ACTIVATION_CSRF_ERROR'),
                        'error'
                    );
                }

                $licenseId         = $input->getString('licenseId', '');
                $authorizationCode = $input->getString('authorizationCode', '');

                if ($licenseId === '' || $authorizationCode === '') {
                    $this->log('Activation: missing licenseId or authorizationCode.', Log::ERROR);
                    $this->jsRedirect(
                        Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=j2commerce',
                        Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_ACTIVATION_MISSING_CODE'),
                        'error'
                    );
                }

                // The actual activation work happens in SetupbuttonField::getInput() -
                // redirect the admin back to the plugin edit page so it can run there
                // (it reads licenseId / authorizationCode from the URL).
                $extensionId = $this->getExtensionId();
                $this->jsRedirect(
                    Uri::base()
                    . 'index.php?option=com_plugins&view=plugin&layout=edit&extension_id=' . $extensionId
                    . '&licenseId=' . urlencode($licenseId)
                    . '&authorizationCode=' . urlencode($authorizationCode)
                );
        }
    }

    private function handleAjaxCheckoutMethods(): void
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);

        if ($this->needsSetup()) {
            echo json_encode([]);
            $app->close();
        }

        echo json_encode($this->api()->fetchCheckoutMethods());
        $app->close();
    }

    private function handleAjaxPay(): void
    {
        $app     = Factory::getApplication();
        $input   = $app->getInput();
        $orderId = (string) $input->getString('order_id', '');

        $cancelUrl = Uri::base()
            . 'index.php?option=com_j2commerce&task=checkout.confirmPayment'
            . '&orderpayment_type=' . $this->_name
            . '&paction=cancel&order_id=' . urlencode($orderId);

        if ($orderId === '') {
            $this->log('Pay aborted - missing order_id. uri=' . Uri::getInstance()->toString(), Log::WARNING);
            $this->jsRedirect($cancelUrl, Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_INVALID_REQUEST'), 'error');
        }

        if ($this->needsSetup()) {
            $this->log('Pay aborted - plugin not configured. orderId=' . $orderId, Log::WARNING);
            $this->jsRedirect($cancelUrl, Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_NOT_CONFIGURED'), 'error');
        }

        $order = $this->loadOrder($orderId);
        if ($order === null) {
            $this->log('Pay aborted - order not found. orderId=' . $orderId, Log::WARNING);
            $this->jsRedirect($cancelUrl, Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_ORDER_NOT_FOUND'), 'error');
        }

        if ($order->orderpayment_type !== $this->_name || !$this->getPayment()->validateHash($order)) {
            $this->log('Pay aborted - invalid request. orderId=' . $orderId
                . ' orderpayment_type="' . ($order->orderpayment_type ?? '') . '" expected="' . $this->_name . '"'
                . ' hashValid=' . ($this->getPayment()->validateHash($order) ? 'yes' : 'NO'), Log::WARNING);
            $this->jsRedirect($cancelUrl, Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_INVALID_REQUEST'), 'error');
        }

        $amount     = (float) ($order->order_total ?? 0);
        $currency   = strtoupper((string) ($order->currency_code ?? $order->order_currency_code ?? 'USD'));

        $returnUrl  = Uri::base()
            . 'index.php?option=com_j2commerce&task=checkout.confirmPayment'
            . '&orderpayment_type=' . $this->_name
            . '&order_id=' . urlencode($orderId);

        $webhookUrl = Uri::base()
            . 'index.php?option=com_ajax&plugin=infynipay&group=j2commerce&format=raw&action=webhook'
            . '&order_id=' . urlencode($orderId);

        $redirectUrl = $this->api()->createHostedPayment($order, $amount, $currency, $returnUrl, $webhookUrl);

        if ($redirectUrl === 'APP_INACTIVE') {
            $this->jsRedirect(
                Uri::base()
                . 'index.php?option=com_j2commerce&task=checkout.confirmPayment'
                . '&orderpayment_type=' . $this->_name
                . '&paction=display&order_id=' . urlencode($orderId)
                . '&ph_error=inactive'
            );
        }

        if ($redirectUrl === 'ALREADY_PAID') {
            $this->jsRedirect(
                Uri::base()
                . 'index.php?option=com_j2commerce&task=checkout.confirmPayment'
                . '&orderpayment_type=' . $this->_name
                . '&paction=display&order_id=' . urlencode($orderId)
            );
        }

        if ($redirectUrl === null || $redirectUrl === '') {
            $this->jsRedirect(
                Uri::base()
                . 'index.php?option=com_j2commerce&task=checkout.confirmPayment'
                . '&orderpayment_type=' . $this->_name
                . '&paction=display&order_id=' . urlencode($orderId)
                . '&ph_error=create_failed'
            );
        }

        $this->syncPaymentStatus($order, 'pending', 0, '', 'PaymentInitiated', false);
        $this->jsRedirect($redirectUrl);
    }

    // -----------------------------------------------------------------------
    // Checkout: inject the small provider-icon strip next to the radio option
    // -----------------------------------------------------------------------

    public function onBeforeRender(Event $event): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->getInput();

        if ($input->getString('option') !== 'com_j2commerce') {
            return;
        }

        if ($this->needsSetup()) {
            return;
        }

        $ajaxUrl     = Uri::base() . 'index.php?option=com_ajax&plugin=infynipay&group=j2commerce&action=checkoutmethods&format=raw';
        $jsAjaxUrl   = json_encode($ajaxUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jsElement   = json_encode($this->_name);

        $loadingText = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_LOADING');
        if ($loadingText === 'PLG_J2COMMERCE_PAYMENT_INFYNIPAY_LOADING') {
            $loadingText = 'Preparing secure payment...';
        }
        $jsLoadingText = json_encode($loadingText, JSON_UNESCAPED_UNICODE);

        $script = <<<JS
(function () {
    var ajaxUrl = {$jsAjaxUrl};
    var element = {$jsElement};

    function init() {
        var inputs = document.querySelectorAll('input[name="payment_plugin"], input[name^="payment_plugin"]');
        var target = null;
        for (var i = 0; i < inputs.length; i++) {
            if ((inputs[i].value || '') === element) { target = inputs[i]; break; }
        }
        if (!target) return;

        var label = target.closest('label') || target.parentElement;
        if (!label || label.querySelector('.phh-icons')) return;

        if (!document.getElementById('phh-ico-css')) {
            var s = document.createElement('style');
            s.id = 'phh-ico-css';
            s.textContent =
                '.phh-icons{display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;margin-left:10px;vertical-align:middle}'
                + '.phh-icons img{height:20px;width:auto;max-width:48px;object-fit:contain;vertical-align:middle}'
                + '.phh-icons .phh-cc{display:inline-flex;align-items:center}'
                + '.phh-icons .phh-cc svg{height:18px;width:auto;color:#374151;vertical-align:middle}'
                + '.phh-icons .phh-sk{display:inline-block;width:30px;height:20px;border-radius:4px;position:relative;overflow:hidden;background:#e2e8f0}'
                + '.phh-icons .phh-sk::after{content:"";position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,rgba(255,255,255,0),rgba(255,255,255,.65) 50%,rgba(255,255,255,0));animation:phh-sk-sweep 1.2s infinite}'
                + '@keyframes phh-sk-sweep{100%{transform:translateX(100%)}}';
            document.head.appendChild(s);
        }

        var strip = document.createElement('span');
        strip.className = 'phh-icons';
        // Shimmer skeleton placeholders shown until the real provider icons load.
        strip.innerHTML = '<span class="phh-sk"></span><span class="phh-sk"></span><span class="phh-sk"></span>';
        label.appendChild(strip);

        fetch(ajaxUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (methods) {
                if (!Array.isArray(methods) || !methods.length) { strip.innerHTML = ''; return; }
                var html = '';
                for (var i = 0; i < methods.length; i++) {
                    var group  = methods[i];
                    var method = group.checkoutMethod || '';
                    var items  = group.paymentCheckoutMethodItems || [];

                    if (method === 'CreditCard') {
                        html += '<span class="phh-cc"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/><line x1="5" y1="15" x2="9" y2="15"/></svg></span>';
                    } else if (method === 'ProviderHostedPage') {
                        for (var j = 0; j < items.length; j++) {
                            var profile = items[j] && items[j].paymentProfile;
                            if (!profile) continue;
                            var prov = profile.paymentProvider;
                            if (!prov) continue;
                            var iconUrl = prov.iconUri1 || prov.iconUri2 || '';
                            var name = (prov.provider || '').replace(/</g,'&lt;').replace(/"/g,'&quot;');
                            if (iconUrl && (iconUrl.indexOf('https://') === 0 || iconUrl.indexOf('data:image/') === 0)) {
                                html += '<img src="' + iconUrl + '" alt="' + name + '" title="' + name + '">';
                            }
                        }
                    }
                }
                strip.innerHTML = html;
            })
            .catch(function () { strip.innerHTML = ''; });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

// "Continue to pay" button: show a spinner the moment it is clicked. The link
// navigates to the pay endpoint, which creates the hosted-payment session
// (a Infynipay API call - a few seconds) before redirecting to Infynipay;
// the prepayment card stays on screen during that wait, so the spinner gives
// the buyer feedback. Delegated on document because the card is injected via AJAX.
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('#ph_pay_btn') : null;
    if (!btn || btn.classList.contains('ph-pay-busy')) { return; }
    if (btn.tagName === 'BUTTON' && btn.disabled) { return; }
    btn.classList.add('ph-pay-busy');
    btn.setAttribute('aria-busy', 'true');
    // Do not preventDefault - let the link navigate to the pay endpoint.
});

// Full-page loader while the Infynipay payment card loads into the confirm
// step. J2Commerce sets aria-busy="true" on the confirm step's content while it
// fetches/renders (which includes our server-rendered card), then "false" when
// done. We show a brief-delayed overlay so fast steps never flicker, and hide it
// when the step finishes (the card is in the DOM by then).
(function () {
    var loadingText = {$jsLoadingText};
    var showTimer = null, safetyTimer = null;

    function overlay() {
        var o = document.getElementById('ph-loading-overlay');
        if (!o) {
            o = document.createElement('div');
            o.id = 'ph-loading-overlay';
            o.setAttribute('role', 'status');
            o.setAttribute('aria-live', 'polite');
            o.innerHTML = '<div class="ph-spin"></div><div class="ph-load-text"></div>';
            o.querySelector('.ph-load-text').textContent = loadingText;
            document.body.appendChild(o);
        }
        return o;
    }

    function show() { overlay().classList.add('is-on'); }

    function hide() {
        if (showTimer) { clearTimeout(showTimer); showTimer = null; }
        if (safetyTimer) { clearTimeout(safetyTimer); safetyTimer = null; }
        var o = document.getElementById('ph-loading-overlay');
        if (o) { o.classList.remove('is-on'); }
    }

    function onBusy(content) {
        if (content.getAttribute('aria-busy') === 'true') {
            if (showTimer) { clearTimeout(showTimer); }
            showTimer = setTimeout(show, 350);
            if (safetyTimer) { clearTimeout(safetyTimer); }
            safetyTimer = setTimeout(hide, 30000);
        } else {
            hide();
        }
    }

    function init() {
        var obs = new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var t = muts[i].target;
                if (t && t.nodeType === 1 && t.classList && t.classList.contains('checkout-content')
                    && t.closest && t.closest('#confirm')) {
                    onBusy(t);
                }
            }
        });
        obs.observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['aria-busy'] });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    window.addEventListener('pageshow', hide);
})();
JS;

        Factory::getDocument()->addStyleDeclaration(
            '#ph-loading-overlay{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;flex-direction:column;gap:14px;background:rgba(248,250,252,.86);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}'
            . '#ph-loading-overlay.is-on{display:flex}'
            . '#ph-loading-overlay .ph-spin{width:46px;height:46px;border:4px solid rgba(99,91,255,.25);border-top-color:#1d4ed8;border-radius:50%;animation:ph-ld-spin .8s linear infinite}'
            . '#ph-loading-overlay .ph-load-text{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;font-weight:600;color:#1e293b}'
            . '@keyframes ph-ld-spin{to{transform:rotate(360deg)}}'
            . '@media(prefers-color-scheme:dark){#ph-loading-overlay{background:rgba(15,23,42,.86)}#ph-loading-overlay .ph-load-text{color:#e2e8f0}}'
        );
        Factory::getDocument()->addScriptDeclaration($script);
    }

    // -----------------------------------------------------------------------
    // Helpers - credentials, order loading, hashing, status sync
    // -----------------------------------------------------------------------

    /** Return the active app_id + token based on sandbox/live mode. */
    private function getActiveCredentials(): array
    {
        $isSandbox = (int) $this->params->get('sandbox', 1) === 1;

        return [
            'app_id' => (string) $this->params->get($isSandbox ? 'sandbox_app_id' : 'live_app_id', ''),
            'token'  => (string) $this->params->get($isSandbox ? 'sandbox_token'  : 'live_token',  ''),
        ];
    }

    private function needsSetup(): bool
    {
        $creds = $this->getActiveCredentials();

        return $creds['app_id'] === '' || $creds['token'] === '';
    }

    /** Build an API client for the active environment's credentials. */
    private function api(): ApiClient
    {
        $creds = $this->getActiveCredentials();

        return new ApiClient(
            self::PAYMENT_APP_API_URL,
            self::PAYMENT_API_URL,
            $creds['app_id'],
            $creds['token'],
            fn (string $message, int $priority = Log::INFO) => $this->log($message, $priority)
        );
    }

    private function getPayment(): Payment
    {
        if ($this->payment === null) {
            $this->payment           = new Payment($this->pluginDispatcher, $this->pluginConfig);
            $this->payment->_element = $this->_name;
        }

        return $this->payment;
    }

    private function loadOrder(string $orderId)
    {
        if ($orderId === '') {
            return null;
        }

        try {
            $factory = Factory::getApplication()->bootComponent('com_j2commerce')->getMVCFactory();
            $order   = $factory->createTable('Order', 'Administrator');
        } catch (\Throwable $e) {
            $this->log('loadOrder: could not create Order table - ' . $e->getMessage(), Log::ERROR);
            return null;
        }

        if (!$order->load(['order_id' => $orderId])) {
            return null;
        }

        return $order;
    }

    private function getExtensionId(): int
    {
        try {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder')  . ' = ' . $db->quote('j2commerce'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('payment_infynipay'));

            return (int) $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function checkGeozone(int $geozoneId, $address): bool
    {
        if ($geozoneId <= 0 || $address === null) {
            return true;
        }

        try {
            $db        = $this->getDatabase();
            $countryId = (int) ($address->country_id ?? 0);
            $zoneId    = (int) ($address->zone_id ?? 0);

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__j2store_geozones_xref'))
                ->where($db->quoteName('geozone_id') . ' = ' . $geozoneId)
                ->where($db->quoteName('country_id') . ' = ' . $countryId)
                ->extendWhere('AND', [
                    $db->quoteName('zone_id') . ' = ' . $zoneId,
                    $db->quoteName('zone_id') . ' = 0',
                ], 'OR');

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function checkSubtotalLimits(float $subtotal): bool
    {
        $min = (float) $this->params->get('min_subtotal', 0);
        $max = (float) $this->params->get('max_subtotal', -1);

        if ($min > 0 && $subtotal < $min) {
            return false;
        }

        if ($max >= 0 && $subtotal > $max) {
            return false;
        }

        return true;
    }

    /**
     * Apply a Infynipay payment state to a J2Commerce order.
     * Idempotent - skips if already at the target status.
     *
     * @return string 'changed'|'skipped'|'not_found'
     */
    private function syncPaymentStatus(
        object $order,
        string $paymentState,
        int $phPaymentId,
        string $transactionId,
        string $trigger,
        bool $terminalOnly
    ): string {
        if ($terminalOnly && !in_array($paymentState, ['captured', 'failed', 'disputed', 'refunded'], true)) {
            $this->log($trigger . ' skipped - non-terminal state. paymentState=' . $paymentState);
            return 'skipped';
        }

        // Map Infynipay states to J2Commerce's NATIVE order statuses (defaults:
        // 1=Confirmed, 3=Failed, 4=Pending). J2Commerce ships no "Refunded" or
        // "Disputed" status, so refunds and disputes both map to Failed.
        $newStatus = match ($paymentState) {
            'captured'                       => (int) $this->params->get('payment_status_success', 1),
            'failed', 'refunded', 'disputed' => (int) $this->params->get('payment_status_failed', 3),
            default                          => (int) $this->params->get('payment_status_pending', 4),
        };

        if ((int) $order->order_state_id === $newStatus) {
            $this->log($trigger . ' skipped - already at status ' . $newStatus . '. orderId=' . $order->order_id);
            return 'skipped';
        }

        $order->order_state_id = $newStatus;

        if (!$order->store()) {
            $this->log($trigger . ' save failed: ' . $order->getError(), Log::ERROR);
            return 'not_found';
        }

        $comment = Text::sprintf(
            'PLG_J2COMMERCE_PAYMENT_INFYNIPAY_HISTORY_COMMENT',
            $trigger,
            $paymentState,
            $transactionId !== '' ? $transactionId : (string) $phPaymentId
        );

        OrderHistoryHelper::add(
            orderId: (string) $order->order_id,
            comment: $comment,
            orderStateId: $newStatus,
        );

        $this->log($trigger . ' completed. orderId=' . $order->order_id . ' state=' . $paymentState . ' newStatus=' . $newStatus);

        return 'changed';
    }

    private function isLocalhostUrl(string $url): bool
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($host);
            return ($long & 0xFF000000) === 0x0A000000
                || ($long & 0xFFF00000) === 0xAC100000
                || ($long & 0xFFFF0000) === 0xC0A80000
                || ($long & 0xFF000000) === 0x7F000000;
        }

        return false;
    }

    private function jsRedirect(string $url, string $message = '', string $type = 'message'): never
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $jsUrl   = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $msgHtml = '';
        if ($message !== '') {
            $colour  = $type === 'error' ? '#f8d7da' : '#d1e7dd';
            $msgHtml = '<p style="font-family:sans-serif;padding:8px 12px;background:' . $colour . ';border-radius:4px">'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">'
            . '</head><body>' . $msgHtml
            . '<script>window.location.replace(' . $jsUrl . ');</script>'
            . '</body></html>';
        exit;
    }

    private function log(string $message, int $priority = Log::INFO): void
    {
        static $registered = false;

        if (!$registered) {
            Log::addLogger(
                ['text_file' => 'plg_j2commerce_payment_infynipay.php'],
                Log::ALL,
                ['plg_j2commerce_payment_infynipay']
            );
            $registered = true;
        }

        Log::add($message, $priority, 'plg_j2commerce_payment_infynipay');
    }

    // -----------------------------------------------------------------------
    // Layout helpers
    // -----------------------------------------------------------------------

    private function renderLayout(string $layout, ?\stdClass $vars = null): string
    {
        return $this->resolvePluginLayout($layout, ['vars' => $vars]);
    }

    private function renderServiceUnavailable(): string
    {
        $vars                 = new \stdClass();
        $vars->service_card   = $this->buildServiceUnavailableCard();

        return $this->renderLayout('postpayment', $vars);
    }

    // -----------------------------------------------------------------------
    // Payment-card HTML builders (ported from PhocaCart)
    // -----------------------------------------------------------------------

    private function buildPaymentMethodSelectionForm(
        array $methods,
        ?string $payUrl,
        float $amount = 0,
        string $currency = '',
        string $orderId = ''
    ): string {
        $uriBase    = Uri::base();
        $serverHost = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $isLocalhost = $this->isLocalhostUrl($uriBase)
            || ($serverHost !== '' && $this->isLocalhostUrl('http://' . $serverHost . '/'));

        $creditCardSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/><line x1="5" y1="15" x2="9" y2="15"/></svg>';

        $cards = '';
        foreach ($methods as $group) {
            $checkoutMethod = (string) ($group['checkoutMethod'] ?? '');
            $items          = $group['paymentCheckoutMethodItems'] ?? [];

            if ($checkoutMethod === 'CreditCard') {
                $cards .= '<div class="ph-method-tile"><span class="ph-icon ph-icon-card">' . $creditCardSvg . '</span></div>';
            } elseif ($checkoutMethod === 'ProviderHostedPage') {
                foreach ($items as $item) {
                    $profile = $item['paymentProfile'] ?? null;
                    if (!is_array($profile)) {
                        continue;
                    }
                    $iconUri1 = (string) ($profile['paymentProvider']['iconUri1'] ?? '');
                    $iconUri2 = (string) ($profile['paymentProvider']['iconUri2'] ?? '');
                    $provider = (string) ($profile['paymentProvider']['provider'] ?? '');
                    $safeProvider = htmlspecialchars($provider !== '' ? $provider : 'Payment Provider', ENT_QUOTES, 'UTF-8');

                    if ($iconUri1 !== '' && !str_starts_with($iconUri1, 'https://') && !str_starts_with($iconUri1, 'data:image/')) {
                        $iconUri1 = '';
                    }
                    if ($iconUri2 !== '' && !str_starts_with($iconUri2, 'https://') && !str_starts_with($iconUri2, 'data:image/')) {
                        $iconUri2 = '';
                    }
                    if ($iconUri1 === '' && $iconUri2 === '') {
                        continue;
                    }

                    if ($iconUri1 !== '') {
                        $iconHtml = '<img src="' . htmlspecialchars($iconUri1, ENT_QUOTES, 'UTF-8') . '" alt="' . $safeProvider . '" class="ph-provider-logo">';
                    } else {
                        $iconHtml = '<span class="ph-dark-pill"><img src="' . htmlspecialchars($iconUri2, ENT_QUOTES, 'UTF-8') . '" alt="' . $safeProvider . '" class="ph-provider-logo"></span>';
                    }

                    $cards .= '<div class="ph-method-tile"><span class="ph-icon">' . $iconHtml . '</span></div>';
                }
            }
        }

        $continueText = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_CONTINUE_TO_PAY');
        $redirectText = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_REDIRECT_TO_INFYNIPAY');

        $logoPng = dirname(__DIR__, 2) . '/images/logos/infynipay-logo-dark.png';
        $logoUrl = is_file($logoPng)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPng))
            : htmlspecialchars(Uri::root() . 'plugins/j2commerce/payment_infynipay/images/logos/infynipay-logo-dark.png', ENT_QUOTES, 'UTF-8');

        $css = '<style>'
            . '#ph-wrap{max-width:460px;margin:32px auto;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            . '#ph-card{background:#fff;border-radius:24px;border:1.5px solid rgba(99,91,255,.18);box-shadow:0 0 0 4px rgba(99,91,255,.06),0 8px 40px rgba(15,23,42,.13),0 2px 8px rgba(99,91,255,.08);overflow:hidden}'
            . '#ph-card-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)}'
            . '#ph-card-header .ph-brand{display:flex;align-items:center}'
            . '#ph-card-header .ph-brand-logo{display:block!important;flex:0 0 auto;height:56px!important;min-height:56px!important;max-height:56px!important;width:auto!important;max-width:none!important}'
            . '.ph-order-ref{display:flex;align-items:center;justify-content:center;gap:8px;text-align:center;margin-bottom:14px;font-size:12px;font-weight:600;color:#64748b;letter-spacing:.03em}'
            . '.ph-order-ref span{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;font-weight:700;font-size:12px}'
            . '.ph-order-ref svg{width:12px;height:12px;flex-shrink:0;color:#94a3b8}'
            . '#ph-card-body{padding:24px}'
            . '.ph-methods{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:24px}'
            . '.ph-method-tile{display:inline-flex;align-items:center;justify-content:center;width:100%;height:68px;padding:8px;border:1.5px solid #e2e8f0;border-radius:14px;background:#f8fafc}'
            . '.ph-method-tile .ph-icon{display:flex;align-items:center;justify-content:center;width:100%;height:100%}'
            . '.ph-icon-card svg{width:40px;height:40px;color:#334155}'
            . '.ph-provider-logo{display:block;max-height:40px;max-width:64px;width:auto;object-fit:contain}'
            . '.ph-dark-pill{display:inline-flex;align-items:center;justify-content:center;width:56px;height:40px;border-radius:8px;background:#1e293b;padding:5px}'
            . '.ph-dark-pill .ph-provider-logo{max-height:30px;max-width:46px}'
            . '.ph-divider{height:1px;background:#f1f5f9;margin:0 0 20px}'
            . '.ph-redirect-msg{display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;padding:12px 14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0}'
            . '.ph-redirect-msg svg{flex-shrink:0;width:15px;height:15px;margin-top:2px;color:#64748b}'
            . '.ph-redirect-msg span{font-size:13px;line-height:1.55;color:#64748b}'
            . '#ph_pay_btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:14px;border:0;border-radius:14px;background:linear-gradient(135deg,#1d4ed8 0%,#0f172a 100%);color:#fff;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:-.01em;transition:opacity .15s,transform .1s;text-decoration:none;box-sizing:border-box}'
            . '#ph_pay_btn:hover{opacity:.92;transform:translateY(-1px)}'
            . '#ph_pay_btn:active{transform:translateY(0)}'
            . '#ph_pay_btn svg{width:16px;height:16px}'
            . '#ph_pay_btn.ph-pay-busy{pointer-events:none;opacity:.92}'
            . '#ph_pay_btn.ph-pay-busy svg{display:none}'
            . '#ph_pay_btn.ph-pay-busy::before{content:"";display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:ph-pay-spin .7s linear infinite;vertical-align:middle}'
            . '@keyframes ph-pay-spin{to{transform:rotate(360deg)}}'
            . '.ph-sandbox-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.4);color:#b45309;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}'
            . '.ph-sandbox-badge svg{width:12px;height:12px;flex-shrink:0}'
            . '@media(max-width:380px){.ph-methods{grid-template-columns:repeat(2,1fr)}.ph-provider-logo{max-height:34px;max-width:54px}}'
            . '</style>';

        $localhostBanner = '';
        if ($isLocalhost) {
            $localhostBanner = '<style>'
                . '#ph-localhost-warn{display:flex;gap:12px;max-width:460px;margin:0 auto 18px;padding:14px 16px;border-radius:14px;background:#fffbeb;border:1.5px solid #f59e0b;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-sizing:border-box}'
                . '#ph-localhost-warn p{margin:4px 0 10px;font-size:13px;line-height:1.55;color:#78350f}'
                . '#ph-localhost-warn strong{color:#92400e}'
                . '#ph-localhost-warn a{font-size:13px;font-weight:600;color:#b45309;text-decoration:underline}'
                . '#ph-localhost-warn-title{font-size:13px;font-weight:700;color:#92400e;margin:0}'
                . '</style>'
                . '<div id="ph-localhost-warn">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;width:20px;height:20px;color:#b45309;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                . '<div>'
                . '<p id="ph-localhost-warn-title">Webhook delivery unavailable</p>'
                . '<p>Your store is running on a <strong>localhost / private network URL</strong>. Payment webhooks cannot reach this address from outside your machine. Use a public domain or a tunnel (Cloudflare Tunnel, ngrok) before accepting live payments.</p>'
                . '<a href="https://github.com/infynipay/infynipay-docs/blob/main/CLOUDFLARE-TUNNEL-INSTRUCTIONS.md" target="_blank" rel="noopener noreferrer">Learn how to set up a Cloudflare Tunnel &rarr;</a>'
                . '</div>'
                . '</div>';
        }

        $amountLabel  = $amount > 0 ? ' - ' . htmlspecialchars(number_format($amount, 2) . ' ' . $currency, ENT_QUOTES, 'UTF-8') : '';
        $safeContinue = htmlspecialchars($continueText, ENT_QUOTES, 'UTF-8') . $amountLabel;
        $safeRedirect = htmlspecialchars($redirectText, ENT_QUOTES, 'UTF-8');
        $arrowSvg     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>';

        $isSandbox    = (int) $this->params->get('sandbox', 1) === 1;
        $sandboxBadge = $isSandbox
            ? '<span class="ph-sandbox-badge"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636-2.87L13.637 3.59a1.914 1.914 0 0 0-3.274 0z"/><path d="M12 17h.01"/></svg>Sandbox</span>'
            : '';

        if (empty($methods) || $cards === '') {
            return $css . $localhostBanner
                . '<div id="ph-wrap"><div id="ph-card"><div id="ph-card-header"><div class="ph-brand">'
                . '<img src="' . $logoUrl . '" alt="Infynipay" class="ph-brand-logo">'
                . '</div></div><div id="ph-card-body"><div class="ph-redirect-msg" style="margin-bottom:0">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                . '<span>' . htmlspecialchars(Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_SERVICE_UNAVAILABLE'), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</div></div></div></div>';
        }

        if ($payUrl !== null && $payUrl !== '') {
            $safeHref = htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8');
            $payBtn   = '<a href="' . $safeHref . '" id="ph_pay_btn">' . $arrowSvg . $safeContinue . '</a>';
        } else {
            $payBtn = '<button type="button" id="ph_pay_btn" disabled>' . $arrowSvg . $safeContinue . '</button>';
        }

        $orderRefHtml = $orderId !== ''
            ? '<div class="ph-order-ref">' . $sandboxBadge . '<span>'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>'
                . 'Order ' . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8')
                . '</span></div>'
            : '';

        return $css . $localhostBanner
            . '<div id="ph-wrap"><div id="ph-card">'
            . '<div id="ph-card-header"><div class="ph-brand"><img src="' . $logoUrl . '" alt="Infynipay" class="ph-brand-logo"></div></div>'
            . '<div id="ph-card-body">'
            . '<div class="ph-methods">' . $cards . '</div>'
            . '<div class="ph-divider"></div>'
            . '<div class="ph-redirect-msg">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            . '<span>' . $safeRedirect . '</span></div>'
            . $orderRefHtml
            . $payBtn
            . '</div></div></div>';
    }

    private function buildConfirmationCard(
        string $orderId,
        string $amount,
        string $currency,
        string $date,
        string $paymentState = 'pending',
        string $redirectUrl = ''
    ): string {
        $currencySymbol = match ($currency) {
            'EUR' => '&euro;',
            'GBP' => '&pound;',
            'JPY' => '&yen;',
            default => '$',
        };

        $isConfirmed = ($paymentState === 'captured');
        $isFailed    = in_array($paymentState, ['failed', 'disputed'], true);

        if ($isConfirmed) {
            $headerTitle = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_SUCCESS_TITLE');
            $headerSub   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_SUCCESS_SUB');
            $headerIcon  = '<polyline points="20 6 9 17 4 12"/>';
            $badgeBg     = 'background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)';
            $badgeText   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_CONFIRMED');
            $dotColor    = '#34d399';
        } elseif ($isFailed) {
            $headerTitle = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_FAILED_TITLE');
            $headerSub   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_FAILED_SUB');
            $headerIcon  = '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>';
            $badgeBg     = 'background:#dc2626';
            $badgeText   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_FAILED');
            $dotColor    = '#fca5a5';
        } else {
            $headerTitle = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_PENDING_TITLE');
            $headerSub   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_PENDING_SUB');
            $headerIcon  = '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
            $badgeBg     = 'background:#f59e0b';
            $badgeText   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_RESULT_PENDING');
            $dotColor    = '#fde68a';
        }

        $continueBtn = '';
        if (!$isConfirmed && $redirectUrl !== '') {
            $continueBtn = '<a href="' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '" class="phrc-continue">'
                . Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_CONTINUE_PAYMENT') . ' &rarr;</a>';
        }

        $isSandbox   = (int) $this->params->get('sandbox', 1) === 1;
        $sandboxHtml = $isSandbox
            ? '<div class="phrc-sandbox"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636-2.87L13.637 3.59a1.914 1.914 0 0 0-3.274 0z"/><path d="M12 17h.01"/></svg>Sandbox</div>'
            : '';

        $orderIdSafe = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');
        $dateSafe    = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
        $amountSafe  = htmlspecialchars($amount, ENT_QUOTES, 'UTF-8');
        $currencySafe = htmlspecialchars($currency, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<style>
.phrc{max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fff;border-radius:24px;border:1.5px solid #e2e8f0;overflow:hidden;text-align:center}
.phrc-top{padding:40px 32px 28px;background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)}
.phrc-check{width:64px;height:64px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px}
.phrc-check svg{width:32px;height:32px;stroke:#fff;fill:none}
.phrc-h{font-size:22px;font-weight:700;color:#fff;margin:0;letter-spacing:-.3px}
.phrc-sub{font-size:15px;color:rgba(255,255,255,.7);margin:8px 0 0;font-weight:400}
.phrc-body{padding:32px 32px 28px}
.phrc-amt{font-size:38px;font-weight:800;color:#111827;margin:0 0 4px;letter-spacing:-.5px}
.phrc-cur{font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:24px}
.phrc-rows{border-top:1px solid #f1f5f9;padding-top:20px}
.phrc-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f8fafc}
.phrc-row:last-child{border-bottom:none}
.phrc-lbl{font-size:14px;color:#94a3b8;font-weight:500}
.phrc-val{font-size:15px;color:#1e293b;font-weight:600}
.phrc-badge{display:inline-flex;align-items:center;gap:6px;margin-top:20px;{$badgeBg};color:#fff;font-size:14px;font-weight:600;padding:10px 20px;border-radius:20px}
.phrc-dot{width:8px;height:8px;background:{$dotColor};border-radius:50%;animation:phrc-p 2s infinite}
@keyframes phrc-p{0%,100%{opacity:1}50%{opacity:.3}}
.phrc-continue{display:inline-flex;align-items:center;justify-content:center;margin-top:20px;padding:14px 28px;border-radius:14px;background:linear-gradient(135deg,#1d4ed8 0%,#0f172a 100%);color:#fff;font-size:15px;font-weight:700;text-decoration:none}
.phrc-sandbox{display:inline-flex;align-items:center;gap:4px;margin-top:12px;padding:4px 10px;border-radius:6px;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.4);color:#b45309;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.phrc-sandbox svg{width:12px;height:12px;flex-shrink:0}
</style>
<div class="phrc">
  <div class="phrc-top">
    <div class="phrc-check"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">{$headerIcon}</svg></div>
    <h3 class="phrc-h">{$headerTitle}</h3>
    <p class="phrc-sub">{$headerSub}</p>
  </div>
  <div class="phrc-body">
    <p class="phrc-amt">{$currencySymbol}{$amountSafe}</p>
    <p class="phrc-cur">{$currencySafe}</p>
    <div class="phrc-rows">
      <div class="phrc-row"><span class="phrc-lbl">Order</span><span class="phrc-val">{$sandboxHtml} {$orderIdSafe}</span></div>
      <div class="phrc-row"><span class="phrc-lbl">Date</span><span class="phrc-val">{$dateSafe}</span></div>
      <div class="phrc-row"><span class="phrc-lbl">Payment Method</span><span class="phrc-val">Infynipay</span></div>
    </div>
    <div class="phrc-badge"><span class="phrc-dot"></span>{$badgeText}</div>
    {$continueBtn}
  </div>
</div>
HTML;
    }

    private function buildServiceUnavailableCard(): string
    {
        $title = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_SERVICE_UNAVAILABLE_TITLE');
        $sub   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_SERVICE_UNAVAILABLE_SUB');
        $msg   = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_SERVICE_UNAVAILABLE_MESSAGE');
        $hint  = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_SERVICE_UNAVAILABLE_HINT');

        return <<<HTML
<style>
.phrc-unavail{max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fff;border-radius:24px;border:1.5px solid #e2e8f0;overflow:hidden;text-align:center}
.phrc-unavail-top{padding:40px 32px 28px;background:linear-gradient(135deg,#7f1d1d 0%,#991b1b 100%)}
.phrc-unavail-icon{width:64px;height:64px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px}
.phrc-unavail-icon svg{width:32px;height:32px;stroke:#fff;fill:none}
.phrc-unavail h3{font-size:22px;font-weight:700;color:#fff;margin:0;letter-spacing:-.3px}
.phrc-unavail .phrc-unavail-sub{font-size:15px;color:rgba(255,255,255,.7);margin:8px 0 0;font-weight:400}
.phrc-unavail-body{padding:32px 32px 28px}
.phrc-unavail-msg{font-size:16px;color:#1e293b;font-weight:500;line-height:1.6;margin:0 0 16px}
.phrc-unavail-hint{font-size:14px;color:#64748b;margin:0}
</style>
<div class="phrc-unavail">
  <div class="phrc-unavail-top">
    <div class="phrc-unavail-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    <h3>{$title}</h3>
    <p class="phrc-unavail-sub">{$sub}</p>
  </div>
  <div class="phrc-unavail-body">
    <p class="phrc-unavail-msg">{$msg}</p>
    <p class="phrc-unavail-hint">{$hint}</p>
  </div>
</div>
HTML;
    }

    /**
     * Friendly error card shown in the checkout area when the hosted-payment
     * session could not be created (e.g. the API returned an error). No charge
     * has been made, so we reassure the buyer and offer a one-click retry.
     */
    private function buildPaymentErrorCard(string $retryUrl): string
    {
        $title    = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_PAYMENT_ERROR_TITLE');
        $sub      = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_PAYMENT_ERROR_SUB');
        $msg      = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_PAYMENT_ERROR_MESSAGE');
        $retryTxt = htmlspecialchars(Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_PAYMENT_ERROR_RETRY'), ENT_QUOTES, 'UTF-8');

        $retryBtn = $retryUrl !== ''
            ? '<a href="' . htmlspecialchars($retryUrl, ENT_QUOTES, 'UTF-8') . '" class="phrc-err-retry">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>'
                . $retryTxt . '</a>'
            : '';

        return <<<HTML
<style>
.phrc-err{max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fff;border-radius:24px;border:1.5px solid #e2e8f0;overflow:hidden;text-align:center}
.phrc-err-top{padding:40px 32px 28px;background:linear-gradient(135deg,#7f1d1d 0%,#b91c1c 100%)}
.phrc-err-icon{width:64px;height:64px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px}
.phrc-err-icon svg{width:32px;height:32px;stroke:#fff;fill:none}
.phrc-err h3{font-size:22px;font-weight:700;color:#fff;margin:0;letter-spacing:-.3px}
.phrc-err-sub{font-size:15px;color:rgba(255,255,255,.78);margin:8px 0 0;font-weight:400}
.phrc-err-body{padding:32px 32px 28px}
.phrc-err-msg{font-size:16px;color:#1e293b;font-weight:500;line-height:1.6;margin:0 0 22px}
.phrc-err-retry{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 28px;border-radius:14px;background:linear-gradient(135deg,#1d4ed8 0%,#0f172a 100%);color:#fff;font-size:15px;font-weight:700;text-decoration:none;transition:opacity .15s}
.phrc-err-retry svg{width:16px;height:16px}
.phrc-err-retry:hover{opacity:.92}
</style>
<div class="phrc-err">
  <div class="phrc-err-top">
    <div class="phrc-err-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
    <h3>{$title}</h3>
    <p class="phrc-err-sub">{$sub}</p>
  </div>
  <div class="phrc-err-body">
    <p class="phrc-err-msg">{$msg}</p>
    {$retryBtn}
  </div>
</div>
HTML;
    }
}
