<?php
/**
 * Desinstalação: remove opções e dados do plugin. NÃO remove arquivos .webp
 * gerados (use o botão "Apagar todos os .webp" antes, se desejar).
 *
 * @package WebPProductCards
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'wpc_quality',
	'wpc_engine_pref',
	'wpc_scope',
	'wpc_convert_on_upload',
	'wpc_wrap_picture',
	'wpc_wrap_thumbnail',
	'wpc_wrap_all',
	'wpc_log_level',
	'wpc_slow_query_ms',
	'wpc_alert_email',
	'wpc_alert_webhook',
	'wpc_alert_thresholds',
	'wpc_alert_cooldown',
	'wpc_cache_stats',
	'wpc_last_health',
	'wpc_activated_version',
	'wpc_activated_at',
	'wpc_activation_unhealthy',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Limpa meta de controle de conversão.
delete_post_meta_by_key( '_wpc_converted_at' );
