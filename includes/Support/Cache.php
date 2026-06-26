<?php
/**
 * Requisito 6: Todo cache tem rastreamento de Hit/Miss.
 *
 * Camada fina sobre a Transients API (usa object cache quando presente).
 * Contadores hit/miss são acumulados no request e persistidos por dia para
 * exposição no health check.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Support;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const PREFIX = 'wpc_c_';

	/** @var int */
	private static $hits = 0;
	/** @var int */
	private static $misses = 0;

	/**
	 * Devolve do cache ou calcula, armazena e devolve. Conta hit/miss.
	 *
	 * @param string   $key      Chave lógica.
	 * @param int      $ttl      Vida em segundos.
	 * @param callable $callback Produz o valor no miss.
	 * @return mixed
	 */
	public static function remember( $key, $ttl, callable $callback ) {
		$found = self::get( $key, $hit );
		if ( $hit ) {
			return $found;
		}
		$value = $callback();
		self::set( $key, $value, $ttl );
		return $value;
	}

	/**
	 * @param string $key
	 * @param bool   $hit Saída: true se houve hit.
	 * @return mixed|null
	 */
	public static function get( $key, &$hit = null ) {
		$raw = get_transient( self::PREFIX . md5( $key ) );
		// Empacotamos em ['d'=>...] para distinguir "miss" de um valor false legítimo.
		if ( is_array( $raw ) && array_key_exists( 'd', $raw ) ) {
			$hit = true;
			self::$hits++;
			self::track( 'hit', $key );
			return $raw['d'];
		}
		$hit = false;
		self::$misses++;
		self::track( 'miss', $key );
		return null;
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 */
	public static function set( $key, $value, $ttl ) {
		set_transient( self::PREFIX . md5( $key ), array( 'd' => $value ), max( 0, (int) $ttl ) );
	}

	public static function delete( $key ) {
		delete_transient( self::PREFIX . md5( $key ) );
	}

	/** Registra o evento no log e persiste o contador diário. */
	private static function track( $type, $key ) {
		Logger::debug(
			'Cache ' . $type,
			array(
				'key'  => $key,
				'hits' => self::$hits,
				'miss' => self::$misses,
			),
			'cache'
		);
		$opt              = get_option( 'wpc_cache_stats', array() );
		$day              = gmdate( 'Y-m-d' );
		$opt[ $day ]      = isset( $opt[ $day ] ) ? $opt[ $day ] : array( 'hit' => 0, 'miss' => 0 );
		$opt[ $day ][ $type ]++;
		// Mantém só os últimos 7 dias.
		if ( count( $opt ) > 7 ) {
			ksort( $opt );
			$opt = array_slice( $opt, -7, null, true );
		}
		update_option( 'wpc_cache_stats', $opt, false );
	}

	/**
	 * Estatísticas do request atual + razão de acerto.
	 *
	 * @return array
	 */
	public static function stats() {
		$total = self::$hits + self::$misses;
		return array(
			'hits'   => self::$hits,
			'misses' => self::$misses,
			'ratio'  => $total ? round( self::$hits / $total, 3 ) : null,
			'daily'  => get_option( 'wpc_cache_stats', array() ),
		);
	}
}
