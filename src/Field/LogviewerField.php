<?php
/**
 * LogviewerField — displays the absolute path to the Infynipay log file
 * on the plugin settings page so the admin can locate it quickly.
 */

declare(strict_types=1);

namespace J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Uri\Uri;

class LogviewerField extends FormField
{
    protected $type = 'Logviewer';

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

        $logPath = $app->get('log_path', $this->getDefaultLogPath());
        $logFile = rtrim($logPath, '/\\') . DIRECTORY_SEPARATOR . 'plg_j2commerce_payment_infynipay.php';

        $this->loadAssets();

        return '<div class="card mb-3 ph-log-card ph-card-disconnected">'
            . '<div class="card-body ph-card-disconnected-body">'
            . '<p class="ph-log-path">' . htmlspecialchars($logFile, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>'
            . '</div>';
    }

    private function getPluginRootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function loadAssets(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $document = Factory::getApplication()->getDocument();
        $document->addStyleSheet(Uri::root() . self::STYLESHEET_PATH);
        $document->addScript(Uri::root() . self::SCRIPT_PATH);
        $loaded = true;
    }

    private function getDefaultLogPath(): string
    {
        return dirname($this->getPluginRootPath(), 3) . '/administrator/logs';
    }
}
