/* global GFFPDF, jQuery */
(function ($) {
	'use strict';

	const Feed = {

		currentFeedId: null,

		init: function () {
			this.bindAddFeed();
			this.bindEditFeed();
			this.bindDeleteFeed();
			this.bindDuplicateFeed();
			this.bindToggleFeed();
			this.bindSaveFeed();
			this.bindModal();
			this.bindUpload();
		},

		/* ----------------------------------------------------------------
		 * Notice
		 * ------------------------------------------------------------- */
		showNotice: function (message, type) {
			const $n = $('#gffpdf-notice');
			$n.removeClass('gffpdf-notice--success gffpdf-notice--error')
			  .addClass(type === 'error' ? 'gffpdf-notice--error' : 'gffpdf-notice--success')
			  .html(message)
			  .show();
			$('html, body').animate({ scrollTop: $n.offset().top - 40 }, 300);
			setTimeout(function () { $n.fadeOut(); }, 5000);
		},

		/* ----------------------------------------------------------------
		 * Modal helpers
		 * ------------------------------------------------------------- */
		openModal: function () {
			$('#gffpdf-modal').show();
			$('body').css('overflow', 'hidden');
		},

		closeModal: function () {
			$('#gffpdf-modal').hide();
			$('body').css('overflow', '');
			Feed.resetModal();
		},

		resetModal: function () {
			Feed.currentFeedId = null;
			$('#gffpdf-feed-id').val('');
			$('#gffpdf-feed-name').val('');
			$('#gffpdf-is-active').prop('checked', true);
			$('#gffpdf-template-path').val('');
			$('#gffpdf-filename-pattern').val('');
			$('#gffpdf-attach-email').prop('checked', false);
			$('#gffpdf-upload-status').text('').removeClass('success error');
			$('#gffpdf-mappings-section').hide();
			$('#gffpdf-mapping-rows').empty();
		},

		bindModal: function () {
			$(document).on('click', '.gffpdf-modal-close, .gffpdf-modal-backdrop', function () {
				Feed.closeModal();
			});

			$(document).on('keydown', function (e) {
				if (e.key === 'Escape') Feed.closeModal();
			});
		},

		/* ----------------------------------------------------------------
		 * Add feed (open blank modal)
		 * ------------------------------------------------------------- */
		bindAddFeed: function () {
			$('#gffpdf-add-feed').on('click', function () {
				Feed.resetModal();
				$('#gffpdf-modal-title').text('Add New Feed');
				Feed.openModal();
			});
		},

		/* ----------------------------------------------------------------
		 * Edit feed (load existing data into modal)
		 * ------------------------------------------------------------- */
		bindEditFeed: function () {
			$(document).on('click', '.gffpdf-edit-feed', function () {
				const feedId = $(this).data('feed-id');
				Feed.currentFeedId = feedId;

				$.post(GFFPDF.ajax_url, {
					action:  'gffpdf_get_feed',
					nonce:   GFFPDF.nonce,
					feed_id: feedId,
				})
				.done(function (res) {
					if (!res.success) {
						Feed.showNotice(res.data.message || GFFPDF.strings.error, 'error');
						return;
					}

					const feed = res.data;

					$('#gffpdf-modal-title').text('Edit Feed');
					$('#gffpdf-feed-id').val(feed.id);
					$('#gffpdf-feed-name').val(feed.feed_name);
					$('#gffpdf-is-active').prop('checked', feed.is_active == 1);
					$('#gffpdf-template-path').val(feed.template_path);
					$('#gffpdf-filename-pattern').val(feed.settings && feed.settings.filename_pattern ? feed.settings.filename_pattern : '');
					$('#gffpdf-attach-email').prop('checked', !!(feed.settings && feed.settings.attach_to_email));

					if (feed.template_path) {
						const name = feed.template_path.split('/').pop();
						$('#gffpdf-upload-status').text('✓ ' + name).addClass('success');
					}

					// Render mappings if pdf_fields available
					if (feed.pdf_fields && feed.pdf_fields.length) {
						window.GFFPDF_Mappings.render(feed.pdf_fields, feed.mappings || {});
					}

					Feed.openModal();
				});
			});
		},

		/* ----------------------------------------------------------------
		 * Save feed
		 * ------------------------------------------------------------- */
		bindSaveFeed: function () {
			$('#gffpdf-save-feed').on('click', function () {
				const feedName = $.trim($('#gffpdf-feed-name').val());
				const templatePath = $('#gffpdf-template-path').val();

				if (!feedName) {
					alert('Please enter a feed name.');
					$('#gffpdf-feed-name').focus();
					return;
				}
				if (!templatePath) {
					alert('Please upload a PDF template.');
					return;
				}

				const $btn = $(this);
				$btn.prop('disabled', true).text(GFFPDF.strings.saving);

				const mappings = window.GFFPDF_Mappings ? window.GFFPDF_Mappings.collect() : {};

				// Send mappings as a JSON string to prevent PHP from mangling
				// keys that contain dots (e.g. sub-field "1.3" → "1_3" in $_POST).
				$.post(GFFPDF.ajax_url, {
					action:        'gffpdf_save_feed',
					nonce:         GFFPDF.nonce,
					feed_id:       Feed.currentFeedId || 0,
					form_id:       GFFPDF.form_id,
					feed_name:     feedName,
					template_path: templatePath,
					is_active:     $('#gffpdf-is-active').is(':checked') ? 1 : 0,
					mappings_json: JSON.stringify(mappings),
					feed_settings: {
						filename_pattern: $('#gffpdf-filename-pattern').val(),
						attach_to_email:  $('#gffpdf-attach-email').is(':checked') ? 1 : 0,
					},
				})
				.done(function (res) {
					if (res.success) {
						Feed.showNotice(GFFPDF.strings.saved, 'success');
						Feed.closeModal();
						// Reload to refresh table
						setTimeout(function () { location.reload(); }, 800);
					} else {
						Feed.showNotice(res.data.message || GFFPDF.strings.error, 'error');
					}
				})
				.fail(function () {
					Feed.showNotice(GFFPDF.strings.error, 'error');
				})
				.always(function () {
					$btn.prop('disabled', false).text('Save Feed');
				});
			});
		},

		/* ----------------------------------------------------------------
		 * Delete feed
		 * ------------------------------------------------------------- */
		bindDeleteFeed: function () {
			$(document).on('click', '.gffpdf-delete-feed', function () {
				if (!confirm(GFFPDF.strings.confirm_delete)) return;

				const feedId = $(this).data('feed-id');
				const $row   = $('#gffpdf-row-' + feedId);

				$.post(GFFPDF.ajax_url, {
					action:  'gffpdf_delete_feed',
					nonce:   GFFPDF.nonce,
					feed_id: feedId,
				})
				.done(function (res) {
					if (res.success) {
						$row.fadeOut(300, function () { $(this).remove(); });
						Feed.showNotice(res.data.message, 'success');
					} else {
						Feed.showNotice(res.data.message || GFFPDF.strings.error, 'error');
					}
				});
			});
		},

		/* ----------------------------------------------------------------
		 * Duplicate feed
		 * ------------------------------------------------------------- */
		bindDuplicateFeed: function () {
			$(document).on('click', '.gffpdf-duplicate-feed', function () {
				if (!confirm(GFFPDF.strings.confirm_duplicate)) return;

				const feedId = $(this).data('feed-id');

				$.post(GFFPDF.ajax_url, {
					action:  'gffpdf_duplicate_feed',
					nonce:   GFFPDF.nonce,
					feed_id: feedId,
				})
				.done(function (res) {
					if (res.success) {
						Feed.showNotice(res.data.message, 'success');
						setTimeout(function () { location.reload(); }, 800);
					} else {
						Feed.showNotice(res.data.message || GFFPDF.strings.error, 'error');
					}
				});
			});
		},

		/* ----------------------------------------------------------------
		 * Toggle active status
		 * ------------------------------------------------------------- */
		bindToggleFeed: function () {
			$(document).on('change', '.gffpdf-status-toggle', function () {
				const $cb    = $(this);
				const feedId = $cb.data('feed-id');

				$.post(GFFPDF.ajax_url, {
					action:    'gffpdf_toggle_feed',
					nonce:     GFFPDF.nonce,
					feed_id:   feedId,
					is_active: $cb.is(':checked') ? 1 : 0,
				});
			});
		},

		/* ----------------------------------------------------------------
		 * PDF file upload
		 * ------------------------------------------------------------- */
		bindUpload: function () {
			$('#gffpdf-upload-btn').on('click', function () {
				$('#gffpdf-pdf-file').trigger('click');
			});

			$('#gffpdf-pdf-file').on('change', function () {
				const file = this.files[0];
				if (!file) return;

				const $status = $('#gffpdf-upload-status');
				$status.text(GFFPDF.strings.uploading).removeClass('success error');

				const formData = new FormData();
				formData.append('action',   'gffpdf_upload_pdf');
				formData.append('nonce',    GFFPDF.nonce);
				formData.append('pdf_file', file);

				$.ajax({
					url:         GFFPDF.ajax_url,
					type:        'POST',
					data:        formData,
					processData: false,
					contentType: false,
				})
				.done(function (res) {
					if (res.success) {
						$status.text('✓ ' + res.data.name).addClass('success');
						$('#gffpdf-template-path').val(res.data.path);

						const fields = res.data.fields;
						if (fields && fields.length) {
							window.GFFPDF_Mappings.render(fields, {});
						} else {
							$('#gffpdf-mappings-section').hide();
							alert(GFFPDF.strings.no_fields);
						}
					} else {
						$status.text(res.data.message || 'Upload failed.').addClass('error');
					}
				})
				.fail(function () {
					$status.text(GFFPDF.strings.error).addClass('error');
				});
			});
		},
	};

	$(document).ready(function () {
		Feed.init();
	});

}(jQuery));