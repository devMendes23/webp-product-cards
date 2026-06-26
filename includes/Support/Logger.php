<?php
/**
 * Requisito 3: Logging estruturado em JSON (uma linha = um objeto JSON),
 * nunca texto livre. Cada linha carrega o request-id (requisito 1).
 *
 * Arquivos: wp-content/uploads/webp-product-cards/logs/app-YYYY-MM-DD.log
 * A pasta é protegida com .htaccess (deny) e index.html.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Support;

defined( 'ABSPATH' ) || exit;

final class Logger {

	const DEBUG    = 'debug';
	const INFO     = 'info';
	const WARNING  = 'warning';
	const ERROR    = 'error';
	const CRITICAL = 'critical';

	/** @var array<string,int> Severidade numérica para filtro por nível mínimo. */
	private static $levels = array(
		self::DEBUG    => 10,
		self::INFO     => 20,
		self::WARNING  => 30,
		self::ERROR    => 40,
		self::CRITICAL => 50,
	);

	/** @var string|null */
	private static $dir = null;

	public static function boot() {
		$uploads    = wp_get_upload_dir();
		self::$dir  = trailingslashit( $uploads['basedir'] ) . WPC_SLUG . '/logs';
		if ( ! is_dir( self::$dir ) ) {
			wp_mkdir_p( self::$dir );
			// Bloqueia acesso web aos logs.
			@file_put_contents( self::$dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( self::$dir . '/index.html', '' ); // phpcs:ignore
		}
	}

	public static function dir() {
		if ( null === self::$dir ) {
			self::boot();
		}
		return self::$dir;
	}

	/** Nível mínimo configurado (descarta o que estiver abaixo). */
	private static function min_level() {
		$lvl = get_option( 'wpc_log_level', self::INFO );
		return isset( self::$levels[ $lvl ] ) ? self::$levels[ $lvl ] : self::$levels[ self::INFO ];
	}

	/**
	 * Escreve uma entrada de log estruturada.
	 *
	 * @param string $level   Um dos níveis da classe.
	 * @param string $message Mensagem curta legível.
	 * @param array  $context Dados estruturados adicionais.
	 * @param string $channel Canal/área (ex.: 'convert', 'db', 'rest').
	 */
	public static function log( $level, $message, array $context = array(), $channel = 'app' ) {
		$severity = isset( self::$levels[ $level ] ) ? self::$levels[ $level ] : self::$levels[ self::INFO ];
		if ( $severity < self::min_level() ) {
			return;
		}

		$now   = microtime( true );
		$entry = array(
			'ts'         => gmdate( 'Y-m-d\TH:i:s', (int) $now ) . sprintf( '.%03dZ', (int) ( ( $now - floor( $now ) ) * 1000 ) ),
			'level'      => $level,
			'request_id' => RequestContext::id(),
			'channel'    => $channel,
			'message'    => $message,
			'context'    => self::sanitize_context( $context ),
			'mem_mb'     => round( memory_get_usage( true ) / 1048576, 2 ),
			'pid'        => function_exists( 'getmypid' ) ? getmypid() : 0,
			'plugin'     => WPC_VERSION,
		);

		$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $line ) {
			$line = wp_json_encode(
				array(
					'ts'      => $entry['ts'],
					'level'   => self::ERROR,
					'message' => 'Falha ao serializar log',
				)
			);
		}

		$file = self::dir() . '/app-' . gmdate( 'Y-m-d' ) . '.log';
		@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore

		// Espelha em error_log quando em modo debug, para facilitar dev.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $severity >= self::$levels[ self::WARNING ] ) {
			error_log( '[WPC] ' . $line ); // phpcs:ignore
		}

		/**
		 * Permite encaminhar logs para um coletor externo (Loki, ELK, etc.).
		 */
		do_action( 'wpc_log', $entry );
	}

	/** Remove valores não serializáveis e trunca strings gigantes. */
	private static function sanitize_context( array $context ) {
		array_walk_recursive(
			$context,
			function ( &$value ) {
				if ( is_string( $value ) && strlen( $value ) > 4000 ) {
					$value = substr( $value, 0, 4000 ) . '…[truncado]';
				} elseif ( is_object( $value ) ) {
					$value = method_exists( $value, '__toString' ) ? (string) $value : get_class( $value );
				} elseif ( is_resource( $value ) ) {
					$value = '[resource]';
				}
			}
		);
		return $context;
	}

	public static function debug( $msg, array $ctx = array(), $ch = 'app' ) {
		self::log( self::DEBUG, $msg, $ctx, $ch ); }
	public static function info( $msg, array $ctx = array(), $ch = 'app' ) {
		self::log( self::INFO, $msg, $ctx, $ch ); }
	public static function warning( $msg, array $ctx = array(), $ch = 'app' ) {
		self::log( self::WARNING, $msg, $ctx, $ch ); }
	public static function error( $msg, array $ctx = array(), $ch = 'app' ) {
		self::log( self::ERROR, $msg, $ctx, $ch ); }
	public static function critical( $msg, array $ctx = array(), $ch = 'app' ) {
		self::log( self::CRITICAL, $msg, $ctx, $ch ); }
}
