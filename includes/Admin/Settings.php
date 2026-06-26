<?php
/**
 * Página de administração e registro de opções.
 *
 * @package WebPProductCards
 */

namespace WebPProductCards\Admin;

use WebPProductCards\Conversion\WebPConverter;
use WebPProductCards\Conversion\ImageDetector;
use WebPProductCards\Support\Alerts;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'fields' ) );
	}

	public function menu() {
		add_menu_page(
			'WebP Product Cards',
			'WebP Cards',
			'manage_options',
			WPC_SLUG,
			array( $this, 'render' ),
			'dashicons-images-alt2',
			58
		);
	}

	public function fields() {
		$g = 'wpc_settings';
		register_setting( $g, 'wpc_quality', array( 'sanitize_callback' => 'absint' ) );
		register_setting( $g, 'wpc_engine_pref', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( $g, 'wpc_scope', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( $g, 'wpc_convert_on_upload', array( 'sanitize_callback' => 'absint' ) );
		register_setting( $g, 'wpc_wrap_picture', array( 'sanitize_callback' => 'absint' ) );
		register_setting( $g, 'wpc_wrap_thumbnail', array( 'sanitize_callback' => 'absint' ) );
		register_setting( $g, 'wpc_wrap_all', array( 'sanitize_callback' => 'absint' ) );
		register_setting( $g, 'wpc_log_level', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( $g, 'wpc_slow_query_ms', array( 'sanitize_callback' => 'absint' ) );
		register_setting( $g, 'wpc_alert_email', array( 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( $g, 'wpc_alert_webhook', array( 'sanitize_callback' => 'esc_url_raw' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$engine      = WebPConverter::engine();
		$health_url  = rest_url( WPC_REST_NS . '/health' );
		?>
		<div class="wrap">
			<h1>WebP Product Cards <span style="font-size:13px;color:#666;">v<?php echo esc_html( WPC_VERSION ); ?></span></h1>

			<?php if ( ! $engine ) : ?>
				<div class="notice notice-error"><p><strong>Atenção:</strong> servidor sem suporte a WebP (nem Imagick/WEBP nem GD <code>imagewebp()</code>). A conversão não funcionará.</p></div>
			<?php else : ?>
				<div class="notice notice-info inline"><p>Motor ativo: <strong><?php echo esc_html( strtoupper( $engine ) ); ?></strong> · Produtos detectados: <strong><?php echo esc_html( ImageDetector::woocommerce_active() ? ImageDetector::product_image_count() : '—' ); ?></strong> · <a href="<?php echo esc_url( $health_url ); ?>" target="_blank">Health check ↗</a></p></div>
			<?php endif; ?>

			<?php if ( ! ImageDetector::woocommerce_active() ) : ?>
				<div class="notice notice-warning inline"><p>WooCommerce inativo. No modo "Somente cards de produto" nada será convertido. Use o escopo "Todas as imagens" se quiser converter mesmo assim.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpc_settings' ); ?>

				<h2 class="title">Conversão</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpc_quality">Qualidade do WebP</label></th>
						<td><input name="wpc_quality" id="wpc_quality" type="number" min="1" max="100" value="<?php echo esc_attr( WebPConverter::quality() ); ?>" class="small-text" /> <span class="description">1–100 (recomendado 80–85)</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpc_engine_pref">Motor</label></th>
						<td>
							<select name="wpc_engine_pref" id="wpc_engine_pref">
								<?php foreach ( array( 'auto' => 'Automático', 'imagick' => 'Imagick', 'gd' => 'GD' ) as $k => $v ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( get_option( 'wpc_engine_pref', 'auto' ), $k ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Escopo</th>
						<td>
							<label><input type="radio" name="wpc_scope" value="product" <?php checked( get_option( 'wpc_scope', 'product' ), 'product' ); ?> /> Somente cards de produto (WooCommerce)</label><br />
							<label><input type="radio" name="wpc_scope" value="all" <?php checked( get_option( 'wpc_scope', 'product' ), 'all' ); ?> /> Todas as imagens</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Converter no upload</th>
						<td><label><input type="checkbox" name="wpc_convert_on_upload" value="1" <?php checked( 1, get_option( 'wpc_convert_on_upload', 0 ) ); ?> /> Converter todo upload de imagem (ignora o escopo)</label></td>
					</tr>
				</table>

				<h2 class="title">Entrega (front-end)</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">&lt;picture&gt; nos cards</th><td><label><input type="checkbox" name="wpc_wrap_picture" value="1" <?php checked( 1, get_option( 'wpc_wrap_picture', 1 ) ); ?> /> Servir WebP com fallback</label></td></tr>
					<tr><th scope="row">Loop Grid / archives</th><td><label><input type="checkbox" name="wpc_wrap_thumbnail" value="1" <?php checked( 1, get_option( 'wpc_wrap_thumbnail', 1 ) ); ?> /> Cobrir thumbnails de produto (Elementor Loop Grid)</label></td></tr>
					<tr><th scope="row">Todas as imagens</th><td><label><input type="checkbox" name="wpc_wrap_all" value="1" <?php checked( 1, get_option( 'wpc_wrap_all', 0 ) ); ?> /> Não limitar aos cards</label></td></tr>
				</table>

				<h2 class="title">Observabilidade &amp; Alertas</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpc_log_level">Nível de log</label></th>
						<td>
							<select name="wpc_log_level" id="wpc_log_level">
								<?php foreach ( array( 'debug', 'info', 'warning', 'error', 'critical' ) as $lvl ) : ?>
									<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( get_option( 'wpc_log_level', 'info' ), $lvl ); ?>><?php echo esc_html( ucfirst( $lvl ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Logs JSON em <code>uploads/<?php echo esc_html( WPC_SLUG ); ?>/logs/</code></p>
						</td>
					</tr>
					<tr><th scope="row"><label for="wpc_slow_query_ms">Limiar consulta lenta (ms)</label></th><td><input name="wpc_slow_query_ms" id="wpc_slow_query_ms" type="number" min="10" value="<?php echo esc_attr( get_option( 'wpc_slow_query_ms', 200 ) ); ?>" class="small-text" /></td></tr>
					<tr><th scope="row"><label for="wpc_alert_email">E-mail de alerta</label></th><td><input name="wpc_alert_email" id="wpc_alert_email" type="email" value="<?php echo esc_attr( get_option( 'wpc_alert_email', '' ) ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row"><label for="wpc_alert_webhook">Webhook de alerta</label></th><td><input name="wpc_alert_webhook" id="wpc_alert_webhook" type="url" value="<?php echo esc_attr( get_option( 'wpc_alert_webhook', '' ) ); ?>" class="regular-text" placeholder="https://hooks.slack.com/..." /></td></tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2 class="title">Converter imagens existentes</h2>
			<p>No escopo "produto", processa apenas as imagens destacadas de produtos.</p>
			<p>
				<button type="button" class="button button-primary" id="wpc-start" <?php disabled( ! $engine ); ?>>Converter agora</button>
				<button type="button" class="button" id="wpc-cleanup">Apagar todos os .webp</button>
			</p>
			<div id="wpc-progress" style="display:none;max-width:480px;">
				<div style="background:#e2e4e7;border-radius:4px;overflow:hidden;height:22px;"><div id="wpc-bar" style="background:#2271b1;height:100%;width:0;transition:width .25s;"></div></div>
				<p id="wpc-status" style="margin-top:8px;"></p>
			</div>
		</div>

		<script>
		( function () {
			var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var nonce   = '<?php echo esc_js( wp_create_nonce( 'wpc_bulk' ) ); ?>';
			var bar = document.getElementById('wpc-bar'), status = document.getElementById('wpc-status'), wrap = document.getElementById('wpc-progress');
			var totals = { converted: 0, skipped: 0 };
			function post( action, data ) {
				var body = new URLSearchParams( Object.assign( { action: action, nonce: nonce }, data ) );
				return fetch( ajaxurl, { method:'POST', credentials:'same-origin', body: body } ).then( function(r){ return r.json(); } );
			}
			function run( offset, total ) {
				post( 'wpc_bulk_convert', { offset: offset } ).then( function ( res ) {
					if ( ! res.success ) { status.textContent = 'Erro: ' + ( res.data && res.data.message || res.data || 'desconhecido' ); return; }
					var d = res.data; total = total || d.total;
					totals.converted += d.converted; totals.skipped += d.skipped;
					var done = Math.min( d.offset, total );
					bar.style.width = ( total ? ( done / total * 100 ) : 100 ) + '%';
					status.textContent = done + ' / ' + total + ' anexos · ' + totals.converted + ' WebP gerados';
					if ( ! d.finished ) { run( d.offset, total ); }
					else { status.textContent = 'Concluído! ' + total + ' anexos · ' + totals.converted + ' WebP gerados, ' + totals.skipped + ' ignorados.'; }
				} ).catch( function(e){ status.textContent = 'Falha: ' + e; } );
			}
			document.getElementById('wpc-start').addEventListener('click', function(){
				totals = { converted: 0, skipped: 0 }; wrap.style.display='block'; bar.style.width='0'; status.textContent='Iniciando...'; run(0,0);
			});
			document.getElementById('wpc-cleanup').addEventListener('click', function(){
				if ( ! window.confirm('Apagar TODOS os .webp gerados em uploads?') ) return;
				wrap.style.display='block'; bar.style.width='100%'; status.textContent='Apagando...';
				post('wpc_cleanup', {}).then(function(res){ status.textContent = res.success ? ( res.data.deleted + ' arquivos .webp apagados.' ) : ('Erro: ' + res.data); });
			});
		} )();
		</script>
		<?php
	}
}
