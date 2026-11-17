<?php
/**
 * ProvidersField â€” renders the read-only "supported gateways" grid of
 * provider icons on the plugin settings page.
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
use Joomla\CMS\Uri\Uri;

class ProvidersField extends FormField
{
    protected $type = 'Providers';

    private const SHARED_STYLESHEET_PATH = 'media/plg_j2commerce_payment_infynipay/css/setupbutton.css';

    private const SHARED_SCRIPT_PATH = 'media/plg_j2commerce_payment_infynipay/js/setupbutton.js';

    private const STYLESHEET_PATH = 'media/plg_j2commerce_payment_infynipay/css/providers.css';

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

        $providers = $this->fetchAllProviders();

        $this->loadAssets();

        return $this->renderLayout([
            'providers' => $providers,
            'emptyText' => Text::_('PLG_J2COMMERCE_PAYMENT_INFYNIPAY_SUPPORTED_GATEWAYS_EMPTY'),
        ]);
    }

    private function loadAssets(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $document = Factory::getApplication()->getDocument();
        $document->addStyleSheet(Uri::root() . self::SHARED_STYLESHEET_PATH);
        $document->addStyleSheet(Uri::root() . self::STYLESHEET_PATH);
        $document->addScript(Uri::root() . self::SHARED_SCRIPT_PATH);
        $loaded = true;
    }

    private function renderLayout(array $displayData): string
    {
        $layout = new FileLayout('field.providers', $this->getPluginRootPath() . '/layouts');

        return $layout->render($displayData);
    }

    private function getPluginRootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function fetchAllProviders(): array
    {
        $cache = null;

        try {
            $cache = Factory::getCache('plg_j2commerce_payment_infynipay', 'output');
            $cache->setCaching(true);
            $cache->setLifeTime(24 * 60);

            $cached = $cache->get('providers_v2');
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        } catch (\Exception $e) {
            $cache = null;
        }

        $result = $this->doFetchAllProviders();

        if (!empty($result) && $cache !== null) {
            try {
                $cache->store($result, 'providers_v2');
            } catch (\Exception $e) {
                // Cache write failures are non-fatal.
            }
        }

        return $result;
    }

    private function doFetchAllProviders(): array
    {
        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/') . '/api/apps/providers';

        try {
            $response = HttpFactory::getHttp()->get($apiUrl, ['Accept' => 'application/json'], 15);
        } catch (\RuntimeException $e) {
            $this->log('providers API unreachable: ' . $e->getMessage(), Log::WARNING);
            return [];
        }

        if ($response->code !== 200) {
            return [];
        }

        $data = json_decode((string) $response->body, true);
        if (!is_array($data)) {
            return [];
        }

        $providers = [];
        foreach ($data as $provider) {
            if (!is_array($provider)) {
                continue;
            }
            $key = trim((string) ($provider['provider'] ?? ''));
            if ($key === '') {
                continue;
            }

            $iconLight = $this->sanitizeIconUri((string) ($provider['iconUri1'] ?? ''));
            $iconDark  = $this->sanitizeIconUri((string) ($provider['iconUri2'] ?? ''));

            if ($iconLight === '' && $iconDark === '') {
                continue;
            }

            $providers[$key] = [
                'provider' => $key,
                'iconUri1' => $iconLight,
                'iconUri2' => $iconDark,
            ];
        }

        return $this->inlineSvgProviderIcons(array_values($providers));
    }

    private function sanitizeIconUri(string $iconUri): string
    {
        $iconUri = trim($iconUri);

        if ($iconUri === '') {
            return '';
        }

        if (stripos($iconUri, 'data:image/') === 0) {
            return $iconUri;
        }

        if (stripos($iconUri, 'https://') !== 0) {
            return '';
        }

        return filter_var($iconUri, FILTER_VALIDATE_URL) ? $iconUri : '';
    }

    private function inlineSvgProviderIcons(array $providers): array
    {
        foreach ($providers as &$provider) {
            if (!is_array($provider)) {
                continue;
            }
            foreach (['iconUri1', 'iconUri2'] as $iconKey) {
                $provider[$iconKey] = $this->inlineSvgIcon((string) ($provider[$iconKey] ?? ''));
            }
        }
        unset($provider);

        return $providers;
    }

    private function inlineSvgIcon(string $iconUri): string
    {
        static $cache = [];

        if ($iconUri === '' || stripos($iconUri, '.svg') === false || !$this->isTrustedSvgHost($iconUri)) {
            return $iconUri;
        }
        if (isset($cache[$iconUri])) {
            return $cache[$iconUri];
        }

        try {
            $response = HttpFactory::getHttp()->get($iconUri, ['Accept' => 'image/svg+xml,*/*'], 5);
        } catch (\RuntimeException $e) {
            return $cache[$iconUri] = $iconUri;
        }

        $svg = trim((string) $response->body);
        if ($response->code !== 200 || stripos($svg, '<svg') !== 0) {
            return $cache[$iconUri] = $iconUri;
        }

        return $cache[$iconUri] = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function isTrustedSvgHost(string $iconUri): bool
    {
        $host = strtolower((string) parse_url($iconUri, PHP_URL_HOST));

        return $host === 'cdn.infynipay.io' || str_ends_with($host, '.infynipay.io');
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
