<?php
/**
 * Entrega no front-end: embrulha o <img> dos cards de produto em <picture> com
 * fonte WebP e fallback para o original. Funciona com WooCommerce e com o Loop
 * Grid / widget de Produtos do Elementor Pro.
 *
 * Escopo de aplicação:
 *  - woocommerce_product_get_image: cards no loop padrão e no widget de Produtos.
 *  - post_thumbnail_html: cobre o Loop Grid do Elementor, restrito a post_type
 *    'product' e fora da página individual do produto (ou seja, só nos cards).
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Frontend;

use WebPProductCards\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class FrontendServing {

	public function register() {
		if ( ! get_option( 'wpc_wrap_picture', 1 ) ) {
			return;
		}

		// Cards do WooCommerce (inclui o widget "Produtos" do Elementor).
		add_filter( 'woocommerce_product_get_image', array( $this, 'wrap' ), 99 );

		// Loop Grid do Elementor e archives: featured image de produtos.
		if ( get_option( 'wpc_wrap_thumbnail', 1 ) ) {
			add_filter( 'post_thumbnail_html', array( $this, 'wrap_thumbnail' ), 99, 3 );
		}

		// Opcional: aplicar em TODAS as imagens do site.
		if ( get_option( 'wpc_wrap_all', 0 ) ) {
			add_filter( 'wp_get_attachment_image', array( $this, 'wrap' ), 99 );
		}
	}

	/**
	 * Restringe o post_thumbnail aos cards de produto.
	 *
	 * @param string $html
	 * @param int    $post_id
	 * @param int    $thumb_id
	 * @return string
	 */
	public function wrap_thumbnail( $html, $post_id, $thumb_id ) {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return $html; // Não é produto.
		}
		if ( function_exists( 'is_product' ) && is_product() && get_queried_object_id() === (int) $post_id ) {
			return $html; // Imagem principal da página do produto: não mexer.
		}
		return $this->wrap( $html );
	}

	/**
	 * Embrulha uma tag <img> em <picture> com a fonte WebP, quando existir.
	 *
	 * @param string $img_html
	 * @return string
	 */
	public function wrap( $img_html ) {
		if ( ! is_string( $img_html ) || false === strpos( $img_html, '<img' ) ) {
			return $img_html;
		}
		if ( false !== strpos( $img_html, '<picture' ) ) {
			return $img_html; // Já embrulhado.
		}
		if ( ! preg_match( '/\ssrc=("|\')(.*?)\1/i', $img_html, $m ) ) {
			return $img_html;
		}

		$webp_src = $this->webp_url_for( $m[2] );

		// srcset: só usamos se TODAS as variantes tiverem .webp (consistência visual).
		$webp_srcset = '';
		if ( preg_match( '/\ssrcset=("|\')(.*?)\1/i', $img_html, $ms ) ) {
			$items = array_filter( array_map( 'trim', explode( ',', $ms[2] ) ) );
			$out   = array();
			foreach ( $items as $item ) {
				$parts = preg_split( '/\s+/', $item, 2 );
				$wu    = $this->webp_url_for( $parts[0] );
				if ( $wu ) {
					$out[] = $wu . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
				}
			}
			if ( $out && count( $out ) === count( $items ) ) {
				$webp_srcset = implode( ', ', $out );
			}
		}

		if ( ! $webp_src && ! $webp_srcset ) {
			return $img_html; // Nenhum WebP disponível ainda.
		}

		$source = '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ? $webp_srcset : $webp_src ) . '"';
		if ( preg_match( '/\ssizes=("|\')(.*?)\1/i', $img_html, $msz ) ) {
			$source .= ' sizes="' . esc_attr( $msz[2] ) . '"';
		}
		$source .= ' />';

		return '<picture>' . $source . $img_html . '</picture>';
	}

	/**
	 * URL .webp correspondente, se o arquivo existir em uploads.
	 *
	 * @param string $url
	 * @return string|false
	 */
	private function webp_url_for( $url ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || 0 !== strpos( $url, $uploads['baseurl'] ) ) {
			return false; // Externa / fora de uploads.
		}
		$webp_url = preg_replace( '/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $url );
		if ( $webp_url === $url ) {
			return false; // Não era jpg/png.
		}
		$relative = preg_replace( '/\?.*$/', '', substr( $webp_url, strlen( $uploads['baseurl'] ) ) );
		$path     = $uploads['basedir'] . $relative;
		return file_exists( $path ) ? $webp_url : false;
	}
}
