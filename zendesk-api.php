<?php

/**
 * Class for interacting with the Zendesk API. Requires WordPress.
 * 
 * This class is in serious need of documentation.
 * You'll have to figure it out for yourself meanwhile.
 * Plugin Name: Zendesk v2 API for WordPress
 * Plugin URI: http://about.scriblio.net/
 * Version: 0.2
 * Credit goes to Viper007Bond for writing this against the v1 API, only to have
 * Zendesk cruelly deprecate it away mere months after this plugin's birth
 *
 * Author: Alex Mills (Viper007Bond), Vasken Hauri (brandwaffle)
 */

class Zendesk_API {
	public $agent_email = ZENDESK_EMAIL;
	public $agent_token = ZENDESK_TOKEN;
	public $zendesk_url = ZENDESK_URL;


	/**
	 * API Interaction
	 */

	public function get_remote_args( $extra_args = array() ) {
		$extra_headers = ( ! empty( $extra_args['headers'] ) ) ? $extra_args['headers'] : array();

		// Build the headers using a defaults array
		$args = wp_parse_args( $extra_args, array(
			'sslverify'  => false, // My localhost has an old valid cert list
			'timeout'    => 60,
		) );

		// Merge headers rather than using defaults
		$args['headers'] = array_merge(
			array(
				'Authorization' => 'Basic ' . base64_encode( $this->agent_email . ':' . $this->agent_token ),
			),
			$extra_headers
		);

		return $args;
	}

	public function do_api_read_request( $path, $args = array(), $remote_args = array() ) {
		// http://core.trac.wordpress.org/ticket/17923
		$args = array_map( 'rawurlencode', $args );
		$result = wp_remote_get( add_query_arg( $args, $this->zendesk_url . $path . '.json' ), $this->get_remote_args( $remote_args ) );

		if ( is_wp_error( $result ) )
			return $result;

		if ( 200 != wp_remote_retrieve_response_code( $result ) )
			return false;

		return json_decode( wp_remote_retrieve_body( $result ) );
	}

	public function do_api_write_request( $path, $data, $remote_args = array() ) {
		$args = array_map( 'rawurlencode' , $args );
		
		//man, I love JSON
		$json = json_encode( $data );

		$remote_args = wp_parse_args( $remote_args, array( 
			'method' => 'POST',
			'headers' => array( 
				'Accept' => 'application/json', //http://developer.zendesk.com/documentation/rest_api/introduction.html#headers
				'Content-Type' => 'application/json',
			),
			'body' => $json,
		) );
		return wp_remote_request( $this->zendesk_url . $path . '.json', $this->get_remote_args( $remote_args ) );
	}

	public function get_result_id ( $request ) {
		$location = wp_remote_retrieve_header( $request, 'location' );

		if ( ! $location )
			return false;

		return (int) preg_replace( '#.*/(\d+)\.xml#', '$1', $location );
	}

	public function get( $path, $args = array(), $page_limit = null, $remote_args = array() ) {
		$result = $this->do_api_read_request( $path, $args, $remote_args );

		if ( ! $result || is_wp_error( $result ) )
			return $result;

		// Return singular results
		if ( is_object( $result ) )
			return $result;

		$items = (array) $result;

		// Get more items if a page number wasn't passed to this function and the page limit was hit
		if ( $page_limit && empty( $args['page'] ) && $page_limit == count( $result ) ) {
			$args['page'] = 1;
			while ( $page_limit == count( $result ) ) {
				$args['page']++;

				// Get more items
				$result = $this->do_api_read_request( $path, $args, $remote_args );

				if ( ! $result || is_wp_error( $result ) )
					return $result;

				$items = array_merge( $items, (array) $result );
			}
		}

		return $items;
	}

	public function create( $path, $data, $remote_args = array() ) {
		$result = $this->do_api_write_request( $path, $data, $remote_args );

		if ( is_wp_error( $result ) )
			return $result;

		if ( 201 != wp_remote_retrieve_response_code( $result ) )
			return false;

		return $this->get_result_id( $result );
	}

	public function create_ticket( $remote_args = array() )
	{
		$data = array(
			'ticket' => array(
				'subject' => 'TEST REST API I LOVE CAPS!!!',
				'description' => 'I am a ticket created with the v2 REST API! Yay!',
			),
		);
		
		return $this->create( 'tickets', $data );
	}

	public function update( $path, $data, $remote_args = array() ) {
		$remote_args = wp_parse_args( $remote_args, array(
			'method' => 'PUT',
		) );

		$result = $this->do_api_write_request( $path, $data, $remote_args );

		if ( is_wp_error( $result ) )
			return $result;

		if ( 200 != wp_remote_retrieve_response_code( $result ) )
			return false;

		return true;
	}


	/**
	 * Search
	 */

	public function search( $search_string ) {
	 	return $this->get( 'search', array( 'query' => $search_string ), 15 );
	 }


	/**
	 * Users
	 */

	public function get_users( $args = array() ) {
		return $this->get( 'users', $args, 100 );
	}

	public function get_user( $id_or_name ) {
		if ( is_int( $id_or_name ) )
			$result = $this->get( "users/{$id_or_name}" );
		else
			$result = $this->get_users( array( 'query' => $id_or_name ) );

		if ( is_wp_error( $result ) )
			return $result;

		if ( empty( $result ) )
			return false;

		// Searching by name returns an array of results. Return just the first.
		if ( is_array( $result ) )
			return $result[0];

		return $result;
	}

	public function user_exists( $id_or_name ) {
		if ( is_int( $id_or_name ) ) {
			$result = $this->get_user( $id_or_name );
		} else {
			// Can't use get_user() because we want to know more than just the first result
			$result = $this->get_users( array( 'query' => $id_or_name ) );
		}

		if ( is_wp_error( $result ) )
			return $result;

		if ( is_array( $result ) )
			return count( $result );

		return (bool) $result;
	}

	public function create_user( $args ) {
		if ( empty( $args['name'] ) || empty( $args['email'] ) )
			return false;

		$data = array(
			'name' => 'user',
			array(
				'name' => 'name',
				'value' => $args['name'],
			),
			array(
				'name' => 'email',
				'value' => $args['email'],
			),
		);

		$data = $this->add_optional_data( $data, $args, array( 'current_tags', 'details', 'notes', 'external_id', 'is_active', 'roles', 'organization-id' ) );

		return $this->create( 'users', $data );
	}

	public function update_user( $id, $args ) {
		$id = (int) $id;

		$data = $this->add_optional_data( array(), $args, array( 'name', 'current_tags', 'details', 'notes', 'external_id', 'restriction_id', 'photo_url', 'is_active', 'roles', 'organization_id', 'email' ) );

		// You gotta update at least something...
		if ( empty( $data ) )
			return false;

		$data = array_merge(
			array(
				'name' => 'user',
			),
			$data
		);

		return $this->update( "users/$id", $data );
	}


	/**
	 * Tickets
	 */

	// Warning: this doesn't do a capability check. That's up to you.
	public function get_ticket( $id ) {
		$id = (int) $id;

		return $this->get( "tickets/$id" );
	}

	public function get_user_ticket( $id, $email ) {
		$id = (int) $id;

		// http://www.zendesk.com/support/api/rest-introduction/#behalf
		$remote_args['headers']['X-On-Behalf-Of'] = $email;

		return $this->get( "requests/$id", array(), null, $remote_args );
	}

	public function get_tickets_for_user( $email, $args = array() ) {
		// http://www.zendesk.com/support/api/rest-introduction/#behalf
		//feature from v1 API, now deprecated in favor of tokenized auth
		//$remote_args['headers']['X-On-Behalf-Of'] = $email;

		// First try to get all tickets from their organization
		$result = $this->get( 'organization_requests', $args, 15, $remote_args );

		// If that fails, then that means they aren't a part of a shared organization
		// Fall back to just getting all of their personal tickets
		if ( false === $result )
			$result = $this->get( 'tickets', $args, 15, $remote_args );

		return $result;
	}

	public function get_tickets_by_view( $view_id ){
		$id = (int) $id;

		return $this->get( "rules/$id" );
	}

	public function get_ticket_url( $ticket ) {
		if ( is_object( $ticket ) && ! empty( $ticket->nice_id ) )
			$ticket = $ticket->nice_id;

		$ticket = (int) $ticket;

		return $this->zendesk_url . 'tickets/' . $ticket;
	}


	/**
	 * Organizations
	 */

	public function get_organizations() {
		return $this->get( 'organizations', array(), 30 );
	}

	public function get_organization( $id_or_name ) {
		if ( is_int( $id_or_name ) )
			$result = $this->get( "organizations/{$id_or_name}" );
		else
			$result = $this->search( 'type:organization "' . $id_or_name . '"' );

		if ( is_wp_error( $result ) )
			return $result;

		if ( empty( $result ) )
			return false;

		// Searching returns an array of results. Return just the first.
		if ( is_array( $result ) )
			return $result[0];

		return $result;
	}

	public function get_organization_members( $id ) {
		$id = (int) $id;

		return $this->get( "organizations/$id/users" );
	}

	public function create_organization( $args ) {
		// Allow passing of just a string (the organization name)
		if ( ! is_array( $args ) ) {
			$newargs = array();
			$newargs['name'] = $args;
			$args = $newargs;
		}

		if ( empty( $args['name'] ) )
			return false;

		$args = wp_parse_args( $args, array(
			'is_shared' => true,
			'is_shared_comments' => true,
		) );

		$data = array(
			'name' => 'organization',
			array(
				'name' => 'name',
				'value' => $args['name'],
			),
			array(
				'name' => 'is_shared',
				'value' => (bool) $args['is_shared'],
			),
			array(
				'name' => 'is_shared_comments',
				'value' => (bool) $args['is_shared_comments'],
			),
		);

		$data = $this->add_optional_data( $data, $args, array( 'current_tags', 'details', 'notes', 'default', 'suspended' ) );

		return $this->create( 'organizations', $data );
	}

	public function update_organization( $id, $args ) {
		$id = (int) $id;

		$data = $this->add_optional_data( array(), $args, array( 'name', 'current_tags', 'details', 'notes', 'is_shared', 'is_shared_comments', 'default', 'suspended' ) );

		// You gotta update at least something...
		if ( empty( $data ) )
			return false;

		$data = array_merge(
			array(
				'name' => 'organization',
			),
			$data
		);

		return $this->update( "organizations/$id", $data );
	}


	/**
	 * Miscellaneous
	 */

	public function generate_xml_element( $dom, $data ) {
		if ( empty( $data['name'] ) )
			return false;

		// Create the element
		$element_value = ( isset( $data['value'] ) ) ? $data['value'] : null;
		if ( false === $element_value )
			$element_value = 0;
		$element = $dom->createElement( $data['name'], $element_value );

		// Add any attributes
		if ( ! empty( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
			foreach ( $data['attributes'] as $attribute_key => $attribute_value ) {
				$element->setAttribute( $attribute_key, $attribute_value );
			}
		}

		// Any other items in the data array should be child elements
		foreach ( $data as $data_key => $child_data ) {
			if ( ! is_numeric( $data_key ) )
				continue;

			$child = $this->generate_xml_element( $dom, $child_data );
			if ( $child )
				$element->appendChild( $child );
		}

		return $element;
	}

	public function add_optional_data( $data, $args, $optionals ) {
		foreach ( $optionals as $optional ) {
			if ( isset( $args[$optional] ) ) {
				$data[] = array( 
					'name' => $optional,
					'value' => $args[$optional],
				);
			}
		}

		return $data;
	}
}

?>
