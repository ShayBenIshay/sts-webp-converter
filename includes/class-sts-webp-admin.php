<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STS_WebP_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_sts_webp_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_sts_webp_bulk_convert',     array( $this, 'ajax_bulk_convert' ) );
		add_action( 'wp_ajax_sts_webp_get_stats',        array( $this, 'ajax_get_stats' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'upload.php',
			'WebP Converter',
			'WebP Converter',
			'manage_options',
			'sts-webp-converter',
			array( $this, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Settings save
	// -------------------------------------------------------------------------

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( 'sts_webp_settings' );

		$quality = isset( $_POST['sts_webp_quality'] ) ? absint( $_POST['sts_webp_quality'] ) : 82;
		$quality = max( 1, min( 100, $quality ) );
		update_option( 'sts_webp_quality', $quality );

		$auto = ! empty( $_POST['sts_webp_auto_convert'] ) ? 1 : 0;
		update_option( 'sts_webp_auto_convert', $auto );

		wp_redirect( admin_url( 'upload.php?page=sts-webp-converter&saved=1' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: bulk convert batch
	// -------------------------------------------------------------------------

	public function ajax_bulk_convert() {
		check_ajax_referer( 'sts_webp_bulk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$force      = ! empty( $_POST['force'] );
		$batch_size = 10;

		$all_ids = $this->get_all_image_ids();
		$total   = count( $all_ids );
		$batch   = array_slice( $all_ids, $offset, $batch_size );

		$converter = STS_WebP_Converter::get_instance();
		$errors    = array();
		$processed = 0;

		foreach ( $batch as $id ) {
			$results = $converter->convert_attachment( $id, $force );
			foreach ( $results as $path => $result ) {
				if ( is_wp_error( $result ) ) {
					$errors[] = basename( $path ) . ': ' . $result->get_error_message();
				}
			}
			$processed++;
		}

		$offset_next = $offset + $processed;

		wp_send_json_success( array(
			'processed'   => $processed,
			'total'       => $total,
			'offset_next' => $offset_next,
			'errors'      => $errors,
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: stats
	// -------------------------------------------------------------------------

	public function ajax_get_stats() {
		check_ajax_referer( 'sts_webp_bulk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$stats = $this->compute_stats();
		wp_send_json_success( $stats );
	}

	// -------------------------------------------------------------------------
	// Stats helpers
	// -------------------------------------------------------------------------

	private function get_all_image_ids() {
		return get_posts( array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
	}

	private function compute_stats() {
		$ids       = $this->get_all_image_ids();
		$total     = count( $ids );
		$converted = 0;
		$converter = STS_WebP_Converter::get_instance();

		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( $file ) {
				$webp = $converter->get_webp_path( $file );
				if ( $webp && file_exists( $webp ) ) {
					$converted++;
				}
			}
		}

		return array(
			'total'     => $total,
			'converted' => $converted,
			'pending'   => $total - $converted,
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$quality      = (int) get_option( 'sts_webp_quality', 82 );
		$auto_convert = (bool) get_option( 'sts_webp_auto_convert', true );
		$saved        = isset( $_GET['saved'] );
		$stats        = $this->compute_stats();
		$nonce        = wp_create_nonce( 'sts_webp_bulk' );
		$pct          = $stats['total'] > 0 ? round( $stats['converted'] / $stats['total'] * 100 ) : 0;

		// Detect PHP WebP capability
		$has_imagick = extension_loaded( 'imagick' );
		$has_gd_webp = function_exists( 'imagewebp' );
		$capable     = $has_imagick || $has_gd_webp;
		?>
		<div class="wrap">
			<h1>WebP Converter</h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<?php if ( ! $capable ) : ?>
				<div class="notice notice-error">
					<p><strong>Warning:</strong> Neither Imagick nor GD with WebP support was found on this server. Image conversion will not work until your host enables one of them.</p>
				</div>
			<?php else : ?>
				<div class="notice notice-info inline">
					<p>
						Using: <strong><?php echo $has_imagick ? 'Imagick' : 'GD'; ?></strong>
						<?php if ( ! $has_imagick && $has_gd_webp ) echo '(Imagick not available — GD fallback)'; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_styles(); ?>

			<!-- Stats -->
			<div class="stswp-stats-row">
				<div class="stswp-stat-card">
					<div class="stswp-stat-value"><?php echo esc_html( number_format( $stats['total'] ) ); ?></div>
					<div class="stswp-stat-label">Total images</div>
				</div>
				<div class="stswp-stat-card">
					<div class="stswp-stat-value" style="color:#00a32a"><?php echo esc_html( number_format( $stats['converted'] ) ); ?></div>
					<div class="stswp-stat-label">Converted to WebP</div>
				</div>
				<div class="stswp-stat-card">
					<div class="stswp-stat-value" style="color:#d63638"><?php echo esc_html( number_format( $stats['pending'] ) ); ?></div>
					<div class="stswp-stat-label">Pending</div>
				</div>
				<div class="stswp-stat-card">
					<div class="stswp-stat-value"><?php echo esc_html( $pct ); ?>%</div>
					<div class="stswp-stat-label">Complete</div>
				</div>
			</div>

			<div class="stswp-progress-track">
				<div class="stswp-progress-fill" id="stswp-progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
			</div>
			<p style="color:#666;font-size:12px"><?php echo esc_html( $stats['converted'] ); ?> / <?php echo esc_html( $stats['total'] ); ?> images</p>

			<!-- Bulk converter -->
			<h2>Bulk Convert</h2>
			<p>Convert all existing PNG and JPG images in your media library to WebP. Originals are kept. Images already converted are skipped.</p>

			<button id="stswp-start" class="button button-primary" <?php echo $stats['pending'] === 0 ? 'disabled' : ''; ?>>
				<?php echo $stats['pending'] === 0 ? 'All images already converted' : 'Convert All (' . number_format( $stats['pending'] ) . ' pending)'; ?>
			</button>
			<button id="stswp-reconvert" class="button" style="margin-left:8px">Reconvert All (force)</button>

			<div id="stswp-log" style="display:none">
				<div id="stswp-log-status" style="margin-top:12px;font-weight:600"></div>
				<div class="stswp-progress-track" style="margin-top:8px">
					<div class="stswp-progress-fill" id="stswp-progress-bulk" style="width:0%"></div>
				</div>
				<textarea id="stswp-log-area" readonly style="margin-top:10px;width:100%;height:140px;font-family:monospace;font-size:12px;resize:vertical;background:#1e1e1e;color:#d4d4d4;border:none;padding:10px;border-radius:4px"></textarea>
			</div>

			<hr style="margin:32px 0">

			<!-- Settings -->
			<h2>Settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sts_webp_settings' ); ?>
				<input type="hidden" name="action" value="sts_webp_save_settings">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="sts_webp_quality">WebP Quality</label></th>
						<td>
							<input type="range" id="sts_webp_quality" name="sts_webp_quality"
								min="1" max="100" value="<?php echo esc_attr( $quality ); ?>"
								oninput="document.getElementById('stswp-quality-val').textContent=this.value">
							<span id="stswp-quality-val"><?php echo esc_html( $quality ); ?></span> / 100
							<p class="description">82 is a good balance of size and quality. Lower = smaller files, higher = better quality.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Auto-convert new uploads</th>
						<td>
							<label>
								<input type="checkbox" name="sts_webp_auto_convert" value="1" <?php checked( $auto_convert ); ?>>
								Automatically convert PNG/JPG to WebP when a new image is uploaded
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>
		</div>

		<script>
		(function() {
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			function startBulk( force ) {
				document.getElementById('stswp-log').style.display = 'block';
				document.getElementById('stswp-start').disabled = true;
				document.getElementById('stswp-reconvert').disabled = true;
				log('Starting conversion...');
				runBatch( 0, 0, force );
			}

			function runBatch( offset, totalDone, force ) {
				var data = new FormData();
				data.append('action', 'sts_webp_bulk_convert');
				data.append('nonce', nonce);
				data.append('offset', offset);
				if ( force ) data.append('force', '1');

				fetch( ajaxUrl, { method: 'POST', body: data } )
					.then(function(r){ return r.json(); })
					.then(function(res){
						if ( ! res.success ) {
							log('Error: ' + res.data);
							return;
						}
						var d = res.data;
						totalDone += d.processed;
						var pct = d.total > 0 ? Math.round( totalDone / d.total * 100 ) : 100;

						document.getElementById('stswp-progress-bulk').style.width = pct + '%';
						document.getElementById('stswp-log-status').textContent =
							'Processing: ' + totalDone + ' / ' + d.total + ' (' + pct + '%)';

						if ( d.errors && d.errors.length ) {
							d.errors.forEach(function(e){ log('Error: ' + e); });
						} else if ( d.processed > 0 ) {
							log('Batch done: offset ' + offset + '–' + (offset + d.processed - 1) );
						}

						if ( d.offset_next < d.total ) {
							runBatch( d.offset_next, totalDone, force );
						} else {
							document.getElementById('stswp-log-status').textContent =
								'Done! ' + totalDone + ' images processed.';
							log('All done. Refresh the page to see updated stats.');
							document.getElementById('stswp-start').disabled = false;
							document.getElementById('stswp-reconvert').disabled = false;
						}
					})
					.catch(function(err){
						log('Network error: ' + err);
					});
			}

			function log( msg ) {
				var area = document.getElementById('stswp-log-area');
				area.value += msg + '\n';
				area.scrollTop = area.scrollHeight;
			}

			document.getElementById('stswp-start').addEventListener('click', function(e){
				e.preventDefault();
				startBulk( false );
			});

			document.getElementById('stswp-reconvert').addEventListener('click', function(e){
				e.preventDefault();
				if ( confirm('This will reconvert every image, overwriting existing WebP files. Continue?') ) {
					startBulk( true );
				}
			});
		})();
		</script>
		<?php
	}

	private function render_styles() {
		?>
		<style>
		.stswp-stats-row {
			display: flex;
			gap: 16px;
			margin: 20px 0 20px;
			flex-wrap: wrap;
		}
		.stswp-stat-card {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 6px;
			padding: 20px 24px;
			flex: 1;
			min-width: 130px;
			box-shadow: 0 1px 3px rgba(0,0,0,.06);
		}
		.stswp-stat-value {
			font-size: 32px;
			font-weight: 700;
			color: #1d2327;
			line-height: 1;
		}
		.stswp-stat-label {
			margin-top: 6px;
			font-size: 13px;
			color: #666;
		}
		.stswp-progress-track {
			height: 10px;
			background: #e0e0e0;
			border-radius: 5px;
			overflow: hidden;
			max-width: 600px;
		}
		.stswp-progress-fill {
			height: 100%;
			background: #2271b1;
			border-radius: 5px;
			transition: width 0.3s ease;
		}
		</style>
		<?php
	}
}
