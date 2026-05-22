/* global GFFPDF_Admin, jQuery */
(function ($) {
	'use strict';

	const Admin = {

		init: function () {
			this.bindSaveSettings();
			this.bindClearLogs();
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
	};

	$(document).ready(function () {
		Admin.init();
	});

}(jQuery));