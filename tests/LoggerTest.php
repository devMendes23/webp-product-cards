<?php
/**
 * Regressão: logs são JSON estruturado válido e carregam o request-id
 * (requisitos 1, 3, 8).
 *
 * @package WebPProductCards
 */

use PHPUnit\Framework\TestCase;
use WebPProductCards\Support\Logger;
use WebPProductCards\Support\RequestContext;

final class LoggerTest extends TestCase {

	protected function setUp(): void {
		RequestContext::boot();
		update_option( 'wpc_log_level', 'debug' );
		Logger::boot();
	}

	public function test_request_id_is_uuid_like() {
		$id = RequestContext::id();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9._\-]{8,64}$/', $id );
		$this->assertSame( $id, RequestContext::id(), 'O request-id é estável no request.' );
	}

	public function test_writes_valid_json_line() {
		Logger::info( 'mensagem de teste', array( 'foo' => 'bar', 'n' => 42 ), 'test' );
		$file  = Logger::dir() . '/app-' . gmdate( 'Y-m-d' ) . '.log';
		$this->assertFileExists( $file );
		$lines = array_filter( explode( "\n", file_get_contents( $file ) ) );
		$last  = json_decode( end( $lines ), true );
		$this->assertIsArray( $last, 'A última linha deve ser JSON válido.' );
		$this->assertSame( 'info', $last['level'] );
		$this->assertSame( 'mensagem de teste', $last['message'] );
		$this->assertSame( 'test', $last['channel'] );
		$this->assertSame( 'bar', $last['context']['foo'] );
		$this->assertSame( RequestContext::id(), $last['request_id'] );
		$this->assertArrayHasKey( 'ts', $last );
		$this->assertArrayHasKey( 'mem_mb', $last );
	}

	public function test_respects_min_level() {
		update_option( 'wpc_log_level', 'error' );
		$file   = Logger::dir() . '/app-' . gmdate( 'Y-m-d' ) . '.log';
		$before = file_exists( $file ) ? count( file( $file ) ) : 0;
		Logger::debug( 'isto deve ser descartado', array(), 'test' );
		$after = file_exists( $file ) ? count( file( $file ) ) : 0;
		$this->assertSame( $before, $after, 'Debug abaixo do nível mínimo não deve ser gravado.' );
	}
}
