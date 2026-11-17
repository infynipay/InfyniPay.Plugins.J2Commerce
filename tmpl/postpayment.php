<?php
/**
 * Postpayment template — renders either the confirmation card or the
 * "service unavailable" card depending on what the extension built.
 *
 * @var \stdClass|null $vars
 */
\defined('_JEXEC') or die;

$vars = $displayData['vars'] ?? null;
?>
<div class="payment_infynipay_postpayment">
<?php if ($vars !== null) : ?>
    <?php if (!empty($vars->service_card)) : ?>
        <?php echo $vars->service_card; ?>
    <?php endif; ?>

    <?php if (!empty($vars->confirmation_card)) : ?>
        <?php echo $vars->confirmation_card; ?>
    <?php endif; ?>

    <?php if (!empty($vars->onafterpayment_text)) : ?>
        <div class="payment_infynipay_onafterpayment mt-3">
            <?php echo $vars->onafterpayment_text; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
