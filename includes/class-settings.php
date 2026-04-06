<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_avalaunch_test_salesforce_connection', array( $this, 'test_salesforce_connection_callback' ) );
	}

	public function add_admin_menu() {
		add_options_page(
			'Avalaunch Address Services',
			'Address Services',
			'manage_options',
			'avalaunch_address_services',
			array( $this, 'settings_page_html' )
		);
	}

	public function register_settings() {
		register_setting( 'avalaunch_options_group', 'avalaunch_options' );

		add_settings_section(
			'avalaunch_api_settings',
			'API Configuration',
			null,
			'avalaunch_address_services'
		);

		add_settings_field(
			'google_maps_api_key',
			'Google Maps API Key',
			array( $this, 'render_input_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array( 'field' => 'google_maps_api_key' )
		);

		add_settings_field(
			'sf_client_id',
			'Salesforce Client ID (Consumer Key)',
			array( $this, 'render_input_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array( 'field' => 'sf_client_id' )
		);

		add_settings_field(
			'sf_client_secret',
			'Salesforce Client Secret (Consumer Secret)',
			array( $this, 'render_input_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array( 'field' => 'sf_client_secret', 'type' => 'password' )
		);





		add_settings_field(
			'sf_custom_login_url',
			'Custom Login URL',
			array( $this, 'render_input_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array( 'field' => 'sf_custom_login_url' )
		);

	}


	public function render_input_field( $args ) {
		$options = get_option( 'avalaunch_options' );
		$field = $args['field'];
		$type = isset( $args['type'] ) ? $args['type'] : 'text';
		$value = isset( $options[ $field ] ) ? $options[ $field ] : '';
		echo sprintf(
			'<input type="%s" name="avalaunch_options[%s]" value="%s" class="regular-text">',
			esc_attr( $type ),
			esc_attr( $field ),
			esc_attr( $value )
		);
	}


	public function render_select_field( $args ) {
		$options = get_option( 'avalaunch_options' );
		$field = $args['field'];
		$value = isset( $options[ $field ] ) ? $options[ $field ] : '';
		$select_options = $args['options'];

		echo sprintf( '<select name="avalaunch_options[%s]">', esc_attr( $field ) );
		foreach ( $select_options as $option_value => $option_label ) {
			echo sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $option_value ),
				selected( $value, $option_value, false ),
				esc_html( $option_label )
			);
		}
		echo '</select>';
	}

	public function test_salesforce_connection_callback() {
		check_ajax_referer( 'avalaunch_test_sf_nonce', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-salesforce.php';
		$salesforce = new Avalaunch_Salesforce();
		$result = $salesforce->test_connection();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public function settings_page_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'avalaunch_options_group' );
				do_settings_sections( 'avalaunch_address_services' );
				submit_button( 'Save Settings' );
				?>
				<p>
					<button type="button" id="avalaunch_test_connection" class="button button-secondary">Test Salesforce Connection</button>
					<span id="avalaunch_test_connection_message" style="margin-left: 10px; font-weight: bold;"></span>
				</p>
				
				<div id="avalaunch_test_connection_log" style="margin-top: 20px; display: none;">
					<h3>Connection Log</h3>
					<pre style="background: #f0f0f1; padding: 15px; border: 1px solid #c3c4c7; overflow: auto; max-height: 400px;"></pre>
				</div>

				<script type="text/javascript">
				jQuery(document).ready(function($) {
					$('#avalaunch_test_connection').click(function(e) {
						e.preventDefault();
						var $button = $(this);
						var $message = $('#avalaunch_test_connection_message');
						var $logContainer = $('#avalaunch_test_connection_log');
						var $log = $logContainer.find('pre');

						$button.prop('disabled', true).text('Testing...');
						$message.text('').css('color', '');
						$logContainer.hide();
						$log.text('');

						$.post(ajaxurl, {
							action: 'avalaunch_test_salesforce_connection',
							_nonce: '<?php echo wp_create_nonce( 'avalaunch_test_sf_nonce' ); ?>'
						}, function(response) {
							$button.prop('disabled', false).text('Test Salesforce Connection');
							
							var data = response.data;
							var message = data.message || 'Unknown result';
							var debug = data.debug || {};

								if (response.success) {
								$message.text(message).css('color', 'green');
								// Do NOT show the log on success — it contains the access token.
							} else {
								$message.text('Connection Failed: ' + message).css('color', 'red');

								// Display Debug Log only on failure
								var debug = data.debug || {};
								if ( debug ) {
									$logContainer.show();
									var logContent = "Status: " + debug.status + "\n";
									logContent += "Headers:\n" + JSON.stringify(debug.headers, null, 2) + "\n";
									logContent += "Body:\n";

									try {
										var jsonBody = typeof debug.body === 'string' ? JSON.parse(debug.body) : debug.body;
										logContent += JSON.stringify(jsonBody, null, 2);
									} catch (e) {
										logContent += debug.body;
									}

									$log.text(logContent);
								}
							}

						}).fail(function(xhr, status, error) {
							$button.prop('disabled', false).text('Test Salesforce Connection');
							$message.text('Request failed: ' + error).css('color', 'red');
						});
					});
				});
				</script>
			</form>
		</div>
		<?php
	}
}
