<?php
/**
 * Motor de conversão JPG/PNG -> WebP.
 *
 * Lógica comprovada do v1 (Imagick com fallback para GD), agora instrumentada
 * com métricas (requisito 7) e captura de stack trace (requisito 2). Não apaga
 * nem altera os originais — apenas gera o .webp ao lado.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Conversion;

use WebPProductCards\Support\Logger;
use WebPProductCards\Support\Metrics;
use WebPProductCards\Support\ErrorHandler;
use WebPProductCards\Support\Alerts;

defined( 'ABSPATH' ) || exit;

final class WebPConverter {

	/** @return int Qualidade 1–100. */
	public static function quality() {
		$q = (int) get_option( 'wpc_quality', 82 );
		return max( 1, min( 100, $q ) );
	}

	/**
	 * Detecta o motor disponível.
	 *
	 * @return string|false 'imagick', 'gd' ou false.
	 */
	public static function engine() {
		$pref = get_option( 'wpc_engine_pref', 'auto' );

		if ( 'gd' !== $pref && extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				if ( ! empty( \Imagick::queryFormats( 'WEBP' ) ) ) {
					return 'imagick';
				}
			} catch ( \Throwable $e ) {
				// cai para GD.
			}
		}
		if ( function_exists( 'imagewebp' ) ) {
			return 'gd';
		}
		return false;
	}

	/**
	 * Gera o .webp ao lado do arquivo de origem (JPG/PNG).
	 *
	 * @param string $source_path Caminho absoluto.
	 * @return string|false Caminho do .webp (novo ou já atualizado) ou false.
	 */
	public static function convert_file( $source_path ) {
		$span   = Metrics::start( 'convert_file' );
		$result = ErrorHandler::guard(
			function () use ( $source_path ) {
				return self::do_convert( $source_path );
			},
			'convert_file',
			array( 'source' => $source_path )
		);
		$metrics = Metrics::stop( $span, array( 'source' => basename( (string) $source_path ) ) );

		if ( is_wp_error( $result ) || false === $result ) {
			Alerts::record( 'conversion_failure', array( 'source' => $source_path ) );
			return false;
		}

		Logger::info(
			'WebP gerado',
			array(
				'source'      => basename( $source_path ),
				'webp'        => basename( $result ),
				'duration_ms' => isset( $metrics['duration_ms'] ) ? $metrics['duration_ms'] : null,
				'orig_bytes'  => @filesize( $source_path ), // phpcs:ignore
				'webp_bytes'  => @filesize( $result ),      // phpcs:ignore
			),
			'convert'
		);
		return $result;
	}

	/** Implementação real da conversão (sem instrumentação). */
	private static function do_convert( $source_path ) {
		if ( ! $source_path || ! file_exists( $source_path ) ) {
			return false;
		}
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $source_path ) ) {
			return false;
		}

		$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );

		// Já existe e está atualizado? Reaproveita (idempotência).
		if ( file_exists( $webp_path ) && filemtime( $webp_path ) >= filemtime( $source_path ) ) {
			return $webp_path;
		}

		$quality = self::quality();
		$engine  = self::engine();

		if ( 'imagick' === $engine ) {
			$img = new \Imagick( $source_path );
			if ( $img->getImageAlphaChannel() ) {
				$img->setImageBackgroundColor( new \ImagickPixel( 'transparent' ) );
			}
			$img->setImageFormat( 'webp' );
			$img->setOption( 'webp:method', '6' );
			$img->setImageCompressionQuality( $quality );
			$img->writeImage( $webp_path );
			$img->clear();
			$img->destroy();
			return file_exists( $webp_path ) ? $webp_path : false;
		}

		if ( function_exists( 'imagewebp' ) ) {
			$info = getimagesize( $source_path );
			if ( ! $info ) {
				return false;
			}
			switch ( $info['mime'] ) {
				case 'image/jpeg':
					$image = imagecreatefromjpeg( $source_path );
					break;
				case 'image/png':
					$image = imagecreatefrompng( $source_path );
					if ( $image ) {
						imagepalettetotruecolor( $image );
						imagealphablending( $image, true );
						imagesavealpha( $image, true );
					}
					break;
				default:
					return false;
			}
			if ( ! $image ) {
				return false;
			}
			$ok = imagewebp( $image, $webp_path, $quality );
			imagedestroy( $image );
			return $ok ? $webp_path : false;
		}

		return false; // Sem motor disponível.
	}

	/**
	 * Lista os caminhos de todos os tamanhos de um anexo (original + miniaturas).
	 *
	 * @param int        $attachment_id
	 * @param array|null $metadata
	 * @return string[]
	 */
	public static function attachment_files( $attachment_id, $metadata = null ) {
		$files = array();
		$full  = get_attached_file( $attachment_id );
		if ( $full && file_exists( $full ) ) {
			$files[] = $full;
		}
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		if ( ! empty( $metadata['sizes'] ) && $full ) {
			$dir = trailingslashit( dirname( $full ) );
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . $size['file'];
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Converte todos os tamanhos de um anexo.
	 *
	 * @return array{converted:int,skipped:int}
	 */
	public static function convert_attachment( $attachment_id, $metadata = null ) {
		$span      = Metrics::start( 'convert_attachment' );
		$converted = 0;
		$skipped   = 0;
		foreach ( self::attachment_files( $attachment_id, $metadata ) as $file ) {
			if ( self::convert_file( $file ) ) {
				$converted++;
			} else {
				$skipped++;
			}
		}
		Metrics::stop(
			$span,
			array(
				'attachment_id' => $attachment_id,
				'converted'     => $converted,
				'skipped'       => $skipped,
			)
		);
		update_post_meta( $attachment_id, '_wpc_converted_at', time() );
		return array(
			'converted' => $converted,
			'skipped'   => $skipped,
		);
	}
}
