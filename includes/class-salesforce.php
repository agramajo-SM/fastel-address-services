<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avalaunch_Salesforce {

	private $token_transient_key = 'avalaunch_sf_access_token';

	public function get_services_by_address( $address_data ) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$options = get_option( 'avalaunch_options' );
		$instance_url = get_transient( 'avalaunch_sf_instance_url' );

		// Placeholder for the actual query. We will use SOQL or a custom Apex endpoint.
		// For now, assuming a standard SOQL query on a custom object 'Service_Area__c' or similar.
		// NOTE: This will likely need to be adjusted based on the user's specific Salesforce schema.
		$query = sprintf(
			"SELECT Id, Name, Services__c FROM Service_Area__c WHERE Zip_Code__c = '%s'",
			esc_sql( $address_data['postal_code'] )
		);
		
		// Encode the query
		$url = $instance_url . '/services/data/v57.0/query/?q=' . urlencode( $query );

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data[0]['errorCode'] ) ) {
			return new WP_Error( 'sf_api_error', $data[0]['message'] );
		}
		
		// If no results, return empty array
		if ( empty( $data['records'] ) ) {
			return array();
		}
		
		// Process records to return a simple list of services
		$services = array();
		foreach( $data['records'] as $record ) {
			// Assuming 'Services__c' is a multi-select picklist or text field
			if( isset( $record['Services__c'] ) ) {
				$services[] = $record['Services__c'];
			} else {
				$services[] = $record['Name']; // Fallback
			}
		}

		return $services;
	}

	private function get_access_token() {
		$token = get_transient( $this->token_transient_key );

		if ( $token ) {
			return $token;
		}

		$options = get_option( 'avalaunch_options' );
		
		$login_url = isset( $options['sf_login_url'] ) ? $options['sf_login_url'] : 'https://login.salesforce.com';
		$token_url = $login_url . '/services/oauth2/token';

		$body = array(
			'grant_type'    => 'password',
			'client_id'     => $options['sf_client_id'],
			'client_secret' => $options['sf_client_secret'],
			'username'      => $options['sf_username'],
			'password'      => $options['sf_password'] . $options['sf_security_token'],
		);

		$response = wp_remote_post( $token_url, array(
			'body' => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $response_body, true );

		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'sf_auth_error', $data['error_description'] );
		}

		$access_token = $data['access_token'];
		$instance_url = $data['instance_url'];

		// Cache token for 1 hour (minimize API calls)
		set_transient( $this->token_transient_key, $access_token, 3600 );
		set_transient( 'avalaunch_sf_instance_url', $instance_url, 3600 );

		return $access_token;
	}
}
