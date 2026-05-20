<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Salesforce {

	private $token_transient_key = 'avalaunch_sf_access_token';

	/**
	 * Searches for coverage by matching the Asset Name against the street number
	 * and the first two distinctive words of the street name.
	 * Example: street_number='10123', keyword1='Creek', keyword2='Run' matches
	 * "10123 S Creek Run Way Unit F107" regardless of directional prefix.
	 * Two keywords make false positives practically impossible.
	 */
	public function get_services_by_address( $street_number, $street_keyword, $unit = '', $street_keyword2 = '', $place_id = '' ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$instance_url = get_transient( 'avalaunch_sf_instance_url' );

		// Sanitize inputs for safe SOQL string interpolation.
		$safe_number   = str_replace( "'", "\\'", $street_number );
		$safe_keyword  = str_replace( "'", "\\'", $street_keyword );
		$safe_place_id = str_replace( "'", "\\'", $place_id );

		// RecordTypeId that identifies valid service assets.
		$record_type_id = '012Rb0000018JEbIAM';

		// Base filters: record type and project status.
		$where = "RecordTypeId = '" . $record_type_id . "'"
			   . " AND Commercial_Residential_Project__r.Status__c <> 'Closed'";

		/**
		 * If we have a Google Place ID, use it for the most accurate match.
		 * However, since Salesforce sometimes uses custom base64 Place IDs for units,
		 * we combine the place ID check with a fallback address string match using OR.
		 */
		$address_conditions = array();

		if ( ! empty( $safe_place_id ) ) {
			$address_conditions[] = "place_id__c = '" . $safe_place_id . "'";
		}

		// Fallback: Street must contain the street number and the keyword(s).
		// Note: We search Street for the street number because Unit__c is often the apartment number.
		$fallback = "Street LIKE '%" . $safe_number . "%' AND Street LIKE '%" . $safe_keyword . "%'";

		if ( ! empty( $street_keyword2 ) ) {
			$safe_keyword2 = str_replace( "'", "\\'", $street_keyword2 );
			$fallback     .= " AND Street LIKE '%" . $safe_keyword2 . "%'";
		}

		$address_conditions[] = "(" . $fallback . ")";

		$where .= " AND (" . implode( " OR ", $address_conditions ) . ")";

		// If a unit number was provided (from the manual input), narrow to that specific unit.
		if ( ! empty( $unit ) ) {
			$safe_unit = str_replace( "'", "\\'", $unit );
			$where    .= " AND (Unit__c LIKE '%" . $safe_unit . "%' OR Street LIKE '%" . $safe_unit . "%')";
		}

		$query = "SELECT Id, Name, Unit__c, Street FROM Asset WHERE " . $where . " LIMIT 1";

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
	protected function execute_query( $instance_url, $query, $access_token ) {
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

		// A record exists under the correct RecordType with an active project → service is available.
		return array(
			'has_coverage' => true,
			'id'           => $record['Id'],
			'name'         => $record['Name'],
			'unit'         => isset( $record['Unit__c'] ) ? $record['Unit__c'] : '',
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

	protected function get_access_token() {
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