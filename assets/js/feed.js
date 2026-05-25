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
			this.bindEditorCancel();
			this.bindUpload();
			this.bindFontColorPicker();
			this.bindFontsPanelToggle();
			this.bindInlineFontManager();
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
		 * Inline editor helpers
		 * ------------------------------------------------------------- */
		openEditor: function () {
			$('#gffpdf-editor').show();
			$('#gffpdf-feed-list-wrap').css('opacity', '0.5');
			$('html, body').animate({ scrollTop: $('#gffpdf-editor').offset().top - 60 }, 400);
		},

		closeEditor: function () {
			$('#gffpdf-editor').hide();
			$('#gffpdf-feed-list-wrap').css('opacity', '1');
			Feed.resetEditor();
		},

		resetEditor: function () {
			Feed.currentFeedId = null;
			$('#gffpdf-feed-id').val('');
			$('#gffpdf-feed-name').val('');
			$('#gffpdf-is-active').prop('checked', true);
			$('#gffpdf-template-path').val('');
			$('#gffpdf-filename-pattern').val('');
			$('#gffpdf-upload-status').text('').removeClass('success error');
			$('#gffpdf-mappings-section').hide();
			$('#gffpdf-mapping-rows').empty();
			$('#gffpdf-shortcode-row').hide();
			// Reset font fields
			$('#gffpdf-font-family').val('');
			$('#gffpdf-font-size').val('');
			$('#gffpdf-font-color').val('');
			$('#gffpdf-font-color-picker').val('#000000');
			$('#gffpdf-reverse-text').prop('checked', false);
			// Reset notifications
			$('.gffpdf-notification-cb').prop('checked', false);
			// Reset conditional logic
			$('#gffpdf-cl-enabled').prop('checked', false);
			$('#gffpdf-cl-settings').hide();
			$('#gffpdf-cl-rules').empty();
		},

		/* ----------------------------------------------------------------
		 * Font color picker sync
		 * ------------------------------------------------------------- */
		bindFontColorPicker: function () {
			$('#gffpdf-font-color-picker').on('input change', function () {
				$('#gffpdf-font-color').val($(this).val());
			});
			$('#gffpdf-font-color').on('input', function () {
				const val = $(this).val();
				if (/^#[0-9a-fA-F]{6}$/.test(val)) {
					$('#gffpdf-font-color-picker').val(val);
				}
			});
			$('#gffpdf-clear-font-color').on('click', function () {
				$('#gffpdf-font-color').val('');
				$('#gffpdf-font-color-picker').val('#000000');
			});
		},

		/* ----------------------------------------------------------------
		 * Fonts panel toggle (collapsible in editor + global settings)
		 * ------------------------------------------------------------- */
		bindFontsPanelToggle: function () {
			$(document).on('click', '#gffpdf-fonts-toggle, #gffpdf-global-fonts-toggle', function () {
				const $btn = $(this);
				const $body = $btn.closest('.gffpdf-section, .gffpdf-settings-panel')
					.find('#gffpdf-fonts-panel-body, #gffpdf-global-fonts-body').first();
				const $arrow = $btn.find('.gffpdf-toggle-arrow');
				if ($body.is(':visible')) {
					$body.slideUp(200);
					$arrow.text('▶');
					$btn.find('span:last').text('(click to expand)');
				} else {
					$body.slideDown(200);
					$arrow.text('▼');
					$btn.find('span:last').text('(click to collapse)');
				}
			});
		},

		/* ----------------------------------------------------------------
		 * Inline font manager (upload + delete, shared across pages)
		 * ------------------------------------------------------------- */
		bindInlineFontManager: function () {
			const ajaxUrl = GFFPDF.ajax_url;
			const nonce   = GFFPDF.nonce;

			$(document).on('click', '#gffpdf-font-upload-btn-inline', function () {
				const file   = $('#gffpdf-font-file-inline')[0].files[0];
				const label  = $('#gffpdf-font-label-inline').val();
				const $status = $('#gffpdf-font-upload-status-inline');

				if (!file) {
					$status.css('color','#d63638').text(GFFPDF.strings.uploading_font ? '' : 'Please select a font file.');
					return;
				}

				$status.css('color','#646970').text(GFFPDF.strings.uploading_font || 'Uploading…');

				const fd = new FormData();
				fd.append('action',     'gffpdf_upload_font');
				fd.append('nonce',      nonce);
				fd.append('font_file',  file);
				fd.append('font_label', label);

				$.ajax({ url: ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
				.done(function (res) {
					if (res.success) {
						$status.css('color','#00a32a').text(res.data.message || GFFPDF.strings.font_uploaded);
						// Update dropdowns with newly added font
						if (res.data.fonts) {
							Feed.refreshFontDropdowns(res.data.fonts);
						}
						// Add row to inline table
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
				if (!confirm(GFFPDF.strings.confirm_del_font || 'Delete this font?')) return;
				const family  = $(this).data('family');
				const $row    = $('#gffpdf-inline-font-row-' + family);

				$.post(ajaxUrl, { action: 'gffpdf_delete_font', nonce: nonce, family: family })
				.done(function (res) {
					if (res.success) {
						$row.remove();
						if (res.data.fonts) {
							Feed.refreshFontDropdowns(res.data.fonts);
						}
						if ($('#gffpdf-inline-custom-fonts-list tr').length === 0) {
							$('#gffpdf-inline-custom-fonts-table').hide();
							$('#gffpdf-no-custom-fonts').show();
						}
					}
				});
			});
		},

		/* Rebuild font <select> options after upload/delete */
		refreshFontDropdowns: function (fonts) {
			$('#gffpdf-font-family').each(function () {
				const $sel     = $(this);
				const current  = $sel.val();
				let html = '<option value="">' + (GFFPDF.strings.use_global || '— Use global setting —') + '</option>';
				$.each(fonts, function (val, label) {
					html += '<option value="' + val + '"' + (val === current ? ' selected' : '') + '>' + label + '</option>';
				});
				$sel.html(html);
			});
		},

		/* ----------------------------------------------------------------
		 * Add feed (open blank editor)
		 * ------------------------------------------------------------- */
		bindAddFeed: function () {
			$('#gffpdf-add-feed').on('click', function () {
				Feed.resetEditor();
				$('#gffpdf-editor-title').text('Add New Feed');
				Feed.openEditor();
			});
		},

		/* ----------------------------------------------------------------
		 * Cancel editor
		 * ------------------------------------------------------------- */
		bindEditorCancel: function () {
			$(document).on('click', '#gffpdf-editor-cancel, #gffpdf-editor-cancel-bottom', function () {
				Feed.closeEditor();
			});
		},

		/* ----------------------------------------------------------------
		 * Edit feed (load existing data into inline editor)
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

					const feed     = res.data;
					const settings = feed.settings || {};

					$('#gffpdf-editor-title').text('Edit Feed');
					$('#gffpdf-feed-id').val(feed.id);
					$('#gffpdf-feed-name').val(feed.feed_name);
					$('#gffpdf-is-active').prop('checked', feed.is_active == 1);
					$('#gffpdf-template-path').val(feed.template_path);
					$('#gffpdf-filename-pattern').val(settings.filename_pattern || '');

					if (feed.template_path) {
						const name = feed.template_path.split('/').pop();
						$('#gffpdf-upload-status').text('✓ ' + name).addClass('success');
					}

					// ── Font settings ─────────────────────────────────────
					$('#gffpdf-font-family').val(settings.font_family || '');
					$('#gffpdf-font-size').val(settings.font_size || '');

					const fontColor = settings.font_color || '';
					$('#gffpdf-font-color').val(fontColor);
					if (/^#[0-9a-fA-F]{6}$/.test(fontColor)) {
						$('#gffpdf-font-color-picker').val(fontColor);
					} else {
						$('#gffpdf-font-color-picker').val('#000000');
					}

					$('#gffpdf-reverse-text').prop('checked', !!settings.reverse_text);

					// ── Notifications ─────────────────────────────────────
					$('.gffpdf-notification-cb').prop('checked', false);
					const notifIds = (settings.notification_ids && Array.isArray(settings.notification_ids))
						? settings.notification_ids
						: [];
					notifIds.forEach(function (id) {
						$('.gffpdf-notification-cb[value="' + id + '"]').prop('checked', true);
					});

					// ── Conditional logic ─────────────────────────────────
					const cl = settings.conditional_logic || {};
					const clEnabled = !!(cl.enabled);
					$('#gffpdf-cl-enabled').prop('checked', clEnabled);
					$('#gffpdf-cl-settings').toggle(clEnabled);
					if (cl.logic_type) {
						$('#gffpdf-cl-logic-type').val(cl.logic_type);
					}
					// Re-render rules if GFFPDF_CL module exists
					if (window.GFFPDF_CL && cl.rules) {
						window.GFFPDF_CL.loadRules(cl.rules);
					}

					// ── Shortcode ─────────────────────────────────────────
					if (feed.id) {
						$('#gffpdf-shortcode-display').text('[gffpdf feed_id="' + feed.id + '" entry_id="{entry_id}"]');
						$('#gffpdf-shortcode-row').show();
					}

					// ── Mappings ──────────────────────────────────────────
					if (feed.pdf_fields && feed.pdf_fields.length) {
						window.GFFPDF_Mappings.render(feed.pdf_fields, feed.mappings || {});
					}

					Feed.openEditor();
				});
			});
		},

		/* ----------------------------------------------------------------
		 * Save feed  (includes all font settings)
		 * ------------------------------------------------------------- */
		bindSaveFeed: function () {
			$('#gffpdf-save-feed').on('click', function () {
				const feedName     = $.trim($('#gffpdf-feed-name').val());
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

				// Collect notification IDs
				const notificationIds = [];
				$('.gffpdf-notification-cb:checked').each(function () {
					notificationIds.push($(this).val());
				});

				// Collect conditional logic
				let conditionalLogic = {};
				if ($('#gffpdf-cl-enabled').is(':checked')) {
					const rules = window.GFFPDF_CL ? window.GFFPDF_CL.getRules() : [];
					conditionalLogic = {
						enabled:    true,
						logic_type: $('#gffpdf-cl-logic-type').val(),
						rules:      rules,
					};
				} else {
					conditionalLogic = { enabled: false };
				}

				// Font settings — send empty string when "use global" is selected
				const fontFamily = $('#gffpdf-font-family').val();   // '' = use global
				const fontSize   = $('#gffpdf-font-size').val();     // '' = use global
				const fontColor  = $('#gffpdf-font-color').val();    // '' = use global
				const reverseText = $('#gffpdf-reverse-text').is(':checked') ? 1 : 0;

				$.post(GFFPDF.ajax_url, {
					action:                  'gffpdf_save_feed',
					nonce:                   GFFPDF.nonce,
					feed_id:                 Feed.currentFeedId || 0,
					form_id:                 GFFPDF.form_id,
					feed_name:               feedName,
					template_path:           templatePath,
					is_active:               $('#gffpdf-is-active').is(':checked') ? 1 : 0,
					mappings_json:           JSON.stringify(mappings),
					notification_ids_json:   JSON.stringify(notificationIds),
					conditional_logic_json:  JSON.stringify(conditionalLogic),
					feed_settings: {
						filename_pattern: $('#gffpdf-filename-pattern').val(),
						font_family:      fontFamily,
						font_size:        fontSize,
						font_color:       fontColor,
						reverse_text:     reverseText,
					},
				})
				.done(function (res) {
					if (res.success) {
						Feed.showNotice(GFFPDF.strings.saved, 'success');
						Feed.closeEditor();
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