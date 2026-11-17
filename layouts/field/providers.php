<?php
/**
 * Supported gateways field layout — renders all available provider icons.
 *
 * Structure follows guide section 7.4: ph-providers-card > ph-providers-grid > ph-provider-item > ph-provider-art
 * No inline styles — all styling is in providers.css.
 *
 * @var array $displayData
 */

defined('_JEXEC') or die;

$providers = is_array($displayData['providers'] ?? null) ? $displayData['providers'] : [];
$emptyText = htmlspecialchars((string) ($displayData['emptyText'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<style>
.ph-provider-item.ph-is-loading{position:relative}
.ph-provider-item.ph-is-loading .ph-provider-art{opacity:0}
.ph-provider-item.ph-is-loading::before{content:'';position:absolute;inset:10px 8px;border-radius:10px;background:#e2e8f0}
[data-bs-theme="dark"] .ph-provider-item.ph-is-loading::before,.joomla-color-scheme-dark .ph-provider-item.ph-is-loading::before{background:#334155}
.ph-providers-card.ph-is-loading .ph-providers-grid{opacity:1}
</style>
<!--
  ph-providers-card  → outer card (border, padding, max-width) — see providers.css
  ph-providers-grid  → 5-column CSS grid of provider icons
  ph-provider-item   → each cell (flex centering, min-height)
  ph-provider-art    → the <img> (constrained height, object-fit: contain)
-->
<div class="ph-providers-card<?= !empty($providers) ? ' ph-is-loading' : ''; ?>">
    <?php if (!empty($providers)) : ?>
    <div class="ph-providers-grid">

        <?php foreach ($providers as $provider) : ?>
        <?php if (!is_array($provider)) { continue; } ?>
        <?php
        $name      = htmlspecialchars((string) ($provider['provider'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iconLight = htmlspecialchars((string) ($provider['iconUri1'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iconDark  = htmlspecialchars((string) ($provider['iconUri2'] ?? ''), ENT_QUOTES, 'UTF-8');

        if ($iconLight === '' && $iconDark === '') { continue; }
        ?>
        <div class="ph-provider-item ph-is-loading" title="<?= $name; ?>">
            <img
                src="<?= $iconLight !== '' ? $iconLight : $iconDark; ?>"
                data-light-src="<?= $iconLight; ?>"
                data-dark-src="<?= $iconDark; ?>"
                data-fallback-src="<?= $iconDark !== '' ? $iconDark : $iconLight; ?>"
                class="ph-provider-art"
                alt="<?= $name; ?>"
                loading="eager"
                decoding="async"
                onerror="if(this.dataset.fallbackSrc&&this.dataset.fallbackSrc!==this.src){this.src=this.dataset.fallbackSrc;this.dataset.fallbackSrc='';}"
            >
        </div>
        <?php endforeach; ?>

    </div>
    <?php else : ?>
    <p class="ph-providers-empty"><?= $emptyText; ?></p>
    <?php endif; ?>
</div>
<script>
(function(){
    var rel=function(el){if(el)el.classList.remove('ph-is-loading');};
    var settle=function(img,cb){
        if(!img){cb();return;}
        if(img.complete&&img.currentSrc){cb();return;}
        img.addEventListener('load',cb,{once:true});
        img.addEventListener('error',cb,{once:true});
    };
    var card=document.querySelector('.ph-providers-card');
    var items=document.querySelectorAll('.ph-provider-item.ph-is-loading');
    var pending=items.length;
    if(pending===0){rel(card);return;}
    items.forEach(function(item){
        var img=item.querySelector('.ph-provider-art');
        settle(img,function(){rel(item);pending--;if(pending<=0)rel(card);});
    });
}());
</script>