<?php
/**
 * Plugin Name: NOVA Bridge Suite
 * Description: Connects NOVA to WordPress so your SEO automation can update pages and layouts the standard API cannot reach.
 * Version: 2.4.7
 * Author: LUNA B.V.
 * Requires PHP: 7.4
 * License: Proprietary
 * Text Domain: nova-bridge-suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOVA_BRIDGE_SUITE_VERSION', '2.4.7' );
define( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE', __FILE__ );
define( 'NOVA_BRIDGE_SUITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOVA_BRIDGE_SUITE_OPTION', 'nova_bridge_settings' );
define( 'NOVA_BRIDGE_SUITE_VERSION_OPTION', 'nova_bridge_suite_version' );
function nova_bridge_suite_normalize_server_globals(): void {
    if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) || '' === $_SERVER['REQUEST_URI'] ) {
        $_SERVER['REQUEST_URI'] = '/';
    }

    if ( ! isset( $_SERVER['HTTP_HOST'] ) || ! is_string( $_SERVER['HTTP_HOST'] ) || '' === $_SERVER['HTTP_HOST'] ) {
        $home_url = get_option( 'home' );
        if ( is_string( $home_url ) && '' !== $home_url ) {
            $host = wp_parse_url( $home_url, PHP_URL_HOST );
            if ( is_string( $host ) && '' !== $host ) {
                $_SERVER['HTTP_HOST'] = $host;
            }
        }
    }
}

nova_bridge_suite_normalize_server_globals();

function nova_bridge_suite_is_admin_content_write_request(): bool {
    if ( ! is_admin() ) {
        return false;
    }

    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }

    $request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
        ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
        : '';

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
        ? (string) $_SERVER['REQUEST_URI']
        : '';

    $action = '';
    if ( isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ) {
        $action = sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) );
    }

    $action2 = '';
    if ( isset( $_REQUEST['action2'] ) && is_scalar( $_REQUEST['action2'] ) ) {
        $action2 = sanitize_key( wp_unslash( (string) $_REQUEST['action2'] ) );
    }

    $content_actions = [
        'editpost',
        'delete',
        'deletepost',
        'delete-post',
        'delete-selected',
        'trash',
        'untrash',
        'inline-save',
    ];

    if ( in_array( $action, $content_actions, true ) || in_array( $action2, $content_actions, true ) ) {
        return true;
    }

    if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
        $ajax_content_actions = [
            'elementor_ajax',
        ];

        if ( in_array( $action, $ajax_content_actions, true ) ) {
            return true;
        }
    }

    if ( 'POST' !== $request_method ) {
        return false;
    }

    return false !== strpos( $request_uri, '/wp-admin/post.php' )
        || false !== strpos( $request_uri, '/wp-admin/edit.php' );
}

function nova_bridge_suite_is_rest_request(): bool {
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return true;
    }

    if ( isset( $_GET['rest_route'] ) && is_scalar( $_GET['rest_route'] ) && '' !== trim( (string) wp_unslash( $_GET['rest_route'] ) ) ) {
        return true;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
        ? rawurldecode( (string) $_SERVER['REQUEST_URI'] )
        : '';

    return false !== strpos( $request_uri, '/wp-json/' );
}

function nova_bridge_suite_is_core_rest_content_write_request(): bool {
    $request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
        ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
        : '';

    if ( ! in_array( $request_method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
        return false;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
        ? rawurldecode( (string) $_SERVER['REQUEST_URI'] )
        : '';

    $rest_route = '';
    if ( isset( $_GET['rest_route'] ) && is_scalar( $_GET['rest_route'] ) ) {
        $rest_route = rawurldecode( (string) wp_unslash( $_GET['rest_route'] ) );
    }

    return false !== strpos( $request_uri, '/wp-json/wp/v2/' )
        || 0 === strpos( $rest_route, '/wp/v2/' );
}

function nova_bridge_suite_core_rest_write_uses_bridge_payload(): bool {
    $raw_body = nova_bridge_suite_get_raw_request_body();
    if ( ! is_string( $raw_body ) || '' === $raw_body ) {
        return false;
    }

    return false !== strpos( $raw_body, '"meta_all"' )
        || false !== strpos( $raw_body, '"meta_all_flat"' );
}

function nova_bridge_suite_get_raw_request_body(): string {
    static $raw_body = null;

    if ( null === $raw_body ) {
        $body = file_get_contents( 'php://input' );
        $raw_body = is_string( $body ) ? $body : '';
    }

    return $raw_body;
}

function nova_bridge_suite_get_rest_route_path(): string {
    if ( isset( $_GET['rest_route'] ) && is_scalar( $_GET['rest_route'] ) ) {
        $route = rawurldecode( (string) wp_unslash( $_GET['rest_route'] ) );
        return trim( $route, '/' );
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
        ? rawurldecode( (string) $_SERVER['REQUEST_URI'] )
        : '';

    if ( '' === $request_uri ) {
        return '';
    }

    $path = wp_parse_url( $request_uri, PHP_URL_PATH );
    if ( ! is_string( $path ) || '' === $path ) {
        return '';
    }

    $marker = '/wp-json/';
    $position = strpos( $path, $marker );

    if ( false === $position ) {
        return '';
    }

    return trim( substr( $path, $position + strlen( $marker ) ), '/' );
}

function nova_bridge_suite_rest_route_matches( string $route, string $prefix ): bool {
    $route  = trim( $route, '/' );
    $prefix = trim( $prefix, '/' );

    return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
}

function nova_bridge_suite_get_query_or_post_request_value( string $key ) {
    if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
        return wp_unslash( $_GET[ $key ] );
    }

    if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
        return wp_unslash( $_POST[ $key ] );
    }

    return null;
}

function nova_bridge_suite_get_json_scalar_request_value( string $key ) {
    $raw_body = nova_bridge_suite_get_raw_request_body();
    if ( '' === $raw_body ) {
        return null;
    }

    $quoted_key = preg_quote( wp_json_encode( $key ), '/' );
    $pattern    = '/' . $quoted_key . '\s*:\s*("(?:\\\\.|[^"\\\\])*"|-?\d+(?:\.\d+)?|true|false|null)/';

    if ( ! preg_match( $pattern, $raw_body, $matches ) ) {
        return null;
    }

    $decoded = json_decode( $matches[1], true );

    return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
}

function nova_bridge_suite_get_request_value( string $key ) {
    $value = nova_bridge_suite_get_query_or_post_request_value( $key );
    if ( null !== $value ) {
        return $value;
    }

    return nova_bridge_suite_get_json_scalar_request_value( $key );
}

function nova_bridge_suite_get_rest_route_id( string $route ): int {
    if ( preg_match( '#/(?:pages|posts|post|page)/(\d+)(?:/|$)#', '/' . trim( $route, '/' ), $matches ) ) {
        return absint( $matches[1] );
    }

    if ( preg_match( '#/(?:wp/v2/)?[a-z0-9_-]+/(\d+)(?:/|$)#i', '/' . trim( $route, '/' ), $matches ) ) {
        return absint( $matches[1] );
    }

    return 0;
}

function nova_bridge_suite_get_managed_blog_post_types(): array {
    $raw = get_option( 'quarantined_cpt_bodyclean_cpts', null );

    if ( null === $raw ) {
        return [ 'blog' ];
    }

    if ( ! is_array( $raw ) ) {
        return [];
    }

    $post_types = [];

    foreach ( $raw as $definition ) {
        if ( ! is_array( $definition ) ) {
            continue;
        }

        $post_type = isset( $definition['type'] ) ? sanitize_key( (string) $definition['type'] ) : '';

        if ( '' === $post_type && isset( $definition['slug'] ) ) {
            $post_type = sanitize_key( sanitize_title( (string) $definition['slug'] ) );
        }

        if ( '' !== $post_type ) {
            $post_types[] = substr( $post_type, 0, 20 );
        }
    }

    return array_values( array_unique( array_filter( $post_types ) ) );
}

function nova_bridge_suite_get_module_keys_for_post_type( string $post_type ): array {
    $post_type   = sanitize_key( $post_type );
    $module_keys = [];

    if ( 'service_page' === $post_type ) {
        $module_keys[] = 'service_page_cpt';
    }

    if ( '' !== $post_type && in_array( $post_type, nova_bridge_suite_get_managed_blog_post_types(), true ) ) {
        $module_keys[] = 'custom_post_types';
    }

    return array_values( array_unique( $module_keys ) );
}

function nova_bridge_suite_collect_admin_content_write_post_ids(): array {
    $post_ids = [];

    foreach ( [ 'post', 'post_ID', 'post_IDs', 'ids' ] as $key ) {
        if ( ! isset( $_REQUEST[ $key ] ) ) {
            continue;
        }

        $value = wp_unslash( $_REQUEST[ $key ] );
        $items = is_array( $value ) ? $value : explode( ',', (string) $value );

        foreach ( $items as $item ) {
            if ( is_scalar( $item ) ) {
                $post_id = absint( $item );
                if ( $post_id > 0 ) {
                    $post_ids[] = $post_id;
                }
            }
        }
    }

    return array_values( array_unique( $post_ids ) );
}

function nova_bridge_suite_get_admin_content_write_module_keys(): ?array {
    if ( ! nova_bridge_suite_is_admin_content_write_request() ) {
        return null;
    }

    $module_keys = [];

    foreach ( nova_bridge_suite_collect_admin_content_write_post_ids() as $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( is_string( $post_type ) ) {
            $module_keys = array_merge( $module_keys, nova_bridge_suite_get_module_keys_for_post_type( $post_type ) );
        }
    }

    $requested_post_type = nova_bridge_suite_get_query_or_post_request_value( 'post_type' );
    if ( is_string( $requested_post_type ) && '' !== trim( $requested_post_type ) ) {
        $module_keys = array_merge( $module_keys, nova_bridge_suite_get_module_keys_for_post_type( $requested_post_type ) );
    }

    return array_values( array_unique( $module_keys ) );
}

function nova_bridge_suite_get_target_post_type_for_rest_route( string $route ): string {
    $post_type = nova_bridge_suite_get_query_or_post_request_value( 'post_type' );

    if ( is_string( $post_type ) && '' !== trim( $post_type ) && 'any' !== $post_type ) {
        return sanitize_key( $post_type );
    }

    $source_post_id = nova_bridge_suite_get_query_or_post_request_value( 'source_post_id' );
    $post_id        = absint( $source_post_id );

    if ( $post_id <= 0 ) {
        $post_id = nova_bridge_suite_get_rest_route_id( $route );
    }

    if ( $post_id > 0 ) {
        $detected = get_post_type( $post_id );

        return is_string( $detected ) ? sanitize_key( $detected ) : '';
    }

    $post_type = nova_bridge_suite_get_json_scalar_request_value( 'post_type' );
    if ( is_string( $post_type ) && '' !== trim( $post_type ) && 'any' !== $post_type ) {
        return sanitize_key( $post_type );
    }

    $source_post_id = nova_bridge_suite_get_json_scalar_request_value( 'source_post_id' );
    $post_id        = absint( $source_post_id );

    if ( $post_id <= 0 ) {
        return '';
    }

    $detected = get_post_type( $post_id );

    return is_string( $detected ) ? sanitize_key( $detected ) : '';
}

function nova_bridge_suite_add_cpt_dependencies_for_rest_route( array $module_keys, string $route ): array {
    $post_type = nova_bridge_suite_get_target_post_type_for_rest_route( $route );

    $module_keys = array_merge( $module_keys, nova_bridge_suite_get_module_keys_for_post_type( $post_type ) );

    $requested_post_type = nova_bridge_suite_get_query_or_post_request_value( 'post_type' );
    if ( null === $requested_post_type && '' === $post_type ) {
        $requested_post_type = nova_bridge_suite_get_json_scalar_request_value( 'post_type' );
    }

    if ( 'any' === $requested_post_type ) {
        $module_keys[] = 'custom_post_types';
        $module_keys[] = 'service_page_cpt';
    }

    return array_values( array_unique( $module_keys ) );
}

function nova_bridge_suite_get_targeted_rest_module_keys( string $route ): ?array {
    $route = trim( $route, '/' );

    if ( '' === $route ) {
        return null;
    }

    $module_keys = null;

    if ( nova_bridge_suite_rest_route_matches( $route, 'cf-bridge/v1' ) ) {
        $module_keys = [ '__core_bridge' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'nova-post-resolver/v1' ) ) {
        $module_keys = [ '__post_resolver' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'seor-bridge/v1' ) ) {
        $module_keys = [ 'pagebuilder_elementor' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'polylang-translations/v1' ) ) {
        $module_keys = [ 'multilingual_polylang' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'wpml-translations/v1' ) ) {
        $module_keys = [ 'multilingual_wpml' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'nova-gutenberg/v1' ) ) {
        $module_keys = [ 'gutenberg_bridge' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'nova-wpbakery/v1' ) ) {
        $module_keys = [ 'pagebuilder_wpbakery' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'nova-breakdance/v1' ) ) {
        $module_keys = [ 'pagebuilder_breakdance' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'nova-avada/v1' ) ) {
        $module_keys = [ 'pagebuilder_avada' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'nova-blog/v1' ) ) {
        $module_keys = [ 'custom_post_types' ];
    } elseif ( nova_bridge_suite_rest_route_matches( $route, 'service-pages/v1' ) ) {
        $module_keys = [ 'service_page_cpt' ];
    }

    if ( null === $module_keys ) {
        return null;
    }

    if (
        nova_bridge_suite_rest_route_matches( $route, 'seor-bridge/v1' )
        || nova_bridge_suite_rest_route_matches( $route, 'polylang-translations/v1' )
        || nova_bridge_suite_rest_route_matches( $route, 'wpml-translations/v1' )
        || nova_bridge_suite_rest_route_matches( $route, 'nova-gutenberg/v1' )
        || nova_bridge_suite_rest_route_matches( $route, 'nova-wpbakery/v1' )
        || nova_bridge_suite_rest_route_matches( $route, 'nova-breakdance/v1' )
    ) {
        $module_keys = nova_bridge_suite_add_cpt_dependencies_for_rest_route( $module_keys, $route );
    }

    return array_values( array_unique( $module_keys ) );
}

function nova_bridge_suite_load_module_file( string $relative_path ): void {
    if ( '' === $relative_path ) {
        return;
    }

    $module_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . $relative_path;

    if ( file_exists( $module_file ) ) {
        require_once $module_file;
    }
}

function nova_bridge_suite_load_selected_modules( array $module_keys ): void {
    require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'includes/settings.php';
    require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'includes/class-nova-bridge-suite-wpml-support.php';

    $settings    = nova_bridge_suite_get_settings();
    $definitions = nova_bridge_suite_module_definitions();
    $conflicts   = nova_bridge_suite_get_module_conflicts();

    foreach ( $module_keys as $module_key ) {
        if ( '__core_bridge' === $module_key ) {
            nova_bridge_suite_load_module_file( 'modules/bridge/nova-bridge.php' );
            continue;
        }

        if ( '__post_resolver' === $module_key ) {
            nova_bridge_suite_load_module_file( 'modules/post-resolver/nova-post-resolver.php' );
            continue;
        }

        if ( empty( $definitions[ $module_key ] ) || empty( $settings[ $module_key ] ) || ! empty( $conflicts[ $module_key ] ) ) {
            continue;
        }

        nova_bridge_suite_load_module_file( $definitions[ $module_key ]['path'] ?? '' );
    }
}

function nova_bridge_suite_maybe_handle_targeted_rest_request(): bool {
    $route = nova_bridge_suite_get_rest_route_path();
    $module_keys = nova_bridge_suite_get_targeted_rest_module_keys( $route );

    if ( null === $module_keys ) {
        return false;
    }

    nova_bridge_suite_load_selected_modules( $module_keys );

    return true;
}

$nova_bridge_suite_admin_content_write_module_keys = nova_bridge_suite_get_admin_content_write_module_keys();
if ( null !== $nova_bridge_suite_admin_content_write_module_keys ) {
    if ( ! empty( $nova_bridge_suite_admin_content_write_module_keys ) ) {
        nova_bridge_suite_load_selected_modules( $nova_bridge_suite_admin_content_write_module_keys );
    }

    return;
}

if ( nova_bridge_suite_is_core_rest_content_write_request() ) {
    if ( nova_bridge_suite_core_rest_write_uses_bridge_payload() ) {
        require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/bridge/nova-bridge.php';
    }

    return;
}

if ( nova_bridge_suite_maybe_handle_targeted_rest_request() ) {
    return;
}

if ( nova_bridge_suite_is_rest_request() ) {
    return;
}

require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'includes/settings.php';
require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'includes/class-nova-bridge-suite-wpml-support.php';

Nova_Bridge_Suite_WPML_Support::bootstrap();

$nova_bridge_suite_should_load_update_checker = is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );

if ( $nova_bridge_suite_should_load_update_checker && file_exists( NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    $nova_bridge_suite_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/InSpace-GEO/NOVA-Bridge-Suite/',
        __FILE__,
        'nova-bridge-suite'
    );
    $nova_bridge_suite_update_checker->setBranch( 'main' );
    $nova_bridge_suite_update_checker->getVcsApi()->enableReleaseAssets( '/NOVA-Bridge-Suite-.*\\.zip$/i' );
}

$nova_bridge_suite_settings = nova_bridge_suite_get_settings();

// Core bridge is always on.
require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/bridge/nova-bridge.php';

// Post resolver is always on.
$nova_bridge_suite_post_resolver_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/post-resolver/nova-post-resolver.php';
if ( file_exists( $nova_bridge_suite_post_resolver_file ) ) {
    require_once $nova_bridge_suite_post_resolver_file;
}

$nova_bridge_suite_modules   = nova_bridge_suite_module_definitions();
$nova_bridge_suite_conflicts = nova_bridge_suite_get_module_conflicts();

foreach ( $nova_bridge_suite_modules as $nova_bridge_suite_setting_key => $nova_bridge_suite_module ) {
    if ( empty( $nova_bridge_suite_settings[ $nova_bridge_suite_setting_key ] ) ) {
        continue;
    }

    if ( ! empty( $nova_bridge_suite_conflicts[ $nova_bridge_suite_setting_key ] ) ) {
        continue;
    }

    $nova_bridge_suite_relative_path = $nova_bridge_suite_module['path'] ?? '';
    if ( '' !== $nova_bridge_suite_relative_path ) {
        $nova_bridge_suite_module_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . $nova_bridge_suite_relative_path;
        if ( file_exists( $nova_bridge_suite_module_file ) ) {
            require_once $nova_bridge_suite_module_file;
        }
    }
}

register_activation_hook( __FILE__, 'nova_bridge_suite_activate' );
register_deactivation_hook( __FILE__, 'nova_bridge_suite_deactivate' );
add_action( 'init', 'nova_bridge_suite_maybe_upgrade', 99 );

function nova_bridge_suite_activate(): void {
    $settings = nova_bridge_suite_get_settings();
    if ( false === get_option( NOVA_BRIDGE_SUITE_OPTION, false ) ) {
        add_option( NOVA_BRIDGE_SUITE_OPTION, $settings );
    }

    nova_bridge_suite_handle_cpt_toggle( false, ! empty( $settings['custom_post_types'] ) );
    nova_bridge_suite_handle_service_cpt_toggle( false, ! empty( $settings['service_page_cpt'] ) );
    update_option( NOVA_BRIDGE_SUITE_VERSION_OPTION, NOVA_BRIDGE_SUITE_VERSION );
}

function nova_bridge_suite_deactivate(): void {
    $settings = nova_bridge_suite_get_settings();
    if ( ! empty( $settings['custom_post_types'] ) ) {
        nova_bridge_suite_handle_cpt_toggle( true, false );
    }

    if ( ! empty( $settings['service_page_cpt'] ) ) {
        nova_bridge_suite_handle_service_cpt_toggle( true, false );
    }
}

function nova_bridge_suite_maybe_upgrade(): void {
    $installed_version = (string) get_option( NOVA_BRIDGE_SUITE_VERSION_OPTION, '' );

    if ( NOVA_BRIDGE_SUITE_VERSION === $installed_version ) {
        return;
    }

    flush_rewrite_rules( false );
    update_option( NOVA_BRIDGE_SUITE_VERSION_OPTION, NOVA_BRIDGE_SUITE_VERSION );
}

add_action( 'update_option_' . NOVA_BRIDGE_SUITE_OPTION, 'nova_bridge_suite_settings_updated', 10, 3 );

function nova_bridge_suite_settings_updated( $old_value, $new_value, string $option ): void {
    $defaults      = nova_bridge_suite_default_settings();
    $old_settings  = wp_parse_args( is_array( $old_value ) ? $old_value : [], $defaults );
    $new_settings  = wp_parse_args( is_array( $new_value ) ? $new_value : [], $defaults );
    $old_enabled   = ! empty( $old_settings['custom_post_types'] );
    $new_enabled   = ! empty( $new_settings['custom_post_types'] );
    $old_service   = ! empty( $old_settings['service_page_cpt'] );
    $new_service   = ! empty( $new_settings['service_page_cpt'] );

    if ( $old_enabled !== $new_enabled ) {
        nova_bridge_suite_handle_cpt_toggle( $old_enabled, $new_enabled );
    }

    if ( $old_service !== $new_service ) {
        nova_bridge_suite_handle_service_cpt_toggle( $old_service, $new_service );
    }
}

function nova_bridge_suite_handle_cpt_toggle( bool $was_enabled, bool $is_enabled ): void {
    if ( nova_bridge_suite_is_module_blocked( 'custom_post_types' ) ) {
        return;
    }

    $cpt_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/cpt/quarantined-cpt.php';
    if ( ! file_exists( $cpt_file ) ) {
        return;
    }

    require_once $cpt_file;

    if ( ! class_exists( '\\SEORAI\\BodycleanCPT\\Plugin' ) ) {
        return;
    }

    if ( ! $was_enabled && $is_enabled ) {
        \SEORAI\BodycleanCPT\Plugin::activate();
        return;
    }

    if ( $was_enabled && ! $is_enabled ) {
        \SEORAI\BodycleanCPT\Plugin::deactivate();
    }
}

function nova_bridge_suite_handle_service_cpt_toggle( bool $was_enabled, bool $is_enabled ): void {
    if ( nova_bridge_suite_is_module_blocked( 'service_page_cpt' ) ) {
        return;
    }

    $cpt_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/service-page-cpt/service-page-cpt.php';
    if ( ! file_exists( $cpt_file ) ) {
        return;
    }

    require_once $cpt_file;

    if ( ! class_exists( '\\SEORAI\\ServicePageCPT\\Plugin' ) ) {
        return;
    }

    if ( ! $was_enabled && $is_enabled ) {
        \SEORAI\ServicePageCPT\Plugin::activate();
        return;
    }

    if ( $was_enabled && ! $is_enabled ) {
        \SEORAI\ServicePageCPT\Plugin::deactivate();
    }
}
