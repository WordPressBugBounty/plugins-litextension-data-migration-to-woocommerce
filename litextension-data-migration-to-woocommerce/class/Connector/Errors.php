<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Connector_Errors {

    const MODULE_DEFAULT                          = 2612;
    const MODULE_CONNECTOR_ALREADY_INSTALLED      = 2613;
    const MODULE_CONNECTOR_SUCCESSFULLY_INSTALLED = 2614;

    const MODULE_ERROR_DEFAULT              = 'defaultError';
    const MODULE_ERROR_EMPTY_TOKEN          = 2615;
    const MODULE_ERROR_ROOT_DIR_PERMISSION  = 2616;
    const MODULE_ERROR_INSTALLED_PERMISSION = 2617;
    const MODULE_ERROR_PERMISSION           = 2618;
    const CONNECTOR_FILE_PERMISSION         = 2619;
    const MODULE_ERROR_TEMPLATE_MISSING     = 2620;
    const MODULE_ERROR_UPLOAD_OUTSIDE_ROOT  = 2621;

    /**
     * Translated error messages, keyed by error code.
     *
     * @return array
     */
    protected static function messages() {
        return array(
            self::CONNECTOR_FILE_PERMISSION         => __( 'Bad permission for Connector file.', 'litextension-data-migration-to-woocommerce' ),
            self::MODULE_ERROR_DEFAULT              => __( 'Connector Install action executed successfully.', 'litextension-data-migration-to-woocommerce' ),
            self::MODULE_ERROR_EMPTY_TOKEN          => __( 'The connector token could not be generated. Please try again.', 'litextension-data-migration-to-woocommerce' ),
            self::MODULE_ERROR_ROOT_DIR_PERMISSION  => __( 'The plugin folder is not writable. Please make it writable for the web server user, or install the Connector manually. If this error persists, please contact our Support Team.', 'litextension-data-migration-to-woocommerce' ),
            self::MODULE_ERROR_INSTALLED_PERMISSION => __( 'The WordPress uploads folder is not available. Please make sure wp-content/uploads exists and is writable by the web server user.', 'litextension-data-migration-to-woocommerce' ),
            self::MODULE_ERROR_PERMISSION           => __( 'WordPress could not write to the plugin folder. Please make sure the plugin directory is writable by the web server user. If this error persists, please contact our Support Team.', 'litextension-data-migration-to-woocommerce' ),
            self::MODULE_ERROR_TEMPLATE_MISSING     => __( 'The bundled connector file is missing from the plugin. Please reinstall the plugin.', 'litextension-data-migration-to-woocommerce' ),
            self::MODULE_ERROR_UPLOAD_OUTSIDE_ROOT  => __( 'The uploads folder is located outside the WordPress root, so the connector cannot be installed automatically. Please contact our Support Team.', 'litextension-data-migration-to-woocommerce' ),
        );
    }

    /**
     * @param int|string $code Error code.
     * @return string
     */
    public static function getErrorMessage( $code ) {
        $messages = self::messages();

        return isset( $messages[ $code ] ) ? $messages[ $code ] : $messages[ self::MODULE_ERROR_DEFAULT ];
    }
}
