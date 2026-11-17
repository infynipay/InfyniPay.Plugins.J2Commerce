<?php
/**
 * @package     J2Commerce
 * @subpackage  plg_j2commerce_payment_infynipay
 *
 * @copyright   (C) 2024-2026 Infynipay
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use J2Commerce\Plugin\J2Commerce\PaymentInfynipay\Extension\Infynipay;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = PluginHelper::getPlugin('j2commerce', 'payment_infynipay');

                return new Infynipay(
                    $dispatcher,
                    (array) $plugin,
                    Factory::getApplication()->getLanguage(),
                    $container->get('DatabaseDriver')
                );
            }
        );
    }
};
