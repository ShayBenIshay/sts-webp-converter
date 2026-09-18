<?php
/**
 * Plugin Name: STS WebP Converter
 * Plugin URI: https://shaytechsolutions.com
 * Description: Converts PNG and JPG images to WebP format — auto-converts new uploads and includes a bulk converter for the existing media library.
 * Version: 1.0.0
 * Author: Shay Tech Solutions
 * Author URI: https://shaytechsolutions.com
 * Text Domain: sts-webp-converter
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STS_WEBP_VERSION', '1.0.0' );
define( 'STS_WEBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STS_WEBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once STS_WEBP_PLUGIN_DIR . 'includes/class-sts-webp-converter.php';

if ( is_admin() ) {
	require_once STS_WEBP_PLUGIN_DIR . 'includes/class-sts-webp-admin.php';
	new STS_WebP_Admin();
} else {
	require_once STS_WEBP_PLUGIN_DIR . 'includes/class-sts-webp-serve.php';
	new STS_WebP_Serve();
}

STS_WebP_Converter::get_instance();
