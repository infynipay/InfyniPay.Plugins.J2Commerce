<?php
/**
 * Installation script for plg_j2commerce_payment_infynipay.
 *
 * On every install / update this script:
 *   1. Creates six Infynipay-specific order statuses in #__j2commerce_orderstatuses
 *      with orderstatus_core = 1 so J2Commerce marks them as system (locked) statuses
 *      that cannot be deleted through the admin UI.
 *   2. Writes the correct status IDs back into the plugin's params row in #__extensions
 *      so the mapping is always consistent — no manual configuration required.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;

class plgJ2CommercePaymentInfynipayInstallerScript
{
    /**
     * Infynipay order statuses that this plugin owns.
     * key        → internal identifier used as plugin param name suffix
     * name       → stored in orderstatus_name (varchar 32); Text::_() returns
     *               the raw string when no matching key exists, so human-readable
     *               English text is safe to store directly.
     * cssclass   → Bootstrap 5 badge classes used by the J2Commerce admin UI.
     */
    private const STATUSES = [
        'payment_status_success'   => ['name' => 'Infynipay: Captured',   'css' => 'badge text-bg-success'],
        'payment_status_pending'   => ['name' => 'Infynipay: Pending',    'css' => 'badge text-bg-warning'],
        'payment_status_failed'    => ['name' => 'Infynipay: Failed',     'css' => 'badge text-bg-danger'],
        'payment_status_cancelled' => ['name' => 'Infynipay: Cancelled',  'css' => 'badge text-bg-secondary'],
        'payment_status_disputed'  => ['name' => 'Infynipay: Disputed',   'css' => 'badge text-bg-warning'],
        'payment_status_refunded'  => ['name' => 'Infynipay: Refunded',   'css' => 'badge text-bg-info'],
    ];

    public function preflight($type, InstallerAdapter $parent): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    public function postflight($type, InstallerAdapter $parent): void
    {
        if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
            return;
        }

        try {
            $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $ids = $this->ensureOrderStatuses($db);
            $this->updatePluginParams($db, $ids);
        } catch (\Throwable $e) {
            // Non-fatal — log and continue so the install itself is not aborted.
            Factory::getApplication()->enqueueMessage(
                'Infynipay: could not create order statuses — ' . $e->getMessage()
                . '. Please re-save the plugin once J2Commerce is fully installed.',
                'warning'
            );
        }

        Factory::getApplication()->enqueueMessage(
            'Infynipay payment plugin installed. '
            . 'Six locked order statuses (<em>Infynipay: Captured</em>, <em>Pending</em>, <em>Failed</em>, '
            . '<em>Cancelled</em>, <em>Disputed</em>, <em>Refunded</em>) have been created automatically. '
            . 'Enable the plugin under <strong>Extensions → Plugins</strong> (filter: <code>j2commerce</code>) '
            . 'and click <strong>Connect to Infynipay</strong> to link your merchant account.',
            'info'
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Ensure all six Infynipay order statuses exist in #__j2commerce_orderstatuses.
     * If a status already exists (matched by name) its orderstatus_core flag is
     * reinforced to 1.  Returns a map of param-key → status ID.
     *
     * @return array<string, int>
     */
    private function ensureOrderStatuses(\Joomla\Database\DatabaseInterface $db): array
    {
        $ids = [];

        // Cache the current MAX ordering value once.
        $maxOrdering = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__j2commerce_orderstatuses'))
        )->loadResult();

        foreach (self::STATUSES as $paramKey => $def) {
            // Look for an existing row with this exact name.
            $existing = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('j2commerce_orderstatus_id'))
                    ->from($db->quoteName('#__j2commerce_orderstatuses'))
                    ->where($db->quoteName('orderstatus_name') . ' = ' . $db->quote($def['name']))
            )->loadResult();

            if ($existing > 0) {
                // Enforce the lock in case an admin somehow cleared it.
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName('#__j2commerce_orderstatuses'))
                        ->set($db->quoteName('orderstatus_core') . ' = 1')
                        ->where($db->quoteName('j2commerce_orderstatus_id') . ' = ' . $existing)
                )->execute();

                $ids[$paramKey] = $existing;
                continue;
            }

            // Insert a new, locked status row.
            $maxOrdering++;
            $row = (object) [
                'orderstatus_name'     => $def['name'],
                'orderstatus_cssclass' => $def['css'],
                'orderstatus_core'     => 1,   // ← system status: J2Commerce prevents deletion
                'enabled'              => 1,
                'ordering'             => $maxOrdering,
                'access'               => 0,
            ];

            $db->insertObject('#__j2commerce_orderstatuses', $row);
            $ids[$paramKey] = (int) $db->insertid();
        }

        return $ids;
    }

    /**
     * Write the six status IDs into the plugin's params JSON in #__extensions.
     * Only the six status keys are touched; all other params (credentials, etc.) are preserved.
     *
     * @param array<string, int> $ids
     */
    private function updatePluginParams(\Joomla\Database\DatabaseInterface $db, array $ids): void
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder')  . ' = ' . $db->quote('j2commerce'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('payment_infynipay'));

        $paramsJson = (string) $db->setQuery($query)->loadResult();
        $params     = $paramsJson !== '' ? (json_decode($paramsJson, true) ?: []) : [];

        foreach ($ids as $paramKey => $statusId) {
            $params[$paramKey] = $statusId;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder')  . ' = ' . $db->quote('j2commerce'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('payment_infynipay'))
        )->execute();
    }
}
