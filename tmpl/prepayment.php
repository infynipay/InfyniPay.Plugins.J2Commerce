<?php
/**
 * @var \stdClass|null $vars
 */
\defined('_JEXEC') or die;

$vars = $displayData['vars'] ?? null;
?>
<div class="payment_infynipay_prepayment">
<?php if ($vars !== null && !empty($vars->html)) : ?>
<?php echo $vars->html; ?>
<?php endif; ?>
</div>
