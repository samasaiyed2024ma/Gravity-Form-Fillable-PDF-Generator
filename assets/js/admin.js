/* global GFFPDF_Admin, jQuery */
(function ($) {
	'use strict';

	const Admin = {

		init: function () {
			this.bindSaveSettings();
			this.bindClearLogs();
			this.bindFontsPanelToggle();
			this.bindInlineFontManager();
		},

		showNotice: function (message, type) {
			const $n = $('#gffpdf-settings-notice');
			$n.removeClass('gffpdf-notice--success gffpdf-notice--error')
			  .addClass(type === 'error' ? 'gffpdf-notice--error' : 'gffpdf-notice--success')
			  .html(message)
			  .show();
			setTimeout(function () { $n.fadeOut(); }, 4000);
		},

		bindSaveSettings: function () {
			$('#gffpdf-save-settings').on('click', function () {
				const $btn = $(this);
				$btn.prop('disabled', true).text('Saving…');

				const settings = {
					default_font_family: $('#gffpdf-font-family').val(),
					default_font_size:   $('#gffpdf-font-size').val(),
					default_font_color:  $('#gffpdf-font-color').val(),
					filename_pattern:    $('#gffpdf-filename-pattern').val(),
					save_pdfs:           $('#gffpdf-save-pdfs').is(':checked') ? 1 : 0,
					flatten_pdf:         $('#gffpdf-flatten').is(':checked') ? 1 : 0,
					rtl_support:         $('#gffpdf-rtl').is(':checked') ? 1 : 0,
					enable_logs:         $('#gffpdf-logs').is(':checked') ? 1 : 0,
					storage_path:        $('#gffpdf-storage-path').val(),
				};

				$.post(GFFPDF_Admin.ajax_url, {
					action:   'gffpdf_save_settings',
					nonce:    GFFPDF_Admin.nonce,
					settings: settings,
				})
				.done(function (res) {
					if (res.success) {
						Admin.showNotice(res.data.message, 'success');
					} else {
						Admin.showNotice(res.data.message || 'Error saving settings.', 'error');
					}
				})
				.fail(function () {
					Admin.showNotice('Request failed. Please try again.', 'error');
				})
				.always(function () {
					$btn.prop('disabled', false).text('Save Settings');
				});
			});
		},

		bindClearLogs: function () {
			$('#gffpdf-clear-logs').on('click', function () {
				if (!confirm('Are you sure you want to delete all log files?')) return;

				const $btn = $(this);
				$btn.prop('disabled', true);

				$.post(GFFPDF_Admin.ajax_url, {
					action: 'gffpdf_clear_logs',
					nonce:  GFFPDF_Admin.nonce,
				})
				.done(function (res) {
					if (res.success) {
						$('.gffpdf-log-viewer').html('<p>Logs cleared.</p>');
						Admin.showNotice(res.data.message, 'success');
					}
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
			});
		},

		/* ----------------------------------------------------------------
		 * Collapsible fonts panel toggle (global settings page)
		 * ------------------------------------------------------------- */
		bindFontsPanelToggle: function () {
			$(document).on('click', '#gffpdf-global-fonts-toggle', function () {
				const $body  = $('#gffpdf-global-fonts-body');
				const $arrow = $(this).find('.gffpdf-toggle-arrow');
				const $hint  = $(this).find('span:last');
				if ($body.is(':visible')) {
					$body.slideUp(200);
					$arrow.text('▶');
					$hint.text('(click to expand)');
				} else {
					$body.slideDown(200);
					$arrow.text('▼');
					$hint.text('(click to collapse)');
				}
			});
		},

		/* ----------------------------------------------------------------
		 * Inline font manager (upload + delete)
		 * ------------------------------------------------------------- */
		bindInlineFontManager: function () {
			const ajaxUrl = GFFPDF_Admin.ajax_url;
			const nonce   = GFFPDF_Admin.nonce;

			$(document).on('click', '#gffpdf-font-upload-btn-inline', function () {
				const file    = $('#gffpdf-font-file-inline')[0].files[0];
				const label   = $('#gffpdf-font-label-inline').val();
				const $status = $('#gffpdf-font-upload-status-inline');

				if (!file) { $status.css('color','#d63638').text('Please select a font file.'); return; }

				$status.css('color','#646970').text('Uploading…');

				const fd = new FormData();
				fd.append('action',     'gffpdf_upload_font');
				fd.append('nonce',      nonce);
				fd.append('font_file',  file);
				fd.append('font_label', label);

				$.ajax({ url: ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
				.done(function (res) {
					if (res.success) {
						$status.css('color','#00a32a').text(res.data.message || 'Font uploaded.');
						// Update the global font family dropdown
						if (res.data.fonts) {
							const $sel     = $('#gffpdf-font-family');
							const current  = $sel.val();
							let html = '';
							$.each(res.data.fonts, function (val, lbl) {
								html += '<option value="' + val + '"' + (val === current ? ' selected' : '') + '>' + lbl + '</option>';
							});
							$sel.html(html);
						}
						const fam = res.data.family;
						const lbl = label || fam;
						$('#gffpdf-no-custom-fonts').hide();
						$('#gffpdf-inline-custom-fonts-table').show();
						$('#gffpdf-inline-custom-fonts-list').append(
							'<tr id="gffpdf-inline-font-row-' + fam + '">' +
							'<td><code>' + $('<span>').text(fam).html() + '</code></td>' +
							'<td>' + $('<span>').text(lbl).html() + '</td>' +
							'<td><button type="button" class="button button-small gffpdf-delete-font-inline" data-family="' + fam + '">Delete</button></td>' +
							'</tr>'
						);
						$('#gffpdf-font-file-inline').val('');
						$('#gffpdf-font-label-inline').val('');
					} else {
						$status.css('color','#d63638').text(res.data ? res.data.message : 'Upload failed.');
					}
				});
			});

			$(document).on('click', '.gffpdf-delete-font-inline', function () {
				if (!confirm('Delete this font?')) return;
				const family = $(this).data('family');
				const $row   = $('#gffpdf-inline-font-row-' + family);

				$.post(ajaxUrl, { action: 'gffpdf_delete_font', nonce: nonce, family: family })
				.done(function (res) {
					if (res.success) {
						$row.remove();
						// Remove from global dropdown too
						$('#gffpdf-font-family option[value="' + family + '"]').remove();
						if ($('#gffpdf-inline-custom-fonts-list tr').length === 0) {
							$('#gffpdf-inline-custom-fonts-table').hide();
							$('#gffpdf-no-custom-fonts').show();
						}
					}
				});
			});
		},
	};

	$(document).ready(function () {
		Admin.init();
	});

}(jQuery));