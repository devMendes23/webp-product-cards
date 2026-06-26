<?php
/**
 * Regressão do escopo: no modo "all" toda imagem é elegível; sem WooCommerce
 * no modo "product" nada é elegível (requisito 8 sobre o fluxo crítico de
 * "converter só os cards de produto").
 *
 * @package WebPProductCards
 */

use PHPUnit\Framework\TestCase;
use WebPProductCards\Conversion\ImageDetector;

final class ScopeTest extends TestCase {

	public function test_scope_all_accepts_any_attachment() {
		update_option( 'wpc_scope', 'all' );
		$this->assertTrue( ImageDetector::is_product_image( 123 ) );
	}

	public function test_scope_product_without_woocommerce_rejects() {
		update_option( 'wpc_scope', 'product' );
		// Sem a classe WooCommerce definida no runner, deve recusar sem tocar o banco.
		$this->assertFalse( ImageDetector::woocommerce_active() );
		$this->assertFalse( ImageDetector::is_product_image( 123 ) );
	}

	public function test_invalid_id_is_rejected() {
		update_option( 'wpc_scope', 'all' );
		$this->assertFalse( ImageDetector::is_product_image( 0 ) );
		$this->assertFalse( ImageDetector::is_product_image( -5 ) );
	}
}
