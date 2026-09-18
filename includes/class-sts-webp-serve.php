<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STS_WebP_Serve {

	private $upload_base_url;
	private $upload_base_dir;

	public function __construct() {
		$upload = wp_upload_dir();
		$this->upload_base_url = trailingslashit( $upload['baseurl'] );
		$this->upload_base_dir = trailingslashit( $upload['basedir'] );

		add_filter( 'wp_get_attachment_image_src',        array( $this, 'swap_image_src' ),       10, 4 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'swap_img_attributes' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset',          array( $this, 'swap_srcset' ),          10, 5 );
	}

	// -------------------------------------------------------------------------
	// Filters
	// -------------------------------------------------------------------------

	/**
	 * Fired by wp_get_attachment_image_src() and wp_get_attachment_image_url().
	 * Covers raw URL calls like wp_get_attachment_image_url($id, 'full') in templates.
	 */
	public function swap_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}
		$webp = $this->maybe_webp_url( $image[0] );
		if ( $webp ) {
			$image[0] = $webp;
		}
		return $image;
	}

	public function swap_img_attributes( $attr, $attachment, $size ) {
		if ( ! empty( $attr['src'] ) ) {
			$webp = $this->maybe_webp_url( $attr['src'] );
			if ( $webp ) {
				$attr['src'] = $webp;
			}
		}

		if ( ! empty( $attr['srcset'] ) ) {
			$attr['srcset'] = $this->swap_srcset_string( $attr['srcset'] );
		}

		return $attr;
	}

	public function swap_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$webp = $this->maybe_webp_url( $source['url'] );
			if ( $webp ) {
				$sources[ $width ]['url'] = $webp;
			}
		}

		return $sources;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the WebP URL if a .webp file exists on disk, otherwise false.
	 */
	private function maybe_webp_url( $url ) {
		if ( ! $this->is_upload_url( $url ) ) {
			return false;
		}

		if ( ! preg_match( '/\.(jpe?g|png)(\?.*)?$/i', $url ) ) {
			return false;
		}

		$path     = $this->url_to_path( $url );
		$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );

		if ( file_exists( $webp_path ) ) {
			return preg_replace( '/\.(jpe?g|png)(\?.*)?$/i', '.webp', $url );
		}

		return false;
	}

	private function is_upload_url( $url ) {
		return strpos( $url, $this->upload_base_url ) === 0;
	}

	private function url_to_path( $url ) {
		// Strip query string before mapping
		$url_clean = preg_replace( '/\?.*$/', '', $url );
		return str_replace( $this->upload_base_url, $this->upload_base_dir, $url_clean );
	}

	private function swap_srcset_string( $srcset ) {
		// srcset format: "url1 100w, url2 200w, ..."
		return preg_replace_callback(
			'/(\S+)(\s+\d+[wx])/i',
			function ( $m ) {
				$webp = $this->maybe_webp_url( $m[1] );
				return ( $webp ? $webp : $m[1] ) . $m[2];
			},
			$srcset
		);
	}
}
