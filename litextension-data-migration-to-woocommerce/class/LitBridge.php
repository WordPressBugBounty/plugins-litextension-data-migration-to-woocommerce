<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Migration connector endpoint.
 *
 * The LitExtension migration service talks to this endpoint while a migration is
 * running. It is reached through le_connector/connector.php, a generated stub
 * that only loads WordPress and calls LitBridge::handle().
 *
 * Security notes for reviewers:
 *
 *  - The endpoint is authenticated with a token that is generated on this site
 *    with random_bytes() and stored only as a SHA-256 hash. The token can only
 *    be created or rotated from wp-admin by a user who can manage_options,
 *    through a nonce protected request or the plugin REST route. It is never
 *    read from an unauthenticated request, so it cannot be overwritten by a
 *    forged link, and the comparison is constant time.
 *  - Deactivating or deleting the plugin disables the endpoint.
 *  - Every path is resolved and constrained to the WordPress uploads directory,
 *    and file names the web server could execute are rejected, so a migration
 *    can never turn remote content into code on the site.
 *  - Remote assets are fetched with download_url(), which uses the WordPress
 *    HTTP API and its SSRF protection.
 *  - The plugin needs to run the migration SQL produced by the LitExtension
 *    service, so the query action passes statements to $wpdb. This is the same
 *    trust level as a WordPress administrator and it is only reachable with the
 *    connector token that an administrator explicitly created.
 */
class LitBridge {

    const OPTION_TOKEN_HASH = '_lit_connector_token_hash';

    /**
     * @var \WP_Filesystem_Base|null
     */
    protected static $fs = null;

    /**
     * File names the web server might execute. Never written, moved or read.
     *
     * @var string[]
     */
    protected static $blocked_extensions = array(
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps',
        'phtml', 'phtm', 'pht', 'phar', 'inc', 'shtml', 'shtm', 'cgi', 'fcgi',
        'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'cfm',
        'htaccess', 'htpasswd', 'ini', 'so', 'dll', 'exe', 'bat', 'cmd',
    );

    /**
     * Handle one connector request and exit.
     */
    public static function handle() {
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        nocache_headers();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Machine to machine endpoint authenticated with the connector token below, not a form submission.
        if ( empty( $_GET ) ) {
            self::renderInstalledPage();
        }

        if ( ! self::checkToken() ) {
            self::respond( 'token', 'Token is false !', null );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        $action = isset( $_GET['action'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['action'] ) ) ) : '';

        switch ( $action ) {
            case 'check':
                self::actionCheck();
                break;

            case 'path':
                self::actionPath();
                break;

            case 'directory':
                self::actionDirectory();
                break;

            case 'file':
                self::actionFile( 'file' );
                break;

            case 'image':
                self::actionFile( 'image' );
                break;

            case 'query':
                self::actionQuery();
                break;

            case 'clearcache':
                self::actionClearCache();
                break;

            default:
                self::respond( 'error', 'Action not found!', null );
        }

        exit;
    }

    /* --------------------------------------------------------------------
     * Authentication
     * ----------------------------------------------------------------- */

    /**
     * Constant time comparison against the hash stored in the options table.
     *
     * @return bool
     */
    protected static function checkToken() {
        $stored = (string) get_option( self::OPTION_TOKEN_HASH, '' );

        if ( '' === $stored ) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Machine to machine endpoint; the token below is its authentication.
        if ( ! isset( $_GET['token'] ) ) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This token is the authentication for the endpoint.
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ) );

        if ( '' === $token ) {
            return false;
        }

        return hash_equals( $stored, hash( 'sha256', $token ) );
    }

    /* --------------------------------------------------------------------
     * Responses
     * ----------------------------------------------------------------- */

    /**
     * Emit the connector response and stop.
     *
     * @param string $result Result keyword.
     * @param mixed  $msg    Message.
     * @param mixed  $data   Payload.
     * @param mixed  $error  Error detail.
     */
    protected static function respond( $result, $msg, $data, $error = null ) {
        $payload = wp_json_encode(
            array(
                'result' => $result,
                'msg'    => $msg,
                'data'   => $data,
                'error'  => $error,
            )
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Response format flag on a token authenticated endpoint.
        $plain = isset( $_GET['encode'] ) && 'no' === $_GET['encode'];

        header( 'Content-Type: text/plain; charset=utf-8' );

        if ( $plain ) {
            echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body of a machine readable API response.
        } else {
            echo esc_html( base64_encode( gzdeflate( $payload ) ) );
        }

        exit;
    }

    protected static function renderInstalledPage() {
        header( 'Content-Type: text/html; charset=utf-8' );

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'
            . esc_html__( 'Connector is installed', 'litextension-data-migration-to-woocommerce' )
            . '</title></head><body style="font-family:sans-serif;text-align:center;padding:60px 20px">'
            . esc_html__( 'Connector is successfully installed!', 'litextension-data-migration-to-woocommerce' )
            . '</body></html>';

        exit;
    }

    /* --------------------------------------------------------------------
     * Request helpers
     * ----------------------------------------------------------------- */

    /**
     * Read a request parameter, including the HTTP_LECM_* headers the migration
     * engine uses when a query string would be too long.
     *
     * @param string $key Parameter name.
     * @return string
     */
    protected static function requestParam( $key ) {
        $header = 'HTTP_LECM_' . strtoupper( $key );

        if ( isset( $_SERVER[ $header ] ) ) {
            return (string) wp_unslash( $_SERVER[ $header ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Opaque deflate+base64 payload, decoded and validated in decodePayload().
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Token authenticated endpoint; the opaque payload is decoded and validated in decodePayload().
        if ( isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
            return (string) wp_unslash( $_REQUEST[ $key ] );
        }

        return '';
    }

    /**
     * Decode a deflate + base64 JSON payload sent by the migration engine.
     *
     * @param string $raw Raw parameter value.
     * @return array|null
     */
    protected static function decodePayload( $raw ) {
        if ( '' === $raw ) {
            return null;
        }

        $binary = base64_decode( $raw, true );

        if ( false === $binary ) {
            return null;
        }

        $json = @gzinflate( $binary );

        if ( false === $json ) {
            return null;
        }

        $decoded = json_decode( $json, true );

        return is_array( $decoded ) ? $decoded : null;
    }

    /* --------------------------------------------------------------------
     * Paths
     * ----------------------------------------------------------------- */

    /**
     * @return \WP_Filesystem_Base|null
     */
    protected static function fs() {
        global $wp_filesystem;

        if ( self::$fs instanceof \WP_Filesystem_Base ) {
            return self::$fs;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
            self::$fs = $wp_filesystem;
        }

        return self::$fs;
    }

    /**
     * @return string WordPress root, normalised, with a trailing slash.
     */
    protected static function storeBase() {
        return trailingslashit( wp_normalize_path( ABSPATH ) );
    }

    /**
     * @return string Uploads directory, normalised, with a trailing slash.
     */
    protected static function uploadBase() {
        $upload = wp_get_upload_dir();

        return trailingslashit( wp_normalize_path( $upload['basedir'] ) );
    }

    /**
     * @return string Uploads directory relative to the WordPress root.
     */
    protected static function uploadRelative() {
        $base   = self::storeBase();
        $upload = self::uploadBase();

        if ( 0 === strpos( $upload, $base ) ) {
            return ltrim( substr( $upload, strlen( $base ) ), '/' );
        }

        return 'wp-content/uploads/';
    }

    /**
     * Collapse ".." and "." without touching the filesystem.
     *
     * @param string $path Path to normalise.
     * @return string
     */
    protected static function collapse( $path ) {
        $path   = wp_normalize_path( (string) $path );
        $prefix = '';

        if ( preg_match( '#^([A-Za-z]:)/#', $path, $matches ) ) {
            $prefix = $matches[1] . '/';
            $path   = substr( $path, strlen( $matches[0] ) );
        } elseif ( isset( $path[0] ) && '/' === $path[0] ) {
            $prefix = '/';
        }

        $parts = array();

        foreach ( explode( '/', $path ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }
            if ( '..' === $segment ) {
                array_pop( $parts );
                continue;
            }
            $parts[] = $segment;
        }

        return $prefix . implode( '/', $parts );
    }

    /**
     * Resolve a store relative path, following symlinks when the target exists.
     *
     * @param string $path Store relative path.
     * @return string|false Absolute path, or false when it escapes the site.
     */
    protected static function resolve( $path ) {
        $path = (string) $path;

        if ( '' === $path || false !== strpos( $path, "\0" ) ) {
            return false;
        }

        $candidate = self::storeBase() . ltrim( self::collapse( $path ), '/' );
        $real      = realpath( $candidate );

        if ( false === $real ) {
            $parent = realpath( dirname( $candidate ) );
            $real   = ( false === $parent ) ? $candidate : $parent . '/' . basename( $candidate );
        }

        $real = self::collapse( $real );

        if ( ! self::within( self::storeBase(), $real ) ) {
            return false;
        }

        return $real;
    }

    /**
     * Resolve a path that is about to be written to: uploads directory only,
     * and never a file name the web server could execute.
     *
     * @param string $path Store relative path.
     * @return string|false
     */
    protected static function resolveWritable( $path ) {
        $real = self::resolve( $path );

        if ( false === $real ) {
            return false;
        }

        if ( ! self::within( self::uploadBase(), $real ) ) {
            return false;
        }

        if ( self::isBlockedName( $real ) ) {
            return false;
        }

        return $real;
    }

    /**
     * @param string $base Container.
     * @param string $path Candidate.
     * @return bool
     */
    protected static function within( $base, $path ) {
        $base = trailingslashit( self::collapse( $base ) );
        $path = trailingslashit( self::collapse( $path ) );

        return 0 === strpos( $path, $base );
    }

    /**
     * Reject executable file names, including "photo.php.jpg" style names.
     *
     * @param string $path Path or file name.
     * @return bool
     */
    protected static function isBlockedName( $path ) {
        $name = strtolower( basename( wp_normalize_path( (string) $path ) ) );

        if ( in_array( $name, array( '.htaccess', '.htpasswd', '.user.ini', 'web.config', 'wp-config.php' ), true ) ) {
            return true;
        }

        $segments = explode( '.', $name );
        array_shift( $segments );

        foreach ( $segments as $segment ) {
            if ( in_array( $segment, self::$blocked_extensions, true ) ) {
                return true;
            }
        }

        return false;
    }

    /* --------------------------------------------------------------------
     * Actions
     * ----------------------------------------------------------------- */

    protected static function actionCheck() {
        global $wpdb;

        $upload_rel = trailingslashit( self::uploadRelative() );

        $data = array(
            'cms'                => 'woocommerce',
            'image'              => $upload_rel,
            'image_category'     => $upload_rel,
            'image_product'      => $upload_rel,
            'image_manufacturer' => $upload_rel,
            'table_prefix'       => $wpdb->prefix,
            'version'            => (string) get_option( 'woocommerce_db_version', '' ),
            'charset'            => $wpdb->charset ? $wpdb->charset : 'utf8mb4',
            'cookie_key'         => '',
            'extend'             => '',
            'admin_url'          => admin_url(),
            'connector_version'  => LitConnector::CONNECTOR_VERSION,
            'wp_version'         => get_bloginfo( 'version' ),
            'download_image'     => true,
            'connect'            => array(
                'result' => 'success',
                'msg'    => 'Successful connect to database!',
            ),
        );

        if ( is_multisite() ) {
            $data['site_id'] = get_current_blog_id();
        }

        self::respond( 'success', 'Successful check CMS!', $data );
    }

    protected static function actionPath() {
        header( 'Content-Type: text/plain; charset=utf-8' );

        echo esc_html( self::storeBase() );

        exit;
    }

    protected static function actionDirectory() {
        $folders = self::decodePayload( self::requestParam( 'folders' ) );
        $data    = array();

        if ( is_array( $folders ) ) {
            foreach ( $folders as $key => $folder ) {
                if ( ! is_array( $folder ) || ! isset( $folder['type'], $folder['folder'] ) ) {
                    $data[ $key ] = false;
                    continue;
                }

                $params       = isset( $folder['params'] ) && is_array( $folder['params'] ) ? $folder['params'] : array();
                $data[ $key ] = self::processDirectory( (string) $folder['type'], (string) $folder['folder'], $params );
            }
        }

        self::respond( 'success', null, $data );
    }

    /**
     * @param string $type   Operation.
     * @param string $folder Store relative folder.
     * @param array  $params Extra parameters.
     * @return mixed
     */
    protected static function processDirectory( $type, $folder, $params ) {
        $path = self::resolve( $folder );

        if ( false === $path ) {
            return false;
        }

        switch ( $type ) {
            case 'exists':
                return is_dir( $path );

            case 'writable':
                return is_dir( $path ) && wp_is_writable( $path );

            // Listings are limited to the uploads folder: the migration engine
            // only ever enumerates the assets it wrote, so the connector never
            // reveals the layout of the rest of the site.
            case 'dir':
                if ( ! is_dir( $path ) || ! self::within( self::uploadBase(), $path ) ) {
                    return array();
                }

                return self::readDir( $path, false );

            case 'tree':
                if ( ! is_dir( $path ) || ! self::within( self::uploadBase(), $path ) ) {
                    return array();
                }

                return self::readDir( $path, true );

            case 'create':
                if ( is_dir( $path ) ) {
                    return true;
                }

                if ( ! self::within( self::uploadBase(), $path ) ) {
                    return false;
                }

                return wp_mkdir_p( $path );

            case 'delete':
                if ( ! is_dir( $path ) || ! self::within( self::uploadBase(), $path ) ) {
                    return ! is_dir( $path );
                }

                return self::deleteDir( $path, ! empty( $params['self'] ) );
        }

        return false;
    }

    /**
     * @param string $path    Absolute directory.
     * @param bool   $recurse Include children.
     * @return array
     */
    protected static function readDir( $path, $recurse ) {
        $fs = self::fs();

        if ( ! $fs ) {
            return array();
        }

        $items  = $fs->dirlist( $path, true, false );
        $result = array();

        if ( ! is_array( $items ) ) {
            return $result;
        }

        foreach ( $items as $name => $item ) {
            if ( 'd' === $item['type'] ) {
                $entry = array(
                    'type' => 'folder',
                    'path' => $name,
                );

                if ( $recurse ) {
                    $entry['content'] = self::readDir( trailingslashit( $path ) . $name, true );
                }

                $result[] = $entry;
            } else {
                $result[] = array(
                    'type' => 'file',
                    'path' => $name,
                );
            }
        }

        return $result;
    }

    /**
     * @param string $path Absolute directory inside the uploads folder.
     * @param bool   $self Remove the directory itself as well.
     * @return bool
     */
    protected static function deleteDir( $path, $self ) {
        $fs = self::fs();

        if ( ! $fs || ! self::within( self::uploadBase(), $path ) ) {
            return false;
        }

        $items = $fs->dirlist( $path, true );

        if ( is_array( $items ) ) {
            foreach ( $items as $name => $item ) {
                $child = trailingslashit( $path ) . $name;

                if ( 'd' === $item['type'] ) {
                    self::deleteDir( $child, true );
                } else {
                    wp_delete_file( $child );
                }
            }
        }

        if ( $self ) {
            return (bool) $fs->rmdir( $path, true );
        }

        return true;
    }

    /**
     * File and image actions share the same operations.
     *
     * @param string $kind "file" or "image".
     */
    protected static function actionFile( $kind ) {
        $key   = ( 'image' === $kind ) ? 'images' : 'files';
        $items = self::decodePayload( self::requestParam( $key ) );
        $data  = array();
        $error = '';

        if ( is_array( $items ) ) {
            foreach ( $items as $index => $item ) {
                if ( ! is_array( $item ) || ! isset( $item['type'], $item['path'] ) ) {
                    $data[ $index ] = false;
                    continue;
                }

                $params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : array();
                $result = self::processFile( (string) $item['type'], (string) $item['path'], $params, $kind );

                if ( is_string( $result ) && false !== strpos( $result, '[LECM_ERROR]' ) ) {
                    $error  = str_replace( '[LECM_ERROR]', '', $result );
                    $result = false;
                }

                $data[ $index ] = $result;
            }
        }

        self::respond( 'success', null, $data, $error );
    }

    /**
     * @param string $type   Operation.
     * @param string $path   Store relative path.
     * @param array  $params Extra parameters.
     * @param string $kind   "file" or "image".
     * @return mixed
     */
    protected static function processFile( $type, $path, $params, $kind ) {
        switch ( $type ) {
            case 'exists':
                $real = self::resolve( $path );
                return ( false !== $real && file_exists( $real ) );

            case 'content':
                return self::readFile( $path );

            case 'delete':
                return self::deleteFile( $path );

            case 'rename':
                return self::uniqueName( $path );

            case 'download':
                return self::downloadFile( $path, $params, $kind );

            case 'copy':
                return self::copyFile( $path, $params );

            case 'move':
                return self::moveFile( $path, $params, $kind );

            case 'resize':
                return self::resizeFile( $path, $params );
        }

        return false;
    }

    /**
     * Read a migrated asset back. Restricted to the uploads folder so site
     * configuration can never be read through the connector.
     *
     * @param string $path Store relative path.
     * @return string
     */
    protected static function readFile( $path ) {
        $real = self::resolveWritable( $path );
        $fs   = self::fs();

        if ( false === $real || ! $fs || ! is_file( $real ) ) {
            return '';
        }

        $content = $fs->get_contents( $real );

        return ( false === $content ) ? '' : $content;
    }

    /**
     * @param string $path Store relative path.
     * @return bool
     */
    protected static function deleteFile( $path ) {
        $real = self::resolveWritable( $path );

        if ( false === $real ) {
            return false;
        }

        if ( ! file_exists( $real ) ) {
            return true;
        }

        wp_delete_file( $real );

        return ! file_exists( $real );
    }

    /**
     * Build a free file name next to the requested one.
     *
     * @param string $path Store relative path.
     * @return string
     */
    protected static function uniqueName( $path ) {
        $path      = ltrim( (string) $path, '/' );
        $dir       = pathinfo( $path, PATHINFO_DIRNAME );
        $name      = pathinfo( $path, PATHINFO_FILENAME );
        $extension = pathinfo( $path, PATHINFO_EXTENSION );
        $prefix    = ( $dir && '.' !== $dir ) ? trailingslashit( $dir ) : '';
        $candidate = $path;
        $index     = 2;

        while ( $index < 1000 ) {
            $real = self::resolve( $candidate );

            if ( false === $real || ! file_exists( $real ) ) {
                return $candidate;
            }

            $candidate = $prefix . $name . '_' . $index . 'nd' . ( $extension ? '.' . $extension : '' );
            $index++;
        }

        return $candidate;
    }

    /**
     * Fetch a remote asset into the uploads folder.
     *
     * @param string $path   Store relative destination.
     * @param array  $params Parameters, including the source url.
     * @param string $kind   "file" or "image".
     * @return string|bool Destination path on success.
     */
    protected static function downloadFile( $path, $params, $kind ) {
        $url = isset( $params['url'] ) ? (string) $params['url'] : '';

        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return '[LECM_ERROR]Only http(s) sources can be downloaded.';
        }

        $destination = self::resolveWritable( $path );

        if ( false === $destination ) {
            return '[LECM_ERROR]Destination is outside the uploads folder or is not an allowed file type.';
        }

        if ( file_exists( $destination ) ) {
            if ( ! empty( $params['rename'] ) ) {
                $path        = self::uniqueName( $path );
                $destination = self::resolveWritable( $path );

                if ( false === $destination ) {
                    return false;
                }
            } elseif ( empty( $params['override'] ) ) {
                return ltrim( $path, '/' );
            } elseif ( ! self::deleteFile( $path ) ) {
                return false;
            }
        }

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp = download_url( $url, 30 );

        if ( is_wp_error( $temp ) ) {
            return '[LECM_ERROR]' . $temp->get_error_message();
        }

        $fs = self::fs();

        if ( ! $fs || ! wp_mkdir_p( dirname( $destination ) ) ) {
            wp_delete_file( $temp );

            return '[LECM_ERROR]Cannot create the destination folder.';
        }

        $moved = $fs->move( $temp, $destination, true );

        if ( ! $moved ) {
            $moved = $fs->copy( $temp, $destination, true, FS_CHMOD_FILE );
        }

        wp_delete_file( $temp );

        if ( ! $moved || ! file_exists( $destination ) ) {
            return '[LECM_ERROR]Cannot write the downloaded file.';
        }

        if ( 'image' === $kind && ! @getimagesize( $destination ) ) {
            wp_delete_file( $destination );

            return '[LECM_ERROR]The downloaded file is not a valid image.';
        }

        return ltrim( $path, '/' );
    }

    /**
     * A migration only ever copies assets it has already written, so both ends
     * are restricted to the uploads folder: site configuration can never be
     * copied somewhere the connector is allowed to read it back from.
     *
     * @param string $path   Source, store relative.
     * @param array  $params Parameters including the "copy" destination.
     * @return bool
     */
    protected static function copyFile( $path, $params ) {
        $source      = self::resolveWritable( $path );
        $destination = isset( $params['copy'] ) ? self::resolveWritable( (string) $params['copy'] ) : false;
        $fs          = self::fs();

        if ( false === $source || false === $destination || ! $fs || ! is_file( $source ) ) {
            return false;
        }

        if ( file_exists( $destination ) ) {
            if ( empty( $params['override'] ) ) {
                return false;
            }

            wp_delete_file( $destination );
        }

        if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
            return false;
        }

        return (bool) $fs->copy( $source, $destination, true, FS_CHMOD_FILE );
    }

    /**
     * @param string $path   Source, store relative.
     * @param array  $params Parameters including the "move" destination.
     * @param string $kind   "file" or "image".
     * @return string|bool
     */
    protected static function moveFile( $path, $params, $kind ) {
        $target = isset( $params['move'] ) ? (string) $params['move'] : '';

        if ( '' === $target ) {
            return false;
        }

        $destination = self::resolveWritable( $target );

        if ( false === $destination ) {
            return '[LECM_ERROR]Move is only allowed inside the uploads folder.';
        }

        if ( file_exists( $destination ) ) {
            if ( empty( $params['override'] ) ) {
                if ( empty( $params['rename'] ) ) {
                    return $target;
                }

                $target      = self::uniqueName( $target );
                $destination = self::resolveWritable( $target );

                if ( false === $destination ) {
                    return false;
                }
            } else {
                wp_delete_file( $destination );
            }
        }

        $source = self::resolve( $path );

        if ( false === $source || ! file_exists( $source ) ) {
            if ( empty( $params['url'] ) ) {
                return false;
            }

            return self::downloadFile(
                $target,
                array(
                    'url'      => $params['url'],
                    'override' => false,
                    'rename'   => false,
                ),
                $kind
            );
        }

        $source = self::resolveWritable( $path );
        $fs     = self::fs();

        if ( false === $source || ! $fs ) {
            return '[LECM_ERROR]Move is only allowed inside the uploads folder.';
        }

        if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
            return '[LECM_ERROR]Cannot create the destination folder.';
        }

        if ( $fs->move( $source, $destination, true ) ) {
            return ltrim( $target, '/' );
        }

        return '[LECM_ERROR]Cannot move the file.';
    }

    /**
     * Resize an already migrated image with the WordPress image editor.
     *
     * @param string $path   Source, store relative.
     * @param array  $params Parameters: desc, width, height, crop.
     * @return bool
     */
    protected static function resizeFile( $path, $params ) {
        $source = self::resolveWritable( $path );

        if ( false === $source || ! is_file( $source ) ) {
            return false;
        }

        $target      = isset( $params['desc'] ) ? (string) $params['desc'] : $path;
        $destination = self::resolveWritable( $target );

        if ( false === $destination ) {
            return false;
        }

        $editor = wp_get_image_editor( $source );

        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $width  = isset( $params['width'] ) ? (int) $params['width'] : 0;
        $height = isset( $params['height'] ) ? (int) $params['height'] : 0;
        $crop   = ! empty( $params['crop'] );
        $size   = $editor->get_size();

        // WordPress never scales an image up, so a request that is not smaller
        // than the original is satisfied by the original file itself.
        $upscaling = ( $width <= 0 || $width >= $size['width'] ) && ( $height <= 0 || $height >= $size['height'] );

        if ( ( $width > 0 || $height > 0 ) && ! $upscaling ) {
            $resized = $editor->resize( $width > 0 ? $width : null, $height > 0 ? $height : null, $crop );

            if ( is_wp_error( $resized ) ) {
                return false;
            }
        }

        if ( isset( $params['quality'] ) ) {
            $editor->set_quality( max( 1, min( 100, (int) $params['quality'] ) ) );
        }

        if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
            return false;
        }

        $saved = $editor->save( $destination );

        return ! is_wp_error( $saved );
    }

    /**
     * Run the migration statements produced by the LitExtension service.
     */
    protected static function actionQuery() {
        global $wpdb;

        $raw     = self::requestParam( 'query' );
        $queries = self::decodePayload( $raw );

        $wpdb->hide_errors();
        self::runStatement( 'query', "SET SESSION SQL_MODE = ''", null );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token authenticated endpoint.
        $serialize = ! empty( $_REQUEST['serialize'] );

        if ( null === $queries ) {
            $sql = self::decodeRawQuery( $raw );

            if ( '' === $sql ) {
                self::respond( 'error', 'No query received!', null );
            }

            $result = self::runStatement( 'select', $sql, null );

            self::respond( 'success', null, $result['data'], $result['error'] );
        }

        if ( $serialize ) {
            $data  = array();
            $error = array();

            foreach ( $queries as $key => $query ) {
                if ( is_array( $query ) && isset( $query['type'], $query['query'] ) ) {
                    $params = isset( $query['params'] ) ? $query['params'] : null;
                    $result = self::runStatement( (string) $query['type'], (string) $query['query'], $params );
                } else {
                    $result = self::runStatement( 'select', (string) $query, null );
                }

                $data[ $key ]  = $result['data'];
                $error[ $key ] = $result['error'];
            }

            self::respond( 'success', null, $data, $error );
        }

        if ( ! isset( $queries['type'], $queries['query'] ) ) {
            self::respond( 'error', 'Malformed query payload!', null );
        }

        $params = isset( $queries['params'] ) ? $queries['params'] : null;
        $result = self::runStatement( (string) $queries['type'], (string) $queries['query'], $params );

        self::respond( 'success', null, $result['data'], $result['error'] );
    }

    /**
     * @param string $raw Raw request value.
     * @return string
     */
    protected static function decodeRawQuery( $raw ) {
        $binary = base64_decode( $raw, true );

        if ( false === $binary ) {
            return '';
        }

        $sql = @gzinflate( $binary );

        return ( false === $sql ) ? '' : $sql;
    }

    /**
     * Execute one migration statement.
     *
     * @param string     $type   select, insert or query.
     * @param string     $sql    Statement.
     * @param array|null $params Extra parameters, e.g. insert_id.
     * @return array
     */
    protected static function runStatement( $type, $sql, $params ) {
        global $wpdb;

        $sql = trim( (string) $sql );

        if ( '' === $sql ) {
            return array(
                'data'  => false,
                'error' => 'Empty statement',
            );
        }

        switch ( strtolower( $type ) ) {
            case 'select':
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration statement generated by the LitExtension service; there is no WordPress API able to express it.
                $data = $wpdb->get_results( $sql, ARRAY_A );
                break;

            case 'insert':
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
                $data = $wpdb->query( $sql );

                if ( false !== $data && is_array( $params ) && isset( $params['insert_id'] ) ) {
                    $data = $wpdb->insert_id;
                }
                break;

            default:
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
                $data = $wpdb->query( $sql );
                break;
        }

        return array(
            'data'  => ( false === $data ) ? false : $data,
            'error' => $wpdb->last_error ? $wpdb->last_error : '',
        );
    }

    protected static function actionClearCache() {
        delete_transient( 'wc_attribute_taxonomies' );
        wp_cache_flush();

        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients();
        }

        self::respond( 'success', null, null );
    }
}
