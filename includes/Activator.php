<?php
/**
 * Ativação: define opções padrão e roda um SELF HEALTH-CHECK (requisito 10).
 *
 * Se a ativação deixar o plugin em estado "unhealthy", registramos o motivo e
 * marcamos uma flag. O pipeline de deploy (.github/workflows/deploy.yml) lê o
 * endpoint /health logo após o deploy e faz rollback se necessário — um plugin
 * não controla seu próprio deploy, então o rollback automático real vive no CI.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards;

use WebPProductCards\Support\Logger;
use WebPProductCards\Rest\HealthController;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate() {
		$defaults = array(
			'wpc_quality'           => 82,
			'wpc_engine_pref'       => 'auto',
			'wpc_scope'             => 'product',
			'wpc_convert_on_upload' => 0,
			'wpc_wrap_picture'      => 1,
			'wpc_wrap_thumbnail'    => 1,
			'wpc_wrap_all'          => 0,
			'wpc_log_level'         => 'info',
			'wpc_slow_query_ms'     => 200,
			'wpc_alert_cooldown'    => 600,
		);
		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value );
		}

		update_option( 'wpc_activated_version', WPC_VERSION );
		update_option( 'wpc_activated_at', time() );

		// Self health-check pós-ativação.
		Logger::boot();
		$report = HealthController::report();
		update_option( 'wpc_last_health', $report );

		Logger::info( 'Ativação concluída', array( 'health' => $report['status'] ), 'lifecycle' );

		if ( 'unhealthy' === $report['status'] ) {
			update_option( 'wpc_activation_unhealthy', 1 );
			Logger::critical( 'Ativação em estado unhealthy', array( 'checks' => $report['checks'] ), 'lifecycle' );
		} else {
			delete_option( 'wpc_activation_unhealthy' );
		}
	}
}
