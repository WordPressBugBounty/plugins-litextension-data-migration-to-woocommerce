<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LitView
 * Function: litView
 */
class LitView {

    /**
     * @param string $file
     * @param array  $_param
     * @return void
     * @throws \Exception
     */
    public function litView( $file, $_param ) {
        $file = (string) $file;

        // Only allow safe view names (no ../, no slashes).
        if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $file ) ) {
            /* translators: %s: View name. */
            throw new \Exception(sprintf(esc_html__( 'Invalid view name: %s', 'litextension-data-migration-to-woocommerce' ), esc_html( $file )));
        }

        $views_dir = trailingslashit( LIT_PATH_PLUGIN . 'views' );
        $filePath  = $views_dir . $file . '.phtml';

        $base_real = realpath( $views_dir );
        $file_real = realpath( $filePath );

        if ( false === $base_real || false === $file_real ) {
            /* translators: %s: File path. */
            throw new \Exception(sprintf(esc_html__( 'File "%s" not found!', 'litextension-data-migration-to-woocommerce' ), esc_html( $filePath )));
        }

        // Normalize paths then ensure the resolved file is inside the views directory.
        $base_real = trailingslashit( wp_normalize_path( $base_real ) );
        $file_real = wp_normalize_path( $file_real );

        if ( 0 !== strpos( $file_real, $base_real ) ) {
            /* translators: %s: File path. */
            throw new \Exception(sprintf(esc_html__( 'File "%s" not allowed!', 'litextension-data-migration-to-woocommerce' ), esc_html( $filePath )));
        }

        // Make params available to the view.
        if ( is_array( $_param ) ) {
            extract( $_param, EXTR_SKIP );
        }

        // Semgrep flags variable require args; path is strictly validated above.
        // nosemgrep: audit.php.lang.security.file.inclusion-arg
        require $file_real;
    }
}
