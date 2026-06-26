<?php
/**
 * Registra os endpoints REST e injeta o X-Request-Id em toda resposta da
 * namespace do plugin (requisito 1).
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Rest;

use WebPProductCards\Support\RequestContext;

defined( 'ABSPATH' ) || exit;

final class RestController {

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		// Garante o cabeçalho de rastreabilidade em todas as respostas REST.
		add_filter( 'rest_post_dispatch', array( $this, 'attach_request_id' ), 10, 3 );
	}

	public function routes() {
		( new HealthController() )->register_routes();

		register_rest_route(
			WPC_REST_NS,
			'/metrics',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => array( $this, 'metrics' ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function metrics() {
		$data = array(
			'request_id' => RequestContext::id(),
			'metrics'    => \WebPProductCards\Support\Metrics::snapshot(),
			'cache'      => \WebPProductCards\Support\Cache::stats(),
			'alerts'     => \WebPProductCards\Support\Alerts::thresholds(),
		);
		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Adiciona X-Request-Id em respostas da nossa namespace.
	 *
	 * @param \WP_REST_Response $response
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return \WP_REST_Response
	 */
	public function attach_request_id( $response, $server, $request ) {
		if ( $request instanceof \WP_REST_Request && 0 === strpos( ltrim( $request->get_route(), '/' ), WPC_REST_NS ) ) {
			$response->header( 'X-Request-Id', RequestContext::id() );
		}
		return $response;
	}
}
