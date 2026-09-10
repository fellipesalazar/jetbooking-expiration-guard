<?php
/**
 * Plugin Name: JetBooking Expiration Guard
 * Description: Cancela automaticamente reservas do JetBooking que ficam pendentes além de um tempo configurável. Funciona com ou sem WooCommerce; se houver pedido vinculado, faz uma checagem extra de segurança antes de cancelar.
 * Version: 1.0.0
 * Author: Fellipe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JBEG_CRON_HOOK', 'jbeg_run_sweep_event' );

/* ---------------------------------------------------------------------
 * Ativação / Desativação
 * ------------------------------------------------------------------- */

function jbeg_activate() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$queue_table      = $wpdb->prefix . 'jbeg_expiration_queue';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$queue_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id BIGINT UNSIGNED NOT NULL,
		order_id BIGINT UNSIGNED NULL,
		created_at DATETIME NOT NULL,
		processed_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY booking_id (booking_id),
		KEY idx_pending (processed_at, created_at)
	) {$charset_collate};";

	dbDelta( $sql );

	if ( ! wp_next_scheduled( JBEG_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'jbeg_every_minute', JBEG_CRON_HOOK );
	}

	add_option( 'jbeg_enabled', '1' );
	add_option( 'jbeg_expiration_minutes', 60 );
	add_option( 'jbeg_target_status', 'pending' );
	add_option( 'jbeg_new_status', 'cancelled' );
	add_option( 'jbeg_check_order', '1' );
	add_option( 'jbeg_last_synced_booking_id', 0 );
	delete_transient( 'jbeg_table_exists_check' );
}
register_activation_hook( __FILE__, 'jbeg_activate' );

function jbeg_deactivate() {
	wp_clear_scheduled_hook( JBEG_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'jbeg_deactivate' );

/* ---------------------------------------------------------------------
 * Agendamento a cada minuto (WP-Cron) + rede de segurança via init
 * Não depende de cron do servidor / cPanel - funciona sozinho.
 * ------------------------------------------------------------------- */

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['jbeg_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'A cada minuto (JetBooking Expiration Guard)' ),
		);
		return $schedules;
	}
);

add_action( JBEG_CRON_HOOK, 'jbeg_run_sweep' );

// Rede de segurança: se o WP-Cron atrasar (sites com pouco tráfego às vezes
// não têm visitas suficientes pra disparar o cron pseudo-agendado), isso
// garante que a varredura ainda rode, sem precisar de nada no servidor.
// A trava de frequência já fica dentro de jbeg_run_sweep().
add_action( 'init', 'jbeg_run_sweep' );

/* ---------------------------------------------------------------------
 * Lógica principal
 * ------------------------------------------------------------------- */

function jbeg_tables() {
	global $wpdb;
	return array(
		'bookings' => $wpdb->prefix . 'jet_apartment_bookings',
		'queue'    => $wpdb->prefix . 'jbeg_expiration_queue',
	);
}

function jbeg_bookings_table_exists() {
	$cached = get_transient( 'jbeg_table_exists_check' );
	if ( false !== $cached ) {
		return '1' === $cached;
	}

	global $wpdb;
	$t      = jbeg_tables();
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t['bookings'] ) ) === $t['bookings'];

	// "Existe" é estável de verdade, então guarda por 1h. "Não existe" pode
	// mudar a qualquer momento (JetBooking sendo instalado/ativado depois
	// deste plugin), então essa checagem se repete bem mais rápido.
	$ttl = $exists ? HOUR_IN_SECONDS : ( 3 * MINUTE_IN_SECONDS );
	set_transient( 'jbeg_table_exists_check', $exists ? '1' : '0', $ttl );

	return $exists;
}

function jbeg_run_sweep( $force = false ) {
	if ( ! $force ) {
		// Trava compartilhada entre visita real (init) e ping externo - evita
		// rodar duas vezes seguidas por engano quando os dois coincidem.
		if ( false !== get_transient( 'jbeg_sweep_lock' ) ) {
			return;
		}
		set_transient( 'jbeg_sweep_lock', 1, 55 );
	}

	if ( '1' !== get_option( 'jbeg_enabled', '1' ) ) {
		return;
	}

	if ( ! jbeg_bookings_table_exists() ) {
		return;
	}

	// Registra que a varredura rodou agora, independente de ter achado
	// algo pra fazer ou não - é isso que aparece no painel como prova
	// de que o cron está ativo.
	update_option( 'jbeg_last_run', current_time( 'mysql', true ) );

	global $wpdb;
	$t = jbeg_tables();

	// 1) Sincroniza reservas novas que ainda não estão na fila de controle.
	// Em vez de comparar contra a tabela inteira toda vez (o que fica cada
	// vez mais caro conforme o número de reservas cresce), guardamos o
	// maior booking_id já sincronizado e só olhamos o que veio depois dele -
	// custo proporcional ao que é novo, não ao total da tabela.
	$last_synced_id = (int) get_option( 'jbeg_last_synced_booking_id', 0 );
	$now_gmt        = current_time( 'mysql', true );

	$new_max_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(booking_id) FROM {$t['bookings']} WHERE booking_id > %d",
			$last_synced_id
		)
	);

	if ( $new_max_id ) {
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$t['queue']} (booking_id, order_id, created_at)
				 SELECT booking_id, order_id, %s
				 FROM {$t['bookings']}
				 WHERE booking_id > %d AND booking_id <= %d",
				$now_gmt,
				$last_synced_id,
				$new_max_id
			)
		);
		update_option( 'jbeg_last_synced_booking_id', $new_max_id );
	}

	$minutes       = (int) get_option( 'jbeg_expiration_minutes', 60 );
	$target_status = get_option( 'jbeg_target_status', 'pending' );
	$new_status    = get_option( 'jbeg_new_status', 'cancelled' );
	$check_order   = '1' === get_option( 'jbeg_check_order', '1' );

	$cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) );

	// 2) Busca candidatos vencidos e ainda não processados.
	$candidates = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, booking_id, order_id
			 FROM {$t['queue']}
			 WHERE processed_at IS NULL
			 AND created_at <= %s
			 LIMIT 200",
			$cutoff_gmt
		)
	);

	update_option( 'jbeg_last_candidates_count', count( $candidates ) );

	if ( ! $candidates ) {
		return;
	}

	foreach ( $candidates as $row ) {

		// Rede de segurança: se tem pedido WooCommerce vinculado e ele já não
		// está mais pendente/aguardando pagamento, NÃO cancela - só marca como
		// processado. Isso é justamente o gate que teria evitado o incidente
		// original (reserva marcada "pending" internamente com o pedido já pago).
		if ( $check_order && $row->order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $row->order_id );
			if ( $order && ! $order->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
				$wpdb->update(
					$t['queue'],
					array( 'processed_at' => current_time( 'mysql', true ) ),
					array( 'id' => $row->id )
				);
				continue;
			}
		}

		// UPDATE atômico e condicional: só cancela se, NESTE EXATO momento,
		// a reserva ainda estiver no status-alvo. Fecha a race condition
		// entre o pagamento chegar e a varredura rodar.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t['bookings']} SET status = %s WHERE booking_id = %d AND status = %s",
				$new_status,
				$row->booking_id,
				$target_status
			)
		);

		$wpdb->update(
			$t['queue'],
			array( 'processed_at' => current_time( 'mysql', true ) ),
			array( 'id' => $row->id )
		);

		if ( $affected ) {
			do_action( 'jbeg_booking_expired', $row->booking_id, $row->order_id );
		}
	}
}

/* ---------------------------------------------------------------------
 * Endpoint de ping externo (opcional)
 * Permite usar um serviço gratuito tipo cron-job.org ou UptimeRobot pra
 * garantir que a varredura rode mesmo em horários sem nenhuma visita real
 * ao site. Não expõe nem recebe nenhum dado sensível - só dispara a
 * mesma varredura seletiva, que já é segura de rodar quantas vezes for.
 * ------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'jbeg/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					jbeg_run_sweep();
					return array(
						'status' => 'ok',
						'ran_at' => current_time( 'mysql', true ) . ' UTC',
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}
);

/* ---------------------------------------------------------------------
 * Painel de configurações (Configurações > Expiration Guard)
 * ------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_options_page(
			'JetBooking Expiration Guard',
			'Expiration Guard',
			'manage_options',
			'jbeg-settings',
			'jbeg_render_settings_page'
		);
	}
);

add_action(
	'admin_post_jbeg_run_now',
	function () {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'jbeg_run_now' ) ) {
			wp_die( 'Não autorizado.' );
		}
		jbeg_run_sweep( true );
		wp_safe_redirect( add_query_arg( 'jbeg_ran', '1', wp_get_referer() ) );
		exit;
	}
);

add_action(
	'admin_init',
	function () {
		register_setting( 'jbeg_settings_group', 'jbeg_enabled' );
		register_setting( 'jbeg_settings_group', 'jbeg_expiration_minutes' );
		register_setting( 'jbeg_settings_group', 'jbeg_target_status' );
		register_setting( 'jbeg_settings_group', 'jbeg_new_status' );
		register_setting( 'jbeg_settings_group', 'jbeg_check_order' );
	}
);

function jbeg_render_settings_page() {
	global $wpdb;
	$t = jbeg_tables();

	if ( ! jbeg_bookings_table_exists() ) {
		echo '<div class="wrap"><h1>JetBooking Expiration Guard</h1>';
		echo '<div class="notice notice-error"><p>A tabela <code>' . esc_html( $t['bookings'] ) . '</code> não foi encontrada. Confirme se o JetBooking está ativo neste site.</p></div></div>';
		return;
	}

	$status_counts    = $wpdb->get_results(
		"SELECT status, COUNT(*) as total FROM {$t['bookings']} GROUP BY status ORDER BY total DESC"
	);
	$last_run         = get_option( 'jbeg_last_run', '' );
	$last_qty         = get_option( 'jbeg_last_candidates_count', null );
	$next_run         = wp_next_scheduled( JBEG_CRON_HOOK );
	$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	?>
	<style>
		.jbeg-wrap { max-width: 720px; }
		.jbeg-wrap .card { max-width: none; margin-bottom: 20px; padding: 16px 20px 20px; }
		.jbeg-wrap .card h2 { margin-top: 0; margin-bottom: 8px; }
		.jbeg-wrap .card p.description { margin-top: 0px; margin-bottom: 20px; }
		.jbeg-status-table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
		.jbeg-status-table td { padding: 8px 12px 8px 0; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
		.jbeg-status-table tr:last-child td { border-bottom: none; }
		.jbeg-status-table td:first-child { color: #50575e; width: 60%; }
		.jbeg-counts-table { width: auto; min-width: 260px; }

		/* Toggle switch, visual apenas - continua sendo um checkbox real por baixo */
		.jbeg-toggle-row th { padding-top: 20px; }
		.jbeg-toggle-wrap { display: flex; align-items: center; gap: 10px; }
		.jbeg-toggle { position: relative; display: inline-block; width: 36px; height: 20px; flex-shrink: 0; }
		.jbeg-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
		.jbeg-toggle .slider { position: absolute; inset: 0; background-color: #ccd0d4; border-radius: 20px; cursor: pointer; transition: background-color .15s ease; }
		.jbeg-toggle .slider::before { content: ""; position: absolute; height: 14px; width: 14px; left: 3px; top: 3px; background-color: #fff; border-radius: 50%; transition: transform .15s ease; }
		.jbeg-toggle input:checked + .slider { background-color: #2271b1; }
		.jbeg-toggle input:checked + .slider::before { transform: translateX(16px); }
		.jbeg-toggle input:focus-visible + .slider { outline: 2px solid #2271b1; outline-offset: 2px; }
		.jbeg-toggle-label { color: #1d2327; }

		.jbeg-ping-row { display: flex; gap: 8px; }
		.jbeg-ping-row input[type="text"] { flex: 1; }
	</style>

	<div class="wrap jbeg-wrap">
		<h1>JetBooking Expiration Guard</h1>

		<?php if ( isset( $_GET['jbeg_ran'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Varredura executada agora manualmente.</p></div>
		<?php endif; ?>

		<div class="card">
			<h2>Status do cron</h2>
			<table class="jbeg-status-table">
				<tbody>
					<tr>
						<td>Última execução</td>
						<td><?php echo $last_run ? '<strong>' . esc_html( get_date_from_gmt( $last_run, 'd/m/Y H:i:s' ) ) . '</strong> (horário do site)' : 'ainda não rodou'; ?></td>
					</tr>
					<tr>
						<td>Reservas avaliadas na última execução</td>
						<td><?php echo null !== $last_qty ? (int) $last_qty : '—'; ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'jbeg_run_now' ); ?>
				<input type="hidden" name="action" value="jbeg_run_now" />
				<?php submit_button( 'Rodar varredura agora', 'secondary', 'submit', false ); ?>
			</form>
		</div>

		<div class="card">
			<h2>Status encontrados na sua tabela de reservas</h2>
			<p class="description">Use um desses valores exatos no campo "Status considerado pendente" abaixo.</p>
			<table class="widefat striped jbeg-counts-table">
				<thead><tr><th>Status</th><th>Qtd. de reservas</th></tr></thead>
				<tbody>
				<?php foreach ( $status_counts as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row->status ); ?></code></td>
						<td><?php echo (int) $row->total; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="card">
			<h2>Configurações</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'jbeg_settings_group' ); ?>
				<table class="form-table">
					<tr class="jbeg-toggle-row">
						<th><label for="jbeg_enabled">Ativo</label></th>
						<td>
							<div class="jbeg-toggle-wrap">
								<input type="hidden" name="jbeg_enabled" value="0" />
								<label class="jbeg-toggle">
									<input type="checkbox" id="jbeg_enabled" name="jbeg_enabled" value="1"
										<?php checked( '1', get_option( 'jbeg_enabled', '1' ) ); ?> />
									<span class="slider"></span>
								</label>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="jbeg_expiration_minutes">Tempo de expiração (minutos)</label></th>
						<td>
							<input type="number" min="1" id="jbeg_expiration_minutes" name="jbeg_expiration_minutes"
								value="<?php echo esc_attr( get_option( 'jbeg_expiration_minutes', 60 ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th><label for="jbeg_target_status">Status considerado pendente</label></th>
						<td>
							<input type="text" id="jbeg_target_status" name="jbeg_target_status"
								value="<?php echo esc_attr( get_option( 'jbeg_target_status', 'pending' ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th><label for="jbeg_new_status">Novo status ao expirar</label></th>
						<td>
							<input type="text" id="jbeg_new_status" name="jbeg_new_status"
								value="<?php echo esc_attr( get_option( 'jbeg_new_status', 'cancelled' ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr class="jbeg-toggle-row">
						<th><label for="jbeg_check_order">Checar pedido WooCommerce antes de cancelar</label></th>
						<td>
							<div class="jbeg-toggle-wrap">
								<input type="hidden" name="jbeg_check_order" value="0" />
								<label class="jbeg-toggle">
									<input type="checkbox" id="jbeg_check_order" name="jbeg_check_order" value="1"
										<?php checked( '1', get_option( 'jbeg_check_order', '1' ) ); ?> />
									<span class="slider"></span>
								</label>
							</div>
							<p class="description">Se o pedido vinculado já não estiver mais pendente/aguardando pagamento, a reserva não é cancelada. Deixe marcado a menos que o site não use WooCommerce.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>

		<div class="card">
			<h2>Garantir execução mesmo sem visitas (opcional)</h2>
			<p class="description">
				Cole essa URL num serviço gratuito de ping externo, tipo
				<a href="https://cron-job.org" target="_blank" rel="noopener">cron-job.org</a>
				(cadastro grátis, configura direto pelo navegador, sem precisar de cPanel), pedindo pra ele
				acessar essa URL a cada 1 ou 5 minutos. Isso garante que a varredura rode mesmo em horários
				sem nenhum visitante real no site.
			</p>
			<div class="jbeg-ping-row">
				<input type="text" id="jbeg-ping-url" readonly onclick="this.select()"
					value="<?php echo esc_url( rest_url( 'jbeg/v1/ping' ) ); ?>" />
				<button type="button" class="button" id="jbeg-copy-ping-url">Copiar</button>
			</div>
			<script>
				( function () {
					var btn = document.getElementById( 'jbeg-copy-ping-url' );
					if ( ! btn ) { return; }
					btn.addEventListener( 'click', function () {
						var input = document.getElementById( 'jbeg-ping-url' );
						input.select();
						navigator.clipboard.writeText( input.value ).then( function () {
							var original = btn.textContent;
							btn.textContent = 'Copiado!';
							setTimeout( function () { btn.textContent = original; }, 1500 );
						} );
					} );
				} )();
			</script>
		</div>
	</div>
	<?php
}
