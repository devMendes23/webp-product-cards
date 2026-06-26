<?php
/**
 * Gatilhos de conversão escopados ao produto.
 *
 * Estratégia para "só os cards de produto":
 *  - A conversão dispara quando uma imagem passa a ser a destacada/galeria de um
 *    PRODUTO (added/updated_post_meta), não em qualquer upload.
 *  - Opcionalmente converte todo upload (wpc_convert_on_upload), desligado por
 *    padrão para honrar o escopo.
 *  - A conversão pesada roda em background (wp_schedule_single_event) para não
 *    travar o salvamento do produto.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Conversion;

use WebPProductCards\Support\Logger;
use WebPProductCards\Support\Cache;

defined( 'ABSPATH' ) || exit;

final class AttachmentHooks {

	const CRON_HOOK = 'wpc_convert_attachment_job';

	public function register() {
		// Conversão assíncrona (executa o job agendado).
		add_action( self::CRON_HOOK, array( $this, 'run_job' ), 10, 1 );

		// Produto ganhou/alterou imagem destacada ou galeria -> converte aquele anexo.
		add_action( 'added_post_meta', array( $this, 'on_product_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_product_meta' ), 10, 4 );

		// Opcional: converter todo upload de imagem (fora do escopo de produto).
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 20, 2 );

		// Invalida o cache de detecção quando a relação muda.
		add_action( 'updated_post_meta', array( $this, 'bust_cache' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'bust_cache' ), 10, 4 );
	}

	/**
	 * Reage a mudanças de _thumbnail_id / _product_image_gallery em produtos.
	 *
	 * @param int    $meta_id
	 * @param int    $post_id
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 */
	public function on_product_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( ! in_array( $meta_key, array( '_thumbnail_id', '_product_image_gallery' ), true ) ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$ids = array();
		if ( '_thumbnail_id' === $meta_key ) {
			$ids[] = (int) $meta_value;
		} else {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $meta_value ) ) );
		}

		foreach ( $ids as $attachment_id ) {
			if ( $attachment_id > 0 ) {
				Cache::delete( 'is_product_image_' . $attachment_id );
				$this->schedule( $attachment_id );
			}
		}
	}

	/** Conversão opcional de todo upload de imagem. */
	public function on_upload( $metadata, $attachment_id ) {
		if ( ! get_option( 'wpc_convert_on_upload', 0 ) ) {
			return $metadata; // Desligado por padrão (escopo = produto).
		}
		$this->schedule( (int) $attachment_id );
		return $metadata;
	}

	/** Agenda a conversão para rodar quase imediatamente, em background. */
	private function schedule( $attachment_id ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $attachment_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $attachment_id ) );
			Logger::debug( 'Conversão agendada', array( 'attachment_id' => $attachment_id ), 'convert' );
		}
	}

	/** Executa o job de conversão (valida o escopo no momento da execução). */
	public function run_job( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! get_option( 'wpc_convert_on_upload', 0 ) && ! ImageDetector::is_product_image( $attachment_id ) ) {
			Logger::debug( 'Job ignorado: fora do escopo de produto', array( 'attachment_id' => $attachment_id ), 'convert' );
			return;
		}
		$result = WebPConverter::convert_attachment( $attachment_id );
		Logger::info( 'Job de conversão concluído', array_merge( array( 'attachment_id' => $attachment_id ), $result ), 'convert' );
	}

	/** Invalida o cache de detecção. */
	public function bust_cache( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( ! in_array( $meta_key, array( '_thumbnail_id', '_product_image_gallery' ), true ) ) {
			return;
		}
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $meta_value ) ) );
		foreach ( $ids as $id ) {
			Cache::delete( 'is_product_image_' . $id );
		}
	}
}
