<?php

namespace LitExtension;

/**
 * Class LitMain
 */
class LitMain
{
    //const APP_LINK = 'http://127.0.0.1/laravel/cartmigration_ui_ver3/public/';
    const APP_LINK = 'https://app.litextension.com/';
    const APP_LINK_LOGIN = 'https://api.litextension.com/';
    const APP_LINK_HOME = 'https://litextension.com/';

	public $litView;
	public $litType;

	public function __construct()
	{
		$this->litView = new LitView();
        $this->litType = new LitType();
		$this->_addActions();
	}

	public static function init(){
		new self;
	}

	public function _addActions(){
        add_action('admin_init', array($this, 'startLitSession'));
		add_action('admin_menu', array($this, 'createMenuAdminPanel'));
        add_action('admin_init', array($this, 'enqueue_scripts'));
//		add_action('login_head', array($this, 'add_favicon'));
//		add_action('admin_head', array($this, 'add_favicon'));
//        add_action('wp_logout', array($this, 'clearLitSession'));
//        add_action('wp_login', array($this, 'clearLitSession'));
	}

	public function createMenuAdminPanel(){
	    add_menu_page('LitExtension', 'LitExtension', 'manage_options', 'litextension', 'litMigration', LIT_URL_PLUGIN . 'assets/images/logo.png');
	    add_submenu_page('litextension', 'Shopping Cart to WooCommerce Migration', 'Shopping Cart to WooCommerce Migration', 'manage_options', 'migrate-to-woocommerce', array($this, 'migrateToWooCommerce'));

        add_submenu_page('', '', '', 'manage_options', 'add-session', array($this, 'litAddSession'));
        add_submenu_page('', '', '', 'manage_options', 'clear-session', array($this, 'litClearSession'));
        add_submenu_page('', '', '', 'manage_options', 'install-connector', array($this, 'litInstallConnector'));

	    remove_submenu_page('litextension', 'litextension');
	}

	public function add_favicon() {
	    $favicon_url = LIT_URL_PLUGIN . 'assets/images/favicon.ico';
	    echo '<link rel="shortcut icon" href="' . esc_url($favicon_url) . '" />';
	}

	public function startLitSession() {
        if(!session_id()) {
            session_start();
        }
    }

    public function clearLitSession() {
        session_write_close();
        session_destroy ();
    }

    public function enqueue_scripts(){
        $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);;
        if($page && ($page == 'litextension' || $page == 'migrate-to-woocommerce' )) {
            wp_enqueue_style('custom-style-lit', plugins_url('../assets/css/litextension.css',__FILE__ ), [], 1.0);
            wp_enqueue_script('custom-script-lit', plugins_url('../assets/js/litextension.js',__FILE__ ), array('jquery'),
                '0.1',
                false);
        }
    }

    public function migrateToWooCommerce(){
        $src = 'target_url=' . esc_url(get_home_url()) . '&app_mode=true';
        $_param = array(
            'src' => $src,
            'url_register' => self::APP_LINK . 'register',
            'url_forgot' => self::APP_LINK . 'forgot-password',
            'plugin_url' => plugins_url('..', __FILE__),
            'app_link' => self::APP_LINK_LOGIN. 'api/app-login',
            'list_cart' => $this->litType->sourceCarts()
        );
	    $this->litView->litView('index', $_param);
	}

	public function pricing(){
//	    $src = self::APP_LINK_HOME . 'pricing';
//	    echo $this->litView->litView('index', $src);
	}

	public function howItWorks(){
//	    $src = self::APP_LINK_HOME . 'how-litextension-works/migrate-from-Shopping-Carts-to-woocommerce.html';
//	    echo $this->litView->litView('index', $src);
	}

	public function additionalServices(){}

	public function liveChatHelp(){}

	public function litAddSession(){
        $lit_email  = filter_input(INPUT_GET, 'litEmail', FILTER_SANITIZE_EMAIL);
	    if ($lit_email){
            $_SESSION['lit-login-plugin'] = sanitize_email($lit_email);
            $_SESSION['lit-security-token'] = sanitize_text_field(filter_input(INPUT_GET, 'security_token', FILTER_SANITIZE_SPECIAL_CHARS));
        }
    }

    public function litClearSession(){
        if (isset($_SESSION['lit-login-plugin'])){
            unset($_SESSION['lit-login-plugin']);
            unset($_SESSION['security_token']);
        }
    }

    public function litInstallConnector(){
		$token = filter_input(INPUT_GET, 'token', FILTER_SANITIZE_SPECIAL_CHARS);;
		if(!$token){
	        $token = '';
		}
	    $connector = new LitConnector();
        echo "<p id='litextension-response'>".esc_html($connector->execute(LitConnector::ACTION_INSTALL, $token))."</p>";
    }

	public function redirect($url){
		echo  '<script type="text/javascript">'.
	     'window.location = "' . esc_url($url) . '"' .
	    '</script>';
	}
}