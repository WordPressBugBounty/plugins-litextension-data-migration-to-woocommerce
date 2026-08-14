<?php

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LitInstaller
 *
 * Activation, deactivation, uninstall and the one time upgrade routine that
 * revokes connectors installed by a vulnerable release.
 */
class LitInstaller {

    const OPTION_VERSION           = '_lit_litextension_version';
    const OPTION_CONNECTOR_REVOKED = '_lit_connector_revoked';

    /**
     * Releases up to and including this one could have their connector token
     * rewritten through a forged request, so any connector left behind by them
     * is removed on upgrade.
     */
    const LAST_VULNERABLE_VERSION = '1.2.5';

    public static function litActivate() {
        add_option( self::OPTION_VERSION, LIT_VERSION, '', 'yes' );

        self::maybeUpgrade();
    }

    public static function litDeactivate() {
        update_option( self::OPTION_VERSION, get_option( self::OPTION_VERSION, LIT_VERSION ), 'no' );
    }

    public static function litUninstall() {
        $connector = new LitConnector();
        $connector->forceRemove();

        delete_option( self::OPTION_VERSION );
        delete_option( self::OPTION_CONNECTOR_REVOKED );
        delete_option( LitConnector::OPTION_TOKEN );
        delete_option( LitConnector::OPTION_TOKEN_HASH );
        delete_option( LitConnector::OPTION_INSTALLED_AT );

        delete_metadata( 'user', 0, LitMain::META_EMAIL, '', true );
        delete_metadata( 'user', 0, LitMain::META_TOKEN, '', true );
    }

    /**
     * Runs once after the plugin files are updated.
     *
     * A connector installed by an affected release may carry a token chosen by
     * an attacker, so it is deleted and the token revoked. The store owner is
     * told to reinstall it from the plugin screen.
     */
    public static function maybeUpgrade() {
        $stored = (string) get_option( self::OPTION_VERSION, '' );

        if ( LIT_VERSION === $stored ) {
            return;
        }

        if ( '' === $stored || version_compare( $stored, self::LAST_VULNERABLE_VERSION, '<=' ) ) {
            $connector = new LitConnector();

            if ( $connector->isConnectorExist() ) {
                $connector->forceRemove();
                update_option( self::OPTION_CONNECTOR_REVOKED, 1, false );
            } else {
                // Revoke any token stored by an affected release even when the
                // connector file itself is already gone.
                delete_option( LitConnector::OPTION_TOKEN );
                delete_option( LitConnector::OPTION_TOKEN_HASH );
            }
        }

        update_option( self::OPTION_VERSION, LIT_VERSION, 'yes' );
    }
}
