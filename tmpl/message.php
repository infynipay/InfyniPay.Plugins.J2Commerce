<?php
/**
 * Generic message template (cancel / error).
 *
 * @var \stdClass|null $vars
 */
\defined('_JEXEC') or die;

$vars = $displayData['vars'] ?? null;
?>
<div class="payment_infynipay_message alert alert-info">
<?php if ($vars !== null && !empty($vars->message)) : ?>
<?php echo \Joomla\CMS\Language\Text::_($vars->message); ?>
<?php endif; ?>
</div>
