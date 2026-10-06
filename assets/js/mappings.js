/* global GFFPDF, jQuery */
/**
 * GFFPDF_Mappings
 *
 * Renders the PDF-field → Gravity-Forms-field mapping rows inside the feed
 * editor, runs auto-mapping, and collects the mapping object for saving.
 *
 * Works for every kind of fillable PDF: field names come from the server
 * (AcroForm names, or dotted XFA paths such as "form1.address.city").
 *
 * Depends on:  jQuery, GFFPDF (localised by class-feed-settings.php)
 * Loaded after: gffpdf-feed (feed.js)
 */
(function ($) {
	'use strict';

	window.GFFPDF_Mappings = {

		/** GF field list loaded from the JSON island in the template. */
		gfFields: [],

		/**
		 * render( pdfFields, savedMappings )
		 *
		 * @param {Array}  pdfFields     string[] or {name, type}[]
		 * @param {Object} savedMappings { pdfFieldName: gfFieldId }
		 */
		render: function (pdfFields, savedMappings) {
			savedMappings = savedMappings || {};

			const $section = $('#gffpdf-mappings-section');
			const $tbody   = $('#gffpdf-mapping-rows');

			$tbody.empty();

			if (!pdfFields || !pdfFields.length) {
				$section.hide();
				return;
			}

			const items = pdfFields.map(function (f) {
				return (typeof f === 'object' && f !== null)
					? { name: String(f.name), type: f.type || 'text' }
					: { name: String(f), type: 'text' };
			});

			this.gfFields = this._loadGFFields();

			const self = this;
			$.each(items, function (i, item) {
				const saved = Object.prototype.hasOwnProperty.call(savedMappings, item.name)
					? String(savedMappings[item.name])
					: '';
				$tbody.append(self._buildRow(i, item, saved));
			});

			$section.show();
			this._bindAutoMap();
		},

		/**
		 * collect() → { pdfFieldName: gfFieldId }
		 * Reads every mapping <select>. Returns {} when no rows are rendered.
		 */
		collect: function () {
			const mappings = {};
			$('#gffpdf-mapping-rows tr').each(function () {
				// .attr(), not .data(): jQuery would turn a name like "123" into a number.
				const pdfField = $(this).attr('data-pdf-field');
				if (pdfField !== undefined && pdfField !== '') {
					mappings[pdfField] = $(this).find('.gffpdf-gf-field-select').val() || '';
				}
			});
			return mappings;
		},

		/* ------------------------------------------------------------------
		 * Private helpers
		 * ---------------------------------------------------------------- */

		_buildRow: function (index, item, savedValue) {
			// Index-based id: two PDF names that differ only by punctuation
			// ("a.b" / "a_b") can no longer collide.
			const selectId = 'gffpdf-map-' + index;

			const $select = $('<select>', { id: selectId, class: 'gffpdf-gf-field-select' });
			$select.append($('<option>', { value: '', text: '— Do not map —' }));

			$.each(this.gfFields, function (i, field) {
				const $opt = $('<option>', {
					value: String(field.id),
					text:  field.label + ' (field ' + field.id + ')',
				});
				if (String(field.id) === savedValue) {
					$opt.prop('selected', true);
				}
				$select.append($opt);
			});

			// A saved mapping to a GF field that no longer exists: keep it visible
			// instead of silently dropping it on the next save.
			if (savedValue && !$select.find('option').filter(function () { return this.value === savedValue; }).length) {
				$select.append($('<option>', { value: savedValue, text: savedValue + ' (field not found)', selected: true }));
			}

			const $row = $('<tr>').attr('data-pdf-field', item.name);

			const $name = $('<td>').append($('<code>', { text: item.name }));
			if (item.type && item.type !== 'text') {
				$name.append(' ', $('<span>', { class: 'gffpdf-badge gffpdf-badge--gray', text: item.type }));
			}
			$row.append($name);

			$row.append(
				$('<td>').append(
					$('<label>', { for: selectId, class: 'screen-reader-text', text: 'GF field for ' + item.name }),
					$select
				)
			);

			return $row;
		},

		_bindAutoMap: function () {
			const $btn = $('#gffpdf-auto-map');

			$btn.off('click.gffpdf').on('click.gffpdf', function () {
				const pdfFields = [];
				$('#gffpdf-mapping-rows tr').each(function () {
					pdfFields.push($(this).attr('data-pdf-field'));
				});

				if (!pdfFields.length) return;

				$btn.prop('disabled', true).text('Mapping…');

				$.post(GFFPDF.ajax_url, {
					action:     'gffpdf_auto_map',
					nonce:      GFFPDF.nonce,
					form_id:    GFFPDF.form_id,
					pdf_fields: pdfFields,
				})
					.done(function (res) {
						if (!res || !res.success) return;

						// res.data = { pdfFieldName: gfFieldId }
						$.each(res.data, function (pdfField, gfFieldId) {
							if (!gfFieldId) return; // never overwrite a row with "nothing"
							$('#gffpdf-mapping-rows tr').filter(function () {
								return $(this).attr('data-pdf-field') === pdfField;
							}).find('.gffpdf-gf-field-select').val(String(gfFieldId));
						});
					})
					.fail(function () {
						window.alert(GFFPDF.strings.error);
					})
					.always(function () {
						$btn.prop('disabled', false).text('⚡ Auto Map');
					});
			});
		},

		_loadGFFields: function () {
			const $el = $('#gffpdf-gf-fields');
			if (!$el.length) return [];
			try {
				return JSON.parse($el.text()) || [];
			} catch (e) {
				return [];
			}
		},
	};

}(jQuery));