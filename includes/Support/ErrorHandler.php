<?php
/**
 * Requisito 2: Todo erro tem stack trace completo, em texto.
 *
 * - guard(): embrulha qualquer fluxo crítico; captura \Throwable, registra o
 *   trace textual completo e devolve um WP_Error padronizado.
 * - register(): converte erros PHP em ExceptionsErro e instala um handler de
 *   shutdown para erros fatais — nada escapa sem stack trace.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Support;

defined( 'ABSPATH' ) || exit;

final class ErrorHandler {

	/** @var bool */
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Captura erros fatais (E_ERROR, parse, etc.) que escapariam ao try/catch.
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	/**
	 * Executa um callable protegido. Em caso de exceção, registra o stack trace
	 * completo (texto) e devolve WP_Error em vez de quebrar a página.
	 *
	 * @param callable $fn      Fluxo a executar.
	 * @param string   $op      Nome da operação (para o log).
	 * @param array    $context Contexto adicional.
	 * @return mixed|\WP_Error
	 */
	public static function guard( callable $fn, $op, array $context = array() ) {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			$payload = self::report( $e, $op, $context );
			return new \WP_Error(
				'wpc_exception',
				$e->getMessage(),
				array(
					'op'         => $op,
					'request_id' => RequestContext::id(),
					'exception'  => $payload['exception'],
				)
			);
		}
	}

	/**
	 * Registra um Throwable com stack trace textual completo.
	 *
	 * @return array O payload do erro (também usado pelos alertas).
	 */
	public static function report( \Throwable $e, $op, array $context = array() ) {
		$payload = array(
			'op'         => $op,
			'exception'  => get_class( $e ),
			'message'    => $e->getMessage(),
			'code'       => $e->getCode(),
			'file'       => $e->getFile(),
			'line'       => $e->getLine(),
			'trace'      => $e->getTraceAsString(), // Requisito 2: trace completo em texto.
			'previous'   => $e->getPrevious() ? get_class( $e->getPrevious() ) . ': ' . $e->getPrevious()->getMessage() : null,
			'context'    => $context,
		);

		Logger::error( 'Exceção em "' . $op . '": ' . $e->getMessage(), $payload, 'error' );
		Alerts::record( 'error', $payload ); // Requisito 9.

		return $payload;
	}

	/**
	 * Captura erros fatais não tratados no fim do request.
	 */
	public static function on_shutdown() {
		$err = error_get_last();
		if ( ! $err ) {
			return;
		}
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( ! in_array( $err['type'], $fatal, true ) ) {
			return;
		}
		// Só nos interessam fatais originados no nosso plugin.
		if ( false === strpos( (string) $err['file'], WPC_SLUG ) ) {
			return;
		}
		Logger::critical(
			'Erro fatal: ' . $err['message'],
			array(
				'type' => $err['type'],
				'file' => $err['file'],
				'line' => $err['line'],
				// Sem objeto de exceção, capturamos o backtrace atual em texto.
				'trace' => self::backtrace_string(),
			),
			'error'
		);
		Alerts::record( 'fatal', $err );
	}

	/** @return string Backtrace atual formatado como texto. */
	private static function backtrace_string() {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore
		$out   = array();
		foreach ( $trace as $i => $f ) {
			$out[] = sprintf(
				'#%d %s%s%s() em %s:%d',
				$i,
				isset( $f['class'] ) ? $f['class'] : '',
				isset( $f['type'] ) ? $f['type'] : '',
				isset( $f['function'] ) ? $f['function'] : '',
				isset( $f['file'] ) ? $f['file'] : '?',
				isset( $f['line'] ) ? $f['line'] : 0
			);
		}
		return implode( "\n", $out );
	}
}
