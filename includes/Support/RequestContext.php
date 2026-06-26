<?php
/**
 * Requisito 1: Request ID único por request, para rastreabilidade ponta a ponta.
 *
 * Reaproveita um X-Request-Id recebido (tracing distribuído atrás de proxy/CDN)
 * quando válido; caso contrário gera um UUID v4. O ID acompanha todo log e toda
 * resposta dos endpoints.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Support;

defined( 'ABSPATH' ) || exit;

final class RequestContext {

	/** @var string|null */
	private static $id = null;

	/** @var float */
	private static $started_at = 0.0;

	public static function boot() {
		if ( null !== self::$id ) {
			return;
		}
		self::$started_at = microtime( true );
		self::$id         = self::resolve_id();
	}

	/**
	 * @return string
	 */
	public static function id() {
		if ( null === self::$id ) {
			self::boot();
		}
		return self::$id;
	}

	/**
	 * @return float Timestamp (com microsegundos) do início do request.
	 */
	public static function started_at() {
		return self::$started_at ? self::$started_at : microtime( true );
	}

	/**
	 * Resolve o ID: cabeçalho de entrada válido OU UUID v4 novo.
	 *
	 * @return string
	 */
	private static function resolve_id() {
		if ( isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ) {
			$incoming = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) );
			if ( preg_match( '/^[A-Za-z0-9._\-]{8,64}$/', $incoming ) ) {
				return $incoming;
			}
		}
		return self::uuid4();
	}

	/**
	 * @return string UUID v4.
	 */
	public static function uuid4() {
		try {
			$data = random_bytes( 16 );
		} catch ( \Exception $e ) {
			$data = '';
			for ( $i = 0; $i < 16; $i++ ) {
				$data .= chr( wp_rand( 0, 255 ) );
			}
		}
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 ); // versão 4.
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 ); // variante.
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
