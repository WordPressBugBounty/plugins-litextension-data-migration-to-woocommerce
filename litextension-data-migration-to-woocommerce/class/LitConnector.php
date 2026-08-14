<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Installs and removes the migration connector.
 *
 * The connector is shipped inside this plugin (connector/connector.php.tpl) and
 * copied into le_connector/connector.php. Nothing is downloaded at runtime and
 * the connector token is always generated locally with a CSPRNG: it is never
 * read from a request parameter.
 */
class LitConnector {

    const ACTION_INSTALL   = 'installConnector';
    const ACTION_UNINSTALL = 'uninstallConnector';
    const ACTION_CHECK     = 'checkConnector';

    const CONNECTOR_VERSION = '2.0.0';
    const CONNECTOR_DIR     = 'le_connector';

    const OPTION_TOKEN        = '_lit_connector_token';
    const OPTION_TOKEN_HASH   = '_lit_connector_token_hash';
    const OPTION_INSTALLED_AT = '_lit_connector_installed_at';

    /**
     * @var string Absolute path of the connector directory.
     */
    protected $_connectorPath;

    /**
     * @var string Absolute path of the installed connector file.
     */
    protected $_connectorFile;

    /**
     * @var string Absolute path of the bundled connector template.
     */
    protected $_templateFile;

    /**
     * @var array
     */
    protected $_response;

    /**
     * @var \WP_Filesystem_Base|null
     */
    protected $fs = null;

    public function __construct() {
        $this->_connectorPath = trailingslashit( LIT_PATH_PLUGIN ) . self::CONNECTOR_DIR;
        $this->_connectorFile = $this->_connectorPath . DIRECTORY_SEPARATOR . 'connector.php';
        $this->_templateFile  = trailingslashit( LIT_PATH_PLUGIN ) . 'connector' . DIRECTORY_SEPARATOR . 'connector.php.tpl';
        $this->_response      = $this->createResponse( 'success' );

        $this->init_filesystem();
    }

    protected function init_filesystem() {
        global $wp_filesystem;

        if ( $this->fs instanceof \WP_Filesystem_Base ) {
            return;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
            $this->fs = $wp_filesystem;
        }
    }

    public function createResponse( $result, $data = null, $msg = '', $code = 200 ) {
        return array(
            'result' => $result,
            'data'   => $data,
            'msg'    => $msg,
            'code'   => $code,
        );
    }

    public function responseSuccess( $data, $msg = '', $code = '' ) {
        return $this->createResponse( 'success', $data, $msg, $code );
    }

    /**
     * Run a connector action.
     *
     * Callers are responsible for the capability and nonce checks; this class is
     * never reachable from an unauthenticated or cross-site request.
     *
     * @param string $action     One of the ACTION_* constants.
     * @param bool   $regenerate Rotate the token before installing.
     * @return array
     */
    public function execute( $action, $regenerate = false ) {
        try {
            switch ( $action ) {
                case self::ACTION_CHECK:
                    return $this->responseSuccess( $this->getStatus() );

                case self::ACTION_INSTALL:
                    $this->_installConnector( (bool) $regenerate );
                    $this->_response['data'] = $this->getStatus();
                    break;

                case self::ACTION_UNINSTALL:
                    $this->_unInstallBridge();
                    $this->_response['data'] = $this->getStatus();
                    break;

                default:
                    if ( ! $action ) {
                        /* translators: Error message when action is missing. */
                        throw new \Exception( esc_html__( 'Action is required!', 'litextension-data-migration-to-woocommerce' ) );
                    }

                    throw new \Exception(
                        sprintf(
                            /* translators: %s: Action name. */
                            esc_html__( 'Unknown Action: %s', 'litextension-data-migration-to-woocommerce' ),
                            esc_html( (string) $action )
                        )
                    );
            }
        } catch ( \Throwable $e ) {
            $this->_handleError( $e );
        }

        return $this->_response;
    }

    /**
     * @param \Exception|\Throwable $exception
     */
    protected function _handleError( $exception ) {
        $this->_response['result'] = 'error';
        $this->_response['code']   = (int) $exception->getCode();
        $this->_response['msg']    = (string) $exception->getMessage();
    }

    public function isConnectorExist() {
        $this->init_filesystem();

        if ( $this->fs ) {
            return $this->fs->exists( $this->_connectorFile );
        }

        return file_exists( $this->_connectorFile );
    }

    public function getConnectorUrl() {
        return trailingslashit( LIT_URL_PLUGIN ) . self::CONNECTOR_DIR . '/connector.php';
    }

    /**
     * Current connector state, used by the admin screen and the REST route.
     *
     * @return array
     */
    public function getStatus() {
        $upload = wp_get_upload_dir();

        return array(
            'installed'         => $this->isConnectorExist(),
            'connector_url'     => $this->getConnectorUrl(),
            'connector_version' => self::CONNECTOR_VERSION,
            'plugin_version'    => LIT_VERSION,
            'token'             => $this->isConnectorExist() ? (string) get_option( self::OPTION_TOKEN, '' ) : '',
            'installed_at'      => (int) get_option( self::OPTION_INSTALLED_AT, 0 ),
            'site_url'          => home_url( '/' ),
            'uploads_writable'  => empty( $upload['error'] ) && wp_is_writable( $upload['basedir'] ),
        );
    }

    /**
     * The connector token. Generated locally on first use.
     *
     * @return string
     */
    public function getToken() {
        $token = (string) get_option( self::OPTION_TOKEN, '' );

        if ( '' === $token || ! $this->isValidToken( $token ) ) {
            $token = $this->regenerateToken();
        }

        return $token;
    }

    /**
     * Create a brand new token and forget the previous one.
     *
     * @return string
     */
    public function regenerateToken() {
        $token = bin2hex( random_bytes( 16 ) );

        update_option( self::OPTION_TOKEN, $token, false );
        update_option( self::OPTION_TOKEN_HASH, hash( 'sha256', $token ), false );

        return $token;
    }

    /**
     * Tokens are always hexadecimal, so nothing that could break out of the PHP
     * string literal in the connector file can ever be stored.
     *
     * @param string $token Token to validate.
     * @return bool
     */
    public function isValidToken( $token ) {
        return (bool) preg_match( '/^[a-f0-9]{32,64}$/', (string) $token );
    }

    public function newException( $error_code ) {
        throw new \Exception( esc_html( Connector_Errors::getErrorMessage( $error_code ) ), (int) $error_code );
    }

    /**
     * Copy the bundled connector into place with a freshly generated token.
     *
     * @param bool $regenerate Rotate the token first.
     * @return void
     */
    protected function _installConnector( $regenerate = false ) {
        $this->init_filesystem();

        if ( ! $this->fs ) {
            $this->newException( Connector_Errors::MODULE_ERROR_PERMISSION );
        }

        $template = $this->fs->get_contents( $this->_templateFile );

        if ( false === $template || '' === $template ) {
            $this->newException( Connector_Errors::MODULE_ERROR_TEMPLATE_MISSING );
        }

        if ( $regenerate || '' === (string) get_option( self::OPTION_TOKEN, '' ) ) {
            $this->regenerateToken();
        }

        $token = $this->getToken();

        if ( ! $this->isValidToken( $token ) ) {
            $this->newException( Connector_Errors::MODULE_ERROR_EMPTY_TOKEN );
        }

        // The endpoint authenticates against this hash, never against the file.
        update_option( self::OPTION_TOKEN_HASH, hash( 'sha256', $token ), false );

        $upload = wp_get_upload_dir();

        if ( ! empty( $upload['error'] ) ) {
            $this->newException( Connector_Errors::MODULE_ERROR_INSTALLED_PERMISSION );
        }

        $store_base = trailingslashit( wp_normalize_path( ABSPATH ) );
        $upload_dir = untrailingslashit( wp_normalize_path( $upload['basedir'] ) );

        if ( 0 !== strpos( trailingslashit( $upload_dir ), $store_base ) ) {
            $this->newException( Connector_Errors::MODULE_ERROR_UPLOAD_OUTSIDE_ROOT );
        }

        $replacements = array(
            '{{WP_LOAD_PATH}}' => $store_base . 'wp-load.php',
        );

        foreach ( $replacements as $placeholder => $value ) {
            // Values land inside single quoted PHP strings in the template.
            $template = str_replace( $placeholder, addcslashes( (string) $value, "\\'" ), $template );
        }

        if ( ! $this->fs->is_dir( $this->_connectorPath ) && ! $this->fs->mkdir( $this->_connectorPath, FS_CHMOD_DIR ) ) {
            $this->newException( Connector_Errors::MODULE_ERROR_PERMISSION );
        }

        if ( ! $this->fs->put_contents( $this->_connectorFile, $template, FS_CHMOD_FILE ) ) {
            $this->newException( Connector_Errors::CONNECTOR_FILE_PERMISSION );
        }

        $this->_writeHardeningFiles();

        update_option( self::OPTION_INSTALLED_AT, time(), false );

        $this->_response['code'] = Connector_Errors::MODULE_CONNECTOR_SUCCESSFULLY_INSTALLED;
        $this->_response['msg']  = esc_html__( 'Connector installed successfully.', 'litextension-data-migration-to-woocommerce' );
    }

    /**
     * Keep the connector folder free of anything but the bridge itself.
     */
    protected function _writeHardeningFiles() {
        $this->fs->put_contents( $this->_connectorPath . DIRECTORY_SEPARATOR . 'index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );

        // Earlier releases wrote an .htaccess here; remove it so the plugin
        // directory contains no hidden files.
        $legacy_htaccess = $this->_connectorPath . DIRECTORY_SEPARATOR . '.htaccess';

        if ( $this->fs->exists( $legacy_htaccess ) ) {
            wp_delete_file( $legacy_htaccess );
        }
    }

    /**
     * Remove the connector and revoke its token.
     *
     * @return bool
     */
    protected function _unInstallBridge() {
        $deleted = true;

        if ( $this->isConnectorExist() || ( $this->fs && $this->fs->is_dir( $this->_connectorPath ) ) ) {
            $deleted = $this->_deleteDir( $this->_connectorPath );
        }

        delete_option( self::OPTION_TOKEN );
        delete_option( self::OPTION_TOKEN_HASH );
        delete_option( self::OPTION_INSTALLED_AT );

        if ( ! $deleted ) {
            $this->newException( Connector_Errors::CONNECTOR_FILE_PERMISSION );
        }

        $this->_response['msg'] = esc_html__( 'Connector removed and its token revoked.', 'litextension-data-migration-to-woocommerce' );

        return $deleted;
    }

    /**
     * Public helper used by the upgrade routine to drop a connector that was
     * installed by a vulnerable version of the plugin.
     *
     * @return bool
     */
    public function forceRemove() {
        $this->init_filesystem();

        if ( ! $this->fs ) {
            return false;
        }

        $removed = true;

        if ( $this->fs->is_dir( $this->_connectorPath ) ) {
            $removed = $this->_deleteDir( $this->_connectorPath );
        }

        delete_option( self::OPTION_TOKEN );
        delete_option( self::OPTION_TOKEN_HASH );
        delete_option( self::OPTION_INSTALLED_AT );

        return $removed;
    }

    protected function _deleteDir( $dirPath ) {
        $this->init_filesystem();

        if ( ! $this->fs || ! $this->fs->is_dir( $dirPath ) ) {
            return false;
        }

        // Never walk outside the plugin directory.
        $plugin_dir = trailingslashit( wp_normalize_path( LIT_PATH_PLUGIN ) );

        if ( 0 !== strpos( trailingslashit( wp_normalize_path( $dirPath ) ), $plugin_dir ) ) {
            return false;
        }

        $objects = $this->fs->dirlist( $dirPath, true );

        if ( is_array( $objects ) ) {
            foreach ( $objects as $name => $item ) {
                $full = trailingslashit( $dirPath ) . $name;

                if ( 'd' === $item['type'] ) {
                    $this->_deleteDir( $full );
                } else {
                    wp_delete_file( $full );
                }
            }
        }

        return (bool) $this->fs->rmdir( $dirPath, true );
    }
}
