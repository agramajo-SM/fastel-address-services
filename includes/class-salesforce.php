<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Salesforce {

	private $token_transient_key = 'avalaunch_sf_access_token';

	/**
	 * Busca cobertura basándose en coordenadas exactas (Lat/Lng).
	 * Usa un radio de 0.01 millas (~16 metros) para tolerar mínimas diferencias
	 * de precisión entre el geocoding de Google Maps y el de Salesforce, garantizando
	 * que el resultado corresponde al mismo punto físico en el mundo real.
	 */
	public function get_services_by_coordinates( $lat, $lng ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$instance_url = get_transient( 'avalaunch_sf_instance_url' );

		// Radio de 0.01 millas (~16 metros): tolerancia mínima para diferencias de
		// precisión entre el geocoding de Google y el de Salesforce. En la práctica
		// funciona como una coincidencia puntual (mismo edificio/dirección).
		$query = sprintf(
			"SELECT Id, Name, Status__c, Internet__c, BuildingType__c, Address__Street__s, Address__City__s, Address__PostalCode__s " .
			"FROM Residential_Project__c " .
			"WHERE DISTANCE(Address__c, GEOLOCATION(%f, %f), 'mi') < 0.01 " .
			"ORDER BY DISTANCE(Address__c, GEOLOCATION(%f, %f), 'mi') ASC " .
			"LIMIT 1",
			$lat, $lng,
			$lat, $lng
		);

		return $this->execute_query( $instance_url, $query, $access_token );
	}

	/**
	 * Crea un Lead en Salesforce cuando no hay cobertura en la dirección buscada.
	 */
	public function create_lead( $email, $searched_address ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$instance_url = get_transient( 'avalaunch_sf_instance_url' );

		// Derivamos el LastName del email (campo requerido en Salesforce)
		$email_parts = explode( '@', $email );
		$last_name   = ! empty( $email_parts[0] ) ? sanitize_text_field( $email_parts[0] ) : 'Unknown';

		$body = wp_json_encode( array(
			'LastName'    => $last_name,
			'Email'       => sanitize_email( $email ),
			'Company'     => 'Residential Interest',
			'LeadSource'  => 'Web',
			'Description' => 'Address searched: ' . sanitize_text_field( $searched_address ),
			'Street'      => sanitize_text_field( $searched_address ),
		) );

		$url      = $instance_url . '/services/data/v60.0/sobjects/Lead/';
		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => $body,
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Salesforce returns 201 Created on success
		if ( 201 !== $code ) {
			$msg = isset( $data[0]['message'] ) ? $data[0]['message'] : 'Could not create lead (HTTP ' . $code . ').';
			return new WP_Error( 'sf_lead_error', $msg );
		}

		return array(
			'success' => true,
			'id'      => $data['id'],
		);
	}

	/**
	 * Helper para ejecutar el query y formatear la respuesta para el frontend
	 */
	private function execute_query( $instance_url, $query, $access_token ) {
		$url = $instance_url . '/services/data/v60.0/query/?q=' . urlencode( $query );

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 20
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data[0]['errorCode'] ) ) {
			return new WP_Error( 'sf_api_error', $data[0]['message'] );
		}

		if ( empty( $data['records'] ) ) {
			return array( 'has_coverage' => false );
		}

		$record = $data['records'][0];

		// Devolvemos una estructura limpia para el buscador estilo Fiber
		return array(
			'has_coverage' => true,
			'id'           => $record['Id'],
			'project_name' => $record['Name'],
			'status'       => trim($record['Status__c']), // 'Active', 'Under Construction', etc.
			'street'       => $record['Address__Street__s'],
			'city'         => $record['Address__City__s'],
			'zip'          => $record['Address__PostalCode__s'],
			'details'      => $record['Internet__c'], // Instrucciones o detalles del servicio
			'type'         => $record['BuildingType__c']
		);
	}

	public function test_connection() {
		delete_transient( $this->token_transient_key );
		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return array(
				'success' => false,
				'message' => $token->get_error_message()
			);
		}

		return array(
			'success' => true,
			'message' => 'Connection successful.'
		);
	}

	private function get_access_token() {
		$token = get_transient( $this->token_transient_key );

		if ( $token ) {
			return $token;
		}

		$response = $this->request_token();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $response_body, true );

		if ( 200 !== $response_code ) {
			return new WP_Error( 'sf_auth_failed', 'Salesforce Error ' . $response_code );
		}

		$access_token = $data['access_token'];
		$instance_url = $data['instance_url'];

		set_transient( $this->token_transient_key, $access_token, 3600 );
		set_transient( 'avalaunch_sf_instance_url', $instance_url, 3600 );

		return $access_token;
	}

	private function request_token() {
		$options = get_option( 'avalaunch_options' );
		$token_url = ! empty( $options['sf_custom_login_url'] ) ? $options['sf_custom_login_url'] : 'https://login.salesforce.com/services/oauth2/token';

		return wp_remote_post( $token_url, array(
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $options['sf_client_id'],
				'client_secret' => $options['sf_client_secret'],
			),
		) );
	}
}