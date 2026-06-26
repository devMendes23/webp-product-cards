<?php
/**
 * Plugin Name:       WebP Product Cards
 * Plugin URI:        https://example.com/webp-product-cards
 * Description:        Converte as imagens dos cards de produtos (WooCommerce) para WebP e as entrega com fallback via <picture>. Arquitetura com observabilidade: request-id, logs JSON, health check, métricas, cache hit/miss, query logging e alertas.
 * Version:           2.0.0
 * Author:            Mateus
 * License:           GPL-2.0-or-later
 * Text Domain:       webp-product-cards
 * Requires at least: 5.6
 * Requires PHP:      7.4
 *
 * @package WebPProductCards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Acesso direto não permitido.
}

define( 'WPC_VERSION', '2.0.0' );
define( 'WPC_FILE', __FILE__ );
define( 'WPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPC_URL', plugin_dir_url( __FILE__ ) );
define( 'WPC_SLUG', 'webp-product-cards' );
define( 'WPC_REST_NS', 'webp-product-cards/v1' );

/* -------------------------------------------------------------------------
 * Autoloader PSR-4:  WebPProductCards\Sub\Classe  ->  includes/Sub/Classe.php
 * ---------------------------------------------------------------------- */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'WebPProductCards\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = WPC_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/* -------------------------------------------------------------------------
 * Ciclo de vida
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, array( '\WebPProductCards\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WebPProductCards\Deactivator', 'deactivate' ) );

/**
 * Acesso global à instância do plugin.
 *
 * @return \WebPProductCards\Plugin
 */
function wpc() {
	return \WebPProductCards\Plugin::instance();
}

// Inicializa o mais cedo possível para que request-id/logger/error-handler
// cubram todo o ciclo do request (requisitos 1, 2, 3).
wpc()->boot();
