<?php
/**
 * SetupbuttonField â€” renders the Infynipay activation/gateways card and
 * handles the OAuth return URL (licenseId + authorizationCode â†’ app credentials).
 *
 * Used by the J2Commerce plugin manifest:
 *   <field name="setup_button" type="Setupbutton" ... />
 */

declare(strict_types=1);

namespace J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Field;

\defined('_JEXEC') or die;

use J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Extension\Infynipay;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

class SetupbuttonField extends FormField
{
    protected $type = 'Setupbutton';

    private const STYLESHEET_PATH = 'media/plg_j2commerce_payment_infynipay/css/setupbutton.css';

    private const SCRIPT_PATH = 'media/plg_j2commerce_payment_infynipay/js/setupbutton.js';

    public function getInput(): string
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load(
            'plg_j2commerce_payment_infynipay',
            $this->getPluginRootPath(),
            null,
            false,
            true
        );

        $input       = $app->getInput();
        $extensionId = $input->getInt('extension_id', 0);

        // Handle OAuth activation callback (licenseId + authorizationCode in URL)
        $licenseId         = $input->getString('licenseId', '');
        $authorizationCode = $input->get('authorizationCode', '', 'RAW');
        $activationError   = '';

        if ($licenseId !== '' && $authorizationCode !== '' && $extensionId > 0) {
            if (!Session::checkToken('get')) {
                $activationError = Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_ACTIVATION_CSRF_ERROR');
                $this->log('Activation: CSRF token mismatch on plugin settings callback.', Log::WARNING);
            } elseif (!$this->isAlreadyActivated($extensionId, $licenseId)) {
                [$isError, $message] = $this->processActivation($extensionId, $licenseId, $authorizationCode);
                if ($isError) {
                    $activationError = $message;
                }
            } else {
                $this->log('Activation: skipped â€” license already activated.');
            }
        }

        // Re-read params after the possible activation save above.
        $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);

        $params = json_decode($db->setQuery($query)->loadResult() ?? '{}', true) ?: [];

        $sandboxAppId = (string) ($params['sandbox_app_id'] ?? '');
        $sandboxToken = (string) ($params['sandbox_token'] ?? '');
        $liveAppId    = (string) ($params['live_app_id'] ?? '');
        $liveToken    = (string) ($params['live_token'] ?? '');

        $sandboxConnected = $sandboxAppId !== '' && $sandboxToken !== '';
        $liveConnected    = $liveAppId !== '' && $liveToken !== '';
        $isConnected      = $sandboxConnected || $liveConnected;

        $returnUrl = Uri::base()
            . 'index.php?option=com_plugins&view=plugin&layout=edit&extension_id=' . $extensionId
            . '&' . Session::getFormToken() . '=1';

        $setupUrl = Infynipay::PANEL_URL
            . '/auth/signin?returnUrl=' . urlencode($returnUrl)
            . '&grantAuthorization=true';

        $baseLogoUrl = Uri::root() . 'plugins/j2commerce/payment_infynipay/images/logos/';
        $logoLight   = $baseLogoUrl . 'infynipay-logo-light.png';
        $logoDark    = $baseLogoUrl . 'infynipay-logo-dark.png';

        $this->loadAssets();

        $serverHost  = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $isLocalhost = $this->isLocalhostUrl(Uri::root())
            || ($serverHost !== '' && $this->isLocalhostUrl('http://' . $serverHost . '/'));

        return $this->renderLayout([
            'activationError' => $activationError,
            'isConnected'     => $isConnected,
            'isLocalhost'     => $isLocalhost,
            'setupUrl'        => $setupUrl,
            'logoLight'        => $logoLight,
            'logoDark'         => $logoDark,
            'apps'             => [
                [
                    'mode'             => 'sandbox',
                    'isConnected'      => $sandboxConnected,
                    'isSetupCompleted' => $sandboxConnected && $this->isAppSetupCompleted($sandboxAppId, $sandboxToken),
                    'url'              => $sandboxAppId !== '' ? rtrim(Infynipay::PANEL_URL, '/') . '/' . urlencode($sandboxAppId) . '/gateways' : '',
                ],
                [
                    'mode'             => 'live',
                    'isConnected'      => $liveConnected,
                    'isSetupCompleted' => $liveConnected && $this->isAppSetupCompleted($liveAppId, $liveToken),
                    'url'              => $liveAppId !== '' ? rtrim(Infynipay::PANEL_URL, '/') . '/' . urlencode($liveAppId) . '/gateways' : '',
                ],
            ],
            'text' => [
                'connect'       => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_CONNECT'),
                'manage'        => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_GATEWAYS_MANAGE'),
                'setup'         => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_GATEWAYS_SETUP'),
                'connected'       => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_STATUS_CONNECTED'),
                'connectedHeader' => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_CONNECTED_HEADER'),
                'notConnected'  => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_GATEWAYS_NOT_CONNECTED'),
                'setupRequired' => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_SETUP_REQUIRED_TITLE'),
                'sandbox'       => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_MODE_SANDBOX'),
                'live'          => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_MODE_LIVE'),
            ],
        ]);
    }

    // ---------------- API helpers ----------------

    private function isAppSetupCompleted(string $appId, string $token): bool
    {
        return $this->getAppStatus($appId, $token)['isSetupCompleted'];
    }

    private function getAppStatus(string $appId, string $token): array
    {
        $default = ['isSetupCompleted' => false];

        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/') . '/api/apps/' . urlencode($appId);

        try {
            $response = HttpFactory::getHttp()->get(
                $apiUrl,
                ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
                3
            );
        } catch (\RuntimeException $e) {
            return $default;
        }

        if ($response->code !== 200) {
            return $default;
        }

        $data = json_decode((string) $response->body, true);

        if (!is_array($data)) {
            return $default;
        }

        return [
            'isSetupCompleted' => !empty($data['isConnectFirstGateway']),
        ];
    }

    private function isAlreadyActivated(int $extensionId, string $licenseId): bool
    {
        $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);

        $stored = json_decode($db->setQuery($query)->loadResult() ?? '{}', true);

        if (!is_array($stored) || (string) ($stored['license_id'] ?? '') !== $licenseId) {
            return false;
        }

        return !empty($stored['sandbox_app_id']) || !empty($stored['live_app_id']);
    }

    /**
     * GET /api/licenses/{licenseId} â†’ save app credentials to #__extensions params.
     *
     * @return array{0: bool, 1: string} [isError, message]
     */
    private function processActivation(int $extensionId, string $licenseId, string $authorizationCode): array
    {
        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/')
            . '/api/licenses/' . urlencode($licenseId);

        try {
            $response = HttpFactory::getHttp()->get(
                $apiUrl,
                ['Accept' => 'text/plain', 'Authorization' => 'Bearer ' . $authorizationCode],
                30
            );
        } catch (\RuntimeException $e) {
            $this->log('Activation API unreachable: ' . $e->getMessage(), Log::ERROR);
            return [true, 'Infynipay API unreachable: ' . $e->getMessage()];
        }

        if ($response->code !== 200) {
            $this->log('Activation API HTTP=' . $response->code, Log::ERROR);
            return [true, 'Infynipay API returned HTTP ' . $response->code];
        }

        $licenses = json_decode((string) $response->body, true);

        if (!is_array($licenses) || empty($licenses)) {
            return [true, 'Infynipay API returned unexpected response.'];
        }

        $sandboxAppId      = '';
        $sandboxToken      = '';
        $liveAppId         = '';
        $liveToken         = '';
        $selectedIsSandbox = null;

        foreach ($licenses as $license) {
            if (!is_array($license)) {
                continue;
            }

            $currentId = (string) ($license['licenseId'] ?? $license['id'] ?? '');
            if ($currentId !== '' && $currentId === $licenseId) {
                $selectedIsSandbox = !empty($license['isSandbox']);
            }

            if (!empty($license['isSandbox'])) {
                $sandboxAppId = (string) ($license['appId']             ?? '');
                $sandboxToken = (string) ($license['authorizationCode'] ?? '');
            } else {
                $liveAppId = (string) ($license['appId']             ?? '');
                $liveToken = (string) ($license['authorizationCode'] ?? '');
            }
        }

        $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);
        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return [true, 'Could not find plugin record (extension_id=' . $extensionId . ').'];
        }

        $params = json_decode($row->params ?? '{}', true) ?: [];

        $params['license_id']     = $licenseId;
        $params['sandbox_app_id'] = $sandboxAppId;
        $params['sandbox_token']  = $sandboxToken;
        $params['live_app_id']    = $liveAppId;
        $params['live_token']     = $liveToken;

        if ($selectedIsSandbox === null) {
            if ($sandboxAppId !== '' && $liveAppId === '') {
                $selectedIsSandbox = true;
            } elseif ($liveAppId !== '' && $sandboxAppId === '') {
                $selectedIsSandbox = false;
            } else {
                $selectedIsSandbox = (int) ($params['sandbox'] ?? 1) === 1;
            }
        }
        $params['sandbox'] = $selectedIsSandbox ? '1' : '0';

        if ($sandboxAppId !== '' && empty($params['sandbox_webhook_secret'])) {
            $params['sandbox_webhook_secret'] = bin2hex(random_bytes(32));
        }
        if ($liveAppId !== '' && empty($params['live_webhook_secret'])) {
            $params['live_webhook_secret'] = bin2hex(random_bytes(32));
        }

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId)
        )->execute();

        $this->log('Credentials saved. mode=' . ($selectedIsSandbox ? 'sandbox' : 'live'));

        if ($sandboxAppId !== '' && $sandboxToken !== '' && !empty($params['sandbox_webhook_secret'])) {
            $this->registerWebhookAuth($sandboxAppId, $sandboxToken, $params['sandbox_webhook_secret']);
        }
        if ($liveAppId !== '' && $liveToken !== '' && !empty($params['live_webhook_secret'])) {
            $this->registerWebhookAuth($liveAppId, $liveToken, $params['live_webhook_secret']);
        }

        return [false, ''];
    }

    private function registerWebhookAuth(string $appId, string $token, string $webhookSecret): void
    {
        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/') . '/api/apps/' . urlencode($appId);

        $payload = json_encode([
            'webhookAuthorizationHeaderScheme'    => ['value' => 'Bearer'],
            'webhookAuthorizationHeaderParameter' => ['value' => $webhookSecret],
        ], JSON_UNESCAPED_SLASHES);

        try {
            $response = HttpFactory::getHttp()->patch(
                $apiUrl,
                $payload,
                [
                    'accept'        => 'text/plain',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                15
            );
            $this->log('registerWebhookAuth: HTTP=' . $response->code);
        } catch (\RuntimeException $e) {
            $this->log('registerWebhookAuth failed: ' . $e->getMessage(), Log::WARNING);
        }
    }

    // ---------------- Utility ----------------

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

    private function loadAssets(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $document = Factory::getApplication()->getDocument();
        $document->addStyleSheet(Uri::root() . self::STYLESHEET_PATH . '?v=1.0.0');
        $document->addScript(Uri::root() . self::SCRIPT_PATH . '?v=1.0.0');
        $loaded = true;
    }

    private function renderLayout(array $displayData): string
    {
        $layout = new FileLayout('field.setupbutton', $this->getPluginRootPath() . '/layouts');

        return $layout->render($displayData);
    }

    private function getPluginRootPath(): string
    {
        return dirname(__DIR__, 2);
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
}
