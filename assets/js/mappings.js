/* global GFFPDF, jQuery */
/**
 * GFFPDF_Mappings
 *
 * Handles rendering the PDF-field → GF-field mapping rows inside the
 * feed modal, auto-mapping, and collecting the final mapping object
 * for submission.
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
		 * Accepts pdfFields as either:
		 *   - string[]                 e.g. ['first_name', 'email']
		 *   - {name: string, type: string}[]  (shape returned by PHP extractor)
		 *
		 * savedMappings is { pdfFieldName: gfFieldId } — values may be
		 * integers (from PHP absint) or strings; both are handled.
		 *
		 * @param {Array}  pdfFields
		 * @param {Object} savedMappings
		 */
		render: function (pdfFields, savedMappings) {
			savedMappings = savedMappings || {};

			const $section = $('#gffpdf-mappings-section');
			const $tbody = $('#gffpdf-mapping-rows');

			$tbody.empty();

			if (!pdfFields || !pdfFields.length) {
				$section.hide();
				return;
			}

			// Normalise: accept {name, type} objects or plain strings
			const names = pdfFields.map(function (f) {
				return (typeof f === 'object' && f !== null) ? f.name : String(f);
			});

			// Load GF fields from the JSON island printed by the PHP template
			this.gfFields = this._loadGFFields();

			const self = this;
			$.each(names, function (i, name) {
				const savedValue = savedMappings.hasOwnProperty(name)
					? String(savedMappings[name])
					: '';
				const $row = self._buildRow(name, savedValue);
				$tbody.append($row);
			});

			$section.show();
			this._bindAutoMap();
		},

		/**
		 * collect()
		 *
		 * Reads every mapping <select> and returns { pdfFieldName: gfFieldId }.
		 * Called by feed.js before posting to gffpdf_save_feed.
		 *
		 * @returns {Object}
		 */
		collect: function () {
			const mappings = {};

			$('#gffpdf-mapping-rows tr').each(function () {
				const $row = $(this);
				const pdfField = $row.data('pdf-field');
				const gfFieldId = $row.find('.gffpdf-gf-field-select').val();

				if (pdfField !== undefined && pdfField !== '') {
					mappings[pdfField] = gfFieldId || '';
				}
			});

			return mappings;
		},

		/* ------------------------------------------------------------------
		 * Private helpers
		 * ---------------------------------------------------------------- */

		/**
		 * Build a single mapping <tr>.
		 *
		 * @param  {string} pdfField   - PDF AcroForm field name (plain string).
		 * @param  {string} savedValue - Previously saved GF field id, or ''.
		 * @returns {jQuery}
		 */
		_buildRow: function (pdfField, savedValue) {
			// Sanitise the name into a safe HTML id attribute
			const selectId = 'gffpdf-map-' + pdfField.replace(/[^a-zA-Z0-9]/g, '_');

			const $select = $('<select>', {
				id: selectId,
				class: 'gffpdf-gf-field-select',
			});

			$select.append($('<option>', { value: '', text: '— Do not map —' }));

			$.each(this.gfFields, function (i, field) {
				const $opt = $('<option>', {
					value: String(field.id),
					text: field.label + ' (field ' + field.id + ')',
				});

				if (String(field.id) === savedValue) {
					$opt.prop('selected', true);
				}

				$select.append($opt);
			});

			const $row = $('<tr>', { 'data-pdf-field': pdfField });

			$row.append(
				$('<td>').append(
					$('<code>', { text: pdfField })
				)
			);

			$row.append(
				$('<td>').append(
					$('<label>', {
						for: selectId,
						class: 'screen-reader-text',
						text: 'GF field for ' + pdfField,
					}),
					$select
				)
			);

			return $row;
		},

		/**
		 * Wire up the ⚡ Auto-Map button.
		 * Uses .off() first so re-opening the modal doesn't stack listeners.
		 */
		_bindAutoMap: function () {
			const $btn = $('#gffpdf-auto-map');

			$btn.off('click.gffpdf').on('click.gffpdf', function () {
				const pdfFields = [];
				$('#gffpdf-mapping-rows tr').each(function () {
					pdfFields.push($(this).data('pdf-field'));
				});

				if (!pdfFields.length) return;

				$btn.prop('disabled', true).text('Mapping…');

				$.post(GFFPDF.ajax_url, {
					action: 'gffpdf_auto_map',
					nonce: GFFPDF.nonce,
					form_id: GFFPDF.form_id,
					pdf_fields: pdfFields,
				})
					.done(function (res) {
						if (!res.success) return;

						// res.data = { pdfFieldName: gfFieldId }
						$.each(res.data, function (pdfField, gfFieldId) {
							const safeName = pdfField.replace(/[^a-zA-Z0-9]/g, '_');
							$('#gffpdf-map-' + safeName).val(String(gfFieldId));
						});
					})
					.always(function () {
						$btn.prop('disabled', false).text('⚡ Auto Map');
					});
			});
		},

		/**
		 * Read GF fields from the <script type="application/json"> island
		 * that the PHP feed-settings template prints as #gffpdf-gf-fields.
		 *
		 * @returns {Array<{id: string|number, label: string, type: string}>}
		 */
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