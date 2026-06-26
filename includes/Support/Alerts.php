<?php
/**
 * Requisito 9: Alertas configuráveis para anomalias.
 *
 * Mantém contadores por janela de 1 minuto. Quando o número de eventos de um
 * tipo cruza o limite configurado, dispara notificação (e-mail e/ou webhook),
 * com throttling para não inundar.
 *
 * Tipos: error, fatal, db_error, slow_query, conversion_failure, health_degraded.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Support;

defined( 'ABSPATH' ) || exit;

final class Alerts {

	/**
	 * Registra a ocorrência de um evento e dispara alerta se ultrapassar o limite.
	 *
	 * @param string $type    Tipo do evento.
	 * @param array  $context Contexto para a notificação.
	 */
	public static function record( $type, array $context = array() ) {
		$thresholds = self::thresholds();
		if ( ! isset( $thresholds[ $type ] ) || $thresholds[ $type ] <= 0 ) {
			return; // Tipo sem alerta configurado.
		}

		$bucket = (int) floor( time() / 60 );
		$key    = 'wpc_alert_' . $type . '_' . $bucket;
		$count  = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 180 );

		if ( $count === $thresholds[ $type ] ) { // Dispara uma vez ao cruzar.
			self::maybe_dispatch( $type, $count, $context );
		}
	}

	/** @return array<string,int> Limites por minuto. */
	public static function thresholds() {
		$defaults = array(
			'error'              => 10,
			'fatal'              => 1,
			'db_error'           => 5,
			'slow_query'         => 20,
			'conversion_failure' => 10,
			'health_degraded'    => 1,
		);
		$saved = get_option( 'wpc_alert_thresholds', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Dispara o alerta respeitando um throttle por tipo.
	 */
	private static function maybe_dispatch( $type, $count, array $context ) {
		$throttle_key = 'wpc_alert_sent_' . $type;
		if ( get_transient( $throttle_key ) ) {
			return; // Já notificamos esse tipo recentemente.
		}
		$cooldown = (int) get_option( 'wpc_alert_cooldown', 600 ); // 10 min.
		set_transient( $throttle_key, 1, $cooldown );

		$payload = array(
			'type'       => $type,
			'count'      => $count,
			'window'     => '60s',
			'site'       => home_url(),
			'request_id' => RequestContext::id(),
			'time'       => gmdate( 'c' ),
			'context'    => $context,
		);

		Logger::warning( 'Alerta disparado: ' . $type, $payload, 'alerts' );

		self::send_email( $type, $payload );
		self::send_webhook( $payload );

		/** Permite integrações externas (Slack, PagerDuty, etc.). */
		do_action( 'wpc_alert', $type, $payload );
	}

	private static function send_email( $type, array $payload ) {
		$to = get_option( 'wpc_alert_email', '' );
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}
		$subject = sprintf( '[%s] Anomalia: %s (%dx/min)', wp_parse_url( home_url(), PHP_URL_HOST ), $type, $payload['count'] );
		$body    = "Um limite de anomalia foi atingido.\n\n" . wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		wp_mail( $to, $subject, $body );
	}

	private static function send_webhook( array $payload ) {
		$url = get_option( 'wpc_alert_webhook', '' );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return;
		}
		wp_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array(
					'Content-Type' => 'application/json',
					'X-Request-Id' => $payload['request_id'],
				),
				'body'     => wp_json_encode( $payload ),
			)
		);
	}
}
