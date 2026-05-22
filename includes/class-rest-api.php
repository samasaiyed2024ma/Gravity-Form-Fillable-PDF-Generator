<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_REST_API {

	const NAMESPACE = 'gffpdf/v1';

	public function register_routes(): void {
		// Feeds
		register_rest_route( self::NAMESPACE, '/feeds', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_feeds' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'form_id' => [ 'type' => 'integer', 'required' => false ],
				],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_feed' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/feeds/(?P<id>\d+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_feed' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_feed' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_feed' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		// Templates
		register_rest_route( self::NAMESPACE, '/templates', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_templates' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload_template' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		// Mappings
		register_rest_route( self::NAMESPACE, '/mappings', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_mappings' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'feed_id' => [ 'type' => 'integer', 'required' => true ],
				],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_mappings' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );
	}

	/* -----------------------------------------------------------------------
	 * Permission
	 * -------------------------------------------------------------------- */

	public function check_permission(): bool {
		return GFFPDF_Security::current_user_can();
	}

	/* -----------------------------------------------------------------------
	 * Feeds endpoints
	 * -------------------------------------------------------------------- */

	public function get_feeds( WP_REST_Request $request ): WP_REST_Response {
		$form_id = $request->get_param( 'form_id' );
		if ( $form_id ) {
			$feeds = GFFPDF_Feed_Settings::get_feeds_by_form( absint( $form_id ) );
		} else {
			global $wpdb;
			$feeds = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}gffpdf_feeds ORDER BY created_at DESC" );
		}
		return new WP_REST_Response( $feeds, 200 );
	}

	public function get_feed( WP_REST_Request $request ): WP_REST_Response {
		$feed = GFFPDF_Feed_Settings::get_feed( absint( $request['id'] ) );
		if ( ! $feed ) {
			return new WP_REST_Response( [ 'message' => 'Feed not found' ], 404 );
		}
		$feed->settings = json_decode( $feed->settings, true );
		$feed->mappings = json_decode( $feed->mappings, true );
		return new WP_REST_Response( $feed, 200 );
	}

	public function create_feed( WP_REST_Request $request ): WP_REST_Response {
		$data   = $request->get_json_params();
		$new_id = GFFPDF_Feed_Settings::create_feed( $data );
		if ( is_wp_error( $new_id ) ) {
			return new WP_REST_Response( [ 'message' => $new_id->get_error_message() ], 400 );
		}
		return new WP_REST_Response( [ 'id' => $new_id ], 201 );
	}

	public function update_feed( WP_REST_Request $request ): WP_REST_Response {
		$id   = absint( $request['id'] );
		$data = $request->get_json_params();
		GFFPDF_Feed_Settings::update_feed( $id, $data );
		return new WP_REST_Response( [ 'updated' => true ], 200 );
	}

	public function delete_feed( WP_REST_Request $request ): WP_REST_Response {
		GFFPDF_Feed_Settings::delete_feed( absint( $request['id'] ) );
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/* -----------------------------------------------------------------------
	 * Templates endpoints
	 * -------------------------------------------------------------------- */

	public function get_templates(): WP_REST_Response {
		$handler = new GFFPDF_Template_Handler();
		return new WP_REST_Response( $handler->list_templates(), 200 );
	}

	public function upload_template( WP_REST_Request $request ): WP_REST_Response {
		$files = $request->get_file_params();
		if ( empty( $files['pdf_file'] ) ) {
			return new WP_REST_Response( [ 'message' => 'No file provided' ], 400 );
		}
		$handler = new GFFPDF_Template_Handler();
		$result  = $handler->handle_upload( $files['pdf_file'] );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		return new WP_REST_Response( $result, 201 );
	}

	/* -----------------------------------------------------------------------
	 * Mappings endpoints
	 * -------------------------------------------------------------------- */

	public function get_mappings( WP_REST_Request $request ): WP_REST_Response {
		$feed_id = absint( $request->get_param( 'feed_id' ) );
		$feed    = GFFPDF_Feed_Settings::get_feed( $feed_id );
		if ( ! $feed ) {
			return new WP_REST_Response( [ 'message' => 'Feed not found' ], 404 );
		}
		return new WP_REST_Response( json_decode( $feed->mappings, true ), 200 );
	}

	public function save_mappings( WP_REST_Request $request ): WP_REST_Response {
		$data    = $request->get_json_params();
		$feed_id = absint( $data['feed_id'] ?? 0 );
		$feed    = GFFPDF_Feed_Settings::get_feed( $feed_id );
		if ( ! $feed ) {
			return new WP_REST_Response( [ 'message' => 'Feed not found' ], 404 );
		}

		$clean    = GFFPDF_Security::sanitize_mappings( $data['mappings'] ?? [] );
		$existing = (array) json_decode( $feed->settings, true );

		GFFPDF_Feed_Settings::update_feed( $feed_id, [
			'form_id'       => $feed->form_id,
			'feed_name'     => $feed->feed_name,
			'template_path' => $feed->template_path,
			'settings'      => $existing,
			'mappings'      => $clean,
			'is_active'     => $feed->is_active,
		] );

		return new WP_REST_Response( [ 'saved' => true ], 200 );
	}
}