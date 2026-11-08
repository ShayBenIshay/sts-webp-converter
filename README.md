# STS WebP Converter

Converts PNG and JPG images to WebP: automatically on upload, plus a bulk converter for the existing media library.

## Features
- Auto-convert new uploads (switchable), with a quality setting.
- Bulk convert existing images, with progress and stats.
- Serves the WebP version through WordPress image functions (image src, attributes and srcset), including raw URL calls in templates. Original files are kept.
- Admin under **Media > WebP Converter**.

## Requirements
WordPress 5.0+, PHP 7.4+ with WebP support in GD or Imagick. WooCommerce is not needed.

## Install
Copy the folder to `wp-content/plugins/`, activate, and open **Media > WebP Converter**. Back up your uploads before the first bulk run.

## Status

Version 1.0.0. Provided as-is, under GPL-2.0-or-later (see `LICENSE`). Published without full functional testing on a live store; try it on staging first.

Setup, customization and support are available from [Shay Tech Solutions](https://shaytechsolutions.com).
