<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STS_WebP_Converter {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Convert a single image file to WebP. Skips if .webp already exists.
	 * Returns the WebP file path on success, WP_Error on failure.
	 */
	public function convert_file( $source_path, $quality = null, $force = false ) {
		if ( null === $quality ) {
			$quality = (int) get_option( 'sts_webp_quality', 82 );
		}

		$dest_path = $this->get_webp_path( $source_path );
		if ( ! $dest_path ) {
			return new WP_Error( 'unsupported', 'Not a supported image type: ' . $source_path );
		}

		if ( ! $force && file_exists( $dest_path ) ) {
			return $dest_path;
		}

		if ( ! file_exists( $source_path ) ) {
			return new WP_Error( 'missing', 'Source file not found: ' . $source_path );
		}

		$imagick_result = null;

		if ( extension_loaded( 'imagick' ) ) {
			$imagick_result = $this->convert_with_imagick( $source_path, $dest_path, $quality );
			if ( ! is_wp_error( $imagick_result ) ) {
				return $imagick_result;
			}
			// Imagick is present but failed (e.g. built without a WebP delegate,
			// "Unable to set image format") - fall through to GD instead of
			// giving up, since GD's imagewebp() doesn't depend on that delegate.
		}

		if ( function_exists( 'imagewebp' ) ) {
			return $this->convert_with_gd( $source_path, $dest_path, $quality );
		}

		return $imagick_result ? $imagick_result : new WP_Error( 'no_library', 'Neither Imagick nor GD with WebP support is available.' );
	}

	/**
	 * Convert all image files for a given attachment ID (original + all sizes).
	 * Returns an array of [ 'path' => string|WP_Error, ... ].
	 */
	public function convert_attachment( $attachment_id, $force = false ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return array();
		}

		$results = array();
		$meta    = wp_get_attachment_metadata( $attachment_id );

		$results[ $file ] = $this->convert_file( $file, null, $force );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( dirname( $file ) );
			foreach ( $meta['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_path             = $dir . $size_data['file'];
				$results[ $size_path ] = $this->convert_file( $size_path, null, $force );
			}
		}

		return $results;
	}

	/**
	 * Return the .webp path for a given image path, or false if not a supported type.
	 */
	public function get_webp_path( $path ) {
		if ( preg_match( '/\.(jpe?g|png)$/i', $path ) ) {
			return preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Upload hook
	// -------------------------------------------------------------------------

	public function on_upload( $metadata, $attachment_id ) {
		if ( ! get_option( 'sts_webp_auto_convert', true ) ) {
			return $metadata;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return $metadata;
		}

		$this->convert_attachment( $attachment_id );

		return $metadata;
	}

	// -------------------------------------------------------------------------
	// Conversion backends
	// -------------------------------------------------------------------------

	private function convert_with_imagick( $source, $dest, $quality ) {
		try {
			$img = new Imagick( $source );
			$img->setImageFormat( 'webp' );
			$img->setImageCompressionQuality( $quality );
			$img->setOption( 'webp:lossless', 'false' );
			$result = $img->writeImage( $dest );
			$img->destroy();
			return $result ? $dest : new WP_Error( 'imagick_write', 'Imagick failed to write: ' . $dest );
		} catch ( Exception $e ) {
			return new WP_Error( 'imagick_error', $e->getMessage() );
		}
	}

	private function convert_with_gd( $source, $dest, $quality ) {
		$ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );

		if ( $ext === 'png' ) {
			$img = @imagecreatefrompng( $source );
			if ( ! $img ) {
				return new WP_Error( 'gd_read', 'GD could not read PNG: ' . $source );
			}
			imagepalettetotruecolor( $img );
			imagealphablending( $img, true );
			imagesavealpha( $img, true );
		} else {
			$img = @imagecreatefromjpeg( $source );
			if ( ! $img ) {
				return new WP_Error( 'gd_read', 'GD could not read JPEG: ' . $source );
			}
		}

		$ok = imagewebp( $img, $dest, $quality );
		imagedestroy( $img );

		return $ok ? $dest : new WP_Error( 'gd_write', 'GD imagewebp() failed for: ' . $dest );
	}
}
