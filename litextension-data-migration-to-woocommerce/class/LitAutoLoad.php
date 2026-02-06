<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LitAutoLoad
 */
class LitAutoLoad {
    /**
     * @var string|null
     */
    private static $_loadDir = null;

    public static function init() {
        if ( null === self::$_loadDir ) {
            self::$_loadDir = plugin_dir_path( __FILE__ );
        }

        spl_autoload_register( array( __CLASS__, 'load' ) );
    }

    /**
     * Autoload classes in the LitExtension namespace only.
     *
     * @param string $className Fully-qualified class name.
     * @return bool
     */
    public static function load( $className ) {
        $prefix = __NAMESPACE__ . '\\';

        // Only handle our own namespace.
        if ( 0 !== strpos( (string) $className, $prefix ) ) {
            return false;
        }

        // Only allow expected characters.
        if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', (string) $className ) ) {
            return false;
        }

        $relative = substr( (string) $className, strlen( $prefix ) );
        if ( '' === $relative ) {
            return false;
        }

        $relative_path = str_replace( array( '\\', '_' ), '/', $relative ) . '.php';

        $base_dir  = trailingslashit( self::$_loadDir );
        $file_path = $base_dir . $relative_path;

        $base_real = realpath( $base_dir );
        $file_real = realpath( $file_path );

        if ( false === $base_real || false === $file_real ) {
            return false;
        }

        // Normalize paths for reliable prefix checking.
        $base_real = trailingslashit( wp_normalize_path( $base_real ) );
        $file_real = wp_normalize_path( $file_real );

        // Ensure the resolved file is within base directory.
        if ( 0 !== strpos( $file_real, $base_real ) ) {
            return false;
        }

        if ( is_readable( $file_real ) ) {
            // Semgrep flags variable require args; path is strictly validated above.
            // nosemgrep: audit.php.lang.security.file.inclusion-arg
            require_once $file_real;
            return true;
        }

        return false;
    }
}
