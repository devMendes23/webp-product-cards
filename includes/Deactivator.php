<?php
/**
 * Desativação: limpa agendamentos. Não apaga opções nem arquivos .webp.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards;

use WebPProductCards\Conversion\AttachmentHooks;
use WebPProductCards\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate() {
		// Remove jobs de conversão pendentes.
		wp_clear_scheduled_hook( AttachmentHooks::CRON_HOOK );
		Logger::info( 'Plugin desativado', array(), 'lifecycle' );
	}
}
