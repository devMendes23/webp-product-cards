<?php
/**
 * Requisito 7: Métricas de performance — tempo (wall), memória e CPU
 * (user/sys via getrusage, quando disponível).
 *
 * API de spans:
 *   $t = Metrics::start('convert');
 *   ... trabalho ...
 *   $m = Metrics::stop($t, ['file' => $path]);   // registra e devolve métricas
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Support;

defined( 'ABSPATH' ) || exit;

final class Metrics {

	/** @var array<string,array> Spans abertos. */
	private static $spans = array();

	/** @var array<string,array> Agregados por nome (para o health check). */
	private static $aggregate = array();

	public static function boot() {
		// Nada a inicializar; mantido por simetria com os demais módulos.
	}

	/**
	 * Abre um span de medição.
	 *
	 * @param string $name Nome lógico (ex.: 'convert_file').
	 * @return string Token do span.
	 */
	public static function start( $name ) {
		$token                  = $name . ':' . substr( md5( uniqid( '', true ) ), 0, 8 );
		self::$spans[ $token ] = array(
			'name'  => $name,
			't'     => microtime( true ),
			'mem'   => memory_get_usage( true ),
			'cpu'   => self::cpu(),
		);
		return $token;
	}

	/**
	 * Fecha um span, registra a métrica e a devolve.
	 *
	 * @param string $token   Token retornado por start().
	 * @param array  $context Contexto adicional para o log.
	 * @return array Métricas calculadas.
	 */
	public static function stop( $token, array $context = array() ) {
		if ( ! isset( self::$spans[ $token ] ) ) {
			return array();
		}
		$s   = self::$spans[ $token ];
		unset( self::$spans[ $token ] );

		$cpu_now  = self::cpu();
		$duration = ( microtime( true ) - $s['t'] ) * 1000;

		$metrics = array(
			'name'         => $s['name'],
			'duration_ms'  => round( $duration, 2 ),
			'mem_delta_kb' => round( ( memory_get_usage( true ) - $s['mem'] ) / 1024, 1 ),
			'peak_mem_mb'  => round( memory_get_peak_usage( true ) / 1048576, 2 ),
			'cpu_user_ms'  => null !== $cpu_now ? round( ( $cpu_now['user'] - $s['cpu']['user'] ) * 1000, 2 ) : null,
			'cpu_sys_ms'   => null !== $cpu_now ? round( ( $cpu_now['sys'] - $s['cpu']['sys'] ) * 1000, 2 ) : null,
		);

		self::aggregate( $s['name'], $metrics['duration_ms'] );

		Logger::debug( 'Métrica: ' . $s['name'], array_merge( $metrics, $context ), 'metrics' );

		return $metrics;
	}

	/** Acumula contagem/tempo por nome para exposição no health check. */
	private static function aggregate( $name, $ms ) {
		if ( ! isset( self::$aggregate[ $name ] ) ) {
			self::$aggregate[ $name ] = array(
				'count'   => 0,
				'total'   => 0.0,
				'max_ms'  => 0.0,
			);
		}
		self::$aggregate[ $name ]['count']++;
		self::$aggregate[ $name ]['total'] += $ms;
		self::$aggregate[ $name ]['max_ms'] = max( self::$aggregate[ $name ]['max_ms'], $ms );
	}

	/**
	 * Snapshot do estado atual (usado em shutdown e no health check).
	 *
	 * @return array
	 */
	public static function snapshot() {
		$cpu  = self::cpu();
		$aggr = array();
		foreach ( self::$aggregate as $name => $a ) {
			$aggr[ $name ] = array(
				'count'  => $a['count'],
				'avg_ms' => $a['count'] ? round( $a['total'] / $a['count'], 2 ) : 0,
				'max_ms' => round( $a['max_ms'], 2 ),
			);
		}
		return array(
			'mem_mb'        => round( memory_get_usage( true ) / 1048576, 2 ),
			'peak_mem_mb'   => round( memory_get_peak_usage( true ) / 1048576, 2 ),
			'mem_limit'     => ini_get( 'memory_limit' ),
			'cpu_user_s'    => null !== $cpu ? round( $cpu['user'], 3 ) : null,
			'cpu_sys_s'     => null !== $cpu ? round( $cpu['sys'], 3 ) : null,
			'spans'         => $aggr,
		);
	}

	/**
	 * Tempo de CPU acumulado do processo (segundos), ou null se indisponível
	 * (getrusage não existe no Windows).
	 *
	 * @return array{user:float,sys:float}|null
	 */
	private static function cpu() {
		if ( ! function_exists( 'getrusage' ) ) {
			return null;
		}
		$r = getrusage();
		if ( ! is_array( $r ) ) {
			return null;
		}
		return array(
			'user' => $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6,
			'sys'  => $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6,
		);
	}
}
