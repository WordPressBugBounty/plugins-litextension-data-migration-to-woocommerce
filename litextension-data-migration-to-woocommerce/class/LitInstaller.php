<?php

namespace LitExtension;

/**
 * Class LitInstaller
 * Function: litActivate, litDeactive, litUninstall
 */
class LitInstaller
{
	public static function litActivate(){
		add_option('_lit_litextension_version', LIT_VERSION, '', 'yes');
	}

	public static function litDeactivate(){
        update_option( '_lit_litextension_version', get_option( '_lit_litextension_version', LIT_VERSION ), 'no' );
	}

	public static function litUninstall(){
    	delete_option('_lit_litextension_version');
	}
}