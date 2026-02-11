<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
			'sf_username',
			'Salesforce Username',
			array( $this, 'render_input_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array( 'field' => 'sf_username' )
		);

		add_settings_field(
			'sf_password',
			'Salesforce Password',
			array( $this, 'render_input_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array( 'field' => 'sf_password', 'type' => 'password' )
		);

		add_settings_field(
			'sf_security_token',
			'Salesforce Security Token',
			array( $this, 'render_input_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array( 'field' => 'sf_security_token', 'type' => 'password' )
		);

		add_settings_field(
			'sf_login_url',
			'Salesforce Login URL',
			array( $this, 'render_select_field' ),
			'avalaunch_address_services',
			'avalaunch_api_settings',
			array(
				'field' => 'sf_login_url',
				'options' => array(
					'https://login.salesforce.com' => 'Production',
					'https://test.salesforce.com' => 'Sandbox'
				)
			)
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
			</form>
		</div>
		<?php
	}
}
