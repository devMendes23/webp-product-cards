<?php
/**
 * Conversão em massa via AJAX (escopada a produtos por padrão) e limpeza dos
 * arquivos .webp. Cada handler é protegido por ErrorHandler::guard (stack trace)
 * e responde com o request-id no corpo.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Admin;

use WebPProductCards\Conversion\WebPConverter;
use WebPProductCards\Conversion\ImageDetector;
use WebPProductCards\Support\ErrorHandler;
use WebPProductCards\Support\RequestContext;
use WebPProductCards\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class BulkConverter {

	const BATCH = 8;

	public function register() {
		add_action( 'wp_ajax_wpc_bulk_convert', array( $this, 'bulk_convert' ) );
		add_action( 'wp_ajax_wpc_cleanup', array( $this, 'cleanup' ) );
	}

	public function bulk_convert() {
		check_ajax_referer( 'wpc_bulk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permissão negada.', 'request_id' => RequestContext::id() ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$result = ErrorHandler::guard(
			function () use ( $offset ) {
				$scope = get_option( 'wpc_scope', 'product' );

				if ( 'all' === $scope ) {
					$query = new \WP_Query(
						array(
							'post_type'              => 'attachment',
							'post_status'            => 'inherit',
							'post_mime_type'         => array( 'image/jpeg', 'image/png' ),
							'posts_per_page'         => self::BATCH,
							'offset'                 => $offset,
							'fields'                 => 'ids',
							'update_post_meta_cache' => false,
							'update_post_term_cache' => false,
						)
					);
					$ids   = $query->posts;
					$total = (int) $query->found_posts;
				} else {
					$ids   = ImageDetector::product_image_ids( self::BATCH, $offset );
					$total = ImageDetector::product_image_count();
				}

				$converted = 0;
				$skipped   = 0;
				foreach ( $ids as $id ) {
					$r          = WebPConverter::convert_attachment( (int) $id );
					$converted += $r['converted'];
					$skipped   += $r['skipped'];
				}

				$new_offset = $offset + count( $ids );

				return array(
					'offset'     => $new_offset,
					'total'      => $total,
					'converted'  => $converted,
					'skipped'    => $skipped,
					'finished'   => ( $new_offset >= $total ) || empty( $ids ),
					'request_id' => RequestContext::id(),
				);
			},
			'bulk_convert',
			array( 'offset' => $offset )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'request_id' => RequestContext::id() ), 500 );
		}
		wp_send_json_success( $result );
	}

	public function cleanup() {
		check_ajax_referer( 'wpc_bulk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permissão negada.' ), 403 );
		}

		$result = ErrorHandler::guard(
			function () {
				$uploads = wp_get_upload_dir();
				$dir     = $uploads['basedir'];
				$deleted = 0;
				if ( is_dir( $dir ) ) {
					$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
					foreach ( $it as $file ) {
						if ( $file->isFile() && preg_match( '/\.webp$/i', $file->getFilename() ) ) {
							if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore
								$deleted++;
							}
						}
					}
				}
				Logger::info( 'Limpeza de WebP', array( 'deleted' => $deleted ), 'admin' );
				return array( 'deleted' => $deleted, 'request_id' => RequestContext::id() );
			},
			'cleanup'
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		wp_send_json_success( $result );
	}
}
