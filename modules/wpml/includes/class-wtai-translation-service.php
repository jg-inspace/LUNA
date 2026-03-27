<?php

if (! defined('ABSPATH')) {
    exit;
}

class WTAI_Translation_Service
{
    /**
     * Create or update a translation for the given source post.
     *
     * @param int   $source_post_id
     * @param array $translation
     *
     * @return array|\WP_Error
     */
    public function upsert_post_translation(int $source_post_id, array $translation)
    {
        $source_post = get_post($source_post_id);

        if (! $source_post) {
            return new \WP_Error('wtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $language_code = isset($translation['language']) ? sanitize_text_field($translation['language']) : '';

        if ($language_code === '') {
            return new \WP_Error('wtai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        $language_code = $this->resolve_language_code($language_code);

        if (is_wp_error($language_code)) {
            return $language_code;
        }

        $post_type     = $source_post->post_type;
        $element_type  = $this->get_element_type($post_type);
        $trid          = apply_filters('wpml_element_trid', null, $source_post_id, $element_type);
        $lang_details  = $this->get_language_details($source_post_id, $element_type);
        $source_lang   = $lang_details['language_code'] ?? apply_filters('wpml_default_language', null);

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source post.', ['status' => 400]);
        }

        $existing_translation_id   = apply_filters('wpml_object_id', $source_post_id, $post_type, false, $language_code);
        $existing_translation_post = $existing_translation_id ? get_post($existing_translation_id) : null;
        $post_data                 = $this->build_post_data($translation, $source_post, $existing_translation_post);

        if ($existing_translation_id) {
            $post_data['ID'] = $existing_translation_id;
            $translation_id  = wp_update_post($post_data, true);
            $created         = false;
        } else {
            $translation_id = wp_insert_post($post_data, true);
            $created        = true;
        }

        if (is_wp_error($translation_id)) {
            return $translation_id;
        }

        if (! $existing_translation_id) {
            do_action(
                'wpml_set_element_language_details',
                [
                    'element_id'           => $translation_id,
                    'element_type'         => $element_type,
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_lang,
                ]
            );
        }

        if (! empty($translation['meta']) && is_array($translation['meta'])) {
            $this->sync_meta($translation_id, $translation['meta']);
        }

        if (! empty($translation['custom_fields']) && is_array($translation['custom_fields'])) {
            $this->sync_meta($translation_id, $translation['custom_fields']);
        }

        if (! empty($translation['taxonomies']) && is_array($translation['taxonomies'])) {
            $this->sync_taxonomies($translation_id, $translation['taxonomies']);
        }

        return [
            'source_post_id' => $source_post_id,
            'translation_id' => $translation_id,
            'language'       => $language_code,
            'created'        => $created,
        ];
    }

    /**
     * Create or update a term translation for the given source term.
     *
     * @param int   $source_term_id
     * @param string $taxonomy
     * @param array $translation
     *
     * @return array|\WP_Error
     */
    public function upsert_term_translation(int $source_term_id, string $taxonomy, array $translation, ?int $trid_override = null)
    {
        if (! taxonomy_exists($taxonomy)) {
            return new \WP_Error('wtai_missing_taxonomy', 'Taxonomy not found.', ['status' => 404]);
        }

        $source_term = get_term($source_term_id, $taxonomy);

        if (! $source_term || is_wp_error($source_term)) {
            return new \WP_Error('wtai_missing_source_term', 'Source term not found.', ['status' => 404]);
        }

        $language_code = isset($translation['language']) ? sanitize_text_field($translation['language']) : '';

        if ($language_code === '') {
            return new \WP_Error('wtai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        $language_code = $this->resolve_language_code($language_code);

        if (is_wp_error($language_code)) {
            return $language_code;
        }

        $element_type = 'tax_' . $taxonomy;
        $trid         = ($trid_override && $trid_override > 0)
            ? (int) $trid_override
            : $this->get_term_trid($source_term_id, $taxonomy);
        $lang_details = $this->get_term_language_details($source_term_id, $taxonomy);
        $source_lang  = $this->resolve_source_term_language($lang_details);
        $slug_for_lookup = '';
        if (! empty($translation['slug'])) {
            $slug_for_lookup = sanitize_title($translation['slug']);
        } elseif (! empty($translation['name'])) {
            $slug_for_lookup = sanitize_title($translation['name']);
        }
        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        $explicit_translation_id = isset($translation['term_id']) ? absint($translation['term_id']) : 0;

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source term.', ['status' => 400]);
        }

        // This endpoint is meant for translating into other languages; avoid mutating the source language entry here.
        if ($language_code === $source_lang) {
            return new \WP_Error(
                'wtai_same_language',
                'Translation language matches source language. Edit the source term directly instead.',
                ['status' => 400]
            );
        }

        // Prefer explicit term_id; only fall back to TRID/wpml_object_id when not provided.
        $existing_translation_id = $explicit_translation_id;
        if (! $existing_translation_id && is_array($translations) && isset($translations[$language_code]->element_id)) {
            $maybe_id = $this->resolve_taxonomy_term_id((int) $translations[$language_code]->element_id, $taxonomy);
            if ($maybe_id && $maybe_id !== $source_term_id) {
                $existing_translation_id = $maybe_id;
            }
        }

        // Fallback to wpml_object_id lookup.
        if (! $existing_translation_id) {
            $existing_translation_id = apply_filters('wpml_object_id', $source_term_id, $taxonomy, false, $language_code);
        }

        // If still pointing at the source, clear it so we don't mutate the source term.
        if ($existing_translation_id === $source_term_id) {
            $existing_translation_id = 0;
        }

        // If we already resolved a target term (explicit or mapped), skip slug conflict checks.
        $skip_slug_checks = ($explicit_translation_id > 0) || ($existing_translation_id > 0);

        $this->normalize_source_term_language_details(
            $source_term_id,
            $taxonomy,
            $trid,
            $source_lang
        );

        if (! $skip_slug_checks && ! $existing_translation_id && $slug_for_lookup !== '') {
            $slug_term = get_term_by('slug', $slug_for_lookup, $taxonomy);

            if ($slug_term && ! is_wp_error($slug_term)) {
                $slug_lang = $this->get_term_language_details((int) $slug_term->term_id, $taxonomy);
                $slug_trid = $this->get_term_trid((int) $slug_term->term_id, $taxonomy);
                $slug_id   = (int) $slug_term->term_id;
                $slug_lang_code = $slug_lang['language_code'] ?? '';
                $debug_context = [
                    'source_term_id'          => $source_term_id,
                    'language_code'           => $language_code,
                    'slug'                    => $translation['slug'],
                    'slug_term_id'            => $slug_id,
                    'slug_lang'               => $slug_lang_code ?: null,
                    'slug_trid'               => $slug_trid ?: null,
                    'existing_translation_id' => $existing_translation_id ?: null,
                    'trid'                    => $trid ?: null,
                ];

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        'WTAI slug lookup: source=%d lang=%s slug=%s => slug_id=%d slug_lang=%s slug_trid=%s existing_translation_id=%s trid=%s',
                        $source_term_id,
                        $language_code,
                        $slug_for_lookup,
                        $slug_id,
                        $slug_lang_code ?: 'none',
                        $slug_trid ?: 'none',
                        $existing_translation_id ?: 'none',
                        $trid ?: 'none'
                    ));
                }

                // Never adopt the source term as a translation target.
                if ($slug_id === $source_term_id) {
                    // Prefer explicit target term if provided.
                    if ($explicit_translation_id && $explicit_translation_id !== $source_term_id) {
                        $existing_translation_id = $explicit_translation_id;
                    } elseif (is_array($translations) && isset($translations[$language_code]->element_id)) {
                        $existing_translation_id = (int) $translations[$language_code]->element_id;
                        if ($existing_translation_id === $source_term_id) {
                            $existing_translation_id = 0;
                        }
                    }

                    // As a fallback, pick any other term in the TRID that is not the source.
                    if (! $existing_translation_id && is_array($translations)) {
                        foreach ($translations as $code => $info) {
                            if (! isset($info->element_id)) {
                                continue;
                            }

                            $candidate_id = $this->resolve_taxonomy_term_id((int) $info->element_id, $taxonomy);
                            if ($candidate_id > 0 && $candidate_id !== $source_term_id) {
                                $existing_translation_id = $candidate_id;
                                break;
                            }
                        }
                    }

                    // If we have a real translation ID, proceed; otherwise error.
                    if (! $existing_translation_id) {
                        return new \WP_Error(
                            'slug_in_use_source',
                            'Slug already used by the source term. Provide a unique slug for the target language.',
                            ['status' => 409, 'debug' => $debug_context]
                        );
                    }
                }

                // If the slug is already assigned to another language, block.
                if ($slug_lang_code !== '' && $slug_lang_code !== $language_code) {
                    return new \WP_Error(
                        'slug_in_use_other_language',
                        sprintf(
                            'Slug already in use by another language (%s). Provide a unique slug for %s.',
                            $slug_lang_code,
                            $language_code
                        ),
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }

                // If the slug belongs to another TRID but same language, adopt and re-link to this TRID.
                if (! empty($slug_trid) && $slug_trid !== $trid && $slug_lang_code === $language_code) {
                    $existing_translation_id = $slug_id;
                } elseif (! empty($slug_trid) && $slug_trid !== $trid && $slug_lang_code !== '') {
                    return new \WP_Error(
                        'slug_in_use_other_trid',
                        'Slug exists in a different translation group. Provide a unique slug for this group.',
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }

                // If the slug is already in this TRID (or unassigned), adopt and then set the correct language.
                if (! $existing_translation_id) {
                    $existing_translation_id = $slug_id;
                }
            }
        }

        $existing_translation_term = $existing_translation_id ? get_term($existing_translation_id, $taxonomy) : null;
        $term_args                 = $this->build_term_data($translation, $source_term, $language_code, $existing_translation_term);

        $translation_term_id     = null;

        // If we already have a target term, update it through the WP term API so
        // WPML/WooCommerce/SEO integrations receive their normal hooks.
        if ($existing_translation_id) {
            $translation_term_id = $existing_translation_id;
            $created = false;
            $update_result = $this->update_existing_translation_term(
                $translation_term_id,
                $taxonomy,
                $term_args,
                $source_term_id,
                $language_code,
                $existing_translation_id
            );
            if (is_wp_error($update_result)) {
                return $update_result;
            }

            clean_term_cache([$translation_term_id], $taxonomy);
            $result = ['term_id' => $translation_term_id];
        } else {
            $result = wp_insert_term($term_args['name'], $taxonomy, $term_args);
            $created = true;
        }

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            $adopt_id   = null;
            $debug_context = [
                'source_term_id'          => $source_term_id,
                'language_code'           => $language_code,
                'existing_translation_id' => $existing_translation_id ?: null,
                'term_args'               => $term_args,
                'wp_error_code'           => $error_code,
                'wp_error_data'           => $result->get_error_data(),
                'requested_slug'          => $translation['slug'] ?? null,
                'lookup_slug'             => $slug_for_lookup ?: null,
            ];

            if ($error_code === 'term_exists') {
                $adopt_id = (int) $result->get_error_data('term_exists');
            } elseif ($error_code === 'duplicate_term_slug') {
                // If caller explicitly targeted a translation term, prefer updating it directly.
                if ($explicit_translation_id && $explicit_translation_id !== $source_term_id) {
                    $adopt_id = $explicit_translation_id;
                    if (isset($term_args['slug'])) {
                        unset($term_args['slug']);
                    }
                }

                // Try multiple sources to identify the existing term we should adopt/update.
                $adopt_id = $adopt_id ?: (int) $result->get_error_data(); // some WP errors store the term_id directly
                if (! $adopt_id) {
                    $adopt_id = (int) $result->get_error_data('term_exists');
                }
                if (! $adopt_id && ! empty($term_args['slug'])) {
                    $slug_term = get_term_by('slug', $term_args['slug'], $taxonomy);
                    if ($slug_term && ! is_wp_error($slug_term)) {
                        $adopt_id = (int) $slug_term->term_id;
                    }
                }
                if (! $adopt_id && $slug_for_lookup !== '' && empty($term_args['slug'])) {
                    $slug_term = get_term_by('slug', $slug_for_lookup, $taxonomy);
                    if ($slug_term && ! is_wp_error($slug_term)) {
                        $adopt_id = (int) $slug_term->term_id;
                    }
                }
                // If still nothing, try TRID mapping for this language (update existing translation).
                if (! $adopt_id && is_array($translations) && isset($translations[$language_code]->element_id)) {
                    $adopt_id = (int) $translations[$language_code]->element_id;
                }
                // If explicit term_id was provided, adopt that as last resort.
                if (! $adopt_id && $explicit_translation_id) {
                    $adopt_id = $explicit_translation_id;
                }

                // If the slug resolves to the source term but we already have a concrete target,
                // prefer the translation we intend to update and drop the slug to avoid re-checks.
                if ($adopt_id === $source_term_id && ($existing_translation_id || $explicit_translation_id)) {
                    $adopt_id = $existing_translation_id ?: $explicit_translation_id;
                    if (isset($term_args['slug'])) {
                        unset($term_args['slug']);
                    }
                }

                // If we have a target term, retry an update without slug to bypass duplicate checks.
                if ($adopt_id || $existing_translation_id || $explicit_translation_id) {
                    $target_id = $adopt_id ?: ($existing_translation_id ?: $explicit_translation_id);
                    $retry_args = $term_args;
                    if (isset($retry_args['slug'])) {
                        unset($retry_args['slug']);
                    }
                    $retry = wp_update_term($target_id, $taxonomy, $retry_args);
                    if (! is_wp_error($retry)) {
                        $translation_term_id = $target_id;
                        $created = false;
                        // proceed to language setting/meta below
                    } else {
                        // If retry still fails and we have context, return a richer error.
                        return new \WP_Error(
                            'duplicate_term_slug',
                            $result->get_error_message(),
                            ['status' => 409, 'debug' => $debug_context]
                        );
                    }
                } else {
                    // If still nothing, return a richer error with context.
                    return new \WP_Error(
                        'duplicate_term_slug',
                        $result->get_error_message(),
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }
            }

            if ($translation_term_id) {
                // already handled via retry
            } elseif ($adopt_id) {
                $adopt_lang = $this->get_term_language_details($adopt_id, $taxonomy);
                $adopt_trid = $this->get_term_trid($adopt_id, $taxonomy);
                $adopt_lang_code = $adopt_lang['language_code'] ?? '';
                $debug_context = [
                    'source_term_id'          => $source_term_id,
                    'language_code'           => $language_code,
                    'slug'                    => $term_args['slug'] ?? '',
                    'slug_term_id'            => $adopt_id,
                    'slug_lang'               => $adopt_lang_code ?: null,
                    'slug_trid'               => $adopt_trid ?: null,
                    'existing_translation_id' => $existing_translation_id ?: null,
                    'trid'                    => $trid ?: null,
                ];

                if ($adopt_id === $source_term_id) {
                    return new \WP_Error(
                        'slug_in_use_source',
                        'Slug already used by the source term. Provide a unique slug for the target language.',
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }

                // Allow adoption if same language; block only when another language already owns the slug in a different TRID.
                if (! empty($adopt_trid) && $adopt_trid !== $trid) {
                    if ($adopt_lang_code !== '' && $adopt_lang_code !== $language_code) {
                        return new \WP_Error(
                            'slug_in_use_other_trid',
                            'Slug exists in a different translation group. Provide a unique slug for this group.',
                            ['status' => 409, 'debug' => $debug_context]
                        );
                    }
                }

                $translation_term_id = $adopt_id;
                $created             = false;
                // attempt to update the adopted term with desired args
                $update_result = wp_update_term($translation_term_id, $taxonomy, $term_args);
                if (is_wp_error($update_result)) {
                    return $update_result;
                }
            } else {
                return $result;
            }
        } else {
            $translation_term_id = $result['term_id'];
        }

        $this->set_term_language_details(
            $translation_term_id,
            $taxonomy,
            $trid,
            $language_code,
            $source_lang
        );

        $this->stabilize_wpml_term_translation_pair(
            $source_term_id,
            $translation_term_id,
            $taxonomy,
            $trid,
            $source_lang,
            $language_code
        );

        $this->canonicalize_wpml_term_translation_group(
            $source_term_id,
            $translation_term_id,
            $taxonomy,
            $trid,
            $source_lang,
            $language_code
        );

        $translation_meta = ! empty($translation['meta']) && is_array($translation['meta'])
            ? $translation['meta']
            : [];
        $translation_meta = $this->augment_term_meta_for_translation(
            $source_term_id,
            $translation_term_id,
            $taxonomy,
            $translation_meta
        );

        if (! empty($translation_meta)) {
            $this->sync_term_meta(
                $translation_term_id,
                $taxonomy,
                $translation_meta,
                $language_code
            );
        }

        $this->sync_term_state_via_rest_bridge(
            $translation_term_id,
            $taxonomy,
            $term_args,
            $translation_meta,
            $language_code
        );

        // Product category meta may be written through the internal REST bridge, but that path
        // does not reliably rebuild Yoast/WPML term state on multilingual WooCommerce archives.
        // Always refresh the translated term after upserting it so SEO output and hreflang stay in sync.
        $this->touch_yoast_term_indexable($translation_term_id, $taxonomy, $language_code);
        if ($source_term_id !== $translation_term_id) {
            $this->touch_yoast_term_indexable($source_term_id, $taxonomy, $source_lang);
        }

        // Keep WPML language caches deterministic after taxonomy rewrites.
        $this->flush_multilingual_caches();

        return [
            'source_term_id'      => $source_term_id,
            'translation_term_id' => $translation_term_id,
            'taxonomy'            => $taxonomy,
            'language'            => $language_code,
            'created'             => $created,
        ];
    }

    private function update_existing_translation_term(
        int $translation_term_id,
        string $taxonomy,
        array $term_args,
        int $source_term_id,
        string $language_code,
        int $existing_translation_id
    ) {
        $existing_term = get_term($translation_term_id, $taxonomy);
        if (! $existing_term || is_wp_error($existing_term)) {
            return new \WP_Error(
                'term_update_failed',
                'Failed to load existing translation term before update.',
                [
                    'status' => 500,
                    'debug'  => [
                        'source_term_id'          => $source_term_id,
                        'language_code'           => $language_code,
                        'existing_translation_id' => $existing_translation_id,
                        'term_args'               => $term_args,
                    ],
                ]
            );
        }

        $update_result = wp_update_term($translation_term_id, $taxonomy, $term_args);

        if (! is_wp_error($update_result)) {
            return $update_result;
        }

        $error_code = $update_result->get_error_code();
        if (($error_code === 'duplicate_term_slug' || $error_code === 'term_exists') && isset($term_args['slug'])) {
            $retry_args = $term_args;
            unset($retry_args['slug']);

            $retry_result = wp_update_term($translation_term_id, $taxonomy, $retry_args);
            if (! is_wp_error($retry_result)) {
                return $retry_result;
            }

            $update_result = $retry_result;
            $error_code    = $update_result->get_error_code();
        }

        // Some stacks (WPML + WooCommerce taxonomy/permalink filters) can still throw
        // duplicate slug errors for updates that do not change the slug. Fall back to
        // a direct row update to keep translation writes deterministic.
        if ($error_code === 'duplicate_term_slug' || $error_code === 'term_exists') {
            $direct_result = $this->update_existing_translation_term_directly(
                $translation_term_id,
                $taxonomy,
                $term_args,
                $existing_term
            );

            if (! is_wp_error($direct_result)) {
                return $direct_result;
            }

            $update_result = $direct_result;
        }

        return new \WP_Error(
            'term_update_failed',
            'Failed to update existing translation term.',
            [
                'status' => 500,
                'debug'  => [
                    'source_term_id'          => $source_term_id,
                    'language_code'           => $language_code,
                    'existing_translation_id' => $existing_translation_id,
                    'term_args'               => $term_args,
                    'wp_error_code'           => $update_result->get_error_code(),
                    'wp_error_data'           => $update_result->get_error_data(),
                ],
            ]
        );
    }

    private function update_existing_translation_term_directly(
        int $term_id,
        string $taxonomy,
        array $term_args,
        \WP_Term $existing_term
    ) {
        global $wpdb;

        if (! isset($wpdb->terms) || ! isset($wpdb->term_taxonomy)) {
            return new \WP_Error('term_db_update_failed', 'WordPress term tables are not available.');
        }

        $name = array_key_exists('name', $term_args)
            ? wp_strip_all_tags((string) $term_args['name'])
            : (string) $existing_term->name;

        $slug = array_key_exists('slug', $term_args)
            ? sanitize_title((string) $term_args['slug'])
            : (string) $existing_term->slug;

        if ($slug === '') {
            $slug = (string) $existing_term->slug;
        }

        $description = array_key_exists('description', $term_args)
            ? wp_kses_post((string) $term_args['description'])
            : (string) $existing_term->description;

        $parent = array_key_exists('parent', $term_args)
            ? max(0, (int) $term_args['parent'])
            : max(0, (int) $existing_term->parent);

        $terms_updated = $wpdb->update(
            $wpdb->terms,
            [
                'name' => $name,
                'slug' => $slug,
            ],
            ['term_id' => $term_id]
        );
        if ($terms_updated === false) {
            return new \WP_Error('term_db_update_failed', 'Failed to update wp_terms row.');
        }

        $tt_id = isset($existing_term->term_taxonomy_id) ? (int) $existing_term->term_taxonomy_id : 0;
        if ($tt_id <= 0) {
            $tt_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT term_taxonomy_id
                     FROM {$wpdb->term_taxonomy}
                     WHERE term_id = %d
                       AND taxonomy = %s
                     LIMIT 1",
                    $term_id,
                    $taxonomy
                )
            );
        }

        if ($tt_id <= 0) {
            return new \WP_Error('term_db_update_failed', 'Failed to resolve term_taxonomy_id.');
        }

        $tax_updated = $wpdb->update(
            $wpdb->term_taxonomy,
            [
                'description' => $description,
                'parent'      => $parent,
            ],
            ['term_taxonomy_id' => $tt_id]
        );
        if ($tax_updated === false) {
            return new \WP_Error('term_db_update_failed', 'Failed to update wp_term_taxonomy row.');
        }

        clean_term_cache([$term_id], $taxonomy);
        do_action('edited_term', $term_id, $tt_id, $taxonomy);
        do_action("edited_{$taxonomy}", $term_id, $tt_id);

        return [
            'term_id'          => $term_id,
            'term_taxonomy_id' => $tt_id,
        ];
    }

    private function augment_term_meta_for_translation(
        int $source_term_id,
        int $translation_term_id,
        string $taxonomy,
        array $meta
    ): array {
        if ($taxonomy !== 'product_cat' || $source_term_id <= 0 || $translation_term_id <= 0) {
            return $meta;
        }

        foreach (['auto_update_uri'] as $meta_key) {
            if (array_key_exists($meta_key, $meta)) {
                continue;
            }

            $source_value = get_term_meta($source_term_id, $meta_key, true);
            if ($source_value === '' || $source_value === null) {
                continue;
            }

            $target_value = get_term_meta($translation_term_id, $meta_key, true);
            if ($target_value !== '' && $target_value !== null) {
                continue;
            }

            $meta[$meta_key] = $source_value;
        }

        return $meta;
    }

    private function build_post_data(array $translation, \WP_Post $source_post, \WP_Post $existing_translation = null): array
    {
        $current_defaults = $existing_translation ?: $source_post;
        $data = [
            'post_type'   => $source_post->post_type,
            'post_status' => $translation['status'] ?? $current_defaults->post_status,
        ];

        if ($existing_translation === null) {
            $data['post_author'] = $translation['author'] ?? $source_post->post_author;
        }

        $this->maybe_set_field($data, 'post_title', $translation, 'title', $existing_translation ? null : $source_post->post_title, 'wp_strip_all_tags');
        $this->maybe_set_field($data, 'post_name', $translation, 'slug', $existing_translation ? null : $source_post->post_name, 'sanitize_title');
        $this->maybe_set_field($data, 'post_content', $translation, 'content', $existing_translation ? null : $source_post->post_content, 'wp_kses_post');
        $this->maybe_set_field($data, 'post_excerpt', $translation, 'excerpt', $existing_translation ? null : $source_post->post_excerpt, 'wp_kses_post');
        $this->maybe_set_field($data, 'post_parent', $translation, 'parent_id', $existing_translation ? null : $source_post->post_parent, 'absint');

        if (isset($translation['comment_status'])) {
            $data['comment_status'] = $translation['comment_status'] === 'open' ? 'open' : 'closed';
        }

        return $data;
    }

    private function maybe_set_field(array &$data, string $target_key, array $translation, string $translation_key, $default = null, $sanitize_callback = null): void
    {
        if (array_key_exists($translation_key, $translation)) {
            $value = $translation[$translation_key];
        } elseif ($default !== null) {
            $value = $default;
        } else {
            return;
        }

        if ($sanitize_callback && is_callable($sanitize_callback)) {
            $value = call_user_func($sanitize_callback, $value);
        }

        $data[$target_key] = $value;
    }

    private function get_element_type(string $post_type): string
    {
        return 'post_' . $post_type;
    }

    private function get_language_details(int $element_id, string $element_type): array
    {
        $details = apply_filters(
            'wpml_element_language_details',
            null,
            [
                'element_id'   => $element_id,
                'element_type' => $element_type,
            ]
        );

        if (is_object($details)) {
            $details = get_object_vars($details);
        }

        if (! is_array($details)) {
            return [];
        }

        if (isset($details['language_code'])) {
            $details['language_code'] = trim((string) $details['language_code']);
        }

        if (array_key_exists('source_language_code', $details)) {
            $details['source_language_code'] = $details['source_language_code'] === null
                ? null
                : trim((string) $details['source_language_code']);
        }

        if (isset($details['trid'])) {
            $details['trid'] = (int) $details['trid'];
        }

        return $details;
    }

    private function get_term_trid(int $term_id, string $taxonomy): int
    {
        $element_type = 'tax_' . $taxonomy;

        foreach ($this->get_wpml_taxonomy_element_ids($term_id, $taxonomy) as $element_id) {
            $trid = apply_filters('wpml_element_trid', null, $element_id, $element_type);

            if ($trid) {
                return (int) $trid;
            }
        }

        return 0;
    }

    private function get_term_language_details(int $term_id, string $taxonomy): array
    {
        $element_type = 'tax_' . $taxonomy;

        foreach ($this->get_wpml_taxonomy_element_ids($term_id, $taxonomy) as $element_id) {
            $details = $this->get_language_details($element_id, $element_type);

            if (! empty($details)) {
                return $details;
            }
        }

        return [];
    }

    private function normalize_source_term_language_details(
        int $term_id,
        string $taxonomy,
        int $trid,
        ?string $language_code
    ): void {
        $language_code = is_string($language_code) ? trim($language_code) : '';

        if ($term_id <= 0 || $trid <= 0 || $language_code === '') {
            return;
        }

        $this->set_term_language_details($term_id, $taxonomy, $trid, $language_code, null);
    }

    private function set_term_language_details(
        int $term_id,
        string $taxonomy,
        int $trid,
        string $language_code,
        ?string $source_language_code
    ): void {
        $element_type = 'tax_' . $taxonomy;
        $canonical_element_id = $this->get_term_taxonomy_id($term_id, $taxonomy);
        $verification_ok = false;

        foreach ($this->get_wpml_taxonomy_element_ids($term_id, $taxonomy) as $element_id) {
            do_action(
                'wpml_set_element_language_details',
                [
                    'element_id'           => $element_id,
                    'element_type'         => $element_type,
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_language_code,
                    'check_duplicates'     => false,
                ]
            );

            $verification_id = $canonical_element_id > 0 ? $canonical_element_id : $element_id;
            $updated_details = $this->get_language_details($verification_id, $element_type);
            $updated_trid    = apply_filters('wpml_element_trid', null, $verification_id, $element_type);

            if (($updated_details['language_code'] ?? '') === $language_code && (int) $updated_trid === $trid) {
                $verification_ok = true;
                break;
            }
        }

        // WPML term links can end up with both legacy term_id rows and duplicate canonical rows.
        // Even when the action above appears to succeed, those duplicates can leave the source term
        // unable to see all sibling translations in the admin UI/switcher. Always canonicalize the
        // mapping to a single term_taxonomy_id row for this term.
        if ($canonical_element_id > 0 && $this->repair_wpml_taxonomy_translation_row($term_id, $taxonomy, $trid, $language_code, $source_language_code)) {
            $updated_details = $this->get_language_details($canonical_element_id, $element_type);
            $updated_trid    = apply_filters('wpml_element_trid', null, $canonical_element_id, $element_type);

            if (($updated_details['language_code'] ?? '') === $language_code && (int) $updated_trid === $trid) {
                return;
            }
        }

        if ($verification_ok) {
            return;
        }
    }

    private function get_wpml_taxonomy_element_ids(int $term_id, string $taxonomy): array
    {
        $ids = [];

        $tt_id = $this->get_term_taxonomy_id($term_id, $taxonomy);
        if ($tt_id > 0) {
            $ids[] = $tt_id;
        }

        if ($term_id > 0) {
            $ids[] = $term_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function get_term_taxonomy_id(int $term_id, string $taxonomy): int
    {
        $term = get_term($term_id, $taxonomy);
        if ($term && ! is_wp_error($term) && isset($term->term_taxonomy_id)) {
            $tt_id = (int) $term->term_taxonomy_id;
            if ($tt_id > 0) {
                return $tt_id;
            }
        }

        return 0;
    }

    private function resolve_taxonomy_term_id(int $element_id, string $taxonomy): int
    {
        if ($element_id <= 0 || ! taxonomy_exists($taxonomy)) {
            return 0;
        }

        $term = get_term($element_id, $taxonomy);
        if ($term && ! is_wp_error($term)) {
            return (int) $term->term_id;
        }

        global $wpdb;
        if (! isset($wpdb->term_taxonomy) || ! isset($wpdb->prefix)) {
            return 0;
        }

        $term_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id
                 FROM {$wpdb->term_taxonomy}
                 WHERE term_taxonomy_id = %d
                   AND taxonomy = %s
                 LIMIT 1",
                $element_id,
                $taxonomy
            )
        );

        if ($term_id <= 0) {
            return 0;
        }

        $resolved = get_term($term_id, $taxonomy);
        if (! $resolved || is_wp_error($resolved)) {
            return 0;
        }

        return (int) $resolved->term_id;
    }

    private function repair_wpml_taxonomy_translation_row(
        int $term_id,
        string $taxonomy,
        int $trid,
        string $language_code,
        ?string $source_language_code
    ): bool {
        global $wpdb;

        $tt_id = $this->get_term_taxonomy_id($term_id, $taxonomy);
        if ($tt_id <= 0 || ! isset($wpdb->prefix)) {
            return false;
        }

        $table = $wpdb->prefix . 'icl_translations';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $exists !== $table) {
            return false;
        }

        $element_type = 'tax_' . $taxonomy;
        $element_ids = [$tt_id];
        if ($term_id > 0 && $term_id !== $tt_id) {
            $element_ids[] = $term_id;
        }

        $placeholders = implode(', ', array_fill(0, count($element_ids), '%d'));
        $query_args   = array_merge([$element_type], $element_ids, [$tt_id]);
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT translation_id, element_id, trid, language_code, source_language_code
                 FROM {$table}
                 WHERE element_type = %s
                   AND element_id IN ({$placeholders})
                 ORDER BY CASE WHEN element_id = %d THEN 0 ELSE 1 END, translation_id ASC",
                ...$query_args
            ),
            ARRAY_A
        );

        $desired = [
            'trid'                 => $trid,
            'language_code'        => $language_code,
            'source_language_code' => $source_language_code,
        ];

        $keeper = null;
        if (is_array($rows) && ! empty($rows)) {
            foreach ($rows as $row) {
                if ((int) ($row['element_id'] ?? 0) === $tt_id) {
                    $keeper = $row;
                    break;
                }
            }

            if (! is_array($keeper)) {
                $keeper = $rows[0];
            }
        }

        if (is_array($keeper) && isset($keeper['translation_id'])) {
            $keeper_id    = (int) $keeper['translation_id'];
            $needs_update = ((int) ($keeper['element_id'] ?? 0) !== $tt_id)
                || ((int) ($keeper['trid'] ?? 0) !== $trid)
                || ((string) ($keeper['language_code'] ?? '') !== $language_code)
                || ((string) ($keeper['source_language_code'] ?? '') !== (string) ($source_language_code ?? ''));

            if ($needs_update) {
                $updated = $wpdb->update(
                    $table,
                    array_merge(
                        [
                            'element_id' => $tt_id,
                        ],
                        $desired
                    ),
                    ['translation_id' => $keeper_id]
                );

                if ($updated === false) {
                    return false;
                }
            }

            $duplicate_ids = [];
            foreach ($rows as $row) {
                $translation_id = (int) ($row['translation_id'] ?? 0);
                if ($translation_id > 0 && $translation_id !== $keeper_id) {
                    $duplicate_ids[] = $translation_id;
                }
            }

            if (! empty($duplicate_ids)) {
                $duplicate_ids = array_values(array_unique($duplicate_ids));
                $delete_sql    = implode(', ', array_fill(0, count($duplicate_ids), '%d'));
                $deleted       = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table} WHERE translation_id IN ({$delete_sql})",
                        ...$duplicate_ids
                    )
                );

                if ($deleted === false) {
                    return false;
                }
            }

            return true;
        }

        $inserted = $wpdb->insert(
            $table,
            array_merge(
                [
                    'element_type' => $element_type,
                    'element_id'   => $tt_id,
                ],
                $desired
            )
        );

        return $inserted !== false;
    }

    private function stabilize_wpml_term_translation_pair(
        int $source_term_id,
        int $translation_term_id,
        string $taxonomy,
        int $trid,
        string $source_language_code,
        string $translation_language_code
    ): void {
        global $wpdb;

        $source_language_code      = trim($source_language_code);
        $translation_language_code = trim($translation_language_code);

        if (
            $source_term_id <= 0
            || $translation_term_id <= 0
            || $trid <= 0
            || $source_language_code === ''
            || $translation_language_code === ''
            || $source_language_code === $translation_language_code
            || ! isset($wpdb->prefix)
        ) {
            return;
        }

        $source_tt_id      = $this->get_term_taxonomy_id($source_term_id, $taxonomy);
        $translation_tt_id = $this->get_term_taxonomy_id($translation_term_id, $taxonomy);

        if ($source_tt_id <= 0 || $translation_tt_id <= 0) {
            return;
        }

        $table  = $wpdb->prefix . 'icl_translations';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $exists !== $table) {
            return;
        }

        $element_type = 'tax_' . $taxonomy;
        $pairs = [
            $source_language_code => [
                'element_id'           => $source_tt_id,
                'source_language_code' => null,
            ],
            $translation_language_code => [
                'element_id'           => $translation_tt_id,
                'source_language_code' => $source_language_code,
            ],
        ];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT translation_id, element_id, language_code, source_language_code
                 FROM {$table}
                 WHERE element_type = %s
                   AND trid = %d
                   AND language_code IN (%s, %s)
                 ORDER BY translation_id ASC",
                $element_type,
                $trid,
                $source_language_code,
                $translation_language_code
            ),
            ARRAY_A
        );

        $rows_by_language = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $lang = isset($row['language_code']) ? (string) $row['language_code'] : '';
                if ($lang === '' || ! isset($pairs[$lang])) {
                    continue;
                }

                if (! isset($rows_by_language[$lang])) {
                    $rows_by_language[$lang] = [];
                }

                $rows_by_language[$lang][] = $row;
            }
        }

        $changed = false;

        foreach ($pairs as $language_code => $expected) {
            $expected_element_id   = (int) $expected['element_id'];
            $expected_source_lang  = $expected['source_language_code'];
            $language_rows         = $rows_by_language[$language_code] ?? [];
            $keeper               = null;

            foreach ($language_rows as $row) {
                if ((int) ($row['element_id'] ?? 0) === $expected_element_id) {
                    $keeper = $row;
                    break;
                }
            }

            if (! is_array($keeper) && ! empty($language_rows)) {
                $keeper = $language_rows[0];
            }

            if (is_array($keeper) && isset($keeper['translation_id'])) {
                $keeper_id = (int) $keeper['translation_id'];
                $needs_update = ((int) ($keeper['element_id'] ?? 0) !== $expected_element_id)
                    || ((string) ($keeper['language_code'] ?? '') !== $language_code)
                    || ((string) ($keeper['source_language_code'] ?? '') !== (string) ($expected_source_lang ?? ''));

                if ($needs_update) {
                    $updated = $wpdb->update(
                        $table,
                        [
                            'element_id'           => $expected_element_id,
                            'trid'                 => $trid,
                            'language_code'        => $language_code,
                            'source_language_code' => $expected_source_lang,
                        ],
                        ['translation_id' => $keeper_id]
                    );

                    if ($updated !== false && $updated > 0) {
                        $changed = true;
                    }
                }

                $duplicate_ids = [];
                foreach ($language_rows as $row) {
                    $row_id = (int) ($row['translation_id'] ?? 0);
                    if ($row_id > 0 && $row_id !== $keeper_id) {
                        $duplicate_ids[] = $row_id;
                    }
                }

                if (! empty($duplicate_ids)) {
                    $duplicate_ids = array_values(array_unique($duplicate_ids));
                    $delete_sql    = implode(', ', array_fill(0, count($duplicate_ids), '%d'));
                    $deleted       = $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$table} WHERE translation_id IN ({$delete_sql})",
                            ...$duplicate_ids
                        )
                    );

                    if ($deleted !== false && $deleted > 0) {
                        $changed = true;
                    }
                }
            } else {
                $inserted = $wpdb->insert(
                    $table,
                    [
                        'element_type'         => $element_type,
                        'element_id'           => $expected_element_id,
                        'trid'                 => $trid,
                        'language_code'        => $language_code,
                        'source_language_code' => $expected_source_lang,
                    ]
                );

                if ($inserted !== false) {
                    $changed = true;
                }
            }
        }

        do_action(
            'wpml_set_element_language_details',
            [
                'element_id'           => $source_tt_id,
                'element_type'         => $element_type,
                'trid'                 => $trid,
                'language_code'        => $source_language_code,
                'source_language_code' => null,
                'check_duplicates'     => false,
            ]
        );

        do_action(
            'wpml_set_element_language_details',
            [
                'element_id'           => $translation_tt_id,
                'element_type'         => $element_type,
                'trid'                 => $trid,
                'language_code'        => $translation_language_code,
                'source_language_code' => $source_language_code,
                'check_duplicates'     => false,
            ]
        );

        clean_term_cache([$source_term_id, $translation_term_id], $taxonomy);

        if ($changed) {
            $this->flush_multilingual_caches();
        }
    }

    private function canonicalize_wpml_term_translation_group(
        int $source_term_id,
        int $translation_term_id,
        string $taxonomy,
        int $trid,
        string $source_language_code,
        string $translation_language_code
    ): void {
        global $wpdb;

        $source_language_code      = trim($source_language_code);
        $translation_language_code = trim($translation_language_code);

        if (
            $source_term_id <= 0
            || $translation_term_id <= 0
            || $trid <= 0
            || $source_language_code === ''
            || $translation_language_code === ''
            || ! isset($wpdb->prefix)
        ) {
            return;
        }

        $element_type = 'tax_' . $taxonomy;
        $table        = $wpdb->prefix . 'icl_translations';
        $exists       = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $exists !== $table) {
            return;
        }

        $expected_terms_by_language = [
            $source_language_code      => $source_term_id,
            $translation_language_code => $translation_term_id,
        ];

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        if (is_array($translations)) {
            foreach ($translations as $language_code => $info) {
                $language_code = trim((string) $language_code);
                if ($language_code === '' || ! isset($info->element_id)) {
                    continue;
                }

                $resolved_term_id = $this->resolve_taxonomy_term_id((int) $info->element_id, $taxonomy);
                if ($resolved_term_id <= 0) {
                    continue;
                }

                $expected_terms_by_language[$language_code] = $resolved_term_id;
            }
        }

        // Force canonical source/target mappings for this write operation.
        $expected_terms_by_language[$source_language_code]      = $source_term_id;
        $expected_terms_by_language[$translation_language_code] = $translation_term_id;

        $expected_rows = [];
        foreach ($expected_terms_by_language as $language_code => $term_id) {
            $language_code = trim((string) $language_code);
            $term_id       = (int) $term_id;

            if ($language_code === '' || $term_id <= 0) {
                continue;
            }

            $tt_id = $this->get_term_taxonomy_id($term_id, $taxonomy);
            if ($tt_id <= 0) {
                continue;
            }

            $expected_rows[$language_code] = [
                'term_id'              => $term_id,
                'element_id'           => $tt_id,
                'source_language_code' => ($language_code === $source_language_code) ? null : $source_language_code,
            ];
        }

        if (count($expected_rows) < 2) {
            return;
        }

        $candidate_element_ids = [];
        $expected_element_ids  = [];
        foreach ($expected_rows as $row) {
            $term_id    = (int) $row['term_id'];
            $element_id = (int) $row['element_id'];

            if ($term_id > 0) {
                $candidate_element_ids[] = $term_id;
            }

            if ($element_id > 0) {
                $candidate_element_ids[] = $element_id;
                $expected_element_ids[]  = $element_id;
            }
        }

        $candidate_element_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_element_ids))));
        $expected_element_ids  = array_values(array_unique(array_filter(array_map('intval', $expected_element_ids))));
        if (empty($candidate_element_ids) || empty($expected_element_ids)) {
            return;
        }

        $existing_rows = [];
        $element_placeholders = implode(', ', array_fill(0, count($candidate_element_ids), '%d'));
        $existing_args = array_merge([$element_type], $candidate_element_ids);
        $existing_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT translation_id
                 FROM {$table}
                 WHERE element_type = %s
                   AND element_id IN ({$element_placeholders})",
                ...$existing_args
            ),
            ARRAY_A
        );

        $conflicting_rows = [];
        $languages = array_keys($expected_rows);
        if (! empty($languages)) {
            $language_placeholders = implode(', ', array_fill(0, count($languages), '%s'));
            $expected_placeholders = implode(', ', array_fill(0, count($expected_element_ids), '%d'));
            $conflicting_args = array_merge([$element_type, $trid], $languages, $expected_element_ids);
            $conflicting_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT translation_id
                     FROM {$table}
                     WHERE element_type = %s
                       AND trid = %d
                       AND language_code IN ({$language_placeholders})
                       AND element_id NOT IN ({$expected_placeholders})",
                    ...$conflicting_args
                ),
                ARRAY_A
            );
        }

        $delete_ids = [];
        foreach ([$existing_rows, $conflicting_rows] as $row_set) {
            if (! is_array($row_set)) {
                continue;
            }

            foreach ($row_set as $row) {
                $translation_id = isset($row['translation_id']) ? (int) $row['translation_id'] : 0;
                if ($translation_id > 0) {
                    $delete_ids[] = $translation_id;
                }
            }
        }

        $delete_ids = array_values(array_unique($delete_ids));
        if (! empty($delete_ids)) {
            $delete_placeholders = implode(', ', array_fill(0, count($delete_ids), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE translation_id IN ({$delete_placeholders})",
                    ...$delete_ids
                )
            );
        }

        foreach ($expected_rows as $language_code => $row) {
            $wpdb->insert(
                $table,
                [
                    'element_type'         => $element_type,
                    'element_id'           => (int) $row['element_id'],
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $row['source_language_code'],
                ]
            );

            do_action(
                'wpml_set_element_language_details',
                [
                    'element_id'           => (int) $row['element_id'],
                    'element_type'         => $element_type,
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $row['source_language_code'],
                    'check_duplicates'     => false,
                ]
            );
        }

        $affected_term_ids = array_values(array_unique(array_map(
            static function (array $row): int {
                return isset($row['term_id']) ? (int) $row['term_id'] : 0;
            },
            $expected_rows
        )));
        $affected_term_ids = array_values(array_filter($affected_term_ids));

        if (! empty($affected_term_ids)) {
            clean_term_cache($affected_term_ids, $taxonomy);
        }
    }

    private function resolve_source_term_language(array $lang_details): string
    {
        $source_language = isset($lang_details['source_language_code'])
            ? trim((string) $lang_details['source_language_code'])
            : '';
        if ($source_language !== '') {
            return $source_language;
        }

        $language_code = isset($lang_details['language_code'])
            ? trim((string) $lang_details['language_code'])
            : '';
        if ($language_code !== '') {
            return $language_code;
        }

        $default_language = apply_filters('wpml_default_language', null);
        return is_string($default_language) ? trim($default_language) : '';
    }

    private function flush_multilingual_caches(): void
    {
        foreach (['wpml_cache_clear', 'wpml_st_cache_flush'] as $action) {
            try {
                do_action($action);
            } catch (\Throwable $e) {
                // Best effort: object-cache backends should not break translation API writes.
            }
        }
    }

    private function sync_meta(int $post_id, array $meta): void
    {
        foreach ($meta as $key => $value) {
            if ($value === null) {
                delete_post_meta($post_id, $key);
                continue;
            }

            update_post_meta($post_id, $key, $value);
        }
    }

    private function sync_taxonomies(int $post_id, array $taxonomies): void
    {
        foreach ($taxonomies as $taxonomy => $terms) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            wp_set_object_terms($post_id, $terms, $taxonomy);
        }
    }

    /**
     * Accept WPML language codes (`en`) and WPML locales (`en_EN`) and normalize to the active code.
     *
     * @return string|\WP_Error
     */
    private function resolve_language_code(string $language_code)
    {
        $language_code = trim($language_code);

        if ($language_code === '') {
            return new \WP_Error('wtai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        $active_languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        if (! is_array($active_languages) || empty($active_languages)) {
            return $language_code;
        }

        $requested = strtolower($language_code);
        $allowed   = [];

        foreach ($active_languages as $code => $data) {
            $code = (string) $code;

            if ($code === '') {
                continue;
            }

            $allowed[] = $code;

            if (strtolower($code) === $requested) {
                return $code;
            }

            $locale = isset($data['default_locale']) ? (string) $data['default_locale'] : '';

            if ($locale !== '' && strtolower($locale) === $requested) {
                return $code;
            }
        }

        return new \WP_Error(
            'wtai_invalid_language',
            sprintf(
                'Unknown WPML language "%s". Use an active WPML language code such as: %s.',
                $language_code,
                implode(', ', $allowed)
            ),
            [
                'status'             => 400,
                'requested_language' => $language_code,
                'allowed_languages' => $allowed,
            ]
        );
    }

    public function get_languages(): array
    {
        $default_language = apply_filters('wpml_default_language', null);
        $active_languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        if (! is_array($active_languages)) {
            return [
                'default_language' => $default_language,
                'default_home_url' => trailingslashit(home_url()),
                'languages'        => [],
            ];
        }

        $languages = [];
        $default_home_url = null;

        foreach ($active_languages as $code => $data) {
            $is_current = isset($data['active']) ? ((int) $data['active'] === 1) : null;
            $home_url   = $this->get_language_home_url($data, $code, $default_language);

            $languages[] = [
                'code'         => $code,
                'is_default'   => $code === $default_language,
                'enabled'      => true, // present in WPML active languages list.
                'is_current'   => $is_current,
                'english_name' => $data['english_name'] ?? '',
                'native_name'  => $data['native_name'] ?? '',
                'locale'       => $data['default_locale'] ?? '',
                'flag_url'     => $data['country_flag_url'] ?? '',
                'home_url'     => $home_url,
            ];

            if ($code === $default_language) {
                $default_home_url = $home_url;
            }
        }

        if (! $default_home_url) {
            $default_home_url = $this->get_language_home_url(
                $active_languages[$default_language] ?? [],
                $default_language,
                $default_language
            );
        }

        return [
            'default_language' => $default_language,
            'default_home_url' => $default_home_url,
            'languages'        => $languages,
        ];
    }

    public function get_term_translations(int $source_term_id, string $taxonomy)
    {
        if (! taxonomy_exists($taxonomy)) {
            return new \WP_Error('wtai_missing_taxonomy', 'Taxonomy not found.', ['status' => 404]);
        }

        $source_term = get_term($source_term_id, $taxonomy);

        if (! $source_term || is_wp_error($source_term)) {
            return new \WP_Error('wtai_missing_source_term', 'Source term not found.', ['status' => 404]);
        }

        $element_type = 'tax_' . $taxonomy;
        $trid         = $this->get_term_trid($source_term_id, $taxonomy);

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source term.', ['status' => 400]);
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);

        $items = [];
        if (is_array($translations)) {
            foreach ($translations as $code => $info) {
                if (! isset($info->element_id)) {
                    continue;
                }

                $resolved_term_id = $this->resolve_taxonomy_term_id((int) $info->element_id, $taxonomy);
                if ($resolved_term_id <= 0) {
                    continue;
                }

                $term_obj = get_term($resolved_term_id, $taxonomy);
                if (! $term_obj || is_wp_error($term_obj)) {
                    continue;
                }
                $items[] = [
                    'language' => $code,
                    'term_id'  => $resolved_term_id,
                    'slug'     => $term_obj->slug,
                    'name'     => $term_obj->name,
                    'is_source'=> ($resolved_term_id === (int) $source_term_id),
                ];
            }
        }

        return [
            'source_term_id' => $source_term_id,
            'taxonomy'       => $taxonomy,
            'trid'           => $trid,
            'translations'   => $items,
        ];
    }

    public function get_term_diagnostics(int $source_term_id, string $taxonomy)
    {
        if (! taxonomy_exists($taxonomy)) {
            return new \WP_Error('wtai_missing_taxonomy', 'Taxonomy not found.', ['status' => 404]);
        }

        $source_term = get_term($source_term_id, $taxonomy);

        if (! $source_term || is_wp_error($source_term)) {
            return new \WP_Error('wtai_missing_source_term', 'Source term not found.', ['status' => 404]);
        }

        $element_type = 'tax_' . $taxonomy;
        $tt_id        = $this->get_term_taxonomy_id($source_term_id, $taxonomy);
        $trid_term_id = (int) apply_filters('wpml_element_trid', null, $source_term_id, $element_type);
        $trid_tt_id   = $tt_id > 0 ? (int) apply_filters('wpml_element_trid', null, $tt_id, $element_type) : 0;
        $resolved_trid = $this->get_term_trid($source_term_id, $taxonomy);

        $details_term_id = $this->get_language_details($source_term_id, $element_type);
        $details_tt_id   = $tt_id > 0 ? $this->get_language_details($tt_id, $element_type) : [];

        $active_languages_skip_missing_0 = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        $current_link = get_term_link($source_term, $taxonomy);
        $link_empty_to = is_string($current_link) ? $current_link : '';
        $active_languages_skip_missing_1 = apply_filters(
            'wpml_active_languages',
            null,
            [
                'skip_missing' => 1,
                'link_empty_to' => $link_empty_to,
            ]
        );

        $object_id_map = [];
        if (is_array($active_languages_skip_missing_0)) {
            foreach ($active_languages_skip_missing_0 as $code => $language_data) {
                $mapped = apply_filters('wpml_object_id', $source_term_id, $taxonomy, false, (string) $code);
                $object_id_map[(string) $code] = [
                    'mapped_term_id' => $mapped ? (int) $mapped : 0,
                    'native_name'    => isset($language_data['native_name']) ? (string) $language_data['native_name'] : '',
                    'active'         => isset($language_data['active']) ? (int) $language_data['active'] : null,
                    'url'            => isset($language_data['url']) ? (string) $language_data['url'] : '',
                ];
            }
        }

        $translations_raw = ($resolved_trid > 0)
            ? apply_filters('wpml_get_element_translations', null, $resolved_trid, $element_type)
            : [];

        $translations = [];
        if (is_array($translations_raw)) {
            foreach ($translations_raw as $code => $info) {
                $element_id        = isset($info->element_id) ? (int) $info->element_id : 0;
                $resolved_term_id  = $this->resolve_taxonomy_term_id($element_id, $taxonomy);
                $term_obj          = $resolved_term_id > 0 ? get_term($resolved_term_id, $taxonomy) : null;
                $term_exists = $term_obj && ! is_wp_error($term_obj);

                $translations[] = [
                    'language'              => (string) $code,
                    'element_id'            => $element_id,
                    'term_exists'           => (bool) $term_exists,
                    'resolved_term_id'      => $term_exists ? (int) $term_obj->term_id : 0,
                    'resolved_term_slug'    => $term_exists ? (string) $term_obj->slug : '',
                    'resolved_term_name'    => $term_exists ? (string) $term_obj->name : '',
                    'raw_language_code'     => isset($info->language_code) ? (string) $info->language_code : '',
                    'raw_source_language'   => isset($info->source_language_code) ? (string) $info->source_language_code : '',
                    'raw_trid'              => isset($info->trid) ? (int) $info->trid : 0,
                ];
            }
        }

        $group_term_ids = [$source_term_id];
        foreach ($translations as $translation_item) {
            $resolved_term_id = isset($translation_item['resolved_term_id'])
                ? (int) $translation_item['resolved_term_id']
                : 0;
            if ($resolved_term_id > 0) {
                $group_term_ids[] = $resolved_term_id;
            }
        }
        $group_term_ids = array_values(array_unique(array_filter(array_map('intval', $group_term_ids))));

        $group_element_ids = [];
        foreach ($group_term_ids as $group_term_id) {
            $group_element_ids[] = $group_term_id;
            $group_tt_id = $this->get_term_taxonomy_id($group_term_id, $taxonomy);
            if ($group_tt_id > 0) {
                $group_element_ids[] = $group_tt_id;
            }
        }
        $group_element_ids = array_values(array_unique(array_filter(array_map('intval', $group_element_ids))));

        $db_rows = [];
        $status_rows = [];
        $db_rows_group_elements = [];
        $db_rows_other_element_types = [];
        global $wpdb;
        if (isset($wpdb->prefix)) {
            $table  = $wpdb->prefix . 'icl_translations';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

            if ((string) $exists === $table) {
                if (! empty($group_element_ids)) {
                    $group_placeholders = implode(', ', array_fill(0, count($group_element_ids), '%d'));
                    $group_args         = array_merge([$element_type], $group_element_ids);

                    $db_rows_group_elements = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT translation_id, element_type, element_id, trid, language_code, source_language_code
                             FROM {$table}
                             WHERE element_type = %s
                               AND element_id IN ({$group_placeholders})
                             ORDER BY element_id ASC, trid ASC, language_code ASC, translation_id ASC",
                            ...$group_args
                        ),
                        ARRAY_A
                    );

                    $db_rows_other_element_types = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT translation_id, element_type, element_id, trid, language_code, source_language_code
                             FROM {$table}
                             WHERE element_type <> %s
                               AND element_id IN ({$group_placeholders})
                             ORDER BY element_type ASC, element_id ASC, trid ASC, translation_id ASC",
                            ...$group_args
                        ),
                        ARRAY_A
                    );
                }

                if ($resolved_trid > 0) {
                    $db_rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT translation_id, element_id, trid, language_code, source_language_code
                             FROM {$table}
                             WHERE element_type = %s
                               AND trid = %d
                             ORDER BY language_code ASC, translation_id ASC",
                            $element_type,
                            $resolved_trid
                        ),
                        ARRAY_A
                    );
                } else {
                    $element_ids = [$source_term_id];
                    if ($tt_id > 0 && $tt_id !== $source_term_id) {
                        $element_ids[] = $tt_id;
                    }
                    $element_ids  = array_values(array_unique(array_map('intval', $element_ids)));
                    $placeholders = implode(', ', array_fill(0, count($element_ids), '%d'));
                    $args         = array_merge([$element_type], $element_ids);

                    $db_rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT translation_id, element_id, trid, language_code, source_language_code
                             FROM {$table}
                             WHERE element_type = %s
                               AND element_id IN ({$placeholders})
                             ORDER BY language_code ASC, translation_id ASC",
                            ...$args
                        ),
                        ARRAY_A
                    );
                }
            }

            if (! empty($db_rows)) {
                $translation_ids = array_values(array_unique(array_filter(array_map(
                    static function ($row): int {
                        return isset($row['translation_id']) ? (int) $row['translation_id'] : 0;
                    },
                    $db_rows
                ))));

                if (! empty($translation_ids)) {
                    $status_table  = $wpdb->prefix . 'icl_translation_status';
                    $status_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $status_table));
                    if ((string) $status_exists === $status_table) {
                        $status_placeholders = implode(', ', array_fill(0, count($translation_ids), '%d'));
                        $status_rows = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT translation_id, status, needs_update, md5
                                 FROM {$status_table}
                                 WHERE translation_id IN ({$status_placeholders})
                                 ORDER BY translation_id ASC",
                                ...$translation_ids
                            ),
                            ARRAY_A
                        );
                    }
                }
            }
        }

        return [
            'source_term_id' => $source_term_id,
            'taxonomy'       => $taxonomy,
            'term_taxonomy_id' => $tt_id,
            'current_language' => (string) apply_filters('wpml_current_language', null),
            'default_language' => (string) apply_filters('wpml_default_language', null),
            'trid'           => [
                'from_term_id' => $trid_term_id,
                'from_tt_id'   => $trid_tt_id,
                'resolved'     => $resolved_trid,
            ],
            'language_details' => [
                'term_id' => $details_term_id,
                'tt_id'   => $details_tt_id,
            ],
            'object_id_map' => $object_id_map,
            'active_languages_skip_missing_0' => $active_languages_skip_missing_0,
            'active_languages_skip_missing_1' => $active_languages_skip_missing_1,
            'translations_raw' => $translations,
            'icl_translations_rows' => is_array($db_rows) ? $db_rows : [],
            'icl_translations_rows_group_elements' => is_array($db_rows_group_elements) ? $db_rows_group_elements : [],
            'icl_translations_rows_other_element_types' => is_array($db_rows_other_element_types) ? $db_rows_other_element_types : [],
            'icl_translation_status_rows' => is_array($status_rows) ? $status_rows : [],
            'term_meta_operational' => [
                'auto_update_uri_source' => get_term_meta($source_term_id, 'auto_update_uri', true),
            ],
        ];
    }

    public function get_post_translations(int $source_post_id)
    {
        $source_post = get_post($source_post_id);

        if (! $source_post || ! isset($source_post->post_type)) {
            return new \WP_Error('wtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $element_type = $this->get_element_type($source_post->post_type);
        $trid         = apply_filters('wpml_element_trid', null, $source_post_id, $element_type);

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source post.', ['status' => 400]);
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);

        $items = [];
        if (is_array($translations)) {
            foreach ($translations as $code => $info) {
                if (! isset($info->element_id)) {
                    continue;
                }
                $post_obj = get_post((int) $info->element_id);
                if (! $post_obj || is_wp_error($post_obj)) {
                    continue;
                }
                $items[] = [
                    'language'   => $code,
                    'post_id'    => (int) $info->element_id,
                    'slug'       => $post_obj->post_name,
                    'title'      => $post_obj->post_title,
                    'status'     => $post_obj->post_status,
                    'post_type'  => $post_obj->post_type,
                    'is_source'  => ((int) $info->element_id === (int) $source_post_id),
                ];
            }
        }

        return [
            'source_post_id' => $source_post_id,
            'post_type'      => $source_post->post_type,
            'trid'           => $trid,
            'translations'   => $items,
        ];
    }

    private function get_language_home_url(array $language_data, string $language_code, string $default_language): string
    {
        $base_home = trailingslashit(home_url());
        $url       = isset($language_data['url']) && is_string($language_data['url']) ? $language_data['url'] : '';

        if ($url === '') {
            $url = apply_filters('wpml_home_url', null, $language_code);
        }

        if (! $url) {
            $url = apply_filters('wpml_permalink', $base_home, $language_code);
        }

        if (! $url) {
            $url = $base_home;
        }

        if ($url === $base_home && $language_code !== $default_language) {
            $url = add_query_arg('lang', $language_code, $base_home);
        }

        return trailingslashit($url);
    }

    private function build_term_data(
        array $translation,
        \WP_Term $source_term,
        string $language_code,
        ?\WP_Term $existing_translation = null
    ): array
    {
        $defaults = [
            'name'        => $existing_translation ? $existing_translation->name : $source_term->name,
            'slug'        => $existing_translation ? $existing_translation->slug : $source_term->slug,
            'description' => $existing_translation ? $existing_translation->description : $source_term->description,
        ];

        $args = [];
        $this->maybe_set_term_field($args, 'name', $translation, 'name', $defaults['name'], 'wp_strip_all_tags');
        // Slug: if not provided, leave untouched (no default) to avoid stealing the source slug on update.
        $this->maybe_set_term_field($args, 'slug', $translation, 'slug', $existing_translation ? $existing_translation->slug : ($existing_translation === null ? $source_term->slug : null), 'sanitize_title', false);
        $this->maybe_set_term_field($args, 'description', $translation, 'description', $defaults['description'], 'wp_kses_post');
        $args['parent'] = $this->resolve_term_parent_id($translation, $source_term, $language_code, $existing_translation);

        if (! isset($args['name']) || $args['name'] === '') {
            $args['name'] = $defaults['name'];
        }

        return $args;
    }

    private function resolve_term_parent_id(
        array $translation,
        \WP_Term $source_term,
        string $language_code,
        ?\WP_Term $existing_translation = null
    ): int
    {
        $taxonomy     = $source_term->taxonomy;
        $element_type = 'tax_' . $taxonomy;

        if (array_key_exists('parent_id', $translation)) {
            return $this->map_term_parent_id((int) $translation['parent_id'], $taxonomy, $language_code, $element_type);
        }

        if ($existing_translation && isset($existing_translation->parent) && (int) $existing_translation->parent > 0) {
            $existing_parent = (int) $existing_translation->parent;
            $existing_lang   = $this->get_term_language_details($existing_parent, $taxonomy);

            if (($existing_lang['language_code'] ?? '') === $language_code) {
                return $existing_parent;
            }
        }

        return $this->map_term_parent_id((int) $source_term->parent, $taxonomy, $language_code, $element_type);
    }

    private function map_term_parent_id(int $parent_id, string $taxonomy, string $language_code, string $element_type): int
    {
        if ($parent_id <= 0) {
            return 0;
        }

        $parent_term = get_term($parent_id, $taxonomy);

        if (! $parent_term || is_wp_error($parent_term)) {
            return 0;
        }

        $parent_lang = $this->get_term_language_details($parent_id, $taxonomy);

        if (($parent_lang['language_code'] ?? '') === $language_code) {
            return $parent_id;
        }

        $translated_parent_id = apply_filters('wpml_object_id', $parent_id, $taxonomy, false, $language_code);

        if ($translated_parent_id && (int) $translated_parent_id !== $parent_id) {
            return (int) $translated_parent_id;
        }

        return 0;
    }

    private function maybe_set_term_field(
        array &$data,
        string $target_key,
        array $translation,
        string $translation_key,
        $default = null,
        $sanitize_callback = null,
        bool $use_default_when_missing = true
    ): void
    {
        if (array_key_exists($translation_key, $translation)) {
            $value = $translation[$translation_key];
        } elseif ($use_default_when_missing && $default !== null) {
            $value = $default;
        } else {
            return;
        }

        if ($sanitize_callback && is_callable($sanitize_callback)) {
            $value = call_user_func($sanitize_callback, $value);
        }

        $data[$target_key] = $value;
    }

    private function sync_term_meta(int $term_id, string $taxonomy, array $meta, string $language_code = ''): bool
    {
        $rest_synced = $this->sync_term_meta_via_rest_bridge($term_id, $taxonomy, $meta, $language_code);

        $this->run_in_wpml_request_language(
            $language_code,
            function () use ($term_id, $taxonomy, $meta, $rest_synced): void {
                foreach ($meta as $key => $value) {
                    // Keep REST bridge as primary for general term meta, but always enforce Yoast
                    // keys directly because some WooCommerce/WPML stacks skip those on meta_all writes.
                    if ($rest_synced && $this->map_yoast_term_key((string) $key) === '') {
                        continue;
                    }

                    if ($this->maybe_set_yoast_term_meta($taxonomy, $term_id, $key, $value)) {
                        continue;
                    }

                    if ($value === null) {
                        delete_term_meta($term_id, $key);
                        continue;
                    }

                    update_term_meta($term_id, $key, $value);
                }
            }
        );

        return $rest_synced;
    }

    private function sync_term_meta_via_rest_bridge(int $term_id, string $taxonomy, array $meta, string $language_code = ''): bool
    {
        return $this->sync_term_state_via_rest_bridge(
            $term_id,
            $taxonomy,
            [],
            $meta,
            $language_code
        );
    }

    private function sync_term_state_via_rest_bridge(
        int $term_id,
        string $taxonomy,
        array $term_args,
        array $meta,
        string $language_code = ''
    ): bool {
        if ($taxonomy !== 'product_cat') {
            return false;
        }

        if (! class_exists('\\WP_REST_Request') || ! function_exists('rest_do_request')) {
            return false;
        }

        $payload = [];
        foreach (['name', 'slug', 'description', 'parent'] as $field) {
            if (array_key_exists($field, $term_args)) {
                $payload[$field] = $term_args[$field];
            }
        }

        if (! empty($meta)) {
            $payload['meta_all'] = $meta;
        }

        if (empty($payload)) {
            return false;
        }

        $response = $this->execute_term_rest_bridge_request($term_id, $taxonomy, $payload, $language_code);

        if ($this->is_rest_response_success($response)) {
            return true;
        }

        if (array_key_exists('slug', $payload)) {
            unset($payload['slug']);
            $retry_response = $this->execute_term_rest_bridge_request($term_id, $taxonomy, $payload, $language_code);
            if ($this->is_rest_response_success($retry_response)) {
                return true;
            }
        }

        return false;
    }

    private function execute_term_rest_bridge_request(
        int $term_id,
        string $taxonomy,
        array $payload,
        string $language_code = ''
    ) {
        return $this->run_in_wpml_request_language(
            $language_code,
            function () use ($term_id, $taxonomy, $payload, $language_code) {
                $request = new \WP_REST_Request('POST', '/wp/v2/' . $taxonomy . '/' . $term_id);
                $request->set_query_params([
                    'context'       => 'edit',
                    'lang'          => $language_code,
                    'wpml_language' => $language_code,
                ]);
                $request->set_body_params($payload);

                return rest_do_request($request);
            }
        );
    }

    private function is_rest_response_success($response): bool
    {
        if (is_wp_error($response)) {
            return false;
        }

        if (! is_object($response) || ! method_exists($response, 'get_status')) {
            return false;
        }

        return (int) $response->get_status() < 400;
    }

    private function maybe_set_yoast_term_meta(string $taxonomy, int $term_id, string $key, $value): bool
    {
        $mapped = $this->map_yoast_term_key($key);
        if ($mapped === '') {
            return false;
        }

        $yoast_value = ($value === null) ? '' : $value;
        $saved       = false;

        if (class_exists('\\WPSEO_Taxonomy_Meta')) {
            try {
                \WPSEO_Taxonomy_Meta::set_value($term_id, $taxonomy, $mapped, $yoast_value);
                $saved = true;
            } catch (\Throwable $e) {
                // fall back to the raw option update below
            }
        }

        if (! $saved) {
            $this->update_yoast_taxonomy_meta_option($taxonomy, $term_id, $mapped, $yoast_value);
        } else {
            $stored = $this->get_yoast_taxonomy_meta_option_value($taxonomy, $term_id, $mapped);
            if ($stored !== $yoast_value) {
                $this->update_yoast_taxonomy_meta_option($taxonomy, $term_id, $mapped, $yoast_value);
            }
        }

        // Also mirror to term meta keys so UI/plugins that read term_meta see values.
        if ($mapped === 'wpseo_title') {
            $this->set_or_delete_term_meta($term_id, 'wpseo_title', $value);
            $this->set_or_delete_term_meta($term_id, '_yoast_wpseo_title', $value);
        } elseif ($mapped === 'wpseo_desc') {
            $this->set_or_delete_term_meta($term_id, 'wpseo_metadesc', $value);
            $this->set_or_delete_term_meta($term_id, 'wpseo_desc', $value);
            $this->set_or_delete_term_meta($term_id, '_yoast_wpseo_metadesc', $value);
        } elseif ($mapped === 'wpseo_canonical') {
            $this->set_or_delete_term_meta($term_id, 'wpseo_canonical', $value);
            $this->set_or_delete_term_meta($term_id, '_yoast_wpseo_canonical', $value);
        }

        return true;
    }

    private function map_yoast_term_key(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return '';
        }

        if (strpos($key, '_yoast_wpseo_') === 0) {
            $rest = substr($key, strlen('_yoast_wpseo_'));
            if ($rest === 'metadesc' || $rest === 'metadescription') {
                return 'wpseo_desc';
            }
            return 'wpseo_' . $rest;
        }

        if ($key === 'wpseo_metadesc' || $key === 'wpseo_metadescription') {
            return 'wpseo_desc';
        }

        if (strpos($key, 'wpseo_') === 0) {
            return $key;
        }

        return '';
    }

    private function update_yoast_taxonomy_meta_option(string $taxonomy, int $term_id, string $yoast_key, $value): bool
    {
        $all = get_option('wpseo_taxonomy_meta');
        if (! is_array($all)) {
            $all = [];
        }
        if (! isset($all[$taxonomy]) || ! is_array($all[$taxonomy])) {
            $all[$taxonomy] = [];
        }

        $term_id = (int) $term_id;
        if (! isset($all[$taxonomy][$term_id]) || ! is_array($all[$taxonomy][$term_id])) {
            $all[$taxonomy][$term_id] = [];
        }

        $current = $all[$taxonomy][$term_id][$yoast_key] ?? null;

        if ($current === $value) {
            return false;
        }

        $all[$taxonomy][$term_id][$yoast_key] = $value;
        update_option('wpseo_taxonomy_meta', $all);

        return true;
    }

    private function get_yoast_taxonomy_meta_option_value(string $taxonomy, int $term_id, string $yoast_key)
    {
        $all = get_option('wpseo_taxonomy_meta');
        if (! is_array($all)) {
            return null;
        }

        return $all[$taxonomy][$term_id][$yoast_key] ?? null;
    }

    private function set_or_delete_term_meta(int $term_id, string $meta_key, $value): void
    {
        if ($value === null) {
            delete_term_meta($term_id, $meta_key);
            return;
        }

        update_term_meta($term_id, $meta_key, $value);
    }

    private function run_in_wpml_language(string $language_code, callable $callback)
    {
        $language_code = trim($language_code);
        if ($language_code === '') {
            return $callback();
        }

        global $sitepress;

        $restore_language = '';
        $switched         = false;

        try {
            if (is_object($sitepress) && method_exists($sitepress, 'get_current_language') && method_exists($sitepress, 'switch_lang')) {
                $restore_language = (string) $sitepress->get_current_language();

                if ($restore_language !== $language_code) {
                    $sitepress->switch_lang($language_code, false);
                    $switched = true;
                }
            }

            return $callback();
        } finally {
            if ($switched && is_object($sitepress) && method_exists($sitepress, 'switch_lang')) {
                $restore_to = $restore_language !== ''
                    ? $restore_language
                    : (string) apply_filters('wpml_default_language', null);

                if ($restore_to !== '') {
                    $sitepress->switch_lang($restore_to, false);
                }
            }
        }
    }

    private function run_in_wpml_request_language(string $language_code, callable $callback)
    {
        $language_code = trim($language_code);
        if ($language_code === '') {
            return $callback();
        }

        $restore = [
            'get_lang'             => $_GET['lang'] ?? null,
            'request_lang'         => $_REQUEST['lang'] ?? null,
            'get_wpml_language'    => $_GET['wpml_language'] ?? null,
            'request_wpml_language'=> $_REQUEST['wpml_language'] ?? null,
        ];

        $_GET['lang']              = $language_code;
        $_REQUEST['lang']          = $language_code;
        $_GET['wpml_language']     = $language_code;
        $_REQUEST['wpml_language'] = $language_code;

        try {
            return $this->run_in_wpml_language($language_code, $callback);
        } finally {
            $this->restore_request_value($_GET, 'lang', $restore['get_lang']);
            $this->restore_request_value($_REQUEST, 'lang', $restore['request_lang']);
            $this->restore_request_value($_GET, 'wpml_language', $restore['get_wpml_language']);
            $this->restore_request_value($_REQUEST, 'wpml_language', $restore['request_wpml_language']);
        }
    }

    private function restore_request_value(array &$target, string $key, $value): void
    {
        if ($value === null) {
            unset($target[$key]);
            return;
        }

        $target[$key] = $value;
    }

    private function touch_yoast_term_indexable(int $term_id, string $taxonomy, string $language_code = ''): void
    {
        $this->run_in_wpml_request_language(
            $language_code,
            function () use ($term_id, $taxonomy): void {
                $term  = get_term($term_id, $taxonomy);
                $tt_id = ($term && ! is_wp_error($term) && isset($term->term_taxonomy_id))
                    ? (int) $term->term_taxonomy_id
                    : 0;

                clean_term_cache([$term_id], $taxonomy);

                if (function_exists('YoastSEO') && class_exists('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository')) {
                    try {
                        $yoast = YoastSEO();
                        if (is_object($yoast) && isset($yoast->classes) && method_exists($yoast->classes, 'get')) {
                            $repo = $yoast->classes->get('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository');
                            if (is_object($repo)) {
                                if (method_exists($repo, 'delete_by_object_id_and_type')) {
                                    $repo->delete_by_object_id_and_type($term_id, 'term');
                                } elseif (method_exists($repo, 'find_by_id_and_type') && method_exists($repo, 'delete')) {
                                    $indexable = $repo->find_by_id_and_type($term_id, 'term');
                                    if ($indexable) {
                                        $repo->delete($indexable);
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // best effort
                    }
                }

                do_action('edited_term', $term_id, $tt_id, $taxonomy);
                do_action("edited_{$taxonomy}", $term_id, $tt_id);

                if (function_exists('YoastSEO') && class_exists('\\Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Term_Watcher')) {
                    try {
                        $yoast = YoastSEO();
                        if (is_object($yoast) && isset($yoast->classes) && method_exists($yoast->classes, 'get')) {
                            $watcher = $yoast->classes->get('\\Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Term_Watcher');
                            if (is_object($watcher) && method_exists($watcher, 'build_indexable')) {
                                $watcher->build_indexable($term_id);
                            }
                        }
                    } catch (\Throwable $e) {
                        // best effort
                    }
                }

                clean_term_cache([$term_id], $taxonomy);
            }
        );
    }
}
