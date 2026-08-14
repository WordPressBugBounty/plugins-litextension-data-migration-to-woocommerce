<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST routes used to set up a migration without a human copying values around.
 *
 * Authentication and authorisation are handled by WordPress itself: the routes
 * are only reachable by a logged in user who can manage_options, which works
 * out of the box with WordPress Application Passwords over HTTPS. No custom
 * credential handling is implemented here.
 *
 * GET    /wp-json/litextension/v1/connector  - connector state and token
 * POST   /wp-json/litextension/v1/connector  - install (optionally rotate token)
 * DELETE /wp-json/litextension/v1/connector  - remove the connector, revoke token
 */
class LitRest {

    const NAMESPACE_V1 = 'litextension/v1';

    public static function registerRoutes() {
        register_rest_route(
            self::NAMESPACE_V1,
            '/connector',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'getConnector' ),
                    'permission_callback' => array( __CLASS__, 'checkPermission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'installConnector' ),
                    'permission_callback' => array( __CLASS__, 'checkPermission' ),
                    'args'                => array(
                        'regenerate' => array(
                            'type'        => 'boolean',
                            'default'     => false,
                            'description' => __( 'Generate a new connector token before installing.', 'litextension-data-migration-to-woocommerce' ),
                        ),
                    ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( __CLASS__, 'deleteConnector' ),
                    'permission_callback' => array( __CLASS__, 'checkPermission' ),
                ),
            )
        );
    }

    /**
     * @return bool|\WP_Error
     */
    public static function checkPermission() {
        if ( current_user_can( LitMain::CAPABILITY ) ) {
            return true;
        }

        return new \WP_Error(
            'lit_forbidden',
            __( 'You are not allowed to manage the migration connector.', 'litextension-data-migration-to-woocommerce' ),
            array( 'status' => is_user_logged_in() ? 403 : 401 )
        );
    }

    /**
     * @return \WP_REST_Response
     */
    public static function getConnector() {
        $connector = new LitConnector();

        return rest_ensure_response( $connector->getStatus() );
    }

    /**
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function installConnector( $request ) {
        $connector = new LitConnector();
        $response  = $connector->execute( LitConnector::ACTION_INSTALL, (bool) $request->get_param( 'regenerate' ) );

        if ( 'error' === $response['result'] ) {
            return new \WP_Error( 'lit_connector_install_failed', $response['msg'], array( 'status' => 500 ) );
        }

        delete_option( LitInstaller::OPTION_CONNECTOR_REVOKED );

        return rest_ensure_response( $connector->getStatus() );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function deleteConnector() {
        $connector = new LitConnector();
        $response  = $connector->execute( LitConnector::ACTION_UNINSTALL );

        if ( 'error' === $response['result'] ) {
            return new \WP_Error( 'lit_connector_remove_failed', $response['msg'], array( 'status' => 500 ) );
        }

        return rest_ensure_response( $connector->getStatus() );
    }
}
