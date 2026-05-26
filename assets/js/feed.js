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
			this.bindConditionalLogicToggle();
			this.bindCopyShortcode();
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
		 * View switcher: list ↔ editor
		 * The editor *replaces* the list — they never show together.
		 * Cancel / Back always returns to the list (no page reload needed).
		 * ------------------------------------------------------------- */
		openEditor: function () {
			$('#gffpdf-list-view').hide();
			$('#gffpdf-editor').show();
			$('html, body').animate({ scrollTop: 0 }, 300); //Automatically scrolls the web page right back to the top.
		},

		closeEditor: function () {
			$('#gffpdf-editor').hide();
			$('#gffpdf-list-view').show();
			Feed.resetEditor(); // Triggers a form reset
			$('html, body').animate({ scrollTop: 0 }, 200);
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
			//A safety check. If the conditional logic module exists on the page, it runs its dedicated cleanup script; otherwise, it force-clears the HTML container manually via jQuery .empty().
			if (window.GFFPDF_CL) { window.GFFPDF_CL.clearRules(); } else { $('#gffpdf-cl-rules').empty(); }
		},

		/* ----------------------------------------------------------------
		 * Font color picker sync
		 * ------------------------------------------------------------- */
		bindFontColorPicker: function () {
			$('#gffpdf-font-color-picker').on('input change', function () {
				/** Takes whatever hex code was picked on the wheel and copies it into a standard text box so the user can see the code string (e.g., #FF0000) */
				$('#gffpdf-font-color').val($(this).val());
			});
			/** checks if the typed phrase is a valid 6-character hex color code (like #333333). If valid, it updates the visual color wheel color to match. */
			$('#gffpdf-font-color').on('input', function () {
				const val = $(this).val();
				if (/^#[0-9a-fA-F]{6}$/.test(val)) {
					$('#gffpdf-font-color-picker').val(val);
				}
			});
			/** Listens for a click on a "Clear" button. It empties the text box and resets the color picker fallback to basic black (#000000). */
			$('#gffpdf-clear-font-color').on('click', function () {
				$('#gffpdf-font-color').val('');
				$('#gffpdf-font-color-picker').val('#000000');
			});
		},

		/* ----------------------------------------------------------------
		 * Conditional logic toggle
		 * ------------------------------------------------------------- */
		bindConditionalLogicToggle: function () {
			$(document).on('change', '#gffpdf-cl-enabled', function () {
				/** Shows the complex conditional rules panel if the checkbox is active (:checked), or hides it entirely if unchecked. */
				$('#gffpdf-cl-settings').toggle($(this).is(':checked'));
			});
		},

		/* ----------------------------------------------------------------
		 * Copy shortcode
		 * ------------------------------------------------------------- */
		bindCopyShortcode: function () {
			$(document).on('click', '#gffpdf-copy-shortcode', function () {
				const text = $('#gffpdf-shortcode-display').text();
				if (navigator.clipboard) {
					navigator.clipboard.writeText(text);
				} else {
					const el = document.createElement('textarea');
					el.value = text;
					document.body.appendChild(el);
					el.select();
					document.execCommand('copy');
					document.body.removeChild(el);
				}
				$(this).text('Copied!');
				const $btn = $(this);
				setTimeout(function () { $btn.text('Copy'); }, 2000);
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
		 * Cancel / Back — always goes back to feed list
		 * ------------------------------------------------------------- */
		bindEditorCancel: function () {
			$(document).on('click', '#gffpdf-editor-back, #gffpdf-editor-cancel-bottom', function () {
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
					/** If the server returns a failure status, it fires an error notification and halts further execution. */
					if (!res.success) {
						Feed.showNotice(res.data.message || GFFPDF.strings.error, 'error');
						return;
					}

					/** If successful, saves the payload of server configurations into temporary variables. */
					const feed     = res.data;
					const settings = feed.settings || {};

					/** Fills out the layout input boxes using data fetched from the database. */
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
		 * Save feed
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

				/** Disables the save button so users cannot double-click it while processing, and inserts a visual loading/spinning ring icon. */
				const $btn = $(this);
				$btn.prop('disabled', true).html('<span class="gffpdf-spinner"></span> Saving…');

				/** Gathers PDF field mappings from a mapping script module. */
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

				// Font settings
				const fontFamily  = $('#gffpdf-font-family').val();
				const fontSize    = $('#gffpdf-font-size').val();
				const fontColor   = $('#gffpdf-font-color').val();
				const reverseText = $('#gffpdf-reverse-text').is(':checked') ? 1 : 0;

				/** $.post(...) Fires a major server communication call to save all gathered configuration objects to the backend data tables. */
				$.post(GFFPDF.ajax_url, {
					action:                  'gffpdf_save_feed',
					nonce:                   GFFPDF.nonce,
					feed_id:                 Feed.currentFeedId || 0,
					form_id:                 GFFPDF.form_id,
					feed_name:               feedName,
					template_path:           templatePath,
					is_active:               $('#gffpdf-is-active').is(':checked') ? 1 : 0,
					mappings_json:           JSON.stringify(mappings), // JSON.stringify turns JavaScript data structures into safe strings for database storage.
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
						setTimeout(function () { location.reload(); }, 600);
					} else {
						Feed.showNotice(res.data.message || GFFPDF.strings.error, 'error');
					}
				})
				.fail(function () {
					Feed.showNotice(GFFPDF.strings.error, 'error');
				})
				.always(function () {
					$btn.prop('disabled', false).html(
						'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Save Feed'
					);
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

				/** Fires a quick query to erase the item from database storage. */
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

			// Fires when a file is selected.
			$('#gffpdf-pdf-file').on('change', function () {
				const file = this.files[0]; // Targets the chosen file.
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
					// prevents jQuery from breaking or altering the file data payload transmission format.
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

	/* ====================================================================
	 * GFFPDF_CL — Conditional Logic rule builder
	 *
	 * Public API (called by Feed):
	 *   window.GFFPDF_CL.addRule()          — append a blank rule row
	 *   window.GFFPDF_CL.loadRules(rules)   — populate from saved data
	 *   window.GFFPDF_CL.getRules()         — collect & return rule array
	 *   window.GFFPDF_CL.clearRules()       — empty the rules container
	 *
	 * A rule object looks like:
	 *   { field_id: '3', operator: 'is', value: 'Yes' }
	 * ==================================================================== */
	window.GFFPDF_CL = (function () {

		/* Operators available for every field type */
		const OPERATORS = [
			{ value: 'is',           label: 'is' },
			{ value: 'is_not',       label: 'is not' },
			{ value: 'contains',     label: 'contains' },
			{ value: 'starts_with',  label: 'starts with' },
			{ value: 'ends_with',    label: 'ends with' },
			{ value: 'greater_than', label: '>' },
			{ value: 'less_than',    label: '<' },
		];

		/* ── helpers ──────────────────────────────────────────────── */

		/** A local cache variable that saves the parsed structure so it doesn't need to re-parse the text over and over again. */
		let _fieldsCache = null;

		function loadCLFields() {
			if (_fieldsCache) return _fieldsCache;
			try {
				_fieldsCache = JSON.parse($('#gffpdf-gf-fields-cl').text()) || [];
			} catch (e) {
				_fieldsCache = [];
			}
			return _fieldsCache;
		}

		/** Find a field object by id (string or number) */
		function findField(fieldId) {
			if (!fieldId) return null;
			return loadCLFields().find(function (f) {
				return String(f.id) === String(fieldId);
			}) || null;
		}

		function buildFieldSelect(selectedId) {
			const $select = $('<select>', { class: 'gffpdf-cl-field-select' });
			$select.append($('<option>', { value: '', text: '— Select field —' }));

			/** Loops over all available fields and adds them as menu options (<option>). If a field matches selectedId, it marks it as selected. */
			$.each(loadCLFields(), function (i, f) {
				const $opt = $('<option>', {
					value: String(f.id),
					text:  f.label + ' (' + f.id + ')',
				});
				if (String(f.id) === String(selectedId)) {
					$opt.prop('selected', true);
				}
				$select.append($opt);
			});
			return $select;
		}

		/** Programmatically builds the comparison operator dropdown select menu box (e.g., "is", "contains", "less than") using the OPERATORS dictionary array. */
		function buildOperatorSelect(selectedOp) {
			const $select = $('<select>', { class: 'gffpdf-cl-operator-select' });
			$.each(OPERATORS, function (i, op) {
				const $opt = $('<option>', { value: op.value, text: op.label });
				if (op.value === selectedOp) {
					$opt.prop('selected', true);
				}
				$select.append($opt);
			});
			return $select;
		}

		/**
		 * Build the value control for a rule row.
		 * - If the field has choices → renders a <select> with those options.
		 * - Otherwise               → renders a free-text <input>.
		 *
		 * @param {string} val        — previously saved value (to pre-select / pre-fill)
		 * @param {Array}  choices    — array of {value, text} from the field definition
		 */
		function buildValueControl(val, choices) {
			if (choices && choices.length) {
				const $select = $('<select>', { class: 'gffpdf-cl-value-input gffpdf-cl-value-select' });
				$select.append($('<option>', { value: '', text: '— Select value —' }));
				$.each(choices, function (i, c) {
					const $opt = $('<option>', { value: c.value, text: c.text });
					if (c.value === val) {
						$opt.prop('selected', true);
					}
					$select.append($opt);
				});
				return $select;
			}
			return $('<input>', {
				type:        'text',
				class:       'gffpdf-cl-value-input',
				placeholder: 'Value',
				value:       val || '',
			});
		}

		/**
		 * Swap the value control inside a rule row when the field changes.
		 * Preserves the current value if the new field also has matching choice.
		 */
		function refreshValueControl($row, fieldId, currentVal) {
			const field    = findField(fieldId);
			const choices  = (field && field.choices && field.choices.length) ? field.choices : null;
			const $newCtrl = buildValueControl(currentVal, choices);
			$row.find('.gffpdf-cl-value-input').replaceWith($newCtrl);
		}

		function buildRemoveBtn() {
			return $('<button>', {
				type:  'button',
				class: 'gffpdf-remove-rule',
				html:  '&times;',
				title: 'Remove rule',
			}).on('click', function () {
				$(this).closest('.gffpdf-cl-rule').remove();
			});
		}

		function buildRuleRow(rule) {
			rule = rule || {};

			const field   = findField(rule.field_id || '');
			const choices = (field && field.choices && field.choices.length) ? field.choices : null;

			const $row        = $('<div>', { class: 'gffpdf-cl-rule' });
			const $fieldSel   = buildFieldSelect(rule.field_id || '');
			const $operatorSel = buildOperatorSelect(rule.operator || 'is');
			const $valueCtrl  = buildValueControl(rule.value || '', choices);

			// When the field dropdown changes, swap the value control
			$fieldSel.on('change', function () {
				const newFieldId   = $(this).val();
				const existingVal  = $row.find('.gffpdf-cl-value-input').val();
				refreshValueControl($row, newFieldId, existingVal);
			});

			$row.append($fieldSel);
			$row.append($operatorSel);
			$row.append($valueCtrl);
			$row.append(buildRemoveBtn());
			return $row;
		}

		/* ── public API ───────────────────────────────────────────── */

		return {

			/** Appends a freshly minted rule row onto the view screen dashboard area container (#gffpdf-cl-rules). */
			addRule: function (rule) {
				// Invalidate field cache so fresh editor opens get latest data
				_fieldsCache = null;
				$('#gffpdf-cl-rules').append(buildRuleRow(rule));
			},

			/** Wipes the rule list and loops through an array of saved rules fetched from the server database, drawing them row by row when editing a feed configuration. */
			loadRules: function (rules) {
				this.clearRules();
				if (!rules || !rules.length) return;
				const self = this;
				$.each(rules, function (i, rule) {
					self.addRule(rule);
				});
			},

			/** Scans the conditional UI rows. 
			 * For each rule row, it extracts the selected field ID, comparison operator, and target input value, 
			 * bundling them into a clean array structure to send to the database during a save operation. 
			 **/
			getRules: function () {
				const rules = [];
				$('#gffpdf-cl-rules .gffpdf-cl-rule').each(function () {
					const fieldId  = $(this).find('.gffpdf-cl-field-select').val();
					const operator = $(this).find('.gffpdf-cl-operator-select').val();
					const value    = $(this).find('.gffpdf-cl-value-input').val();
					if (fieldId) {
						rules.push({ field_id: fieldId, operator: operator, value: value });
					}
				});
				return rules;
			},

			/** Empties out the entire rules HTML container area. */
			clearRules: function () {
				$('#gffpdf-cl-rules').empty();
			},
		};

	}());

	/* Wire up the "+ Add Rule" button */
	$(document).on('click', '#gffpdf-cl-add-rule', function () {
		window.GFFPDF_CL.addRule();
	});

}(jQuery));