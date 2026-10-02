<?php
/**
 * REST endpoints for plugin settings.
 *
 * @package Advision\Redirects
 */

namespace Advision\Redirects\Rest;

use Advision\Redirects\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => array_map(
						[ self::class, 'arg' ],
						[
							'slug_watcher'             => [ 'type' => 'boolean' ],
							'log_404'                  => [ 'type' => 'boolean' ],
							'forward_query_string'     => [ 'type' => 'boolean' ],
							'remove_data_on_uninstall' => [ 'type' => 'boolean' ],
							'log_404_retention_days'   => [
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 365,
							],
							'log_404_max_rows'         => [
								'type'    => 'integer',
								'minimum' => 100,
								'maximum' => 100000,
							],
							'excluded_404_extensions'  => [
								'type'     => 'array',
								'items'    => [
									'type'    => 'string',
									'pattern' => '^[A-Za-z0-9]{1,10}$',
								],
								'maxItems' => 50,
							],
						]
					),
				],
			]
		);
	}

	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( Settings::all() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown( $request, array_keys( Settings::defaults() ) );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$input = [];
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		return rest_ensure_response( Settings::update( $input ) );
	}
}
