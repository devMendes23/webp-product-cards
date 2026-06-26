<?php
/**
 * Requisito 4: Health Check com status detalhado.
 *
 * GET /wp-json/webp-product-cards/v1/health
 *   -> 200 healthy | 200 degraded | 503 unhealthy
 *
 * Cada checagem reporta status próprio. Também é usado pela ativação
 * (self-check) e pelo pipeline de deploy (requisito 10).
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Rest;

use WebPProductCards\Conversion\WebPConverter;
use WebPProductCards\Conversion\ImageDetector;
use WebPProductCards\Support\Logger;
use WebPProductCards\Support\Metrics;
use WebPProductCards\Support\Cache;
use WebPProductCards\Support\RequestContext;
use WebPProductCards\Support\Alerts;

defined( 'ABSPATH' ) || exit;

final class HealthController {

	public function register_routes() {
		register_rest_route(
			WPC_REST_NS,
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // Endpoint público de liveness/readiness.
				'callback'            => array( $this, 'handle' ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function handle() {
		$report = self::report();
		$code   = 'unhealthy' === $report['status'] ? 503 : 200;
		return new \WP_REST_Response( $report, $code );
	}

	/**
	 * Monta o relatório de saúde. Reutilizável fora do REST.
	 *
	 * @return array
	 */
	public static function report() {
		$checks = array();

		// 1) Motor de conversão.
		$engine            = WebPConverter::engine();
		$checks['engine']  = array(
			'status' => $engine ? 'pass' : 'fail',
			'value'  => $engine ? $engine : 'nenhum',
			'detail' => $engine ? 'WebP suportado' : 'Sem Imagick/WEBP nem GD imagewebp()',
		);

		// 2) Diretório de uploads gravável.
		$uploads            = wp_get_upload_dir();
		$writable           = empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] );
		$checks['uploads']  = array(
			'status' => $writable ? 'pass' : 'fail',
			'detail' => $writable ? $uploads['basedir'] : 'Não gravável: ' . $uploads['basedir'],
		);

		// 3) Diretório de logs gravável.
		$log_dir          = Logger::dir();
		$log_ok           = is_dir( $log_dir ) && wp_is_writable( $log_dir );
		$checks['logs']   = array(
			'status' => $log_ok ? 'pass' : 'warn',
			'detail' => $log_ok ? $log_dir : 'Logs não graváveis',
		);

		// 4) WooCommerce (degrada, não falha, no modo product).
		$wc                    = ImageDetector::woocommerce_active();
		$scope                 = get_option( 'wpc_scope', 'product' );
		$checks['woocommerce'] = array(
			'status' => $wc ? 'pass' : ( 'product' === $scope ? 'warn' : 'pass' ),
			'detail' => $wc ? 'ativo' : 'inativo (modo "' . $scope . '")',
		);

		// 5) Versão do PHP.
		$php_ok          = version_compare( PHP_VERSION, '7.4', '>=' );
		$checks['php']   = array(
			'status' => $php_ok ? 'pass' : 'warn',
			'value'  => PHP_VERSION,
		);

		// 6) Cobertura de conversão (quantos produtos já têm WebP).
		$checks['coverage'] = array(
			'status' => 'pass',
			'value'  => self::coverage(),
		);

		// Status global: fail => unhealthy; warn => degraded; senão healthy.
		$status = 'healthy';
		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				$status = 'unhealthy';
				break;
			}
			if ( 'warn' === $c['status'] ) {
				$status = 'degraded';
			}
		}

		if ( 'healthy' !== $status ) {
			Alerts::record( 'health_degraded', array( 'status' => $status, 'checks' => $checks ) );
		}

		return array(
			'status'      => $status,
			'request_id'  => RequestContext::id(),
			'version'     => WPC_VERSION,
			'time'        => gmdate( 'c' ),
			'checks'      => $checks,
			'metrics'     => Metrics::snapshot(),
			'cache'       => Cache::stats(),
		);
	}

	/**
	 * Estimativa de cobertura: % de imagens destacadas de produto com .webp.
	 *
	 * @return array
	 */
	private static function coverage() {
		if ( ! ImageDetector::woocommerce_active() ) {
			return array( 'total' => 0, 'converted' => 0, 'pct' => null );
		}
		$ids       = ImageDetector::product_image_ids( 100, 0 ); // amostra.
		$total     = count( $ids );
		$converted = 0;
		$uploads   = wp_get_upload_dir();
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( $file ) {
				$webp = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
				if ( $webp !== $file && file_exists( $webp ) ) {
					$converted++;
				}
			}
		}
		return array(
			'sample'    => $total,
			'converted' => $converted,
			'pct'       => $total ? round( $converted / $total * 100, 1 ) : null,
		);
	}
}
