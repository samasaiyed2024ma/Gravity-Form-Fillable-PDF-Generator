=== GF PDF Generator ===
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later

Fills the real form fields of an uploaded fillable (AcroForm) PDF with Gravity Forms entry data on submission.

== Description ==

The generated PDF is the original template with its own form fields filled in,
so it stays fully editable (Acrobat, Chrome/Firefox/Edge viewers, Preview, etc.).
Nothing is rasterised or redrawn.

Everything is done in pure PHP and ships inside the plugin. No pdftk, Ghostscript,
qpdf, Imagick, exec()/shell_exec() or server configuration is needed.

PHP extensions used: mbstring (required by WordPress anyway) and zlib. GD is optional
(PNG signatures are decoded by a built-in reader if GD is missing).

== Supported ==

* Text, multi-line, comb (fixed-character-box) fields
* Checkboxes and radio groups (matched to the template's own on/off states)
* Dropdown / combo boxes (option export or display value)
* Signature images (PNG / JPEG / data-URI) drawn into the field's box
* Unicode text: accented Latin, Cyrillic, Greek, Hebrew, Arabic (RTL) via embedded fonts,
  including custom fonts uploaded in Fonts settings
* Classic PDFs and PDF 1.5+ files with compressed cross-reference / object streams
* Damaged cross-reference tables are rebuilt automatically

== Not supported ==

* Password-protected / encrypted templates (a clear error is shown on upload)
* XFA-only forms: the XFA layer is dropped so viewers show the AcroForm values
* Complex-script shaping for Indic scripts (Gujarati, Devanagari, ...)