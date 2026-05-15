jQuery(document).ready(function($) {

    function makeCode(container) {
        if (typeof QRCode === 'undefined') {
            console.log("QRCode library not loaded");
            return;
        }

        var id = container.data('id');
        var id2 = container.data('id2');
        var qrColor = $('#wcu-qr-code-color-picker' + id).val();
        var qrContainerId = "showqrcode" + id;
        
        $("#" + qrContainerId).html("");

        var qrcode = new QRCode(qrContainerId, {
            text: "",
            width: 250,
            height: 250,
            colorDark: qrColor,
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });

        var referralurl = "";
        if (id2 === "p1") {
            referralurl = $.trim($('#p1short').text());
            if (!referralurl) {
                referralurl = $.trim($('#p1').text());
            }
        } else {
            referralurl = $.trim($('#' + id2).text());
        }

        referralurl = decodeURIComponent(encodeURIComponent(referralurl));

        qrcode.makeCode(referralurl);

        // Logo logic
        var logoEnable = container.data('logo-enable');
        var logoUrl = container.data('logo-url');

        if (logoEnable && logoUrl) {
            // The qrcode library draws to <canvas> synchronously but only sets
            // the paired <img>.src after an async data-URI probe (_safeSetDataURI).
            // On mobile that probe can take longer than any fixed timeout, so we
            // read the canvas directly — it is always available immediately.
            var qrCanvas = $('#' + qrContainerId + ' canvas')[0];
            var qrImg   = $('#' + qrContainerId + ' img')[0];

            if (qrCanvas) {
                var compositeCanvas = document.createElement('canvas');
                var ctx = compositeCanvas.getContext('2d');
                compositeCanvas.width  = 250;
                compositeCanvas.height = 250;

                // Draw QR from the synchronously-painted canvas
                ctx.drawImage(qrCanvas, 0, 0, 250, 250);

                var logoSize  = 50;
                var clearSize = 50;
                var x = (compositeCanvas.width  - clearSize) / 2;
                var y = (compositeCanvas.height - clearSize) / 2;

                ctx.fillStyle = '#ffffff';
                ctx.fillRect(x, y, clearSize, clearSize);

                ctx.strokeStyle = '#000000';
                ctx.lineWidth = 1;
                ctx.strokeRect(x, y, clearSize, clearSize);

                var logo = new Image();
                logo.crossOrigin = 'anonymous';
                logo.onload = function() {
                    var logoX = (compositeCanvas.width  - logoSize) / 2;
                    var logoY = (compositeCanvas.height - logoSize) / 2;
                    ctx.drawImage(logo, logoX, logoY, logoSize, logoSize);

                    try {
                        var dataUrl = compositeCanvas.toDataURL('image/png');
                        if (qrImg) {
                            qrImg.src = dataUrl;
                            qrImg.style.display = 'block';
                        }
                        // Store for download fallback
                        $('#' + qrContainerId).data('composite-canvas', compositeCanvas);
                    } catch (e) {
                        console.log('QR logo canvas error: ' + e);
                    }
                    if (qrImg) {
                        qrImg.setAttribute('data-logo-added', 'true');
                    }
                };
                logo.onerror = function() {
                    // Logo failed to load — unblock download so it still works
                    if (qrImg) {
                        qrImg.setAttribute('data-logo-added', 'true');
                    }
                };
                logo.src = logoUrl;
            }
        }
    }

    // Event delegation for Generate button
    $(document).on('click', '.wcusage_landing_qr_show', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var container = $(".display-qr-code" + id);
        
        if (container.is(":visible")) {
            container.hide();
        } else {
            $("#showqrcode" + id).show();
            container.css("opacity", 0).css("display", "inline-block").animate({ opacity: 1 }, 500);
            $("#wcu-download-qr" + id).show();
            
            setTimeout(function() {
                try {
                    makeCode(container);
                } catch (err) {
                    console.log("Error generating QR code: " + err);
                }
            }, 50);
        }
    });

    // Event delegation for Color Picker
    $(document).on('change', '.wcu-qr-code-color-picker-input', function() {
        var id = $(this).attr('id').replace('wcu-qr-code-color-picker', '');
        var container = $(".display-qr-code" + id);
        setTimeout(function() {
            makeCode(container);
        }, 200);
    });

    // Event delegation for Campaign Change
    $(document).on('change', '#wcu-referral-campaign', function() {
        $('.wcu-display-qr-code:visible').each(function() {
            var container = $(this);
            setTimeout(function() {
                makeCode(container);
            }, 200);
        });
    });

    // Event delegation for Custom URL Input
    $(document).on('input', '.wcusage_custom_ref_url', function() {
        $('.wcu-display-qr-code:visible').each(function() {
            var container = $(this);
            setTimeout(function() {
                makeCode(container);
            }, 200);
        });
    });

    // Event delegation for Short URL Generate
    $(document).on('click', '#generate-short-url', function() {
        $('.wcu-display-qr-code:visible').each(function() {
            var container = $(this);
            setTimeout(function() {
                makeCode(container);
            }, 2500);
        });
    });

    // Download Functionality
    window.wcusage_downloadQR = function(id) {
        var container  = $(".display-qr-code" + id);
        var title      = container.data('title');
        var logoEnable = container.data('logo-enable');
        var logoUrl    = container.data('logo-url');

        var img    = $('#showqrcode' + id + ' img')[0];
        var canvas = $('#showqrcode' + id + ' canvas')[0];

        if (logoEnable && logoUrl) {
            // Poll for the logo composite, but give up after 3 s so the
            // button never silently hangs forever (was: no timeout at all).
            var attempts    = 0;
            var maxAttempts = 30; // 30 × 100 ms = 3 s
            var checkLogo   = function() {
                if (img && img.getAttribute('data-logo-added') === 'true') {
                    downloadImage(img.src, title);
                } else if (attempts >= maxAttempts) {
                    // Timed out — fall back to whatever is available
                    downloadFromSource(img, canvas, title);
                } else {
                    attempts++;
                    setTimeout(checkLogo, 100);
                }
            };
            checkLogo();
        } else {
            downloadFromSource(img, canvas, title);
        }
    };

    // Download from img.src, falling back to the raw canvas if src is empty
    function downloadFromSource(img, canvas, title) {
        if (img && img.src && img.src !== window.location.href) {
            downloadImage(img.src, title);
        } else if (canvas) {
            try {
                downloadImage(canvas.toDataURL('image/png'), title);
            } catch (e) {
                console.log('QR download canvas error: ' + e);
            }
        }
    }

    // Convert data URL → Blob → object URL so mobile browsers download the
    // file as a proper PNG instead of saving it as "<name>.png.html".
    function downloadImage(dataUrl, title) {
        if (!dataUrl || dataUrl === window.location.href) {
            console.log('QR download: no image data available');
            return;
        }
        var imgName = 'qr-' + title;
        try {
            var parts = dataUrl.split(',');
            var mime  = parts[0].match(/:(.*?);/)[1];
            var raw   = atob(parts[1]);
            var n     = raw.length;
            var bytes = new Uint8Array(n);
            while (n--) { bytes[n] = raw.charCodeAt(n); }
            var blob    = new Blob([bytes], { type: mime });
            var blobUrl = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href     = blobUrl;
            a.download = imgName + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function() { URL.revokeObjectURL(blobUrl); }, 1000);
        } catch (e) {
            // Last-resort fallback for very old browsers
            var a = document.createElement('a');
            a.href     = dataUrl;
            a.download = imgName + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    }
    
    // Attach click handler for download buttons
    $(document).on('click', '.wcu-download-qr', function() {
        var id = $(this).attr('id').replace('wcu-download-qr', '');
        wcusage_downloadQR(id);
    });

    // Auto-generate QR codes for shortcode
    $('.wcu-display-qr-code-shortcode').each(function() {
        var container = $(this);
        var id = container.data('id');
        $("#showqrcode" + id).show();
        $("#wcu-download-qr" + id).show();
        setTimeout(function() {
            makeCode(container);
        }, 200);
    });

});
