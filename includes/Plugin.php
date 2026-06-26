<?php
/**
 * Orquestrador central do plugin (singleton).
 *
 * Inicializa a camada de observabilidade primeiro (request-id, logger,
 * error-handler, métricas) e depois registra os módulos de domínio.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards;

use WebPProductCards\Support\RequestContext;
use WebPProductCards\Support\Logger;
use WebPProductCards\Support\ErrorHandler;
use WebPProductCards\Support\Metrics;
use WebPProductCards\Conversion\AttachmentHooks;
use WebPProductCards\Frontend\FrontendServing;
use WebPProductCards\Admin\Settings;
use WebPProductCards\Admin\BulkConverter;
use WebPProductCards\Rest\RestController;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	private function __construct() {}

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap. Idempotente.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// --- Camada de observabilidade (deve vir antes de tudo) ---
		RequestContext::boot();   // Requisito 1: request-id único por request.
		Logger::boot();           // Requisito 3: logs JSON estruturados.
		ErrorHandler::register();  // Requisito 2: stack trace completo em texto.
		Metrics::boot();          // Requisito 7: métricas de tempo/memória/CPU.

		Logger::debug(
			'Plugin inicializado.',
			array(
				'version'     => WPC_VERSION,
				'php'         => PHP_VERSION,
				'is_admin'    => is_admin(),
				'doing_cron'  => defined( 'DOING_CRON' ) && DOING_CRON,
			),
			'boot'
		);

		// --- Módulos de domínio ---
		( new AttachmentHooks() )->register();   // Conversão escopada a produtos.
		( new FrontendServing() )->register();   // Entrega <picture> nos cards.
		( new Settings() )->register();          // Página de configurações.
		( new BulkConverter() )->register();     // Conversão em massa (AJAX).
		( new RestController() )->register();     // Endpoints REST (+ health).

		// Encerra o request medindo o ciclo completo (requisito 7).
		add_action( 'shutdown', array( $this, 'on_shutdown' ), PHP_INT_MAX );

		do_action( 'wpc_booted', $this );
	}

	/**
	 * Métrica de fechamento do request inteiro.
	 */
	public function on_shutdown() {
		$total_ms = ( microtime( true ) - RequestContext::started_at() ) * 1000;
		Logger::debug(
			'Request finalizado.',
			array(
				'total_ms'     => round( $total_ms, 2 ),
				'peak_mem_mb'  => round( memory_get_peak_usage( true ) / 1048576, 2 ),
				'metrics'      => Metrics::snapshot(),
			),
			'shutdown'
		);
	}
}
