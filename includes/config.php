<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php
if (!defined('UNIFIED_PROTOCOL')) {
    define('UNIFIED_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('UNIFIED_HOST')) {
    define('UNIFIED_HOST', 'rt.app');
}

if (!defined('UNIFIED_BASE_URL')) {
	define('UNIFIED_BASE_URL', UNIFIED_PROTOCOL . UNIFIED_HOST);
}

if (!defined('UNIFIED_PLUGIN_VERSION')) {
    define('UNIFIED_PLUGIN_VERSION', '1.0.2');
}
