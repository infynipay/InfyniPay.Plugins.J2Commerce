<?php
/**
 * Setup button field layout — renders Sandbox and Live gateway cards.
 *
 * @var array $displayData
 */

defined('_JEXEC') or die;

$activationError = (string) ($displayData['activationError'] ?? '');
$isConnected     = !empty($displayData['isConnected']);
$isLocalhost     = !empty($displayData['isLocalhost']);
$setupUrl        = (string) ($displayData['setupUrl'] ?? '');
$logoLight       = (string) ($displayData['logoLight'] ?? '');
$logoDark        = (string) ($displayData['logoDark'] ?? '');
$apps            = is_array($displayData['apps'] ?? null) ? $displayData['apps'] : [];
$text            = $displayData['text'] ?? [];

$connectText       = (string) ($text['connect'] ?? 'Connect');
$manageText        = (string) ($text['manage'] ?? 'Manage Gateways');
$setupText         = (string) ($text['setup'] ?? 'Complete Setup');
$connectedText     = (string) ($text['connected'] ?? 'Connected');
$connectedHeader   = (string) ($text['connectedHeader'] ?? 'InfyniPay is connected.');
$notConnectedText  = (string) ($text['notConnected'] ?? 'Not connected');
$setupRequiredText = (string) ($text['setupRequired'] ?? 'Setup required');
$sandboxText       = (string) ($text['sandbox'] ?? 'Sandbox');
$liveText          = (string) ($text['live'] ?? 'Live');

$setupUrlEscaped  = htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8');
$logoLightEscaped = htmlspecialchars($logoLight, ENT_QUOTES, 'UTF-8');
$logoDarkEscaped  = htmlspecialchars($logoDark, ENT_QUOTES, 'UTF-8');
?>
<style>
.ph-logo-dk{display:none}.ph-logo-lt{display:block}
@media(prefers-color-scheme:dark){.ph-logo-lt{display:none}.ph-logo-dk{display:block}}
[data-bs-theme="dark"] .ph-logo-lt,.joomla-color-scheme-dark .ph-logo-lt{display:none}
[data-bs-theme="dark"] .ph-logo-dk,.joomla-color-scheme-dark .ph-logo-dk{display:block}
@keyframes ph-skeleton-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.ph-card-disconnected.ph-is-loading{position:relative;overflow:hidden}
.ph-card-disconnected.ph-is-loading>*{opacity:0}
.ph-card-disconnected.ph-is-loading::before{content:'';position:absolute;inset:0;z-index:2;background:linear-gradient(#e2e8f0 0 0) 1.15rem 1rem/148px 40px no-repeat,linear-gradient(#e2e8f0 0 0) 11.2rem 1.35rem/270px 16px no-repeat,linear-gradient(#e2e8f0 0 0) 1.15rem calc(100% - 3rem)/190px 38px no-repeat,linear-gradient(90deg,rgba(255,255,255,0) 0%,rgba(255,255,255,.72) 50%,rgba(255,255,255,0) 100%) 0 0/200% 100% no-repeat;animation:ph-skeleton-shimmer 1.15s ease-in-out infinite}
[data-bs-theme="dark"] .ph-card-disconnected.ph-is-loading::before,.joomla-color-scheme-dark .ph-card-disconnected.ph-is-loading::before{background:linear-gradient(#334155 0 0) 1.15rem 1rem/148px 40px no-repeat,linear-gradient(#334155 0 0) 11.2rem 1.35rem/270px 16px no-repeat,linear-gradient(#334155 0 0) 1.15rem calc(100% - 3rem)/190px 38px no-repeat,linear-gradient(90deg,rgba(15,23,42,0) 0%,rgba(148,163,184,.24) 50%,rgba(15,23,42,0) 100%) 0 0/200% 100% no-repeat}
</style>
<?php if ($activationError !== '') : ?>
<div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
    <?= htmlspecialchars($activationError, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($isLocalhost) : ?>
<style>
#ph-localhost-warn{display:flex!important;gap:12px;max-width:720px;margin:0 0 18px;padding:14px 16px;border-radius:10px;background:#fffbeb;border:1.5px solid #f59e0b;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-sizing:border-box}
#ph-localhost-warn p{margin:4px 0 10px;font-size:13px;line-height:1.55;color:#78350f}
#ph-localhost-warn strong{color:#92400e}
#ph-localhost-warn a{font-size:13px;font-weight:600;color:#b45309;text-decoration:underline}
#ph-localhost-warn-title{font-size:13px;font-weight:700;color:#92400e;margin:0}
</style>
<div id="ph-localhost-warn">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;width:20px;height:20px;color:#b45309;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
        <p id="ph-localhost-warn-title">Webhook delivery unavailable</p>
        <p>Your Joomla site is running on a <strong>localhost / private network URL</strong>. Infynipay webhook notifications cannot reach a local/private network address. Payment status updates will not be received.</p>
        <p style="margin-bottom:0">To receive webhooks, deploy your site to a public URL or use a tunnel such as Cloudflare Tunnel or ngrok.</p>
        <a href="https://github.com/infynipay/infynipay-docs/blob/main/CLOUDFLARE-TUNNEL-INSTRUCTIONS.md" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-top:6px">
            Learn how to set up a Cloudflare Tunnel &rarr;
        </a>
    </div>
</div>
<?php endif; ?>

<?php /* ── Connect banner — shown when no account has been connected yet ── */ ?>
<?php if (!$isConnected) : ?>
<div class="card mb-3 ph-card-disconnected ph-is-loading" style="max-width:720px; border:1px solid #000; box-shadow:none">
    <div class="card-header ph-card-disconnected-header d-flex align-items-center gap-3 fw-semibold">
        <div class="ph-logo-wrap">
            <img src="<?= $logoLightEscaped; ?>" alt="Infynipay" class="ph-logo ph-logo-lt" loading="eager" decoding="async">
            <img src="<?= $logoDarkEscaped; ?>" alt="Infynipay" class="ph-logo ph-logo-dk" loading="eager" decoding="async">
        </div>
        <span class="ph-card-disconnected-title"><?= htmlspecialchars($setupRequiredText, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="card-body ph-card-disconnected-body">
        <a href="<?= $setupUrlEscaped; ?>" class="btn btn-primary">
            &#128279;&nbsp;<?= htmlspecialchars($connectText, ENT_QUOTES, 'UTF-8'); ?>
        </a>
    </div>
</div>
<?php endif; ?>

<?php /* ── Gateway cards — only shown when account is connected (sandbox + live) ── */ ?>
<?php if ($isConnected) : ?>
<div class="card mb-3 ph-card-connected">
    <div class="card-header ph-card-connected-header d-flex align-items-center gap-3 fw-semibold">
        <div class="ph-logo-wrap">
            <img src="<?= $logoLightEscaped; ?>" alt="Infynipay" class="ph-logo ph-logo-lt" loading="eager" decoding="async">
            <img src="<?= $logoDarkEscaped; ?>" alt="Infynipay" class="ph-logo ph-logo-dk" loading="eager" decoding="async">
        </div>
        <span class="ph-card-connected-title"><?= htmlspecialchars($connectedHeader, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="card-body ph-card-connected-body">
<div class="ph-gateway-apps">
<?php foreach ($apps as $app) : ?>
    <?php if (!is_array($app)) { continue; } ?>
    <?php
    $mode             = (string) ($app['mode'] ?? '');
    $isSandbox        = $mode === 'sandbox';
    $modeLabel        = $isSandbox ? $sandboxText : $liveText;
    $modeSubtitle     = $isSandbox
        ? 'Test environment for trial transactions'
        : 'Production environment for real payments';
    $appConnected      = !empty($app['isConnected']);
    $appSetupCompleted = !empty($app['isSetupCompleted']);
    $url               = htmlspecialchars((string) ($app['url'] ?? ''), ENT_QUOTES, 'UTF-8');

    // Determine state class and button style
    if ($appConnected && $appSetupCompleted) {
        $stateClass  = 'ready';
        $statusLabel = htmlspecialchars($modeLabel . ' account is ready', ENT_QUOTES, 'UTF-8');
        $statusText  = $manageText;
        $btnClass    = 'btn btn-primary btn-sm ph-gateway-card__action';
    } elseif ($appConnected) {
        $stateClass  = 'incomplete';
        $statusLabel = htmlspecialchars('Setup incomplete', ENT_QUOTES, 'UTF-8');
        $statusText  = $setupText;
        $btnClass    = 'btn btn-warning btn-sm ph-gateway-card__action';
    } else {
        $stateClass  = 'disconnected';
        $statusLabel = htmlspecialchars($notConnectedText, ENT_QUOTES, 'UTF-8');
        $statusText  = $connectText;
        $btnClass    = 'btn btn-outline-secondary btn-sm ph-gateway-card__action';
    }

    $modeIcon = $isSandbox
        ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v6.5L4.5 18a2 2 0 0 0 1.7 3h11.6a2 2 0 0 0 1.7-3L14 8.5V2"/><path d="M9 2h6"/><path d="M7 14h10"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>';

    $btnHref   = ($appConnected && $url !== '') ? $url : $setupUrlEscaped;
    $btnTarget = ($appConnected && $url !== '') ? ' target="_blank" rel="noopener noreferrer"' : '';
    ?>
    <div class="ph-gateway-card ph-gateway-card--<?= $mode; ?> ph-gateway-card--<?= $stateClass; ?> ph-is-loading">
        <div class="ph-gateway-card__accent"></div>
        <div class="ph-gateway-card__body">

            <div class="ph-gateway-card__head">
                <span class="ph-gateway-card__icon">
                    <?= $modeIcon; ?>
                </span>
                <div class="ph-gateway-card__title-wrap">
                    <div class="ph-gateway-card__title"><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="ph-gateway-card__subtitle"><?= htmlspecialchars($modeSubtitle, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>

            <div class="ph-gateway-card__status">
                <span class="ph-gateway-card__dot"></span>
                <span><?= $statusLabel; ?></span>
            </div>

            <a href="<?= $btnHref; ?>"<?= $btnTarget; ?> class="<?= htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8'); ?>">
                <?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>
            </a>

        </div>
    </div>
<?php endforeach; ?>
</div>
    </div>
</div>
<?php endif; ?>
<script>
(function(){
    var rel=function(el){if(el)el.classList.remove('ph-is-loading');};
    var visibleLogo=function(card){
        var logos=card.querySelectorAll('.ph-logo-lt,.ph-logo-dk'),vis=null;
        logos.forEach(function(img){if(window.getComputedStyle(img).display!=='none')vis=img;});
        return vis||logos[0]||null;
    };
    var settle=function(img,cb){
        if(!img){cb();return;}
        if(img.complete&&img.currentSrc){cb();return;}
        img.addEventListener('load',cb,{once:true});
        img.addEventListener('error',cb,{once:true});
    };
    // Disconnected "Connect" card — release once its header logo settles.
    document.querySelectorAll('.ph-card-disconnected').forEach(function(card){
        settle(visibleLogo(card),function(){rel(card);});
    });
    // Connected card — release the Sandbox/Live gateway cards (incl. the
    // Complete Setup button) once the header logo settles, so they get a real
    // skeleton instead of flashing for one frame. The timeout is a safety net
    // in case the image load/error events never fire (cached/blocked images).
    document.querySelectorAll('.ph-card-connected').forEach(function(card){
        var release=function(){
            card.querySelectorAll('.ph-gateway-card.ph-is-loading').forEach(rel);
        };
        settle(visibleLogo(card),release);
        window.setTimeout(release,2500);
    });
}());
</script>