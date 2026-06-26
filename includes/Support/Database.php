<?php
/**
 * Requisito 5: Todo acesso ao banco tem logging com tempo ("carry logging").
 *
 * Fina camada sobre $wpdb que cronometra cada consulta, registra duração,
 * número de linhas e erro do driver, e dispara alerta em consultas lentas.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Support;

defined( 'ABSPATH' ) || exit;

final class Database {

	/**
	 * @param string $sql
	 * @param array  $args Parâmetros para $wpdb->prepare (opcional).
	 * @return string|null
	 */
	public static function get_var( $sql, array $args = array() ) {
		return self::run( 'get_var', $sql, $args );
	}

	public static function get_row( $sql, array $args = array() ) {
		return self::run( 'get_row', $sql, $args );
	}

	public static function get_col( $sql, array $args = array() ) {
		return self::run( 'get_col', $sql, $args );
	}

	public static function get_results( $sql, array $args = array() ) {
		return self::run( 'get_results', $sql, $args );
	}

	public static function query( $sql, array $args = array() ) {
		return self::run( 'query', $sql, $args );
	}

	/**
	 * Executa cronometrando e registrando.
	 *
	 * @param string $method Método do $wpdb.
	 * @param string $sql
	 * @param array  $args
	 * @return mixed
	 */
	private static function run( $method, $sql, array $args ) {
		global $wpdb;

		$prepared = $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB

		$start  = microtime( true );
		$result = $wpdb->{$method}( $prepared ); // phpcs:ignore WordPress.DB
		$ms     = ( microtime( true ) - $start ) * 1000;

		$rows = is_array( $result ) ? count( $result ) : ( null === $result ? 0 : 1 );

		$context = array(
			'method'     => $method,
			'ms'         => round( $ms, 2 ),
			'rows'       => $rows,
			'sql'        => self::shorten( $prepared ),
			'last_error' => $wpdb->last_error ? $wpdb->last_error : null,
		);

		$slow = (float) get_option( 'wpc_slow_query_ms', 200 );

		if ( $wpdb->last_error ) {
			Logger::error( 'Erro de banco', $context, 'db' );
			Alerts::record( 'db_error', $context );
		} elseif ( $ms >= $slow ) {
			Logger::warning( 'Consulta lenta', $context, 'db' );
			Alerts::record( 'slow_query', $context );
		} else {
			Logger::debug( 'Consulta', $context, 'db' );
		}

		return $result;
	}

	/** Encurta o SQL para o log (evita despejar payloads enormes). */
	private static function shorten( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( (string) $sql ) );
		return strlen( $sql ) > 300 ? substr( $sql, 0, 300 ) . '…' : $sql;
	}
}
