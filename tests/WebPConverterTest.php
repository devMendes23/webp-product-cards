<?php
/**
 * Regressão do fluxo crítico: conversão JPG/PNG -> WebP (requisito 8).
 *
 * @package WebPProductCards
 */

use PHPUnit\Framework\TestCase;
use WebPProductCards\Conversion\WebPConverter;
use WebPProductCards\Support\Logger;

final class WebPConverterTest extends TestCase {

	private $tmp;

	protected function setUp(): void {
		if ( ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD com imagewebp() indisponível neste runner.' );
		}
		$this->tmp = sys_get_temp_dir() . '/wpc-conv-' . uniqid();
		mkdir( $this->tmp, 0777, true );
		Logger::boot();
	}

	protected function tearDown(): void {
		foreach ( glob( $this->tmp . '/*' ) as $f ) {
			@unlink( $f );
		}
		@rmdir( $this->tmp );
	}

	private function make_png( $name, $with_alpha = true ) {
		$path = $this->tmp . '/' . $name;
		$im   = imagecreatetruecolor( 40, 40 );
		if ( $with_alpha ) {
			imagesavealpha( $im, true );
			$transparent = imagecolorallocatealpha( $im, 0, 0, 0, 127 );
			imagefill( $im, 0, 0, $transparent );
		}
		$red = imagecolorallocate( $im, 200, 30, 30 );
		imagefilledrectangle( $im, 10, 10, 30, 30, $red );
		imagepng( $im, $path );
		imagedestroy( $im );
		return $path;
	}

	private function make_jpg( $name ) {
		$path = $this->tmp . '/' . $name;
		$im   = imagecreatetruecolor( 40, 40 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 50, 120, 200 ) );
		imagejpeg( $im, $path, 90 );
		imagedestroy( $im );
		return $path;
	}

	public function test_converts_png_with_alpha() {
		$src  = $this->make_png( 'card.png', true );
		$webp = WebPConverter::convert_file( $src );
		$this->assertNotFalse( $webp, 'Deveria gerar o .webp' );
		$this->assertFileExists( $webp );
		$this->assertSame( 'card.webp', basename( $webp ) );
		$info = getimagesize( $webp );
		$this->assertSame( 'image/webp', $info['mime'] );
		// O original permanece intacto (requisito de não destruir arquivos).
		$this->assertFileExists( $src );
	}

	public function test_converts_jpg() {
		$src  = $this->make_jpg( 'foto.jpg' );
		$webp = WebPConverter::convert_file( $src );
		$this->assertNotFalse( $webp );
		$this->assertSame( 'image/webp', getimagesize( $webp )['mime'] );
	}

	public function test_rejects_non_image_extension() {
		$txt = $this->tmp . '/nota.txt';
		file_put_contents( $txt, 'isto nao e imagem' );
		$this->assertFalse( WebPConverter::convert_file( $txt ) );
	}

	public function test_rejects_missing_file() {
		$this->assertFalse( WebPConverter::convert_file( $this->tmp . '/inexistente.png' ) );
	}

	public function test_is_idempotent() {
		$src   = $this->make_jpg( 'idem.jpg' );
		$first = WebPConverter::convert_file( $src );
		clearstatcache();
		$mtime = filemtime( $first );
		// Segunda chamada deve reaproveitar (mesmo arquivo, mesmo mtime).
		$second = WebPConverter::convert_file( $src );
		$this->assertSame( $first, $second );
		$this->assertSame( $mtime, filemtime( $second ) );
	}
}
