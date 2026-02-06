<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LitConnector {
    const ACTION_INSTALL   = 'installConnector';
    const ACTION_UNINSTALL = 'uninstallConnector';
    const ACTION_CHECK     = 'checkConnector';

    const URL_DOWNLOAD_CONNECTOR = 'https://api.litextension.com/api/get-connector';

    protected $_rootPath;
    protected $_connectorPath;
    protected $_connectorFile;
    protected $_response;

    /**
     * @var \WP_Filesystem_Base|null
     */
    protected $fs = null;

    public function __construct() {
        $plugin_name = plugin_basename( __FILE__ );
        $plugin_name = explode( '/', $plugin_name )[0];

        $this->_rootPath      = rtrim( get_home_path(), '/' );
        $this->_connectorPath = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin_name . DIRECTORY_SEPARATOR . 'le_connector';
        $this->_connectorFile = $this->_connectorPath . DIRECTORY_SEPARATOR . 'connector.php';
        $this->_response      = $this->createResponse( 'success' );

        $this->init_filesystem();
    }

    protected function init_filesystem() {
        global $wp_filesystem;

        if ( $this->fs instanceof \WP_Filesystem_Base ) {
            return;
        }

        // Ensure filesystem is initialized.
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Tries to initialize with available method. In admin it usually works without creds.
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

    public function execute( $action, $token ) {
        try {
            switch ( $action ) {
                case self::ACTION_CHECK:
                    return $this->responseSuccess( $this->isConnectorExist() );

                case self::ACTION_INSTALL:
                    $this->_installConnector( $token );
                    break;

                case self::ACTION_UNINSTALL:
                    $this->_unInstallBridge();
                    break;

                default:
                    if ( ! $action ) {
                        /* translators: Error message when action is missing. */
                        throw new \Exception( __( 'Action is required!', 'litextension-data-migration-to-woocommerce' ) );
                    }

                    /* translators: %s: Action name. */
                    throw new \Exception(sprintf(__( 'Unknown Action: %s', 'litextension-data-migration-to-woocommerce' ), (string) $action));
            }
        } catch ( \Throwable $e ) {
            $this->_handleError( $e );
        }

        return wp_json_encode( $this->_response );
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

        // Fallback if filesystem cannot be initialized (rare).
        return file_exists( $this->_connectorFile );
    }

    public function newException( $error_code ) {

        throw new \Exception( esc_html(Connector_Errors::getErrorMessage( $error_code )), esc_textarea($error_code) );
    }

    protected function _installConnector( $token ) {
        if ( ! $token ) {
            $this->newException( Connector_Errors::MODULE_ERROR_EMPTY_TOKEN );
        }

        if ( $this->isConnectorExist() ) {
            if ( ! $this->_changeToken( $token ) ) {
                $this->newException( Connector_Errors::CONNECTOR_FILE_PERMISSION );
            }
            return;
        }

        $this->_downloadConnector( $token );
    }

    protected function _changeToken( $token ) {
        $this->init_filesystem();

        $token = sanitize_text_field( (string) $token );

        $connector = '';
        if ( $this->fs ) {
            $connector = $this->fs->get_contents( $this->_connectorFile );
        } else {
            $connector = file_get_contents( $this->_connectorFile ); // Fallback.
        }

        if ( false === $connector || '' === $connector ) {
            return false;
        }

        preg_match(
            '/^\s*define\s*\(\s*\'LECM_TOKEN\',\s*(\'|\")(.+)(\'|\")\s*\)\s*;/m',
            $connector,
            $match
        );

        if ( ! $match ) {
            return false;
        }

        $old_token = $match[2];
        if ( $old_token === $token ) {
            return true;
        }

        $connector = str_replace( $old_token, $token, $connector );

        if ( ! $this->_checkConnectorFilePermission() ) {
            $this->newException( Connector_Errors::CONNECTOR_FILE_PERMISSION );
        }

        if ( $this->fs ) {
            return (bool) $this->fs->put_contents( $this->_connectorFile, $connector, FS_CHMOD_FILE );
        }

        return (bool) file_put_contents( $this->_connectorFile, $connector ); // Fallback.
    }

    protected function _downloadConnector( $token ) {
        $this->init_filesystem();

        $token = sanitize_text_field( (string) $token );

        if ( ! $token ) {
            $this->newException( Connector_Errors::MODULE_ERROR_EMPTY_TOKEN );
        }

        if ( ! $this->fs ) {
            // If filesystem API not available, keep consistent behavior by failing fast.
            $this->newException( Connector_Errors::MODULE_ERROR_PERMISSION );
        }

        // Ensure dir exists (WP_Filesystem).
        if ( ! $this->fs->is_dir( $this->_connectorPath ) ) {
            $made = $this->fs->mkdir( $this->_connectorPath, FS_CHMOD_DIR );
            if ( ! $made ) {
                $this->newException( Connector_Errors::MODULE_ERROR_PERMISSION );
            }
        }

        // Check writable via WP_Filesystem by attempting to put a temp file.
        if ( ! $this->_checkDirPermission( $this->_connectorPath ) ) {
            $this->newException( Connector_Errors::MODULE_ERROR_INSTALLED_PERMISSION );
        }

        $response = wp_remote_get( self::URL_DOWNLOAD_CONNECTOR );

        if ( is_wp_error( $response ) ) {
            $this->newException( Connector_Errors::MODULE_ERROR_PERMISSION );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || empty( $data['data'] ) ) {
            $this->newException( Connector_Errors::MODULE_ERROR_PERMISSION );
        }

        $decoded = base64_decode( (string) $data['data'], true );
        if ( false === $decoded ) {
            $this->newException( Connector_Errors::MODULE_ERROR_PERMISSION );
        }

        $content = str_replace( '__SAMPLE__LECM__TOKEN__', $token, $decoded );

        $written = $this->fs->put_contents( $this->_connectorFile, $content, FS_CHMOD_FILE );
        if ( ! $written ) {
            $this->newException( Connector_Errors::CONNECTOR_FILE_PERMISSION );
        }

        $this->_response['code'] = Connector_Errors::MODULE_CONNECTOR_SUCCESSFULLY_INSTALLED;
    }

    protected function _unInstallBridge() {
        if ( ! $this->isConnectorExist() ) {
            return true;
        }

        return $this->_deleteDir( $this->_connectorPath );
    }

    protected function _checkDirPermission( $path ) {
        $this->init_filesystem();

        if ( ! $this->fs ) {
            return false;
        }

        // Use a temp file write to validate permission.
        $tmp = trailingslashit( $path ) . 'le_tmp_' . wp_generate_password( 8, false ) . '.txt';

        $ok = $this->fs->put_contents( $tmp, '1', FS_CHMOD_FILE );
        if ( $ok ) {
            wp_delete_file( $tmp );
            return true;
        }

        // Try chmod directory then retry once.
        $this->fs->chmod( $path, FS_CHMOD_DIR );

        $ok = $this->fs->put_contents( $tmp, '1', FS_CHMOD_FILE );
        if ( $ok ) {
            wp_delete_file( $tmp );
            return true;
        }

        return false;
    }

    protected function _checkConnectorFilePermission() {
        $this->init_filesystem();

        if ( ! $this->fs ) {
            return false;
        }

        // If file doesn't exist yet, treat as writable if directory is writable.
        if ( ! $this->fs->exists( $this->_connectorFile ) ) {
            return $this->_checkDirPermission( $this->_connectorPath );
        }

        // Try read + write same content (lightweight check).
        $current = $this->fs->get_contents( $this->_connectorFile );
        if ( false !== $current ) {
            $ok = $this->fs->put_contents( $this->_connectorFile, $current, FS_CHMOD_FILE );
            if ( $ok ) {
                return true;
            }
        }

        $this->fs->chmod( $this->_connectorFile, FS_CHMOD_FILE );

        $current = $this->fs->get_contents( $this->_connectorFile );
        if ( false !== $current ) {
            return (bool) $this->fs->put_contents( $this->_connectorFile, $current, FS_CHMOD_FILE );
        }

        return false;
    }

    protected function _deleteDir( $dirPath ) {
        $this->init_filesystem();

        if ( ! $this->fs ) {
            return false;
        }

        if ( ! $this->fs->is_dir( $dirPath ) ) {
            return false;
        }

        $objects = $this->fs->dirlist( $dirPath, true );
        if ( is_array( $objects ) ) {
            foreach ( $objects as $name => $item ) {
                $full = trailingslashit( $dirPath ) . $name;

                if ( 'd' === $item['type'] ) {
                    $this->_deleteDir( $full );
                } else {
                    // WP preferred delete for files.
                    wp_delete_file( $full );
                }
            }
        }

        // Remove the directory itself.
        return (bool) $this->fs->rmdir( $dirPath, true );
    }
}
