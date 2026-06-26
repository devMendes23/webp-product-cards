<?php
/**
 * Decide se um anexo pertence a um CARD DE PRODUTO.
 *
 * Este é o cerne do "converter só os cards de produto": uma imagem só é elegível
 * se for a imagem destacada de um produto WooCommerce OU estiver na galeria do
 * produto. As consultas passam por Database (logging com tempo, requisito 5) e o
 * resultado é cacheado com hit/miss (requisito 6).
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Conversion;

use WebPProductCards\Support\Database;
use WebPProductCards\Support\Cache;

defined( 'ABSPATH' ) || exit;

final class ImageDetector {

	const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * O anexo é imagem de um card de produto?
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function is_product_image( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return false;
		}

		// Escopo "all" desativa a restrição (converte qualquer imagem).
		if ( 'all' === get_option( 'wpc_scope', 'product' ) ) {
			return true;
		}

		// Sem WooCommerce não há "produto"; nada é elegível no modo product.
		if ( ! self::woocommerce_active() ) {
			return false;
		}

		return Cache::remember(
			'is_product_image_' . $attachment_id,
			self::TTL,
			function () use ( $attachment_id ) {
				return self::lookup( $attachment_id );
			}
		);
	}

	/** Consulta efetiva (featured image OU galeria). */
	private static function lookup( $attachment_id ) {
		global $wpdb;

		// 1) É imagem destacada de algum post?
		$parent_id = (int) Database::get_var(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
			array( $attachment_id )
		);
		if ( $parent_id && 'product' === get_post_type( $parent_id ) ) {
			return true;
		}

		// 2) Está em alguma galeria de produto? (_product_image_gallery = "12,34,56")
		$like = '%' . $wpdb->esc_like( (string) $attachment_id ) . '%';
		$rows = Database::get_col(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s LIMIT 50",
			array( $like )
		);
		if ( $rows ) {
			foreach ( $rows as $pid ) {
				// LIKE pode dar falso-positivo (ex.: 12 casando em 123); valida na lista exata.
				$gallery = get_post_meta( (int) $pid, '_product_image_gallery', true );
				$ids     = array_filter( array_map( 'absint', explode( ',', (string) $gallery ) ) );
				if ( in_array( $attachment_id, $ids, true ) && 'product' === get_post_type( $pid ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * IDs de anexos que são imagem de card de produto (destacada + galeria).
	 * Usado pela conversão em massa escopada.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return int[]
	 */
	public static function product_image_ids( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		// Imagens destacadas de produtos.
		$sql = "
			SELECT DISTINCT pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_thumbnail_id'
			  AND p.post_type = 'product'
			  AND p.post_status IN ('publish','draft','private','pending')
			ORDER BY pm.meta_value DESC
			LIMIT %d OFFSET %d
		";
		$ids = Database::get_col( $sql, array( $limit, $offset ) );

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	/** Total aproximado de imagens destacadas de produtos (para barra de progresso). */
	public static function product_image_count() {
		global $wpdb;
		return (int) Database::get_var(
			"SELECT COUNT(DISTINCT pm.meta_value)
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_thumbnail_id' AND p.post_type = 'product'"
		);
	}

	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}
}
