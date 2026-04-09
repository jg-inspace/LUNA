<?php
/**
 * Multilingual support helpers for NOVA Bridge Suite.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nova_Bridge_Suite_WPML_Support {

	/**
	 * Blog CPT option that stores one or more custom post type definitions.
	 */
	private const BLOG_CPTS_OPTION = 'quarantined_cpt_bodyclean_cpts';

	/**
	 * Blog CPT post type slug used when no custom definitions were saved.
	 */
	private const DEFAULT_BLOG_POST_TYPE = 'blog';

	/**
	 * Service CPT post type slug.
	 */
	private const SERVICE_POST_TYPE = 'service_page';

	/**
	 * Blog related-posts meta key.
	 */
	private const BLOG_RELATED_POSTS_META = 'blog_related_posts';

	/**
	 * Service related-posts meta key.
	 */
	private const SERVICE_RELATED_POSTS_META = 'sp_related_posts';

	/**
	 * Service attachment meta keys.
	 */
	private const SERVICE_IMAGE_META_KEYS = [
		'sp_image_1',
		'sp_image_2',
	];

	/**
	 * Track bootstrap status so the filter is only registered once.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		// The blog CPT slug is configurable, so extend multilingual configs at runtime.
		add_filter( 'wpml_config_array', [ self::class, 'filter_wpml_config_array' ] );
		add_filter( 'pll_get_post_types', [ self::class, 'filter_polylang_post_types' ], 10, 2 );
		add_filter( 'pll_translate_post_meta', [ self::class, 'filter_polylang_post_meta' ], 10, 5 );
	}

	/**
	 * Adds runtime CPT definitions to WPML config.
	 *
	 * @param mixed $config Parsed WPML config array.
	 * @return array<string,mixed>
	 */
	public static function filter_wpml_config_array( $config ): array {
		if ( ! is_array( $config ) ) {
			$config = [];
		}

		$types = array_merge(
			[ self::SERVICE_POST_TYPE ],
			self::get_blog_post_types()
		);

		foreach ( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) as $post_type ) {
			self::merge_custom_type_config( $config, $post_type, '1' );
		}

		return $config;
	}

	public static function maybe_translate_post_id( int $post_id, string $post_type = '', ?string $language_code = null ): int {
		if ( $post_id <= 0 ) {
			return $post_id;
		}

		if ( '' === $post_type ) {
			$detected_post_type = get_post_type( $post_id );
			$post_type          = is_string( $detected_post_type ) ? $detected_post_type : 'post';
		}

		if ( self::is_wpml_available() ) {
			$translated = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $language_code );

			return is_numeric( $translated ) ? (int) $translated : $post_id;
		}

		if ( self::is_polylang_available() && function_exists( 'pll_get_post' ) ) {
			$translated = ( null === $language_code || '' === $language_code )
				? pll_get_post( $post_id )
				: pll_get_post( $post_id, (string) $language_code );

			return is_numeric( $translated ) && (int) $translated > 0 ? (int) $translated : $post_id;
		}

		return $post_id;
	}

	public static function maybe_translate_post_ids( array $post_ids, string $post_type = '', ?string $language_code = null ): array {
		$translated_ids = [];

		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			$translated_id = self::maybe_translate_post_id( $post_id, $post_type, $language_code );

			if ( $translated_id > 0 && ! in_array( $translated_id, $translated_ids, true ) ) {
				$translated_ids[] = $translated_id;
			}
		}

		return $translated_ids;
	}

	public static function maybe_translate_attachment_id( int $attachment_id, ?string $language_code = null ): int {
		return self::maybe_translate_post_id( $attachment_id, 'attachment', $language_code );
	}

	private static function is_wpml_available(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' );
	}

	private static function is_polylang_available(): bool {
		return defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_current_language' );
	}

	/**
	 * Programmatically enables multilingual support for NOVA CPTs in Polylang.
	 *
	 * @param mixed $post_types Existing Polylang post types.
	 * @param mixed $hide       Whether Polylang is building the settings UI list.
	 * @return array<int|string,string>
	 */
	public static function filter_polylang_post_types( $post_types, $hide ): array {
		if ( ! is_array( $post_types ) ) {
			$post_types = [];
		}

		foreach ( array_unique( array_merge( [ self::SERVICE_POST_TYPE ], self::get_blog_post_types() ) ) as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type ) {
				continue;
			}

			if ( $hide ) {
				$key = array_search( $post_type, $post_types, true );
				if ( false !== $key ) {
					unset( $post_types[ $key ] );
				}

				unset( $post_types[ $post_type ] );
				continue;
			}

			$post_types[ $post_type ] = $post_type;
		}

		return $post_types;
	}

	/**
	 * Translates NOVA post-meta IDs when Polylang copies or syncs translations.
	 *
	 * @param mixed  $value Meta value.
	 * @param mixed  $key   Meta key.
	 * @param mixed  $lang  Target language slug.
	 * @param mixed  $from  Source post ID.
	 * @param mixed  $to    Target post ID.
	 * @return mixed
	 */
	public static function filter_polylang_post_meta( $value, $key, $lang, $from = 0, $to = 0 ) {
		unset( $from, $to );

		$key  = (string) $key;
		$lang = is_string( $lang ) ? $lang : '';

		if ( self::BLOG_RELATED_POSTS_META === $key || self::SERVICE_RELATED_POSTS_META === $key ) {
			$post_ids = is_array( $value ) ? $value : maybe_unserialize( $value );

			if ( ! is_array( $post_ids ) ) {
				return $value;
			}

			return self::maybe_translate_post_ids( $post_ids, '', $lang );
		}

		if ( in_array( $key, self::SERVICE_IMAGE_META_KEYS, true ) ) {
			return self::maybe_translate_attachment_id( absint( $value ), $lang );
		}

		return $value;
	}

	/**
	 * Returns all configured blog CPT post type slugs.
	 *
	 * @return array<int,string>
	 */
	private static function get_blog_post_types(): array {
		if ( class_exists( '\\SEORAI\\BodycleanCPT\\Plugin' ) ) {
			$types = \SEORAI\BodycleanCPT\Plugin::get_post_types();

			if ( ! empty( $types ) ) {
				return array_values(
					array_unique(
						array_filter(
							array_map( 'sanitize_key', $types )
						)
					)
				);
			}
		}

		$raw = get_option( self::BLOG_CPTS_OPTION, null );

		if ( null === $raw ) {
			return [ self::DEFAULT_BLOG_POST_TYPE ];
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$types = [];

		foreach ( $raw as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$type = isset( $definition['type'] ) ? sanitize_key( (string) $definition['type'] ) : '';

			if ( '' === $type && isset( $definition['slug'] ) ) {
				$type = sanitize_key( sanitize_title( (string) $definition['slug'] ) );
			}

			if ( '' !== $type ) {
				$types[] = substr( $type, 0, 20 );
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Ensures a CPT exists in WPML's parsed config array.
	 *
	 * @param array<string,mixed> $config Config array passed by reference.
	 * @param string              $post_type Post type slug.
	 * @param string              $translate Translation mode.
	 * @return void
	 */
	private static function merge_custom_type_config( array &$config, string $post_type, string $translate ): void {
		if ( ! isset( $config['wpml-config'] ) || ! is_array( $config['wpml-config'] ) ) {
			$config['wpml-config'] = [];
		}

		if ( ! isset( $config['wpml-config']['custom-types'] ) || ! is_array( $config['wpml-config']['custom-types'] ) ) {
			$config['wpml-config']['custom-types'] = [];
		}

		if ( ! isset( $config['wpml-config']['custom-types']['custom-type'] ) || ! is_array( $config['wpml-config']['custom-types']['custom-type'] ) ) {
			$config['wpml-config']['custom-types']['custom-type'] = [];
		}

		foreach ( $config['wpml-config']['custom-types']['custom-type'] as &$entry ) {
			if ( ! is_array( $entry ) || ( $entry['value'] ?? '' ) !== $post_type ) {
				continue;
			}

			if ( ! isset( $entry['attr'] ) || ! is_array( $entry['attr'] ) ) {
				$entry['attr'] = [];
			}

			$entry['attr']['translate'] = $translate;
			return;
		}
		unset( $entry );

		$config['wpml-config']['custom-types']['custom-type'][] = [
			'value' => $post_type,
			'attr'  => [
				'translate' => $translate,
			],
		];
	}
}
