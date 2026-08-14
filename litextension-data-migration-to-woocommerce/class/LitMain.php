<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LitMain
 *
 * Admin screens and request handlers. Every state changing request goes through
 * admin-post.php or admin-ajax.php and is protected by a capability check and a
 * nonce, so none of them can be triggered by a cross-site request.
 */
class LitMain {

    const APP_LINK       = 'https://app.litextension.com/';
    const APP_LINK_LOGIN = 'https://api.litextension.com/';
    const APP_LINK_HOME  = 'https://litextension.com/';

    const CAPABILITY = 'manage_options';

    const META_EMAIL = '_lit_login_email';
    const META_TOKEN = '_lit_security_token';

    const CONNECTOR_ACTION = 'lit_connector_action';
    const AJAX_NONCE       = 'lit_ajax_nonce';
    const NOTICE_TRANSIENT = '_lit_admin_notice_';

    /**
     * @var LitView
     */
    public $litView;

    /**
     * @var LitType
     */
    public $litType;

    public function __construct() {
        $this->litView = new LitView();
        $this->litType = new LitType();

        $this->_addActions();
    }

    public static function init() {
        new self();
    }

    public function _addActions() {
        add_action( 'admin_menu', array( $this, 'createMenuAdminPanel' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_notices', array( $this, 'renderAdminNotices' ) );

        add_action( 'admin_post_' . self::CONNECTOR_ACTION, array( $this, 'handleConnectorRequest' ) );

        add_action( 'wp_ajax_lit_save_session', array( $this, 'ajaxSaveSession' ) );
        add_action( 'wp_ajax_lit_clear_session', array( $this, 'ajaxClearSession' ) );

        add_action( 'rest_api_init', array( __NAMESPACE__ . '\LitRest', 'registerRoutes' ) );
    }

    public function createMenuAdminPanel() {
        add_menu_page(
            __( 'LitExtension', 'litextension-data-migration-to-woocommerce' ),
            __( 'LitExtension', 'litextension-data-migration-to-woocommerce' ),
            self::CAPABILITY,
            'litextension',
            array( $this, 'migrateToWooCommerce' ),
            LIT_URL_PLUGIN . 'assets/images/logo.png'
        );

        add_submenu_page(
            'litextension',
            __( 'Shopping Cart to WooCommerce Migration', 'litextension-data-migration-to-woocommerce' ),
            __( 'Shopping Cart to WooCommerce Migration', 'litextension-data-migration-to-woocommerce' ),
            self::CAPABILITY,
            'migrate-to-woocommerce',
            array( $this, 'migrateToWooCommerce' )
        );

        // Landing page the LitExtension app redirects to. Registered as a hidden
        // submenu so it is reachable but not listed in the menu.
        add_submenu_page(
            'litextension',
            __( 'Migration Connector', 'litextension-data-migration-to-woocommerce' ),
            __( 'Migration Connector', 'litextension-data-migration-to-woocommerce' ),
            self::CAPABILITY,
            'install-connector',
            array( $this, 'litInstallConnector' )
        );

        remove_submenu_page( 'litextension', 'litextension' );
        remove_submenu_page( 'litextension', 'install-connector' );
    }

    public function enqueue_scripts( $hook_suffix ) {
        if ( ! $this->isPluginScreen() ) {
            return;
        }

        wp_enqueue_style(
            'custom-style-lit',
            LIT_URL_PLUGIN . 'assets/css/litextension.css',
            array(),
            LIT_VERSION
        );

        wp_enqueue_script(
            'custom-script-lit',
            LIT_URL_PLUGIN . 'assets/js/litextension.js',
            array( 'jquery' ),
            LIT_VERSION,
            true
        );

        wp_localize_script(
            'custom-script-lit',
            'litExtensionData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
                'appUrl'  => self::APP_LINK,
            )
        );
    }

    /**
     * Whether the current admin screen belongs to this plugin.
     *
     * @return bool
     */
    protected function isPluginScreen() {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }

        $screen = get_current_screen();

        if ( ! $screen ) {
            return false;
        }

        foreach ( array( 'litextension', 'migrate-to-woocommerce', 'install-connector' ) as $slug ) {
            if ( false !== strpos( $screen->id, $slug ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Main migration screen.
     */
    public function migrateToWooCommerce() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'litextension-data-migration-to-woocommerce' ) );
        }

        $connector = new LitConnector();

        $_param = array(
            'src'          => 'target_url=' . rawurlencode( get_home_url() ) . '&app_mode=true',
            'url_register' => self::APP_LINK . 'register',
            'url_forgot'   => self::APP_LINK . 'forgot-password',
            'plugin_url'   => untrailingslashit( LIT_URL_PLUGIN ),
            'app_link'     => self::APP_LINK_LOGIN . 'api/app-login',
            'list_cart'    => $this->litType->sourceCarts(),
            'email'        => $this->getLoginEmail(),
            'token'        => $this->getSecurityToken(),
            'connector'    => $connector->getStatus(),
            'form_url'      => admin_url( 'admin-post.php' ),
            'form_action'   => self::CONNECTOR_ACTION,
            'nonce_action'  => self::CONNECTOR_ACTION,
            'redirect_page' => 'migrate-to-woocommerce',
        );

        $this->litView->litView( 'index', $_param );
    }

    /**
     * Landing page for https://site/wp-admin/admin.php?page=install-connector
     *
     * Older releases installed the connector straight from this URL using a
     * token taken from the query string. That made the action forgeable, so the
     * page now only reports the connector state: installing or removing the
     * connector requires the nonce protected form below.
     */
    public function litInstallConnector() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'litextension-data-migration-to-woocommerce' ) );
        }

        $connector = new LitConnector();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only: the value is never used, it is only detected so the notice below can be shown.
        $legacy_token_in_url = isset( $_GET['token'] );

        $_param = array(
            'connector'           => $connector->getStatus(),
            'form_url'            => admin_url( 'admin-post.php' ),
            'form_action'         => self::CONNECTOR_ACTION,
            'nonce_action'        => self::CONNECTOR_ACTION,
            'legacy_token_in_url' => $legacy_token_in_url,
            'plugin_url'          => untrailingslashit( LIT_URL_PLUGIN ),
            'redirect_page'       => 'install-connector',
        );

        $this->litView->litView( 'connector', $_param );
    }

    /**
     * Install / reinstall / remove the connector.
     */
    public function handleConnectorRequest() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die(
                esc_html__( 'You are not allowed to manage the migration connector.', 'litextension-data-migration-to-woocommerce' ),
                '',
                array( 'response' => 403 )
            );
        }

        check_admin_referer( self::CONNECTOR_ACTION );

        $lit_action = isset( $_POST['lit_action'] ) ? sanitize_key( wp_unslash( $_POST['lit_action'] ) ) : '';
        $redirect   = isset( $_POST['lit_redirect'] ) ? sanitize_key( wp_unslash( $_POST['lit_redirect'] ) ) : 'migrate-to-woocommerce';

        if ( ! in_array( $redirect, array( 'migrate-to-woocommerce', 'install-connector' ), true ) ) {
            $redirect = 'migrate-to-woocommerce';
        }

        $connector = new LitConnector();

        switch ( $lit_action ) {
            case 'install':
                $response = $connector->execute( LitConnector::ACTION_INSTALL, false );
                break;

            case 'reinstall':
                $response = $connector->execute( LitConnector::ACTION_INSTALL, true );
                break;

            case 'uninstall':
                $response = $connector->execute( LitConnector::ACTION_UNINSTALL );
                break;

            default:
                $response = array(
                    'result' => 'error',
                    'msg'    => __( 'Unknown connector action.', 'litextension-data-migration-to-woocommerce' ),
                );
        }

        if ( 'error' !== $response['result'] ) {
            delete_option( LitInstaller::OPTION_CONNECTOR_REVOKED );
        }

        $this->addNotice(
            'error' === $response['result'] ? 'error' : 'success',
            '' !== (string) $response['msg']
                ? (string) $response['msg']
                : __( 'Connector updated successfully.', 'litextension-data-migration-to-woocommerce' )
        );

        wp_safe_redirect( admin_url( 'admin.php?page=' . $redirect ) );
        exit;
    }

    /**
     * Store the LitExtension login of the current administrator.
     */
    public function ajaxSaveSession() {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'msg' => __( 'Permission denied.', 'litextension-data-migration-to-woocommerce' ) ), 403 );
        }

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $token = isset( $_POST['security_token'] ) ? sanitize_text_field( wp_unslash( $_POST['security_token'] ) ) : '';

        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'msg' => __( 'Invalid email address.', 'litextension-data-migration-to-woocommerce' ) ), 400 );
        }

        $user_id = get_current_user_id();

        update_user_meta( $user_id, self::META_EMAIL, $email );
        update_user_meta( $user_id, self::META_TOKEN, $token );

        wp_send_json_success( array( 'email' => $email ) );
    }

    /**
     * Forget the LitExtension login of the current administrator.
     */
    public function ajaxClearSession() {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'msg' => __( 'Permission denied.', 'litextension-data-migration-to-woocommerce' ) ), 403 );
        }

        $user_id = get_current_user_id();

        delete_user_meta( $user_id, self::META_EMAIL );
        delete_user_meta( $user_id, self::META_TOKEN );

        wp_send_json_success();
    }

    /**
     * @return string
     */
    public function getLoginEmail() {
        $email = get_user_meta( get_current_user_id(), self::META_EMAIL, true );

        return is_string( $email ) ? $email : '';
    }

    /**
     * @return string
     */
    public function getSecurityToken() {
        $token = get_user_meta( get_current_user_id(), self::META_TOKEN, true );

        return is_string( $token ) ? $token : '';
    }

    /**
     * Queue an admin notice for the current user.
     *
     * @param string $type    "success" or "error".
     * @param string $message Message to display.
     */
    protected function addNotice( $type, $message ) {
        set_transient(
            self::NOTICE_TRANSIENT . get_current_user_id(),
            array(
                'type'    => $type,
                'message' => $message,
            ),
            60
        );
    }

    public function renderAdminNotices() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $key    = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient( $key );

        if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
            delete_transient( $key );

            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                'error' === $notice['type'] ? 'error' : 'success',
                esc_html( $notice['message'] )
            );
        }

        if ( get_option( LitInstaller::OPTION_CONNECTOR_REVOKED ) ) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
                esc_html__( 'LitExtension: for security reasons the migration connector installed by a previous version was removed and its token revoked. If a migration is in progress, reinstall the connector.', 'litextension-data-migration-to-woocommerce' ),
                esc_url( admin_url( 'admin.php?page=migrate-to-woocommerce' ) ),
                esc_html__( 'Open LitExtension', 'litextension-data-migration-to-woocommerce' )
            );
        }
    }
}
