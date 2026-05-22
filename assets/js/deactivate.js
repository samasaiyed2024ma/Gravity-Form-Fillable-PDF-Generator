(function ($){
    'use strict';

    $(document).ready(function(){
        // Only target plugin's deactivate link
        var $deactivateLink = $( '#the-list [data-slug="gf-pdf-generator"] .deactivate a' );
        if(!$deactivateLink.length) return;

        // Build modal HTML
        var modal = [
           '<div id="gffpdf-deactivate-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;">',
            '  <div style="background:#fff;width:460px;position:absolute;top:50%;left:50%;transform:translate(-50%, -50%);border-radius:6px;padding:30px;box-shadow:0 5px 30px rgba(0,0,0,0.3);">',
            '    <h3 style="margin:0 0 10px;font-size:18px;">Deactivate GF PDF Generator</h3>',
            '    <p style="color:#555;margin:0 0 24px;">What would you like to do with the plugin data (settings, feeds, generated PDF records)?</p>',
            '    <div style="display:flex;gap:12px;">',
            '      <a id="gffpdf-deactivate-remove" href="#" class="button button-primary" style="flex:1;text-align:center;background:#d63638;border-color:#d63638;height:38px;line-height:38px;padding:0;">',
            '        🗑 Remove All Data',
            '      </a>',
            '      <a id="gffpdf-deactivate-keep" href="#" class="button button-secondary" style="flex:1;text-align:center;height:38px;line-height:38px;padding:0;">',
            '        Keep Data &amp; Deactivate',
            '      </a>',
            '    </div>',
            '    <p style="text-align:center;margin:16px 0 0;">',
            '      <a id="gffpdf-deactivate-cancel" href="#" style="color:#999;font-size:13px;">Cancel</a>',
            '    </p>',
            '  </div>',
            '</div>',
        ].join('');

        $('body').append(modal);

        var deactivateHref = $deactivateLink.attr('href');
        var $overlay = $('#gffpdf-deactivate-overlay');

        // Intercept deactivate click
        $deactivateLink.on('click', function(e){
            e.preventDefault();
            $overlay.fadeIn(150);
        });

        // Remove data then deactivate
        $('#gffpdf-deactivate-remove').on('click', function(e){
            e.preventDefault();
            $.post(gffpdf_deactivate.ajax_url, {
                action: 'gffpdf_deactivate_cleanup',
                nonce: gffpdf_deactivate.nonce
            }).always(function(){
                window.location.href = deactivateHref;
            });
        });

        // Keep data, juct deactivate
        $('#gffpdf-deactivate-keep').on('click', function(e){
            e.preventDefault();
            window.location.href = deactivateHref;
        });

        // Cancel
        $('#gffpdf-deactivate-cancel').on('click', function(e){
            e.preventDefault();
            $overlay.fadeOut(150);
        });

        // Close on overlay background click
        $overlay.on('click', function(e){
            if($(e.target).is($overlay)){
                $overlay.fadeOut(150);
            }
        });
    });
})(jQuery);

( function ( $ ) {
    'use strict';

    $( document ).ready( function () {
        console.log( 'gffpdf deactivate.js loaded' );
        console.log( 'gffpdf_deactivate object:', typeof gffpdf_deactivate !== 'undefined' ? gffpdf_deactivate : 'NOT DEFINED' );

        var $deactivateLink = $( '#the-list [data-slug="gf-fillable-pdf-generator"] .deactivate a' );
        console.log( 'deactivate link found:', $deactivateLink.length, $deactivateLink );

        if ( ! $deactivateLink.length ) {
            console.log( 'ERROR: deactivate link not found — check data-slug' );

            // Log all plugin slugs on page so we can find the right one
            $( '#the-list tr' ).each( function () {
                console.log( 'plugin row data-slug:', $( this ).attr( 'data-slug' ) );
            } );
            return;
        }

        $deactivateLink.on( 'click', function ( e ) {
            e.preventDefault();
            console.log( 'deactivate clicked' );
            alert( 'JS is working — popup would show here' );
        } );
    } );
} )( jQuery );