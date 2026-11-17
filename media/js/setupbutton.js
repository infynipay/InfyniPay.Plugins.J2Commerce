document.addEventListener('DOMContentLoaded', function () {
    var licenseInput = document.querySelector('input[name="jform[params][license_id]"]');
    var sandboxInput = document.querySelector('input[name="jform[params][sandbox]"]');

    var isDarkTheme = function () {
        var root = document.documentElement;

        if (root.getAttribute('data-bs-theme') === 'dark' || document.body.classList.contains('joomla-color-scheme-dark')) {
            return true;
        }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    };

    var releaseSkeleton = function (element) {
        if (element) {
            element.classList.remove('ph-is-loading');
        }
    };

    var whenImageSettles = function (image, callback) {
        var done = false;
        var finish = function () {
            if (done) {
                return;
            }

            done = true;
            callback();
        };

        if (!image) {
            finish();
            return;
        }

        if (image.complete && image.currentSrc) {
            finish();
            return;
        }

        image.addEventListener('load', finish, { once: true });
        image.addEventListener('error', finish, { once: true });
    };

    var initThemeLogos = function () {
        // Logo visibility is now handled by CSS (ph-logo-lt / ph-logo-dk).
        // This function only needs to release the disconnected-card skeleton
        // once the visible logo image has settled (loaded or errored).
        document.querySelectorAll('.ph-card-disconnected').forEach(function (card) {
            // Find whichever logo is currently visible (not display:none).
            var logos = card.querySelectorAll('.ph-logo-lt, .ph-logo-dk');
            var visible = null;
            logos.forEach(function (img) {
                if (window.getComputedStyle(img).display !== 'none') {
                    visible = img;
                }
            });
            whenImageSettles(visible || logos[0] || null, function () {
                releaseSkeleton(card);
            });
        });
    };

    var initProviderImages = function () {
        var useDark = isDarkTheme();
        var pendingProviders = 0;
        var providerCard = document.querySelector('.ph-providers-card');

        var finishProvider = function (item) {
            releaseSkeleton(item);
            pendingProviders -= 1;

            if (pendingProviders <= 0) {
                releaseSkeleton(providerCard);
            }
        };

        document.querySelectorAll('.ph-provider-art').forEach(function (image) {
            var lightSrc = image.dataset.lightSrc || '';
            var darkSrc = image.dataset.darkSrc || '';
            var preferredSrc = useDark ? (darkSrc || lightSrc) : (lightSrc || darkSrc);
            var fallbackSrc = useDark ? (lightSrc || '') : (darkSrc || '');
            var item = image.closest('.ph-provider-item');

            pendingProviders += 1;

            if (preferredSrc) {
                image.style.display = '';

                if (item) {
                    item.style.display = '';
                }

                image.dataset.fallbackSrc = fallbackSrc;

                if (image.getAttribute('src') !== preferredSrc) {
                    image.setAttribute('src', preferredSrc);
                }

                whenImageSettles(image, function () {
                    finishProvider(item);
                });
            } else {
                finishProvider(item);
            }
        });

        if (pendingProviders === 0) {
            releaseSkeleton(providerCard);
        }
    };

    // ── Responsive grid column sync ────────────────────────────────────────
    var syncProviderGrid = function () {
        document.querySelectorAll('.ph-providers-grid').forEach(function (grid) {
            var width = grid.clientWidth;
            var columns = 5;

            if (width <= 420)      { columns = 2; }
            else if (width <= 560) { columns = 3; }
            else if (width <= 660) { columns = 4; }

            grid.style.display = 'grid';
            grid.style.gridTemplateColumns = 'repeat(' + columns + ', minmax(0, 1fr))';
            grid.style.gridAutoRows = 'minmax(48px, auto)';
            grid.style.gap = '0.5rem';

            grid.querySelectorAll('.ph-provider-item').forEach(function (item) {
                item.style.width    = '100%';
                item.style.maxWidth = 'none';
                item.style.minWidth = '0';
                item.style.flex     = 'none';
            });
        });
    };

    // ── SVG MIME type fix ─────────────────────────────────────────────────
    // Some CDNs (e.g. Azure Blob Storage) serve .svg files with
    // Content-Type: application/octet-stream. Browsers refuse to render them
    // in <img> tags. Fix: fetch, re-wrap as Blob with image/svg+xml, use object URL.
    var svgBlobCache = {};

    var fixSvgImages = function () {
        if (!window.fetch || !window.Blob || !window.URL || !URL.createObjectURL) { return; }

        document.querySelectorAll('.ph-provider-art').forEach(function (img) {
            var src = img.getAttribute('src') || '';

            if (!src || src.slice(0, 5) === 'blob:' || src.slice(0, 5) === 'data:') { return; }

            var base = src.split('?')[0].toLowerCase();
            if (base.slice(-4) !== '.svg') { return; }

            if (svgBlobCache[src]) {
                img.src = svgBlobCache[src];
                return;
            }

            fetch(src, { mode: 'cors', credentials: 'omit' })
                .then(function (r) {
                    var ct = (r.headers.get('content-type') || '').toLowerCase();
                    if (ct.indexOf('svg') !== -1) { return null; }
                    return r.text();
                })
                .then(function (text) {
                    if (!text) { return; }
                    var blob = new Blob([text], { type: 'image/svg+xml' });
                    var url  = URL.createObjectURL(blob);
                    svgBlobCache[src] = url;
                    if (img.getAttribute('src') === src) {
                        img.removeAttribute('onerror');
                        img.src = url;
                    }
                })
                .catch(function () {});
        });
    };

    var initCardRows = function () {
        var label = document.querySelector('input[name="jform[params][sandbox]"]');
        var labelRow = label ? label.closest('.control-group') : null;
        var labelColumn = labelRow ? labelRow.querySelector('.control-label') : null;

        if (labelColumn) {
            document.documentElement.style.setProperty('--ph-infynipay-label-offset', labelColumn.offsetWidth + 'px');
        }

        document.querySelectorAll('.ph-card-connected, .ph-card-disconnected, .ph-providers-card, .ph-log-card').forEach(function (card) {
            var row = card.closest('.control-group');

            if (row) {
                row.classList.add('ph-infynipay-control-group');

                if (!row.querySelector('.control-label label') && !card.classList.contains('ph-providers-card') && !card.classList.contains('ph-log-card') && (card.classList.contains('ph-card-connected') || card.classList.contains('ph-card-disconnected'))) {
                    row.classList.add('ph-infynipay-setup-control-group');
                }
            }
        });

        [
            'sandbox',
            'setup_button',
            'providers_overview',
            'order_status_success',
            'order_status_pending',
            'order_status_failed',
            'order_status_cancelled',
            'order_status_disputed',
            'order_status_refunded',
            'log_viewer'
        ].forEach(function (fieldName) {
            var field = document.querySelector('[name="jform[params][' + fieldName + ']"]');
            var row = field ? field.closest('.control-group') : null;

            if (row) {
                row.classList.add('ph-infynipay-control-group');
            }
        });

        [
            'order_status_success',
            'order_status_pending',
            'order_status_failed',
            'order_status_cancelled',
            'order_status_disputed',
            'order_status_refunded'
        ].forEach(function (fieldName) {
            var field = document.querySelector('[name="jform[params][' + fieldName + ']"]');
            var row = field ? field.closest('.control-group') : null;

            if (row) {
                row.classList.add('ph-status-row');
            }
        });

        // Wrap all status rows in a single compact grid container
        var firstStatusRow = document.querySelector('.ph-status-row');
        if (firstStatusRow && firstStatusRow.parentNode) {
            var statusGrid = document.createElement('div');
            statusGrid.className = 'ph-status-grid';
            firstStatusRow.parentNode.insertBefore(statusGrid, firstStatusRow);
            document.querySelectorAll('.ph-status-row').forEach(function (row) {
                statusGrid.appendChild(row);
            });
        }
    };

    initCardRows();
    initThemeLogos();
    initProviderImages();
    fixSvgImages();
    syncProviderGrid();

    // Release the Sandbox/Live gateway cards (including the Complete Setup
    // button) once the connected-card header logo settles, matching the
    // disconnected card's skeleton behaviour. The timeout is a safety net in
    // case the image load/error events never fire (cached/blocked images).
    document.querySelectorAll('.ph-card-connected').forEach(function (card) {
        var release = function () {
            card.querySelectorAll('.ph-gateway-card.ph-is-loading').forEach(function (gatewayCard) {
                releaseSkeleton(gatewayCard);
            });
        };

        var logos = card.querySelectorAll('.ph-logo-lt, .ph-logo-dk');
        var visible = null;
        logos.forEach(function (img) {
            if (window.getComputedStyle(img).display !== 'none') {
                visible = img;
            }
        });

        whenImageSettles(visible || logos[0] || null, release);
        window.setTimeout(release, 2500);
    });

    window.addEventListener('resize', syncProviderGrid);

    if (window.ResizeObserver) {
        document.querySelectorAll('.ph-providers-card').forEach(function (card) {
            new ResizeObserver(syncProviderGrid).observe(card);
        });
    }

    if (!licenseInput) {
        return;
    }

    var connectedRows = [];

    document.querySelectorAll('.ph-connected-only').forEach(function (element) {
        var row = element.closest('.control-group, .control-wrapper, .mb-3, .field-spacer, .form-group');

        if (!row) {
            row = element.closest('fieldset, .controls, .control-field');
        }

        if (row) {
            connectedRows.push(row);
        }
    });

    var syncVisibility = function () {
        var isConnected = licenseInput.value.trim() !== '';
        connectedRows.forEach(function (row) {
            row.style.display = isConnected ? '' : 'none';
        });
    };

    syncVisibility();

    var observer = new MutationObserver(syncVisibility);
    observer.observe(licenseInput, {
        attributes: true,
        attributeFilter: ['value']
    });

    licenseInput.addEventListener('change', syncVisibility);
});
