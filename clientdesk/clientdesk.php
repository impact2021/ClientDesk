<?php
/**
 * Plugin Name: ClientDesk
 * Description: Plain-English website editing, page management, and SEO tools — powered by Impact Websites.
 * Version: 2.9.3
 * Author: impact2021
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CDC_VERSION', '2.9.3' );
define( 'CDC_URL',     plugin_dir_url( __FILE__ ) );
define( 'CDC_PATH',    plugin_dir_path( __FILE__ ) );

// Field keys
define( 'CDC_FIELD_HEADER',  '_iw_header' );
define( 'CDC_FIELD_BODY',    '_iw_body' );
define( 'CDC_FIELD_FOOTER',  '_iw_footer' );
define( 'CDC_FIELD_SCRIPTS', '_iw_scripts' );
define( 'CDC_GLOBAL_HEADER',  'iw_global_header' );
define( 'CDC_GLOBAL_FOOTER',  'iw_global_footer' );
define( 'CDC_GLOBAL_SCRIPTS', 'iw_global_scripts' );
define( 'CDC_GLOBAL_WOO_CSS', 'iw_global_woo_css' );
define( 'CDC_BRAND_PRIMARY',  'iw_brand_primary' );
define( 'CDC_BRAND_HOVER',    'iw_brand_hover' );
define( 'CDC_BRAND_LIGHT',    'iw_brand_light' );
define( 'CDC_META_TITLE', '_impact_websites_meta_title' );
define( 'CDC_META_DESC',  '_impact_websites_meta_desc' );
define( 'CDC_SCHEMA_KEY', 'cdc_schema_data' );
define( 'CDC_PAGE_SCHEMA_KEY', '_cdc_page_schema' );

final class ClientDesk {

    const MENU_SLUG     = 'clientdesk';
    const HISTORY_SLUG  = 'clientdesk-history';
    const SETTINGS_SLUG = 'clientdesk-settings';
    const GLOBALS_SLUG  = 'clientdesk-globals';
    const OPT_KEY         = 'cdc_licence_key';
    const OPT_ENDPOINT    = 'cdc_server_endpoint';
    const OPT_DOMAIN       = 'cdc_domain';
    const OPT_FONT_HEADING = 'cdc_font_heading';
    const OPT_FONT_BODY    = 'cdc_font_body';
    const OPT_LINK_COLOR   = 'cdc_link_color';
    const OPT_DEBUG        = 'cdc_debug_mode';
    const LOG_FILE         = 'cdc-debug.log';

    // ---------------------------------------------------------------
    // Debug logger
    // ---------------------------------------------------------------

    public static function log( string $context, $data ): void {
        if ( ! get_option( self::OPT_DEBUG ) ) return;
        $log_path = CDC_PATH . self::LOG_FILE;
        $line     = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [' . $context . '] ';
        $line    .= is_string( $data ) ? $data : json_encode( $data, JSON_PRETTY_PRINT );
        $line    .= "\n";
        file_put_contents( $log_path, $line, FILE_APPEND | LOCK_EX );
    }

    public static function log_clear(): void {
        $log_path = CDC_PATH . self::LOG_FILE;
        if ( file_exists( $log_path ) ) unlink( $log_path );
    }

    public function __construct() {
        // Front end
        add_action( 'wp_head',               [ $this, 'inject_meta' ], 1 );
        add_action( 'wp_head',               [ $this, 'inject_schema' ], 2 );
        add_action( 'wp_head',               [ $this, 'output_scripts' ], 99 );
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_frontend' ] );
        // CPT + menus
        add_action( 'init',                  [ $this, 'register_cpt' ] );
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // Meta box
        add_action( 'add_meta_boxes',        [ $this, 'register_meta_box' ] );
        add_action( 'save_post_page',        [ $this, 'save_meta_box' ], 20 );
        add_action( 'save_post_page',        [ $this, 'auto_populate' ], 10, 2 );
        // Globals save
        add_action( 'admin_post_cdc_save_globals', [ $this, 'save_globals' ] );
        // Disable block editor for pages
        add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_block_editor' ], 10, 2 );
        // AJAX
        add_action( 'wp_ajax_cdc_chat',           [ $this, 'ajax_chat' ] );
        add_action( 'wp_ajax_cdc_rollback',       [ $this, 'ajax_rollback' ] );
        add_action( 'wp_ajax_cdc_get_seo',        [ $this, 'ajax_get_seo' ] );
        add_action( 'wp_ajax_cdc_apply_seo',      [ $this, 'ajax_apply_seo' ] );
        add_action( 'wp_ajax_cdc_analyse_seo',    [ $this, 'ajax_analyse_seo' ] );
        add_action( 'wp_ajax_cdc_get_usage',      [ $this, 'ajax_get_usage' ] );
        add_action( 'wp_ajax_cdc_image_swap',     [ $this, 'ajax_image_swap' ] );
        add_action( 'wp_ajax_cdc_get_images',     [ $this, 'ajax_get_images' ] );
        add_action( 'wp_ajax_cdc_stream_chat',    [ $this, 'ajax_stream_chat' ] );
        add_action( 'wp_ajax_cdc_apply_streamed', [ $this, 'ajax_apply_streamed' ] );
        add_action( 'wp_ajax_cdc_create_page',    [ $this, 'ajax_create_page' ] );
        add_action( 'wp_ajax_cdc_save_schema',    [ $this, 'ajax_save_schema' ] );
        add_action( 'wp_ajax_cdc_get_schema',     [ $this, 'ajax_get_schema' ] );
        add_action( 'wp_ajax_cdc_save_fonts',     [ $this, 'ajax_save_fonts' ] );
        add_action( 'wp_ajax_cdc_restore_change', [ $this, 'ajax_restore_change' ] );
    }

    // ---------------------------------------------------------------
    // Theme render functions — called from impact-websites theme
    // ---------------------------------------------------------------

    public function enqueue_frontend(): void {
        if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() ) ) {
            $woo_css = (string) get_option( CDC_GLOBAL_WOO_CSS, '' );
            if ( '' !== trim( $woo_css ) ) {
                wp_register_style( 'cdc-global-woo-css', false, [], CDC_VERSION );
                wp_enqueue_style( 'cdc-global-woo-css' );
                wp_add_inline_style( 'cdc-global-woo-css', $woo_css );
            }
        }
    }

    private function sanitize_brand_light( string $value ): string {
        $value = (string) preg_replace( '/[^#a-fA-F0-9]/', '', $value );
        if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
            return '#003D5E18';
        }
        return $value;
    }

    public function output_scripts(): void {
        // Google Fonts injection — builds URL from heading + body font settings
        $font_heading = trim( (string) get_option( self::OPT_FONT_HEADING, '' ) );
        $font_body    = trim( (string) get_option( self::OPT_FONT_BODY, '' ) );
        $link_color   = sanitize_hex_color( trim( (string) get_option( self::OPT_LINK_COLOR, '' ) ) ) ?: '';
        $brand_primary = sanitize_hex_color( (string) get_option( CDC_BRAND_PRIMARY, '#003D5E' ) ) ?: '#003D5E';
        $brand_hover   = sanitize_hex_color( (string) get_option( CDC_BRAND_HOVER, '#00527e' ) ) ?: '#00527e';
        $brand_light   = $this->sanitize_brand_light( (string) get_option( CDC_BRAND_LIGHT, '#003D5E18' ) );

        if ( $font_heading || $font_body ) {
            $families = [];
            if ( $font_heading ) $families[] = str_replace( ' ', '+', $font_heading ) . ':ital,wght@0,400;0,600;0,700;1,400';
            if ( $font_body && $font_body !== $font_heading ) $families[] = str_replace( ' ', '+', $font_body ) . ':ital,wght@0,400;0,500;0,600;1,400';

            $font_url = 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', $families ) . '&display=swap';

            echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"; // phpcs:ignore
            echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"; // phpcs:ignore
            echo '<link rel="stylesheet" href="' . esc_url( $font_url ) . '">' . "\n"; // phpcs:ignore

        }

        if ( $font_heading || $font_body || $link_color || $brand_primary || $brand_hover || $brand_light ) {
            echo '<style id="cdc-font-apply">' . "\n"; // phpcs:ignore
            echo ':root { --iw-brand: ' . esc_attr( $brand_primary ) . '; --iw-brand-hover: ' . esc_attr( $brand_hover ) . '; --iw-brand-light: ' . esc_attr( $brand_light ) . '; }' . "\n"; // phpcs:ignore
            if ( $font_body ) {
                $fb_css = esc_attr( $font_heading === $font_body ? $font_heading : $font_body );
                echo 'body, p, li, td, input, select, textarea, button { font-family: \'' . $fb_css . '\', sans-serif !important; }' . "\n"; // phpcs:ignore
            }
            if ( $font_heading ) {
                $fh_css = esc_attr( $font_heading );
                echo 'h1, h2, h3, h4, h5, h6 { font-family: \'' . $fh_css . '\', sans-serif !important; }' . "\n"; // phpcs:ignore
            }
            if ( $link_color ) {
                echo 'a, a:visited { color: ' . esc_attr( $link_color ) . ' !important; }' . "\n"; // phpcs:ignore
            }
            echo '</style>' . "\n"; // phpcs:ignore
        }

        // Global scripts
        $global = (string) get_option( CDC_GLOBAL_SCRIPTS, '' );
        if ( '' !== trim( $global ) ) {
            echo "\n" . $global . "\n"; // phpcs:ignore
        }
    }

    // ---------------------------------------------------------------
    // Front-end meta injection
    // ---------------------------------------------------------------

    public function inject_meta(): void {
        if ( ! is_singular( 'page' ) ) return;
        $id    = get_the_ID();
        $title = (string) get_post_meta( $id, CDC_META_TITLE, true );
        $desc  = (string) get_post_meta( $id, CDC_META_DESC,  true );
        $url   = get_permalink( $id );

        remove_action( 'wp_head', '_wp_render_title_tag', 1 );

        if ( $title ) {
            echo '<title>' . esc_html( $title ) . '</title>' . "\n";
            echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
        } else {
            echo '<title>' . esc_html( get_the_title( $id ) . ' — ' . get_bloginfo( 'name' ) ) . '</title>' . "\n";
        }
        if ( $desc ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
            echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
        }
        echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
    }

    // ---------------------------------------------------------------
    // Front-end schema injection
    // ---------------------------------------------------------------

    public function inject_schema(): void {
        $schema = (array) get_option( CDC_SCHEMA_KEY, [] );
        if ( empty( $schema ) || empty( $schema['type'] ) ) return;

        // Page-specific schema override
        if ( is_singular( 'page' ) ) {
            $page_id     = get_the_ID();
            $page_schema = get_post_meta( $page_id, CDC_PAGE_SCHEMA_KEY, true );
            if ( $page_schema && is_array( $page_schema ) && ! empty( $page_schema['enabled'] ) && ! empty( $page_schema['json'] ) ) {
                $json = json_decode( $page_schema['json'], true );
                if ( $json ) {
                    echo '<script type="application/ld+json">' . "\n" . wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" . '</script>' . "\n"; // phpcs:ignore
                    return;
                }
            }
        }

        // Build global schema JSON-LD
        $ld = $this->build_schema_jsonld( $schema );
        if ( $ld ) {
            echo '<script type="application/ld+json">' . "\n" . wp_json_encode( $ld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" . '</script>' . "\n"; // phpcs:ignore
        }
    }

    private function build_schema_jsonld( array $s ): array {
        $type   = $s['type']   ?? 'LocalBusiness';
        $name   = $s['name']   ?? get_bloginfo( 'name' );
        $url    = $s['url']    ?? home_url();
        $phone  = $s['phone']  ?? '';
        $email  = $s['email']  ?? '';
        $desc   = $s['desc']   ?? '';
        $logo   = $s['logo']   ?? '';
        $addr   = $s['addr']   ?? '';
        $city   = $s['city']   ?? '';
        $region = $s['region'] ?? '';
        $post   = $s['post']   ?? '';
        $country = $s['country'] ?? 'NZ';
        $area   = $s['area']   ?? '';
        $socials = array_filter( (array) ( $s['socials'] ?? [] ) );
        $reviews = array_filter( (array) ( $s['reviews'] ?? [] ) );

        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => $type,
            'name'     => $name,
            'url'      => $url,
        ];

        if ( $desc )  $ld['description'] = $desc;
        if ( $phone ) $ld['telephone']   = $phone;
        if ( $email ) $ld['email']       = $email;
        if ( $area )  $ld['areaServed']  = $area;

        if ( $logo ) {
            $ld['logo'] = [ '@type' => 'ImageObject', 'url' => $logo ];
            $ld['image'] = $logo;
        }

        if ( $addr || $city ) {
            $ld['address'] = array_filter( [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $addr,
                'addressLocality' => $city,
                'addressRegion'   => $region,
                'postalCode'      => $post,
                'addressCountry'  => $country,
            ] );
        }

        // Opening hours
        $hours_ld = [];
        $days = [ 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday' ];
        foreach ( $days as $day ) {
            $key = strtolower( $day );
            $open  = $s[ 'hours_' . $key . '_open' ]  ?? '';
            $close = $s[ 'hours_' . $key . '_close' ] ?? '';
            $closed = ! empty( $s[ 'hours_' . $key . '_closed' ] );
            if ( $closed || ( ! $open && ! $close ) ) continue;
            $hours_ld[] = [ '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/' . $day, 'opens' => $open, 'closes' => $close ];
        }
        if ( $hours_ld ) $ld['openingHoursSpecification'] = $hours_ld;

        if ( $socials ) $ld['sameAs'] = array_values( $socials );

        // Reviews
        if ( $reviews ) {
            $ld['review'] = array_map( function( $r ) {
                return [
                    '@type'        => 'Review',
                    'author'       => [ '@type' => 'Person', 'name' => $r['author'] ?? 'Customer' ],
                    'reviewRating' => [ '@type' => 'Rating', 'ratingValue' => (int) ( $r['rating'] ?? 5 ), 'bestRating' => 5, 'worstRating' => 1 ],
                    'reviewBody'   => $r['text'] ?? '',
                    'datePublished' => $r['date'] ?? '',
                ];
            }, $reviews );

            $total = count( $reviews );
            $avg   = $total > 0 ? round( array_sum( array_column( $reviews, 'rating' ) ) / $total, 1 ) : 5;
            $ld['aggregateRating'] = [ '@type' => 'AggregateRating', 'ratingValue' => $avg, 'reviewCount' => $total, 'bestRating' => 5, 'worstRating' => 1 ];
        }

        $ld['mainEntityOfPage'] = [ '@type' => 'WebPage', '@id' => $url ];

        return $ld;
    }

    // ---------------------------------------------------------------
    // AJAX — schema save / get
    // ---------------------------------------------------------------

    public function ajax_save_schema(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $raw = isset( $_POST['schema'] ) ? wp_unslash( $_POST['schema'] ) : '';
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) wp_send_json_error( [ 'message' => 'Invalid data.' ] );

        // Sanitize each field
        $allowed = [ 'type','name','url','phone','email','desc','logo','addr','city','region','post','country','area','socials','reviews',
                     'hours_monday_open','hours_monday_close','hours_monday_closed',
                     'hours_tuesday_open','hours_tuesday_close','hours_tuesday_closed',
                     'hours_wednesday_open','hours_wednesday_close','hours_wednesday_closed',
                     'hours_thursday_open','hours_thursday_close','hours_thursday_closed',
                     'hours_friday_open','hours_friday_close','hours_friday_closed',
                     'hours_saturday_open','hours_saturday_close','hours_saturday_closed',
                     'hours_sunday_open','hours_sunday_close','hours_sunday_closed',
                     'wizard_complete' ];

        $clean = [];
        foreach ( $allowed as $k ) {
            if ( ! isset( $data[ $k ] ) ) continue;
            if ( $k === 'socials' ) {
                $clean['socials'] = array_map( 'esc_url_raw', (array) $data['socials'] );
            } elseif ( $k === 'reviews' ) {
                $clean['reviews'] = array_map( function( $r ) {
                    return [
                        'author' => sanitize_text_field( $r['author'] ?? '' ),
                        'rating' => max( 1, min( 5, (int) ( $r['rating'] ?? 5 ) ) ),
                        'text'   => sanitize_textarea_field( $r['text'] ?? '' ),
                        'date'   => sanitize_text_field( $r['date'] ?? '' ),
                    ];
                }, (array) $data['reviews'] );
            } elseif ( in_array( $k, [ 'url', 'logo' ], true ) ) {
                $clean[ $k ] = esc_url_raw( (string) $data[ $k ] );
            } elseif ( str_ends_with( $k, '_closed' ) ) {
                $clean[ $k ] = (bool) $data[ $k ];
            } else {
                $clean[ $k ] = sanitize_text_field( (string) $data[ $k ] );
            }
        }

        // Page-specific schema
        if ( isset( $data['page_schema_id'], $data['page_schema_json'] ) ) {
            $page_id = (int) $data['page_schema_id'];
            if ( $page_id > 0 ) {
                $page_data = [
                    'enabled' => ! empty( $data['page_schema_enabled'] ),
                    'json'    => sanitize_textarea_field( wp_unslash( $data['page_schema_json'] ) ),
                ];
                update_post_meta( $page_id, CDC_PAGE_SCHEMA_KEY, $page_data );
            }
        }

        update_option( CDC_SCHEMA_KEY, $clean );
        wp_send_json_success( [ 'message' => 'Schema saved.' ] );
    }

    public function ajax_get_schema(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $schema = (array) get_option( CDC_SCHEMA_KEY, [] );

        // If a page_id is passed, also return page-specific schema
        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        $page_schema = $page_id > 0 ? (array) get_post_meta( $page_id, CDC_PAGE_SCHEMA_KEY, true ) : [];

        wp_send_json_success( [ 'schema' => $schema, 'page_schema' => $page_schema ] );
    }

    public function disable_block_editor( bool $use, string $post_type ): bool {
        return $post_type === 'page' ? false : $use;
    }

    // ---------------------------------------------------------------
    // Auto-populate new pages
    // ---------------------------------------------------------------

    public function auto_populate( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $header  = get_post_meta( $post_id, CDC_FIELD_HEADER, true );
        $body    = get_post_meta( $post_id, CDC_FIELD_BODY,   true );
        $footer  = get_post_meta( $post_id, CDC_FIELD_FOOTER, true );
        if ( '' !== $header || '' !== $body || '' !== $footer ) return;

        $title = esc_html( $post->post_title ?: 'Page' );
        update_post_meta( $post_id, CDC_FIELD_HEADER,  '' );
        update_post_meta( $post_id, CDC_FIELD_BODY,    "<main class=\"iw-main\" role=\"main\">\n\n    <!-- {$title} content goes here -->\n\n</main>" );
        update_post_meta( $post_id, CDC_FIELD_FOOTER,  '' );
        update_post_meta( $post_id, CDC_FIELD_SCRIPTS, '' );
    }

    // ---------------------------------------------------------------
    // Meta box
    // ---------------------------------------------------------------

    public function register_meta_box(): void {
        add_meta_box( 'cdc_content_fields', 'Page Content — Impact Websites', [ $this, 'render_meta_box' ], 'page', 'normal', 'high' );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'cdc_save_fields', 'cdc_mb_nonce' );
        $header     = (string) get_post_meta( $post->ID, CDC_FIELD_HEADER,  true );
        $body       = (string) get_post_meta( $post->ID, CDC_FIELD_BODY,    true );
        $footer     = (string) get_post_meta( $post->ID, CDC_FIELD_FOOTER,  true );
        $scripts    = (string) get_post_meta( $post->ID, CDC_FIELD_SCRIPTS, true );
        $meta_title = (string) get_post_meta( $post->ID, CDC_META_TITLE,    true );
        $meta_desc  = (string) get_post_meta( $post->ID, CDC_META_DESC,     true );
        $globals_url = esc_url( admin_url( 'admin.php?page=' . self::GLOBALS_SLUG ) );
        ?>
        <div class="cdc-metabox">
            <div class="cdc-tabs">
                <button type="button" class="cdc-tab cdc-tab--active" data-target="cdc-panel-body">Body</button>
                <button type="button" class="cdc-tab" data-target="cdc-panel-header">Header</button>
                <button type="button" class="cdc-tab" data-target="cdc-panel-footer">Footer</button>
                <button type="button" class="cdc-tab" data-target="cdc-panel-scripts">Scripts</button>
                <button type="button" class="cdc-tab" data-target="cdc-panel-seo">SEO</button>
            </div>
            <div id="cdc-panel-body" class="cdc-panel cdc-panel--active">
                <p class="cdc-panel-desc">Main page content. This is what ClientDesk edits by default.</p>
                <textarea id="cdc-field-body" name="cdc_body" class="cdc-code-editor"><?php echo esc_textarea( $body ); ?></textarea>
            </div>
            <div id="cdc-panel-header" class="cdc-panel">
                <p class="cdc-panel-desc">Per-page header override. Leave empty to use the <a href="<?php echo $globals_url; ?>">Global Header</a>.</p>
                <textarea id="cdc-field-header" name="cdc_header" class="cdc-code-editor"><?php echo esc_textarea( $header ); ?></textarea>
            </div>
            <div id="cdc-panel-footer" class="cdc-panel">
                <p class="cdc-panel-desc">Per-page footer override. Leave empty to use the <a href="<?php echo $globals_url; ?>">Global Footer</a>.</p>
                <textarea id="cdc-field-footer" name="cdc_footer" class="cdc-code-editor"><?php echo esc_textarea( $footer ); ?></textarea>
            </div>
            <div id="cdc-panel-scripts" class="cdc-panel">
                <p class="cdc-panel-desc">Per-page scripts in <code>&lt;head&gt;</code>. Leave empty to use <a href="<?php echo $globals_url; ?>">Global Scripts</a>.</p>
                <textarea id="cdc-field-scripts" name="cdc_scripts" class="cdc-code-editor"><?php echo esc_textarea( $scripts ); ?></textarea>
            </div>
            <div id="cdc-panel-seo" class="cdc-panel">
                <p class="cdc-panel-desc">SEO fields for this page. Also editable via ClientDesk.</p>
                <div class="cdc-seo-fields">
                    <div class="cdc-seo-field-row">
                        <label for="cdc-meta-title">Meta Title</label>
                        <input type="text" id="cdc-meta-title" name="cdc_meta_title" value="<?php echo esc_attr( $meta_title ); ?>" />
                        <span class="cdc-char-count" data-max="60" data-field="cdc-meta-title"><span class="cdc-count"><?php echo mb_strlen( $meta_title ); ?></span> / 60</span>
                    </div>
                    <div class="cdc-seo-field-row">
                        <label for="cdc-meta-desc">Meta Description</label>
                        <textarea id="cdc-meta-desc" name="cdc_meta_desc" rows="3"><?php echo esc_textarea( $meta_desc ); ?></textarea>
                        <span class="cdc-char-count" data-max="155" data-field="cdc-meta-desc"><span class="cdc-count"><?php echo mb_strlen( $meta_desc ); ?></span> / 155</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_meta_box( int $post_id ): void {
        if ( ! isset( $_POST['cdc_mb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cdc_mb_nonce'] ) ), 'cdc_save_fields' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        $map = [
            'cdc_header'     => CDC_FIELD_HEADER,
            'cdc_body'       => CDC_FIELD_BODY,
            'cdc_footer'     => CDC_FIELD_FOOTER,
            'cdc_scripts'    => CDC_FIELD_SCRIPTS,
            'cdc_meta_title' => CDC_META_TITLE,
            'cdc_meta_desc'  => CDC_META_DESC,
        ];
        foreach ( $map as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                update_post_meta( $post_id, $meta_key, wp_unslash( $_POST[ $post_key ] ) );
            }
        }
    }

    // ---------------------------------------------------------------
    // CPT
    // ---------------------------------------------------------------

    public function register_cpt(): void {
        register_post_type( 'cdc_change', [
            'public' => false, 'show_ui' => false, 'show_in_menu' => false,
            'exclude_from_search' => true, 'supports' => [ 'title', 'author' ],
            'labels' => [ 'name' => 'Changes', 'singular_name' => 'Change' ],
        ] );
    }

    // ---------------------------------------------------------------
    // Menus
    // ---------------------------------------------------------------

    public function register_menus(): void {
        $cap = $this->cap();
        add_menu_page( 'ClientDesk', 'ClientDesk', $cap, self::MENU_SLUG, [ $this, 'render_chat' ], 'dashicons-edit-page', 59 );
        add_submenu_page( self::MENU_SLUG, 'Make a Change',   'Make a Change',   $cap, self::MENU_SLUG,     [ $this, 'render_chat' ] );
        add_submenu_page( self::MENU_SLUG, 'Change History',  'Change History',  $cap, self::HISTORY_SLUG,  [ $this, 'render_history' ] );
        add_submenu_page( self::MENU_SLUG, 'Global Content',  'Global Content',  'manage_options', self::GLOBALS_SLUG, [ $this, 'render_globals' ] );
        add_submenu_page( self::MENU_SLUG, 'Settings',        'Settings',        $cap, self::SETTINGS_SLUG, [ $this, 'render_settings' ] );
    }

    // ---------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------

    public function register_settings(): void {
        foreach ( [ self::OPT_KEY, self::OPT_ENDPOINT, self::OPT_DOMAIN, self::OPT_FONT_HEADING, self::OPT_FONT_BODY, self::OPT_DEBUG ] as $opt ) {
            register_setting( 'cdc_settings', $opt, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        }
        add_action( 'admin_post_cdc_clear_log', [ $this, 'clear_log' ] );
    }

    public function clear_log(): void {
        check_admin_referer( 'cdc_clear_log' );
        if ( current_user_can( 'manage_options' ) ) self::log_clear();
        wp_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&log_cleared=1' ) );
        exit;
    }

    // ---------------------------------------------------------------
    // Assets
    // ---------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        // ClientDesk chat pages
        if ( false !== strpos( $hook, self::MENU_SLUG ) || false !== strpos( $hook, self::HISTORY_SLUG ) ) {
            wp_enqueue_style( 'cdc-ui', CDC_URL . 'assets/clientdesk.css', [], CDC_VERSION );
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
            wp_enqueue_media();
        }
        // Meta box + globals page — code editors
        $screen = get_current_screen();
        $is_page_edit = in_array( $hook, [ 'post.php', 'post-new.php' ], true ) && $screen && $screen->post_type === 'page';
        $is_globals   = false !== strpos( $hook, self::GLOBALS_SLUG );
        if ( $is_page_edit || $is_globals ) {
            $settings = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
            wp_enqueue_script( 'wp-theme-plugin-editor' );
            wp_enqueue_style( 'wp-codemirror' );
            wp_enqueue_style( 'cdc-metabox', CDC_URL . 'assets/meta-box.css', [], CDC_VERSION );
            wp_enqueue_script( 'cdc-metabox', CDC_URL . 'assets/meta-box.js', [ 'jquery', 'code-editor' ], CDC_VERSION, true );
            wp_localize_script( 'cdc-metabox', 'cdcMetaBox', [ 'cmSettings' => $settings ?: [] ] );
        }
    }

    // ---------------------------------------------------------------
    // Global content page
    // ---------------------------------------------------------------

    public function render_globals(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        $global_header  = (string) get_option( CDC_GLOBAL_HEADER,  '' );
        $global_footer  = (string) get_option( CDC_GLOBAL_FOOTER,  '' );
        $global_scripts = (string) get_option( CDC_GLOBAL_SCRIPTS, '' );
        $global_woo_css = (string) get_option( CDC_GLOBAL_WOO_CSS, '' );
        $brand_primary  = sanitize_hex_color( (string) get_option( CDC_BRAND_PRIMARY, '#003D5E' ) ) ?: '#003D5E';
        $brand_hover    = sanitize_hex_color( (string) get_option( CDC_BRAND_HOVER, '#00527e' ) ) ?: '#00527e';
        $brand_light    = $this->sanitize_brand_light( (string) get_option( CDC_BRAND_LIGHT, '#003D5E18' ) );
        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Global content saved.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Global Content — Impact Websites</h1>
            <p>Site Header and Footer render on every page unless a per-page override is set. Scripts inject into <code>&lt;head&gt;</code> on every page.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cdc_save_globals', 'cdc_globals_nonce' ); ?>
                <input type="hidden" name="action" value="cdc_save_globals" />
                <div class="cdc-globals-wrap">
                    <div class="cdc-tabs">
                        <button type="button" class="cdc-tab cdc-tab--active" data-target="cdc-g-header">Site Header</button>
                        <button type="button" class="cdc-tab" data-target="cdc-g-footer">Site Footer</button>
                        <button type="button" class="cdc-tab" data-target="cdc-g-scripts">Scripts / Tracking</button>
                        <button type="button" class="cdc-tab" data-target="cdc-g-brand-colours">Brand Colours</button>
                        <button type="button" class="cdc-tab" data-target="cdc-g-woo-css">WooCommerce CSS</button>
                    </div>
                    <div id="cdc-g-header" class="cdc-panel cdc-panel--active">
                        <p class="cdc-panel-desc">Renders as the header on every page. Editable via ClientDesk by selecting "Site Header".</p>
                        <textarea name="cdc_global_header" class="cdc-code-editor cdc-global-editor"><?php echo esc_textarea( $global_header ); ?></textarea>
                    </div>
                    <div id="cdc-g-footer" class="cdc-panel">
                        <p class="cdc-panel-desc">Renders as the footer on every page. Editable via ClientDesk by selecting "Site Footer".</p>
                        <textarea name="cdc_global_footer" class="cdc-code-editor cdc-global-editor"><?php echo esc_textarea( $global_footer ); ?></textarea>
                    </div>
                    <div id="cdc-g-scripts" class="cdc-panel">
                        <p class="cdc-panel-desc">Paste analytics, tracking pixels, or any other <code>&lt;head&gt;</code> code here. Renders on every page.</p>
                        <textarea name="cdc_global_scripts" class="cdc-code-editor cdc-global-editor"><?php echo esc_textarea( $global_scripts ); ?></textarea>
                    </div>
                    <div id="cdc-g-brand-colours" class="cdc-panel">
                        <p class="cdc-panel-desc">Sets CSS variables used site-wide. Any CSS using <code>var(--iw-brand)</code>, <code>var(--iw-brand-hover)</code>, or <code>var(--iw-brand-light)</code> will update automatically.</p>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="cdc_brand_primary">Primary brand colour</label></th>
                                    <td><input type="color" id="cdc_brand_primary" name="cdc_brand_primary" value="<?php echo esc_attr( $brand_primary ); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="cdc_brand_hover">Hover colour</label></th>
                                    <td><input type="color" id="cdc_brand_hover" name="cdc_brand_hover" value="<?php echo esc_attr( $brand_hover ); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="cdc_brand_light">Light tint (supports alpha hex, e.g. #003D5E18)</label></th>
                                    <td><input type="text" id="cdc_brand_light" name="cdc_brand_light" class="regular-text" placeholder="#003D5E18" pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$" value="<?php echo esc_attr( $brand_light ); ?>" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="cdc-g-woo-css" class="cdc-panel">
                        <p class="cdc-panel-desc">CSS injected on WooCommerce pages only (shop, product, cart, checkout). Does not load on any other page.</p>
                        <textarea name="cdc_global_woo_css" class="cdc-code-editor cdc-global-editor"><?php echo esc_textarea( $global_woo_css ); ?></textarea>
                    </div>
                </div>
                <p><button type="submit" class="button button-primary button-large">Save Global Content</button></p>
            </form>
        </div>
        <?php
    }

    public function save_globals(): void {
        if ( ! isset( $_POST['cdc_globals_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cdc_globals_nonce'] ) ), 'cdc_save_globals' ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        update_option( CDC_GLOBAL_HEADER,  wp_unslash( $_POST['cdc_global_header']  ?? '' ) );
        update_option( CDC_GLOBAL_FOOTER,  wp_unslash( $_POST['cdc_global_footer']  ?? '' ) );
        update_option( CDC_GLOBAL_SCRIPTS, wp_unslash( $_POST['cdc_global_scripts'] ?? '' ) );
        update_option( CDC_GLOBAL_WOO_CSS, wp_unslash( $_POST['cdc_global_woo_css'] ?? '' ) );
        update_option( CDC_BRAND_PRIMARY, sanitize_hex_color( wp_unslash( $_POST['cdc_brand_primary'] ?? '#003D5E' ) ) );
        update_option( CDC_BRAND_HOVER, sanitize_hex_color( wp_unslash( $_POST['cdc_brand_hover'] ?? '#00527e' ) ) );
        update_option( CDC_BRAND_LIGHT, $this->sanitize_brand_light( (string) wp_unslash( $_POST['cdc_brand_light'] ?? '#003D5E18' ) ) );
        wp_redirect( admin_url( 'admin.php?page=' . self::GLOBALS_SLUG . '&saved=1' ) );
        exit;
    }

    // ---------------------------------------------------------------
    // AJAX — chat
    // ---------------------------------------------------------------

    public function ajax_chat(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        $field   = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'body';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : [];

        if ( $page_id === 0 ) wp_send_json_error( [ 'message' => 'Please select a page first.' ] );
        if ( '' === $message ) wp_send_json_error( [ 'message' => 'Message cannot be empty.' ] );
        if ( ! is_array( $history ) ) $history = [];

        $page_html = $this->get_field_content( $page_id, $field );
        if ( is_wp_error( $page_html ) ) wp_send_json_error( [ 'message' => $page_html->get_error_message() ] );

        $page_title = match( $page_id ) {
            -1      => 'Site Header',
            -2      => 'Site Footer',
            default => ( ( $p = get_post( $page_id ) ) ? $p->post_title : 'this page' ),
        };

        [ $licence_key, $endpoint, $domain ] = $this->config();
        if ( ! $licence_key || ! $endpoint ) wp_send_json_error( [ 'message' => 'ClientDesk is not configured. Go to ClientDesk → Settings.' ] );

        $response = wp_remote_post( $endpoint, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'licence_key'   => $licence_key,
                'domain'        => $domain,
                'page_title'    => $page_title,
                'page_html'     => $this->minify_html( $page_html ),
                'history'       => $history,
                'message'       => $message,
                'font_heading'  => trim( (string) get_option( self::OPT_FONT_HEADING, '' ) ),
                'font_body'     => trim( (string) get_option( self::OPT_FONT_BODY, '' ) ),
            ] ),
            'timeout' => 120,
        ] );

        if ( is_wp_error( $response ) ) wp_send_json_error( [ 'message' => 'Could not reach ClientDesk server: ' . $response->get_error_message() ] );

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || ! is_array( $body ) ) wp_send_json_error( [ 'message' => "Unexpected response from server (HTTP {$code})." ] );
        if ( ! ( $body['success'] ?? false ) ) wp_send_json_error( [ 'message' => $body['error'] ?? 'Server returned an error.' ] );

        $action_data = $body['action'] ?? null;
        $new_history = $body['history'] ?? $history;

        if ( is_array( $action_data ) && ( $action_data['action'] ?? '' ) === 'apply' ) {
            $new_html = (string) ( $action_data['html'] ?? '' );
            $summary  = (string) ( $action_data['summary'] ?? 'Change applied.' );
            if ( '' === $new_html ) wp_send_json_error( [ 'message' => 'Server returned an empty HTML payload.' ] );
            $write = $this->write_field_content( $page_id, $field, $new_html );
            if ( is_wp_error( $write ) ) wp_send_json_error( [ 'message' => 'Could not save: ' . $write->get_error_message() ] );
            $this->log_change( $page_id, $summary );
            wp_send_json_success( [ 'type' => 'applied', 'message' => $summary . ' Live on your site now. Use "Undo" below if needed.', 'page_url' => get_permalink( $page_id ) ?: '', 'history' => $new_history, 'page_id' => $page_id, 'spent_fmt' => $body['spent_fmt'] ?? null, 'budget_fmt' => $body['budget_fmt'] ?? null, 'remaining_fmt' => $body['remaining_fmt'] ?? null, 'show_warning' => $body['show_warning'] ?? false, 'contact_phone' => $body['contact_phone'] ?? '', 'contact_email' => $body['contact_email'] ?? '' ] );
            return;
        }

        if ( is_array( $action_data ) && ( $action_data['action'] ?? '' ) === 'need_image' ) {
            wp_send_json_success( [ 'type' => 'need_image', 'message' => $action_data['message'] ?? 'Please select an image.', 'history' => $new_history ] );
            return;
        }

        if ( is_array( $action_data ) && ( $action_data['action'] ?? '' ) === 'update_meta' ) {
            $meta_field = sanitize_key( (string) ( $action_data['field'] ?? '' ) );
            $meta_value = sanitize_textarea_field( (string) ( $action_data['value'] ?? '' ) );
            $meta_key   = $meta_field === 'meta_title' ? CDC_META_TITLE : CDC_META_DESC;
            if ( $meta_field && $meta_value && $page_id > 0 ) {
                update_post_meta( $page_id, $meta_key, $meta_value );
                $this->log_change( $page_id, ucfirst( str_replace( '_', ' ', $meta_field ) ) . ' updated.' );
            }
            wp_send_json_success( [ 'type' => 'meta_updated', 'field' => $meta_field, 'message' => ( $meta_field === 'meta_title' ? 'Meta title' : 'Meta description' ) . ' updated. Live on your site now.', 'history' => $new_history, 'page_id' => $page_id, 'spent_fmt' => $body['spent_fmt'] ?? null, 'budget_fmt' => $body['budget_fmt'] ?? null, 'remaining_fmt' => $body['remaining_fmt'] ?? null, 'show_warning' => $body['show_warning'] ?? false, 'contact_phone' => $body['contact_phone'] ?? '', 'contact_email' => $body['contact_email'] ?? '' ] );
            return;
        }

        wp_send_json_success( [ 'type' => 'message', 'message' => $body['raw'] ?? '', 'history' => $new_history ] );
    }

    // ---------------------------------------------------------------
    // AJAX — stream chat (SSE proxy to MasterDesk)
    // ---------------------------------------------------------------

    public function ajax_stream_chat(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) {
            header( 'Content-Type: text/event-stream' );
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => 'Permission denied.' ] ) . "\n\n";
            exit;
        }

        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        $field   = isset( $_POST['field'] )   ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'body';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : [];

        self::log( 'ajax_stream_chat', 'Request — page_id=' . $page_id . ' field=' . $field );

        if ( ! $page_id || ! $message ) {
            self::log( 'ajax_stream_chat', 'Missing page or message' );
            header( 'Content-Type: text/event-stream' );
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => 'Missing page or message.' ] ) . "\n\n";
            exit;
        }

        $page_html = $this->get_field_content( $page_id, $field );
        if ( is_wp_error( $page_html ) ) {
            self::log( 'ajax_stream_chat', 'get_field_content error: ' . $page_html->get_error_message() );
            header( 'Content-Type: text/event-stream' );
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => $page_html->get_error_message() ] ) . "\n\n";
            exit;
        }

        $page_title = match( $page_id ) {
            -1      => 'Site Header',
            -2      => 'Site Footer',
            default => ( ( $p = get_post( $page_id ) ) ? $p->post_title : 'this page' ),
        };

        [ $licence_key, $endpoint, $domain ] = $this->config();
        if ( ! $licence_key || ! $endpoint ) {
            self::log( 'ajax_stream_chat', 'Not configured — key=' . ( $licence_key ? 'set' : 'missing' ) . ' endpoint=' . ( $endpoint ?: 'missing' ) );
            header( 'Content-Type: text/event-stream' );
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => 'ClientDesk is not configured.' ] ) . "\n\n";
            exit;
        }

        self::log( 'ajax_stream_chat', 'Connecting to: ' . $endpoint );

        // Set SSE headers
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );
        while ( ob_get_level() > 0 ) ob_end_flush();

        $post_body = wp_json_encode( [
            'licence_key'  => $licence_key,
            'domain'       => $domain,
            'page_title'   => $page_title,
            'page_html'    => $this->minify_html( $page_html ),
            'history'      => is_array( $history ) ? $history : [],
            'message'      => $message,
            'font_heading' => trim( (string) get_option( self::OPT_FONT_HEADING, '' ) ),
            'font_body'    => trim( (string) get_option( self::OPT_FONT_BODY, '' ) ),
        ] );

        $ch = curl_init( $endpoint );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_body,
            CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function( $ch, $chunk ) {
                echo $chunk;
                if ( ob_get_level() > 0 ) ob_flush();
                flush();
                return strlen( $chunk );
            },
        ] );

        curl_exec( $ch );
        $curl_error = curl_error( $ch );
        $curl_errno = curl_errno( $ch );
        curl_close( $ch );

        if ( $curl_errno ) {
            self::log( 'ajax_stream_chat', 'cURL error ' . $curl_errno . ': ' . $curl_error );
            echo "event: error\ndata: " . wp_json_encode( [ 'message' => 'Could not reach ClientDesk server: ' . $curl_error ] ) . "\n\n";
            flush();
        } else {
            self::log( 'ajax_stream_chat', 'Stream completed OK' );
        }

        exit;
    }

    // ---------------------------------------------------------------
    // AJAX — apply streamed HTML change (called after stream done)
    // ---------------------------------------------------------------

    public function ajax_apply_streamed(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $page_id  = isset( $_POST['page_id'] )  ? (int) $_POST['page_id'] : 0;
        $field    = isset( $_POST['field'] )    ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'body';
        $new_html = isset( $_POST['html'] )     ? wp_unslash( $_POST['html'] ) : '';
        $summary  = isset( $_POST['summary'] )  ? sanitize_text_field( wp_unslash( $_POST['summary'] ) ) : 'Change applied.';

        if ( ! $page_id ) wp_send_json_error( [ 'message' => 'Invalid page.' ] );
        if ( '' === $new_html ) wp_send_json_error( [ 'message' => 'Empty HTML payload.' ] );

        $write = $this->write_field_content( $page_id, $field, $new_html );
        if ( is_wp_error( $write ) ) wp_send_json_error( [ 'message' => 'Could not save: ' . $write->get_error_message() ] );

        $this->log_change( $page_id, $summary );

        wp_send_json_success( [
            'message'  => $summary,
            'page_url' => get_permalink( $page_id ) ?: '',
        ] );
    }

    // ---------------------------------------------------------------
    // AJAX — get images from current page HTML
    // Returns [{src, alt}] for all <img> tags in the field
    // ---------------------------------------------------------------

    public function ajax_get_images(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        $field   = isset( $_POST['field'] )   ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'body';

        if ( ! $page_id ) wp_send_json_error( [ 'message' => 'Invalid page.' ] );

        $html = $this->get_field_content( $page_id, $field );
        if ( is_wp_error( $html ) ) wp_send_json_error( [ 'message' => $html->get_error_message() ] );

        $images = [];
        if ( preg_match_all( '/<img([^>]+)>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $attrs ) {
                $src = '';
                $alt = '';
                if ( preg_match( '/src=["\']([^"\']*)["\']/i', $attrs, $m ) ) $src = $m[1];
                if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $attrs, $m ) ) $alt = $m[1];
                if ( '' === $src || str_starts_with( $src, 'data:' ) ) continue;
                $images[] = [ 'src' => $src, 'alt' => $alt ];
            }
        }

        wp_send_json_success( [ 'images' => $images ] );
    }

    // ---------------------------------------------------------------
    // AJAX — targeted image swap
    //
    // Replaces a specific image src by exact match (old_src → new_src).
    // Falls back to appending when old_src is empty (adding new image).
    // ---------------------------------------------------------------

    public function ajax_image_swap(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        $field   = isset( $_POST['field'] )   ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'body';
        $new_src = isset( $_POST['new_src'] ) ? esc_url_raw( wp_unslash( $_POST['new_src'] ) ) : '';
        $old_src = isset( $_POST['old_src'] ) ? wp_unslash( $_POST['old_src'] ) : '';

        if ( ! $page_id ) wp_send_json_error( [ 'message' => 'Invalid page.' ] );
        if ( '' === $new_src ) wp_send_json_error( [ 'message' => 'No image URL provided.' ] );

        $html = $this->get_field_content( $page_id, $field );
        if ( is_wp_error( $html ) ) wp_send_json_error( [ 'message' => $html->get_error_message() ] );

        $new_html = $html;

        if ( '' !== $old_src ) {
            // Targeted replace: match the exact src value, handles both quote styles
            $escaped = preg_quote( $old_src, '/' );
            $count   = 0;
            $new_html = preg_replace_callback(
                '/(<img[^>]+src=["\'])' . $escaped . '(["\'][^>]*>)/i',
                function( $m ) use ( $new_src, &$count ) { $count++; return $m[1] . $new_src . $m[2]; },
                $html
            );

            // Fallback: plain string replace (catches background-image or other attribute patterns)
            if ( $count === 0 && str_contains( $html, $old_src ) ) {
                $new_html = str_replace( $old_src, $new_src, $html );
                $count    = 1;
            }

            if ( $count === 0 ) {
                wp_send_json_error( [ 'message' => 'Could not find the original image. The page may have changed — please refresh and try again.' ] );
                return;
            }
        } else {
            // No old_src — append as new image if page has none, otherwise replace img[0]
            preg_match_all( '/<img([^>]+)>/i', $html, $img_matches, PREG_OFFSET_CAPTURE );
            if ( empty( $img_matches[0] ) ) {
                $new_html .= "\n<img src=\"{$new_src}\" alt=\"\" loading=\"lazy\">";
            } else {
                $attr_match = $img_matches[1][0];
                $old_attr   = $attr_match[0];
                $new_attr   = preg_replace( '/src=["\'][^"\']*["\']/i', 'src="' . $new_src . '"', $old_attr );
                if ( $new_attr === $old_attr ) $new_attr = 'src="' . $new_src . '" ' . $old_attr;
                $new_html   = substr_replace( $html, $new_attr, $attr_match[1], strlen( $old_attr ) );
            }
        }

        $write = $this->write_field_content( $page_id, $field, $new_html );
        if ( is_wp_error( $write ) ) wp_send_json_error( [ 'message' => 'Could not save: ' . $write->get_error_message() ] );

        $this->log_change( $page_id, 'Image updated.' );

        wp_send_json_success( [
            'message'  => 'Image updated. Live on your site now.',
            'page_url' => get_permalink( $page_id ) ?: '',
        ] );
    }
    // ---------------------------------------------------------------
    // HTML minifier — strips comments and collapses whitespace
    // ---------------------------------------------------------------

    private function minify_html( string $html ): string {
        // Strip HTML comments
        $html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );
        // Collapse whitespace around newlines
        $html = preg_replace( '/\s*\n\s*/', "\n", $html );
        // Collapse multiple blank lines
        $html = preg_replace( '/\n{2,}/', "\n", $html );
        return trim( $html );
    }

    // ---------------------------------------------------------------
    // AJAX — rollback
    // ---------------------------------------------------------------

    public function ajax_rollback(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        $result  = $this->rollback( $page_id );
        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        wp_send_json_success( [ 'message' => 'Change reversed. Your previous content has been restored.' ] );
    }

    // ---------------------------------------------------------------
    // AJAX — restore to before a specific logged change
    // ---------------------------------------------------------------

    public function ajax_restore_change(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        $change_id = isset( $_POST['change_id'] ) ? (int) $_POST['change_id'] : 0;
        if ( ! $change_id ) wp_send_json_error( [ 'message' => 'Invalid change ID.' ] );
        $page_id = (int) get_post_meta( $change_id, '_cdc_page_id', true );
        $key     = (string) get_post_meta( $change_id, '_cdc_restore_key', true );
        $content = get_post_meta( $change_id, '_cdc_restore_content', true );
        if ( $content === '' || $content === false ) wp_send_json_error( [ 'message' => 'No restore data for this change. Only changes made after this update have a restore point.' ] );
        $valid_keys = [ CDC_FIELD_HEADER, CDC_FIELD_BODY, CDC_FIELD_FOOTER, CDC_FIELD_SCRIPTS ];
        if ( $key && ! in_array( $key, $valid_keys, true ) ) wp_send_json_error( [ 'message' => 'Invalid restore data.' ] );
        if ( $key && $page_id > 0 ) {
            update_post_meta( $page_id, $key, $content );
        } elseif ( $page_id > 0 ) {
            $result = wp_update_post( [ 'ID' => $page_id, 'post_content' => $content ], true );
            if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        } else {
            wp_send_json_error( [ 'message' => 'Cannot restore: page not found.' ] );
        }
        wp_send_json_success( [ 'message' => 'Page restored to before this change.' ] );
    }

    // ---------------------------------------------------------------
    // AJAX — usage
    // ---------------------------------------------------------------

    public function ajax_get_usage(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        [ $licence_key, $endpoint, $domain ] = $this->config();
        if ( ! $licence_key || ! $endpoint ) wp_send_json_error( [ 'message' => 'Not configured.' ] );
        $usage_endpoint = preg_replace( '/\/chat$/', '/usage', $endpoint );
        $response = wp_remote_post( $usage_endpoint, [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( [ 'licence_key' => $licence_key, 'domain' => $domain ] ), 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) wp_send_json_error( [ 'message' => 'Could not reach server.' ] );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! ( $body['success'] ?? false ) ) wp_send_json_error( [ 'message' => 'No usage data.' ] );
        wp_send_json_success( [ 'spent_fmt' => $body['spent_fmt'] ?? '', 'budget_fmt' => $body['budget_fmt'] ?? '', 'remaining_fmt' => $body['remaining_fmt'] ?? '', 'show_warning' => $body['show_warning'] ?? false, 'contact_phone' => $body['contact_phone'] ?? '', 'contact_email' => $body['contact_email'] ?? '' ] );
    }

    // ---------------------------------------------------------------
    // AJAX — SEO
    // ---------------------------------------------------------------

    public function ajax_get_seo(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        if ( $page_id <= 0 ) wp_send_json_error( [ 'message' => 'Invalid page.' ] );

        $html = $this->get_field_content( $page_id, 'body' );
        if ( is_wp_error( $html ) ) wp_send_json_error( [ 'message' => $html->get_error_message() ] );

        $h1 = ''; $h2s = []; $h3s = []; $alts = [];
        if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/si', $html, $m ) ) $h1 = wp_strip_all_tags( $m[1] );
        preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/si', $html, $m2 ); foreach ( $m2[1] as $h ) $h2s[] = wp_strip_all_tags( $h );
        preg_match_all( '/<h3[^>]*>(.*?)<\/h3>/si', $html, $m3 ); foreach ( $m3[1] as $h ) $h3s[] = wp_strip_all_tags( $h );
        preg_match_all( '/<img[^>]+alt=["\']([^"\']*)["\'][^>]*>/si', $html, $im ); foreach ( $im[1] as $a ) $alts[] = $a;
        $words = str_word_count( wp_strip_all_tags( $html ) );

        $mt   = (string) get_post_meta( $page_id, CDC_META_TITLE, true );
        $md   = (string) get_post_meta( $page_id, CDC_META_DESC,  true );
        $tl   = mb_strlen( trim( preg_replace( '/%%[^%]+%%/', '', $mt ) ) );
        $dl   = mb_strlen( trim( preg_replace( '/%%[^%]+%%/', '', $md ) ) );
        $miss = count( array_filter( $alts, fn($a) => $a === '' ) );

        $gs = (string) get_option( CDC_GLOBAL_SCRIPTS, '' );
        $an = '';
        if ( str_contains( $gs, 'gtag' ) || str_contains( $gs, 'googletagmanager' ) ) $an = 'Google Analytics';
        elseif ( str_contains( $gs, 'fbq' ) ) $an = 'Meta Pixel';
        elseif ( str_contains( $gs, '_paq' ) ) $an = 'Matomo';
        elseif ( str_contains( $gs, 'clarity' ) ) $an = 'Microsoft Clarity';
        elseif ( trim( $gs ) !== '' ) $an = 'Tracking code';

        wp_send_json_success( [ 'score' => [
            'analytics'  => [ 'status' => $an ? 'good' : 'missing', 'hint' => $an ? $an . ' installed.' : 'No analytics found.' ],
            'meta_title' => [ 'value' => $mt, 'status' => $mt === '' ? 'missing' : ( $tl > 60 ? 'long' : 'good' ), 'hint' => $mt === '' ? 'Not set.' : ( $tl > 60 ? "Too long ({$tl} chars — keep under 60)." : "{$tl} chars." ) ],
            'meta_desc'  => [ 'value' => $md, 'status' => $md === '' ? 'missing' : ( $dl > 160 ? 'long' : ( $dl < 50 ? 'short' : 'good' ) ), 'hint' => $md === '' ? 'Not set.' : ( $dl > 160 ? "Too long ({$dl} chars — aim for 50–160)." : "{$dl} chars." ) ],
            'h1'         => [ 'value' => $h1, 'status' => $h1 === '' ? 'missing' : 'good', 'hint' => $h1 === '' ? 'No H1 found.' : $h1 ],
            'h2'         => [ 'values' => $h2s, 'status' => empty( $h2s ) ? 'warn' : 'good', 'hint' => empty( $h2s ) ? 'No H2 headings.' : count( $h2s ) . ' found.' ],
            'h3'         => [ 'values' => $h3s, 'status' => empty( $h3s ) ? 'warn' : 'good', 'hint' => empty( $h3s ) ? 'No H3 headings.' : count( $h3s ) . ' found.' ],
            'images'     => [ 'status' => $miss > 0 ? 'warn' : 'good', 'hint' => $miss > 0 ? "{$miss} image(s) missing alt text." : ( count( $alts ) > 0 ? 'All have alt text.' : 'No images found.' ) ],
            'words'      => [ 'count' => $words, 'status' => $words < 300 ? 'warn' : 'good', 'hint' => $words < 300 ? "{$words} words — low. Run a keyword analysis for section suggestions." : "{$words} words." ],
        ] ] );
    }

    public function ajax_apply_seo(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        if ( $page_id <= 0 ) wp_send_json_error( [ 'message' => 'Invalid page.' ] );
        $mt = isset( $_POST['meta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_title'] ) ) : null;
        $md = isset( $_POST['meta_desc'] )  ? sanitize_textarea_field( wp_unslash( $_POST['meta_desc'] ) ) : null;
        if ( $mt !== null ) update_post_meta( $page_id, CDC_META_TITLE, $mt );
        if ( $md !== null ) update_post_meta( $page_id, CDC_META_DESC,  $md );
        wp_send_json_success( [ 'message' => 'SEO fields updated.' ] );
    }

    public function ajax_analyse_seo(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        $page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
        $keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        if ( $page_id <= 0 || '' === $keyword ) wp_send_json_error( [ 'message' => 'Missing page or keyword.' ] );

        $html = $this->get_field_content( $page_id, 'body' );
        if ( is_wp_error( $html ) ) wp_send_json_error( [ 'message' => $html->get_error_message() ] );

        $page       = get_post( $page_id );
        $mt         = (string) get_post_meta( $page_id, CDC_META_TITLE, true );
        $md         = (string) get_post_meta( $page_id, CDC_META_DESC,  true );
        $word_count = str_word_count( wp_strip_all_tags( $html ) );

        [ $licence_key, $endpoint, $domain ] = $this->config();
        if ( ! $licence_key || ! $endpoint ) wp_send_json_error( [ 'message' => 'ClientDesk is not configured.' ] );

        $seo_endpoint = preg_replace( '/\/chat$/', '/seo', $endpoint );
        $response = wp_remote_post( $seo_endpoint, [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( [ 'licence_key' => $licence_key, 'domain' => $domain, 'page_title' => $page ? $page->post_title : '', 'page_html' => $html, 'meta_title' => $mt, 'meta_desc' => $md, 'keyword' => $keyword, 'word_count' => $word_count ] ), 'timeout' => 120 ] );

        if ( is_wp_error( $response ) ) wp_send_json_error( [ 'message' => 'Could not reach server: ' . $response->get_error_message() ] );
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $body ) || ! ( $body['success'] ?? false ) ) wp_send_json_error( [ 'message' => $body['error'] ?? 'Server error.' ] );

        wp_send_json_success( [ 'suggestions' => $body['suggestions'] ?? [], 'spent_fmt' => $body['spent_fmt'] ?? null, 'budget_fmt' => $body['budget_fmt'] ?? null, 'remaining_fmt' => $body['remaining_fmt'] ?? null, 'show_warning' => $body['show_warning'] ?? false, 'contact_phone' => $body['contact_phone'] ?? '', 'contact_email' => $body['contact_email'] ?? '' ] );
    }

    // ---------------------------------------------------------------
    // Field read / write / rollback
    // ---------------------------------------------------------------

    private function get_field_content( int $page_id, string $field = 'body' ): string|WP_Error {
        if ( $page_id === -1 ) return (string) get_option( CDC_GLOBAL_HEADER, '' );
        if ( $page_id === -2 ) return (string) get_option( CDC_GLOBAL_FOOTER, '' );

        $post = get_post( $page_id );
        if ( ! $post ) return new WP_Error( 'cdc_no_page', 'Page not found.' );

        $key = match( $field ) {
            'header'  => CDC_FIELD_HEADER,
            'footer'  => CDC_FIELD_FOOTER,
            'scripts' => CDC_FIELD_SCRIPTS,
            default   => CDC_FIELD_BODY,
        };

        $content = (string) get_post_meta( $page_id, $key, true );
        if ( '' !== trim( $content ) ) return $content;

        // Divi fallback
        $pc = $post->post_content;
        if ( preg_match( '/\[et_pb_code[^\]]*\](.*?)\[\/et_pb_code\]/s', $pc, $m ) ) return trim( $m[1] );
        return trim( $pc );
    }

    private function write_field_content( int $page_id, string $field, string $new_html ): true|WP_Error {
        if ( $page_id === -1 ) { update_option( CDC_GLOBAL_HEADER, $new_html ); return true; }
        if ( $page_id === -2 ) { update_option( CDC_GLOBAL_FOOTER, $new_html ); return true; }

        $post = get_post( $page_id );
        if ( ! $post ) return new WP_Error( 'cdc_no_page', 'Page not found.' );

        $key = match( $field ) {
            'header'  => CDC_FIELD_HEADER,
            'footer'  => CDC_FIELD_FOOTER,
            'scripts' => CDC_FIELD_SCRIPTS,
            default   => CDC_FIELD_BODY,
        };

        // Snapshot
        $snaps   = (array) get_post_meta( $page_id, '_cdc_snapshots', true );
        $snaps[] = [ 'ts' => time(), 'key' => $key, 'content' => get_post_meta( $page_id, $key, true ) ];
        if ( count( $snaps ) > 30 ) $snaps = array_slice( $snaps, -30 );
        update_post_meta( $page_id, '_cdc_snapshots', $snaps );

        if ( get_post_meta( $page_id, $key, true ) !== false ) {
            update_post_meta( $page_id, $key, $new_html );
            return true;
        }

        // Divi fallback
        $pc = $post->post_content;
        if ( preg_match( '/(\[et_pb_code[^\]]*\]).*?(\[\/et_pb_code\])/s', $pc ) ) {
            $updated = preg_replace( '/(\[et_pb_code[^\]]*\]).*?(\[\/et_pb_code\])/s', '$1' . $new_html . '$2', $pc );
        } else {
            $updated = $new_html;
        }
        $result = wp_update_post( [ 'ID' => $page_id, 'post_content' => $updated ], true );
        return is_wp_error( $result ) ? $result : true;
    }

    private function rollback( int $page_id ): true|WP_Error {
        $snaps = (array) get_post_meta( $page_id, '_cdc_snapshots', true );
        if ( empty( $snaps ) ) return new WP_Error( 'cdc_no_snap', 'No snapshot available.' );
        $last = array_pop( $snaps );
        update_post_meta( $page_id, '_cdc_snapshots', $snaps );
        $key     = $last['key']     ?? null;
        $content = $last['content'] ?? '';
        if ( $key && $page_id > 0 ) { update_post_meta( $page_id, $key, $content ); return true; }
        $result = wp_update_post( [ 'ID' => $page_id, 'post_content' => $content ], true );
        return is_wp_error( $result ) ? $result : true;
    }

    private function log_change( int $page_id, string $summary ): void {
        $id = wp_insert_post( [ 'post_type' => 'cdc_change', 'post_status' => 'publish', 'post_title' => substr( $summary, 0, 80 ), 'post_author' => get_current_user_id() ] );
        if ( ! is_wp_error( $id ) ) {
            update_post_meta( $id, '_cdc_page_id', $page_id );
            update_post_meta( $id, '_cdc_summary', $summary );
            // Store the most recent snapshot so the History page can restore to before this change
            $snaps = (array) get_post_meta( $page_id, '_cdc_snapshots', true );
            if ( ! empty( $snaps ) ) {
                $last = end( $snaps );
                update_post_meta( $id, '_cdc_restore_key',     $last['key']     ?? '' );
                update_post_meta( $id, '_cdc_restore_content', $last['content'] ?? '' );
            }
        }
    }

    private function config(): array {
        $key      = trim( (string) get_option( self::OPT_KEY, '' ) );
        $endpoint = trim( (string) get_option( self::OPT_ENDPOINT, '' ) );
        $domain   = trim( (string) get_option( self::OPT_DOMAIN, '' ) );
        if ( '' === $domain ) $domain = parse_url( home_url(), PHP_URL_HOST ) ?: '';
        return [ $key, $endpoint, $domain ];
    }

    // ---------------------------------------------------------------
    // Chat page
    // ---------------------------------------------------------------

    public function render_chat(): void {
        if ( ! current_user_can( $this->cap() ) ) wp_die( 'Permission denied.' );
        $pages      = get_pages( [ 'sort_column' => 'post_title', 'sort_order' => 'asc' ] );
        $configured = '' !== trim( (string) get_option( self::OPT_KEY, '' ) ) && '' !== trim( (string) get_option( self::OPT_ENDPOINT, '' ) );
        ?>
        <button id="cd-launch-btn" class="cd-launch-btn">
            <span class="cd-launch-icon">≡</span>
            Open <strong>Client</strong>Desk
        </button>

        <div class="cd-modal" id="cd-modal" style="display:none;">
        <div class="cd-wrap">
            <div class="cd-topbar">
                <span class="cd-logo"><span class="cd-logo-icon">≡</span><strong>Client</strong>Desk</span>
                <?php if ( ! $configured ) : ?><span class="cd-alert">⚠ Not configured — <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">Settings</a></span><?php endif; ?>
                <span class="cd-usage" id="cd-usage" style="display:none;"></span>
                <span class="cd-iw-credit">An <strong>Impact Websites</strong> exclusive</span>
                <!-- Inline font selectors -->
                <div class="cd-font-inline" id="cd-font-inline">
                    <span class="cd-font-inline-label">H:</span>
                    <select id="cd-font-heading" class="cd-font-select-inline">
                        <?php
                        $saved_heading = trim( (string) get_option( self::OPT_FONT_HEADING, '' ) );
                        $google_fonts = self::google_fonts_list();
                        $saved_link_color = sanitize_hex_color( (string) get_option( self::OPT_LINK_COLOR, '' ) ) ?: '#0000ee';
                        echo '<option value=""'  . selected( '', $saved_heading, false ) . '>— Heading font —</option>';
                        foreach ( $google_fonts as $font ) {
                            echo '<option value="' . esc_attr( $font ) . '"' . selected( $font, $saved_heading, false ) . '>' . esc_html( $font ) . '</option>';
                        }
                        ?>
                    </select>
                    <span class="cd-font-inline-label">B:</span>
                    <select id="cd-font-body" class="cd-font-select-inline">
                        <?php
                        $saved_body = trim( (string) get_option( self::OPT_FONT_BODY, '' ) );
                        echo '<option value=""'  . selected( '', $saved_body, false ) . '>— Body font —</option>';
                        foreach ( $google_fonts as $font ) {
                            echo '<option value="' . esc_attr( $font ) . '"' . selected( $font, $saved_body, false ) . '>' . esc_html( $font ) . '</option>';
                        }
                        ?>
                    </select>
                    <span class="cd-font-inline-label">L:</span>
                    <input type="text" id="cd-link-color" class="cd-color-input-inline" value="<?php echo esc_attr( $saved_link_color ); ?>" aria-label="Link color">
                    <button class="cd-font-save" id="cd-font-save">Save</button>
                    <span class="cd-font-saved" id="cd-font-saved" style="display:none;">✓</span>
                </div>
                <!-- Schema trigger — state set by JS after schema load -->
                <button class="cd-wizard-trigger" id="cd-wizard-trigger" title="Schema setup">Schema</button>
                <button class="cd-modal-close" id="cd-modal-close" title="Close">✕</button>
            </div>

            <!-- Schema wizard overlay — sits inside the modal -->
            <div class="cd-wizard" id="cd-wizard" style="display:none;">
                <div class="cd-wizard-inner">
                    <div class="cd-wizard-header">
                        <span class="cd-wizard-title">Schema Setup</span>
                        <span class="cd-wizard-step-label" id="cd-wizard-step-label">Step 1 of 4</span>
                        <button class="cd-wizard-skip" id="cd-wizard-skip">Skip for now</button>
                    </div>
                    <div class="cd-wizard-progress">
                        <div class="cd-wizard-progress-bar" id="cd-wizard-progress-bar" style="width:25%"></div>
                    </div>

                    <!-- Step 1: Business type -->
                    <div class="cd-wizard-step" id="cd-wstep-1">
                        <div class="cd-wizard-step-title">What type of business is this?</div>
                        <div class="cd-wizard-types">
                            <?php
                            $types = [
                                'LocalBusiness'       => ['icon'=>'📍','label'=>'Local Business','desc'=>'Trades, services, general'],
                                'ProfessionalService' => ['icon'=>'💼','label'=>'Professional Service','desc'=>'Accountants, lawyers, consultants'],
                                'Restaurant'          => ['icon'=>'🍽️','label'=>'Restaurant / Café','desc'=>'Food and hospitality'],
                                'Store'               => ['icon'=>'🛍️','label'=>'Retail Store','desc'=>'Physical or online shop'],
                                'MedicalBusiness'     => ['icon'=>'🏥','label'=>'Medical / Health','desc'=>'Clinics, practitioners'],
                                'Person'              => ['icon'=>'👤','label'=>'Person','desc'=>'Authors, sole traders, personal brands'],
                                'Organization'        => ['icon'=>'🏢','label'=>'Organisation','desc'=>'Non-profits, associations'],
                                '__other__'           => ['icon'=>'✏️','label'=>'Other','desc'=>'Enter any schema.org type'],
                            ];
                            foreach ( $types as $value => $t ) :
                            ?>
                            <label class="cd-wizard-type-card" data-type="<?php echo esc_attr( $value ); ?>">
                                <input type="radio" name="cdc_biz_type" value="<?php echo esc_attr( $value ); ?>">
                                <span class="cd-wizard-type-icon"><?php echo $t['icon']; ?></span>
                                <span class="cd-wizard-type-label"><?php echo esc_html( $t['label'] ); ?></span>
                                <span class="cd-wizard-type-desc"><?php echo esc_html( $t['desc'] ); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="cd-wizard-other-wrap" id="cd-wizard-other-wrap" style="display:none;">
                            <label>Schema.org type (e.g. Florist, BeautySalon, AutoRepair)</label>
                            <input type="text" id="cd-wizard-other-type" placeholder="e.g. Florist" />
                        </div>
                    </div>

                    <!-- Step 2: Core details -->
                    <div class="cd-wizard-step" id="cd-wstep-2" style="display:none;">
                        <div class="cd-wizard-step-title">Core business details</div>
                        <div class="cd-wizard-fields">
                            <div class="cd-wf-row"><label>Business name</label><input type="text" id="cd-wf-name" placeholder="<?php echo esc_attr( get_bloginfo('name') ); ?>" /></div>
                            <div class="cd-wf-row"><label>Website URL</label><input type="url" id="cd-wf-url" placeholder="<?php echo esc_attr( home_url() ); ?>" /></div>
                            <div class="cd-wf-row"><label>Phone</label><input type="tel" id="cd-wf-phone" placeholder="+64 9 000 0000" /></div>
                            <div class="cd-wf-row"><label>Email</label><input type="email" id="cd-wf-email" placeholder="hello@example.co.nz" /></div>
                            <div class="cd-wf-row"><label>Description</label><textarea id="cd-wf-desc" rows="3" placeholder="Brief description of the business…"></textarea></div>
                            <div class="cd-wf-row"><label>Logo URL</label><input type="url" id="cd-wf-logo" placeholder="https://…/logo.png" /></div>
                            <div class="cd-wf-row"><label>Street address</label><input type="text" id="cd-wf-addr" placeholder="123 Main St" /></div>
                            <div class="cd-wf-row"><label>City / Suburb</label><input type="text" id="cd-wf-city" placeholder="Auckland" /></div>
                            <div class="cd-wf-row"><label>Region</label><input type="text" id="cd-wf-region" placeholder="Auckland" /></div>
                            <div class="cd-wf-row"><label>Postcode</label><input type="text" id="cd-wf-post" placeholder="1010" /></div>
                            <div class="cd-wf-row"><label>Country code</label><input type="text" id="cd-wf-country" placeholder="NZ" /></div>
                            <div class="cd-wf-row"><label>Area served</label><input type="text" id="cd-wf-area" placeholder="e.g. West Auckland" /></div>
                        </div>
                    </div>

                    <!-- Step 3: Hours -->
                    <div class="cd-wizard-step" id="cd-wstep-3" style="display:none;">
                        <div class="cd-wizard-step-title">Opening hours</div>
                        <div class="cd-wizard-hours">
                            <?php foreach ( ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day ) : $k = strtolower($day); ?>
                            <div class="cd-hours-row">
                                <span class="cd-hours-day"><?php echo esc_html($day); ?></span>
                                <label class="cd-hours-closed-label"><input type="checkbox" class="cd-hours-closed" data-day="<?php echo esc_attr($k); ?>" /> Closed</label>
                                <div class="cd-hours-times" id="cd-hours-times-<?php echo esc_attr($k); ?>">
                                    <input type="time" class="cd-hours-open"  data-day="<?php echo esc_attr($k); ?>" value="09:00" />
                                    <span>–</span>
                                    <input type="time" class="cd-hours-close" data-day="<?php echo esc_attr($k); ?>" value="17:00" />
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Step 4: Socials & Reviews -->
                    <div class="cd-wizard-step" id="cd-wstep-4" style="display:none;">
                        <div class="cd-wizard-step-title">Social profiles &amp; reviews</div>
                        <div class="cd-wizard-fields">
                            <div class="cd-wf-row"><label>Facebook</label><input type="url" id="cd-wf-facebook" placeholder="https://facebook.com/…" /></div>
                            <div class="cd-wf-row"><label>Instagram</label><input type="url" id="cd-wf-instagram" placeholder="https://instagram.com/…" /></div>
                            <div class="cd-wf-row"><label>LinkedIn</label><input type="url" id="cd-wf-linkedin" placeholder="https://linkedin.com/…" /></div>
                            <div class="cd-wf-row"><label>YouTube</label><input type="url" id="cd-wf-youtube" placeholder="https://youtube.com/…" /></div>
                        </div>
                        <div class="cd-wizard-step-title" style="margin-top:20px;">Customer reviews</div>
                        <div class="cd-reviews-list" id="cd-reviews-list"></div>
                        <button class="cd-add-review-btn" id="cd-add-review-btn">＋ Add review</button>
                    </div>

                    <div class="cd-wizard-footer">
                        <button class="cd-wizard-back" id="cd-wizard-back" style="display:none;">← Back</button>
                        <button class="cd-wizard-next" id="cd-wizard-next">Next →</button>
                        <button class="cd-wizard-save" id="cd-wizard-save" style="display:none;">Save schema</button>
                        <span class="cd-wizard-saving" id="cd-wizard-saving" style="display:none;">Saving…</span>
                    </div>
                </div>
            </div>
            <div class="cd-page-bar">
                <label for="cd-page-select">Page:</label>
                <select id="cd-page-select">
                    <option value="">Select a page…</option>
                    <option value="-1">⬆ Site Header</option>
                    <option value="-2">⬇ Site Footer</option>
                    <option value="" disabled>──────────────</option>
                    <?php foreach ( $pages as $page ) : ?>
                        <option value="<?php echo esc_attr( (string) $page->ID ); ?>"><?php echo esc_html( $page->post_title ); ?></option>
                    <?php endforeach; ?>
                    <option value="" disabled>──────────────</option>
                    <option value="__new__">＋ Add a new page…</option>
                </select>
                <div class="cd-page-bar-sep"></div>
                <label for="cd-keyword-bar">Keyphrase:</label>
                <input type="text" id="cd-keyword-bar" placeholder="e.g. Auckland plumber" />
                <button id="cd-analyse-btn" disabled>Analyse SEO for this page</button>
            </div>
            <div class="cd-warning" id="cd-warning" style="display:none;"></div>
            <!-- New page creation panel -->
            <div class="cd-new-page-panel" id="cd-new-page-panel" style="display:none;">
                <div class="cd-new-page-inner">
                    <div class="cd-new-page-title">New Page</div>
                    <div class="cd-new-page-row">
                        <label for="cd-new-page-name">Page name</label>
                        <input type="text" id="cd-new-page-name" placeholder="e.g. About Us" autocomplete="off" />
                    </div>
                    <div class="cd-new-page-row cd-new-page-row--check">
                        <label><input type="checkbox" id="cd-new-page-menu" checked /> Add to header navigation menu</label>
                    </div>
                    <div class="cd-new-page-actions">
                        <button class="cd-btn-create-page" id="cd-create-page-submit">Create page</button>
                        <button class="cd-btn-cancel-page" id="cd-create-page-cancel">Cancel</button>
                    </div>
                    <div class="cd-new-page-result" id="cd-new-page-result" style="display:none;"></div>
                </div>
            </div>
            <div class="cd-columns">
                <div class="cd-col-chat">
                    <div class="cd-chat" id="cd-chat">
                        <div class="cd-bubble cd-bubble--cd">
                            <div class="cd-bubble-label">CLIENTDESK</div>
                            <div class="cd-bubble-text">Select a page above, then tell me what you want changed. You can change text, swap images, add new sections, remove elements — just describe it.</div>
                        </div>
                    </div>
                    <!-- Image picker panel — slides up when replacing an image -->
                    <div class="cd-img-picker" id="cd-img-picker">
                        <div class="cd-img-picker-header">
                            <span class="cd-img-picker-label">Select image to replace</span>
                            <button class="cd-img-picker-close" id="cd-img-picker-close" title="Close">✕</button>
                        </div>
                        <div class="cd-img-picker-hint">Click an image to choose a replacement, or add a new one from your library.</div>
                        <div class="cd-img-picker-grid" id="cd-img-picker-grid"></div>
                    </div>

               <div class="cd-input-bar">
                        <textarea id="cd-input" placeholder="Type a change…" rows="1" <?php echo ! $configured ? 'disabled' : ''; ?>></textarea>
                        <button id="cd-send" <?php echo ! $configured ? 'disabled' : ''; ?>>SEND</button>
                    </div>
                </div>
                <div class="cd-col-seo">
                    <div class="cd-seo-panel" id="cd-seo-panel">
                        <!-- Tab bar -->
                        <div class="cd-panel-tabs">
                            <button class="cd-panel-tab cd-panel-tab--active" data-panel="seo">SEO</button>
                            <button class="cd-panel-tab" data-panel="schema">Schema</button>
                        </div>

                        <!-- SEO tab -->
                        <div class="cd-panel-pane cd-panel-pane--active" id="cd-pane-seo">
                            <div class="cd-seo-empty" id="cd-seo-empty"><p>Select a page to see its SEO status.</p></div>
                            <div class="cd-seo-content" id="cd-seo-content" style="display:none;">
                                <div class="cd-seo-score" id="cd-seo-score"></div>
                            </div>
                            <div class="cd-seo-loading" id="cd-seo-loading" style="display:none;"><div class="cd-dots"><span></span><span></span><span></span></div></div>
                        </div>

                        <!-- Schema tab -->
                        <div class="cd-panel-pane" id="cd-pane-schema" style="display:none;">
                            <div class="cd-schema-status" id="cd-schema-status">
                                <div class="cd-schema-loading"><div class="cd-dots"><span></span><span></span><span></span></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div><!-- /.cd-modal -->
        <script>
        (function() {
            var nonce      = <?php echo wp_json_encode( wp_create_nonce( 'cdc_nonce' ) ); ?>;
            var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var streamUrl  = <?php echo wp_json_encode( CDC_URL . 'stream.php' ); ?>;
            var chat       = document.getElementById('cd-chat');
            var input      = document.getElementById('cd-input');
            var sendBtn    = document.getElementById('cd-send');
            var pageSelect = document.getElementById('cd-page-select');
            var usageBadge = document.getElementById('cd-usage');
            var warningBar = document.getElementById('cd-warning');
            var seoEmpty   = document.getElementById('cd-seo-empty');
            var seoContent = document.getElementById('cd-seo-content');
            var seoLoading = document.getElementById('cd-seo-loading');
            var seoScore   = document.getElementById('cd-seo-score');
            var keywordBar = document.getElementById('cd-keyword-bar');
            var analyseBtn = document.getElementById('cd-analyse-btn');
            var imgPicker      = document.getElementById('cd-img-picker');
            var imgPickerGrid  = document.getElementById('cd-img-picker-grid');
            var imgPickerClose = document.getElementById('cd-img-picker-close');
            var newPagePanel   = document.getElementById('cd-new-page-panel');
            var newPageName    = document.getElementById('cd-new-page-name');
            var newPageMenu    = document.getElementById('cd-new-page-menu');
            var createPageSubmit = document.getElementById('cd-create-page-submit');
            var createPageCancel = document.getElementById('cd-create-page-cancel');
            var newPageResult  = document.getElementById('cd-new-page-result');

            var history = [], currentPageId = 0, currentField = 'body', busy = false;
            var pageImages = []; // [{src, alt}] cache for current page
            var lastSeoScore = {}; // cache of current score for suggestion filtering

            // ---------------------------------------------------------------
            // New page creation panel
            // ---------------------------------------------------------------

            function showNewPagePanel() {
                newPagePanel.style.display = 'block';
                newPageName.value = '';
                newPageMenu.checked = true;
                newPageResult.style.display = 'none';
                newPageResult.textContent = '';
                setTimeout(function() { newPageName.focus(); }, 50);
                // Don't touch pageSelect.value here — setting it triggers another change event
            }

            function hideNewPagePanel() {
                newPagePanel.style.display = 'none';
                // Don't reset pageSelect.value here — caller manages its own state
            }

            createPageCancel.addEventListener('click', hideNewPagePanel);

            createPageSubmit.addEventListener('click', function() {
                var title = newPageName.value.trim();
                if (!title) { newPageName.focus(); return; }

                createPageSubmit.disabled = true;
                createPageSubmit.textContent = 'Creating…';

                var data = new FormData();
                data.append('action',    'cdc_create_page');
                data.append('nonce',     nonce);
                data.append('title',     title);
                data.append('add_menu',  newPageMenu.checked ? '1' : '0');

                fetch(ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        createPageSubmit.disabled = false;
                        createPageSubmit.textContent = 'Create page';
                        if (!res.success) {
                            newPageResult.style.display = 'block';
                            newPageResult.className = 'cd-new-page-result cd-new-page-result--error';
                            newPageResult.textContent = res.data.message || 'Could not create page.';
                            return;
                        }
                        var d = res.data;
                        var navMsg = d.nav_added ? ' Added to navigation.' : '';
                        newPageResult.style.display = 'block';
                        newPageResult.className = 'cd-new-page-result cd-new-page-result--ok';
                        newPageResult.innerHTML = '✓ Page created.' + navMsg
                            + (d.permalink ? ' <a href="'+d.permalink+'" target="_blank">View →</a>' : '');

                        // Add the new page to the dropdown and select it
                        var opt = document.createElement('option');
                        opt.value = d.page_id;
                        opt.textContent = d.title;
                        // Insert before the separator + __new__ (last 2 options)
                        var opts = pageSelect.options;
                        pageSelect.insertBefore(opt, opts[opts.length - 2]);

                        // Switch to the new page after a short delay
                        setTimeout(function() {
                            hideNewPagePanel();
                            pageSelect.value = d.page_id;
                            pageSelect.dispatchEvent(new Event('change'));
                        }, 1800);
                    })
                    .catch(function() {
                        createPageSubmit.disabled = false;
                        createPageSubmit.textContent = 'Create page';
                        newPageResult.style.display = 'block';
                        newPageResult.className = 'cd-new-page-result cd-new-page-result--error';
                        newPageResult.textContent = 'Network error. Please try again.';
                    });
            });

            // Allow Enter key in name field to submit
            newPageName.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); createPageSubmit.click(); }
            });

            // ---------------------------------------------------------------
            // Image picker
            // ---------------------------------------------------------------

            function openImagePicker() {
                if (!pageImages.length) {
                    // No images on page — go straight to library to add one
                    openMedia(function(url) { doImageSwap('', url); });
                    return;
                }
                renderImagePicker();
                imgPicker.classList.add('cd-img-picker--open');
            }

            function closeImagePicker() {
                imgPicker.classList.remove('cd-img-picker--open');
            }

            imgPickerClose.addEventListener('click', closeImagePicker);

            function renderImagePicker() {
                imgPickerGrid.innerHTML = '';

                pageImages.forEach(function(img) {
                    var thumb = document.createElement('button');
                    thumb.className = 'cd-img-thumb';
                    thumb.type = 'button';
                    thumb.title = img.alt || img.src;

                    var im = document.createElement('img');
                    im.src = img.src;
                    im.alt = img.alt || '';
                    im.loading = 'lazy';

                    var overlay = document.createElement('div');
                    overlay.className = 'cd-img-thumb-overlay';

                    var label = document.createElement('div');
                    label.className = 'cd-img-thumb-label';
                    label.textContent = 'Replace';

                    thumb.appendChild(im);
                    thumb.appendChild(overlay);
                    thumb.appendChild(label);

                    thumb.addEventListener('click', function() {
                        var oldSrc = img.src;
                        closeImagePicker();
                        openMedia(function(newUrl) { doImageSwap(oldSrc, newUrl); });
                    });

                    imgPickerGrid.appendChild(thumb);
                });

                // Add new image button
                var addBtn = document.createElement('button');
                addBtn.className = 'cd-img-picker-add';
                addBtn.type = 'button';
                addBtn.innerHTML = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="1.5"/><line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="1.5"/></svg>Add image';
                addBtn.addEventListener('click', function() {
                    closeImagePicker();
                    openMedia(function(url) { doImageSwap('', url); });
                });
                imgPickerGrid.appendChild(addBtn);
            }

            // ---------------------------------------------------------------
            // Load images from current page field into pageImages cache
            // ---------------------------------------------------------------

            function loadPageImages(pageId, field) {
                pageImages = [];
                if (!pageId || pageId < 0) return;
                var d = new FormData();
                d.append('action',  'cdc_get_images');
                d.append('nonce',   nonce);
                d.append('page_id', pageId);
                d.append('field',   field);
                fetch(ajaxUrl, {method:'POST', body:d})
                    .then(function(r){return r.json();})
                    .then(function(res){ if (res.success && res.data) pageImages = res.data.images || []; })
                    .catch(function(){});
            }

            // ---------------------------------------------------------------
            // Targeted image swap — sends old_src + new_src to PHP
            // ---------------------------------------------------------------

            function doImageSwap(oldSrc, newSrc) {
                if (!newSrc) return;
                if (!currentPageId) { alert('Please select a page first.'); return; }

                setInputState(false);
                var typing = addTyping();

                var data = new FormData();
                data.append('action',  'cdc_image_swap');
                data.append('nonce',   nonce);
                data.append('page_id', currentPageId);
                data.append('field',   currentField);
                data.append('new_src', newSrc);
                data.append('old_src', oldSrc);

                fetch(ajaxUrl, {method:'POST', body:data})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        typing.remove();
                        if (!res.success) {
                            addBubble('cd','ERROR', res.data.message || 'Could not update image.');
                            setInputState(true); return;
                        }
                        var extra = null;
                        if (res.data.page_url) {
                            extra = document.createElement('div');
                            extra.className = 'cd-view-link';
                            extra.innerHTML = '<a href="'+res.data.page_url+'" target="_blank">View page \u2192</a>';
                        }
                        addBubble('cd','DONE', res.data.message, extra);
                        addRollback(currentPageId);
                        loadPageImages(currentPageId, currentField);
                        loadSeo(currentPageId);
                        setInputState(true);
                    })
                    .catch(function(){
                        typing.remove();
                        addBubble('cd','ERROR','Network error. Please try again.');
                        setInputState(true);
                    });
            }

            // ---------------------------------------------------------------
            // Detect if message is an image swap/replace request
            // ---------------------------------------------------------------

            function isImageIntent(text) {
                var t = text.toLowerCase();
                return /\b(swap|change|replace|update|switch)\b.*\b(image|photo|picture|banner|hero|bg|background)\b/.test(t)
                    || /\b(image|photo|picture|banner|hero)\b.*\b(swap|change|replace|update|switch)\b/.test(t)
                    || /\bnew (image|photo|picture)\b/.test(t);
            }

            // ---------------------------------------------------------------
            // Utilities
            // ---------------------------------------------------------------

            function scrollBottom() { chat.scrollTop = chat.scrollHeight; }
            function setInputState(e) { input.disabled=!e; sendBtn.disabled=!e; busy=!e; if(e)input.focus(); }

            function addBubble(role, label, text, extraEl) {
                var wrap = document.createElement('div'); wrap.className='cd-bubble cd-bubble--'+role;
                if(label){var l=document.createElement('div');l.className='cd-bubble-label';l.textContent=label;wrap.appendChild(l);}
                var t=document.createElement('div');t.className='cd-bubble-text';t.innerHTML=text.replace(/\n/g,'<br>');wrap.appendChild(t);
                if(extraEl)wrap.appendChild(extraEl);
                chat.appendChild(wrap); scrollBottom(); return wrap;
            }

            function addTyping() {
                var wrap=document.createElement('div');wrap.className='cd-bubble cd-bubble--cd cd-typing';
                wrap.innerHTML='<div class="cd-dots"><span></span><span></span><span></span></div>';
                chat.appendChild(wrap); scrollBottom(); return wrap;
            }

            function addRollback(pageId) {
                var row=document.createElement('div');row.className='cd-rollback-row';
                var btn=document.createElement('button');btn.className='cd-btn-rollback';btn.textContent='\u21a9 Undo this change';
                btn.onclick=function(){row.remove();doRollback(pageId);};row.appendChild(btn);chat.appendChild(row);scrollBottom();
            }

            function updateUsage(spent_fmt,budget_fmt,remaining_fmt,show_warning,phone,email) {
                if(!spent_fmt)return;
                usageBadge.style.display='inline-block';
                usageBadge.textContent=spent_fmt+' of '+budget_fmt+' used this month';
                var rem=parseFloat((remaining_fmt||'0').replace('$',''));
                usageBadge.className='cd-usage'+(rem<1?' cd-usage--low':'');
                if(show_warning&&phone&&email){
                    warningBar.style.display='flex';
                    warningBar.innerHTML='<span class="cd-warning-icon">\u26a0</span><span class="cd-warning-text">You only have <strong>'+remaining_fmt+'</strong> left this month.</span><span class="cd-warning-contact">Top up? Contact Patrick: <a href="tel:'+phone+'">'+phone+'</a> &nbsp;\u00b7&nbsp; <a href="mailto:'+email+'">'+email+'</a></span>';
                } else { warningBar.style.display='none'; }
            }

            // ---------------------------------------------------------------
            // Page select
            // ---------------------------------------------------------------

            pageSelect.addEventListener('change', function() {
                // Handle "Add a new page" option
                if (this.value === '__new__') {
                    showNewPagePanel();
                    return;
                }
                hideNewPagePanel();
                currentPageId=parseInt(this.value)||0;
                history=[];
                lastSeoScore={};
                var name=this.options[this.selectedIndex].text.replace(/^[\u2b06\u2b07]\s*/,'');
                currentField=currentPageId===-1?'header':currentPageId===-2?'footer':'body';
                closeImagePicker();
                pageImages=[];

                if(!currentPageId){
                    chat.innerHTML='<div class="cd-bubble cd-bubble--cd"><div class="cd-bubble-label">CLIENTDESK</div><div class="cd-bubble-text">Select a page above, then tell me what you want changed.</div></div>';
                    seoEmpty.style.display='block'; seoContent.style.display='none'; keywordBar.value='';
                    analyseBtn.disabled=true; return;
                }
                chat.innerHTML='<div class="cd-bubble cd-bubble--cd"><div class="cd-bubble-label">CLIENTDESK</div><div class="cd-bubble-text">Ready. Tell me what you want changed on <strong>'+name+'</strong>.</div></div>';
                if(currentPageId>0){
                    keywordBar.value=localStorage.getItem('cd_kw_'+currentPageId)||'';
                    analyseBtn.disabled=!keywordBar.value.trim();
                    loadSeo(currentPageId);
                } else {
                    keywordBar.value=''; analyseBtn.disabled=true;
                    seoEmpty.style.display='block'; seoContent.style.display='none'; seoLoading.style.display='none';
                }
                loadPageImages(currentPageId, currentField);
            });

            // Load usage on init
            function fetchUsage() {
                var d = new FormData(); d.append('action','cdc_get_usage'); d.append('nonce',nonce);
                fetch(ajaxUrl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(res){
                    if(res.success&&res.data){var d=res.data;updateUsage(d.spent_fmt,d.budget_fmt,d.remaining_fmt,d.show_warning,d.contact_phone,d.contact_email);}
                }).catch(function(){});
            }
            fetchUsage();

            keywordBar.addEventListener('input',function(){
                if(currentPageId>0) localStorage.setItem('cd_kw_'+currentPageId,this.value.trim());
                analyseBtn.disabled = !this.value.trim() || !currentPageId || currentPageId < 0;
            });

            // ---------------------------------------------------------------
            // SEO
            // ---------------------------------------------------------------

            // Map suggestion type strings to score keys for inline placement
            var SEO_ROW_MAP = {
                'meta title':       'meta_title',
                'meta_title':       'meta_title',
                'meta description': 'meta_desc',
                'meta_desc':        'meta_desc',
                'h1':               'h1',
                'h2':               'h2',
                'h3':               'h3',
                'image alt':        'images',
                'alt':              'images',
                'word count':       'words',
                'content':          'words',
                'new section':      'words',
            };

            function seoTypeToRowKey(type) {
                var t = (type||'').toLowerCase();
                for (var k in SEO_ROW_MAP) {
                    if (t.indexOf(k) !== -1) return SEO_ROW_MAP[k];
                }
                return null;
            }

            function loadSeo(pageId) {
                seoEmpty.style.display='none'; seoContent.style.display='none'; seoLoading.style.display='block';
                var d=new FormData(); d.append('action','cdc_get_seo'); d.append('nonce',nonce); d.append('page_id',pageId);
                fetch(ajaxUrl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(res){
                    seoLoading.style.display='none';
                    if(!res.success){seoEmpty.style.display='block';seoEmpty.querySelector('p').textContent='Could not load SEO data.';return;}
                    lastSeoScore = res.data.score;
                    renderSeoScore(res.data.score);
                    injectMetaFixCards(res.data.score);
                    seoContent.style.display='block';
                }).catch(function(){seoLoading.style.display='none';seoEmpty.style.display='block';});
            }

            // Immediately show fix cards for meta title/desc when they're too long or too short.
            // Calls MasterDesk to generate a properly rewritten suggestion.
            function injectMetaFixCards(score) {
                ['meta_title', 'meta_desc'].forEach(function(key) {
                    var field = score[key];
                    if (!field || field.status === 'good' || field.status === 'missing') return;
                    var el = document.getElementById('cd-sugg-' + key);
                    if (!el) return;

                    var currentValue = field.value || '';
                    var limit = key === 'meta_title' ? 60 : 160;
                    var minLimit = key === 'meta_title' ? 50 : 50;
                    var label = key === 'meta_title' ? 'meta title' : 'meta description';
                    var isLong = field.status === 'long';

                    // Show loading state immediately
                    el.innerHTML = '<div class="cd-seo-inline-card cd-seo-inline-card--fix">'
                        + '<div class="cd-seo-inline-fix-hint">Generating a suggested ' + label + '…</div>'
                        + '<div class="cd-seo-fix-loading"><div class="cd-dots"><span></span><span></span><span></span></div></div>'
                        + '</div>';
                    el.style.display = 'block';
                    var row = el.closest('.cd-seo-row');
                    if (row) row.classList.add('cd-seo-row--open');

                    // Build a tight, cheap prompt
                    var prompt = isLong
                        ? 'Rewrite this ' + label + ' to be under ' + limit + ' characters while keeping the meaning. Current (' + currentValue.length + ' chars): "' + currentValue + '". Reply with ONLY the rewritten ' + label + ', no explanation, no quotes.'
                        : 'Rewrite this ' + label + ' to be between ' + minLimit + ' and ' + limit + ' characters. Current (' + currentValue.length + ' chars): "' + currentValue + '". Reply with ONLY the rewritten ' + label + ', no explanation, no quotes.';

                    // Call via the existing AJAX chat handler with empty page HTML
                    var d = new FormData();
                    d.append('action',   'cdc_chat');
                    d.append('nonce',    nonce);
                    d.append('page_id',  currentPageId);
                    d.append('field',    'body');
                    d.append('message',  prompt);
                    d.append('history',  '[]');

                    fetch(ajaxUrl, {method:'POST', body:d})
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            // Response comes back as type:'message' with the raw text
                            var suggested = '';
                            if (res.success && res.data) {
                                suggested = (res.data.message || res.data.raw || '').trim().replace(/^["']|["']$/g, '');
                            }
                            renderMetaFixCard(el, key, label, currentValue, suggested, limit, isLong);
                        })
                        .catch(function() {
                            renderMetaFixCard(el, key, label, currentValue, '', limit, isLong);
                        });
                });
            }

            function renderMetaFixCard(el, key, label, currentValue, suggested, limit, isLong) {
                var hint = isLong
                    ? 'Currently ' + currentValue.length + ' chars — needs to be under ' + limit + '.'
                    : 'Currently ' + currentValue.length + ' chars — needs to be 50–' + limit + ' chars.';

                var suggestedHtml = suggested
                    ? '<div class="cd-seo-inline-fix-hint" style="margin-top:10px;">Suggested replacement:</div>'
                      + '<textarea class="cd-seo-fix-textarea" id="cd-fix-textarea-' + key + '" rows="2">' + suggested.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</textarea>'
                      + '<div class="cd-seo-fix-counter"><span id="cd-fix-count-' + key + '">' + suggested.length + '</span> / ' + limit + '</div>'
                      + '<button class="cd-seo-edit-meta-btn" id="cd-fix-apply-' + key + '">Apply</button>'
                    : '<div class="cd-seo-inline-fix-hint" style="color:#c0392b;">Could not generate suggestion — edit manually below.</div>'
                      + '<textarea class="cd-seo-fix-textarea" id="cd-fix-textarea-' + key + '" rows="2">' + currentValue.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</textarea>'
                      + '<div class="cd-seo-fix-counter"><span id="cd-fix-count-' + key + '">' + currentValue.length + '</span> / ' + limit + '</div>'
                      + '<button class="cd-seo-edit-meta-btn" id="cd-fix-apply-' + key + '">Apply</button>';

                el.innerHTML = '<div class="cd-seo-inline-card cd-seo-inline-card--fix">'
                    + '<div class="cd-seo-inline-fix-hint">' + hint + '</div>'
                    + '<div class="cd-seo-inline-current">' + currentValue.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>'
                    + suggestedHtml
                    + '</div>';

                // Live counter
                var textarea = document.getElementById('cd-fix-textarea-' + key);
                var counter  = document.getElementById('cd-fix-count-' + key);
                var applyBtn = document.getElementById('cd-fix-apply-' + key);

                if (textarea && counter && applyBtn) {
                    textarea.addEventListener('input', function() {
                        var len = textarea.value.length;
                        counter.textContent = len;
                        counter.style.color = len > limit ? '#c0392b' : '#888';
                        applyBtn.disabled = len > limit || len < 10;
                    });
                    // Trigger once to set initial state
                    var initLen = textarea.value.length;
                    counter.style.color = initLen > limit ? '#c0392b' : '#888';
                    applyBtn.disabled = initLen > limit || initLen < 10;

                    applyBtn.addEventListener('click', function() {
                        var v = textarea.value.trim();
                        if (!v || v.length > limit) return;
                        applySeoField(key, v);
                        applyBtn.textContent = '✓ Applied';
                        applyBtn.disabled = true;
                    });
                }
            }

            function renderSeoScore(score) {
                var rows = [
                    {key:'analytics',    label:'Analytics',       status:score.analytics?score.analytics.status:'missing', hint:score.analytics?score.analytics.hint:'No analytics found.', value:null, editField:null},
                    {key:'meta_title',   label:'Meta title',      status:score.meta_title.status, hint:score.meta_title.hint, value:score.meta_title.value, editField:'meta_title'},
                    {key:'meta_desc',    label:'Meta description', status:score.meta_desc.status,  hint:score.meta_desc.hint,  value:score.meta_desc.value,  editField:'meta_desc'},
                    {key:'h1',           label:'H1',               status:score.h1.status,         hint:score.h1.hint,         value:score.h1.value,         editField:null},
                    {key:'h2',           label:'H2',               status:score.h2.status,         hint:score.h2.hint,         value:(score.h2.values||[]).join('\n'), editField:null},
                    {key:'h3',           label:'H3',               status:score.h3.status,         hint:score.h3.hint,         value:(score.h3.values||[]).join('\n'), editField:null},
                    {key:'images',       label:'Image alt text',   status:score.images.status,     hint:score.images.hint,     value:null, editField:null},
                    {key:'words',        label:'Word count',       status:score.words.status,       hint:score.words.hint,      value:null, editField:null},
                ];
                var html = '';
                rows.forEach(function(r) {
                    html += seoRow(r.key, r.label, r.status, r.hint, r.value, r.editField);
                });
                seoScore.innerHTML = html;

                seoScore.querySelectorAll('.cd-seo-row').forEach(function(row) {
                    row.addEventListener('click', function(e) {
                        if (e.target.classList.contains('cd-seo-edit-btn') || e.target.classList.contains('cd-seo-apply-btn')) return;
                        row.classList.toggle('cd-seo-row--open');
                    });
                });

                seoScore.querySelectorAll('.cd-seo-edit-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var f = btn.dataset.field;
                        var v = prompt('Update ' + (f==='meta_title' ? 'meta title' : 'meta description') + ':', btn.dataset.current);
                        if (v === null) return;
                        applySeoField(f, v);
                    });
                });
            }

            function seoRow(key, label, status, hint, value, editField) {
                var dotClass = status === 'good' ? 'cd-seo-dot--good' : status === 'missing' ? 'cd-seo-dot--bad' : 'cd-seo-dot--warn';
                var icon = '<span class="cd-seo-dot ' + dotClass + '">\u25cf</span>';
                var editBtn = '';
                if (editField && value !== null && value !== '') {
                    editBtn = ' <button class="cd-seo-edit-btn" data-field="' + editField + '" data-current="' + (value||'').replace(/"/g, '&quot;') + '">Edit</button>';
                } else if (editField && (status === 'long' || status === 'short') && value !== null) {
                    editBtn = ' <button class="cd-seo-edit-btn" data-field="' + editField + '" data-current="' + (value||'').replace(/"/g, '&quot;') + '">Edit</button>';
                }
                var valueBlock = (value && value.trim()) ? '<div class="cd-seo-row-value">' + value.replace(/\n/g, '<br>') + '</div>' : '';
                var hasExpand = !!(value && value.trim());
                return '<div class="cd-seo-row' + (hasExpand ? ' cd-seo-row--expandable' : '') + '" data-row-key="' + key + '">'
                    + icon
                    + '<div class="cd-seo-row-content">'
                    +   '<div class="cd-seo-row-label">' + label + editBtn
                    +     '<span class="cd-seo-row-arrow">' + (hasExpand ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>' : '') + '</span>'
                    +   '</div>'
                    +   '<div class="cd-seo-row-hint">' + hint + '</div>'
                    +   '<div class="cd-seo-row-expanded">' + valueBlock + '</div>'
                    +   '<div class="cd-seo-inline-sugg" id="cd-sugg-' + key + '" style="display:none;"></div>'
                    + '</div>'
                    + '</div>';
            }

            function applySeoField(field, value) {
                var d = new FormData();
                d.append('action', 'cdc_apply_seo');
                d.append('nonce', nonce);
                d.append('page_id', currentPageId);
                d.append(field, value);
                fetch(ajaxUrl, {method:'POST', body:d})
                    .then(function(r){return r.json();})
                    .then(function(res) {
                        if (res.success) loadSeo(currentPageId);
                        else alert('Could not save: ' + (res.data.message || 'error'));
                    });
            }

            analyseBtn.addEventListener('click', function() {
                var keyword = keywordBar.value.trim();
                if (!keyword) { keywordBar.focus(); return; }
                if (!currentPageId || currentPageId < 0) return;
                localStorage.setItem('cd_kw_' + currentPageId, keyword);
                analyseBtn.disabled = true;
                analyseBtn.textContent = '\u2026';
                // Clear any existing inline suggestions
                document.querySelectorAll('.cd-seo-inline-sugg').forEach(function(el) {
                    el.style.display = 'none'; el.innerHTML = '';
                });
                var d = new FormData();
                d.append('action', 'cdc_analyse_seo');
                d.append('nonce', nonce);
                d.append('page_id', currentPageId);
                d.append('keyword', keyword);
                fetch(ajaxUrl, {method:'POST', body:d})
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        analyseBtn.disabled = false;
                        analyseBtn.textContent = 'Analyse';
                        if (!res.success) {
                            // Show error in word count row as fallback
                            var errEl = document.getElementById('cd-sugg-words');
                            if (errEl) { errEl.style.display='block'; errEl.innerHTML='<div class="cd-seo-sugg-error">' + (res.data.message || 'Analysis failed.') + '</div>'; }
                            return;
                        }
                        updateUsage(res.data.spent_fmt, res.data.budget_fmt, res.data.remaining_fmt, res.data.show_warning, res.data.contact_phone, res.data.contact_email);
                        renderInlineSuggestions(res.data.suggestions);
                    })
                    .catch(function() {
                        analyseBtn.disabled = false;
                        analyseBtn.textContent = 'Analyse';
                        var errEl = document.getElementById('cd-sugg-words');
                        if (errEl) { errEl.style.display='block'; errEl.innerHTML='<div class="cd-seo-sugg-error">Network error.</div>'; }
                    });
            });

            function renderInlineSuggestions(suggestions) {
                if (!suggestions || !suggestions.length) {
                    var el = document.getElementById('cd-sugg-words');
                    if (el) { el.style.display='block'; el.innerHTML='<div class="cd-seo-sugg-ok">Looking good for that keyword.</div>'; }
                    return;
                }

                // Group suggestions by row key.
                // Only skip meta title/desc suggestions when they're already 'good' — not when long/short.
                var groups = {};
                suggestions.forEach(function(s) {
                    var rowKey = seoTypeToRowKey(s.type);

                    var metaField = s.field || (seoTypeToRowKey(s.type) === 'meta_title' ? 'meta_title'
                                             : seoTypeToRowKey(s.type) === 'meta_desc'  ? 'meta_desc' : null);

                    if ((metaField === 'meta_title' && lastSeoScore.meta_title && lastSeoScore.meta_title.status === 'good')
                     || (metaField === 'meta_desc'  && lastSeoScore.meta_desc  && lastSeoScore.meta_desc.status  === 'good')) {
                        return;
                    }

                    if (!rowKey) rowKey = 'words';
                    if (!groups[rowKey]) groups[rowKey] = [];
                    groups[rowKey].push(s);
                });

                Object.keys(groups).forEach(function(rowKey) {
                    var el = document.getElementById('cd-sugg-' + rowKey);
                    if (!el) return;
                    var items = groups[rowKey];
                    var html = '';
                    items.forEach(function(s) {
                        // Resolve the field: use explicit s.field, or infer from rowKey for meta fields
                        var resolvedField = s.field || (rowKey === 'meta_title' ? 'meta_title' : rowKey === 'meta_desc' ? 'meta_desc' : null);
                        html += '<div class="cd-seo-inline-card">'
                            + (s.value ? '<div class="cd-seo-inline-value">' + s.value + '</div>' : '')
                            + (resolvedField || s.value ? '<button class="cd-seo-apply-btn"'
                                + ' data-field="' + (resolvedField || '') + '"'
                                + ' data-type="' + (s.type || rowKey) + '"'
                                + ' data-value="' + (s.value||'').replace(/"/g,'&quot;') + '">Apply</button>' : '')
                            + '</div>';
                    });
                    el.innerHTML = html;
                    el.style.display = 'block';

                    var row = el.closest('.cd-seo-row');
                    if (row) row.classList.add('cd-seo-row--open');

                    // Bind each button — capture field/type/value from data attributes, not closure vars
                    el.querySelectorAll('.cd-seo-apply-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var f = btn.dataset.field;
                            var v = btn.dataset.value;
                            var t = btn.dataset.type;
                            if (f === 'meta_title' || f === 'meta_desc') {
                                applySeoField(f, v);
                                btn.textContent = '\u2713';
                                btn.disabled = true;
                            } else {
                                // Content change — send as a chat instruction
                                send(buildInstruction(t, v));
                            }
                        });
                    });
                });
            }

            function buildInstruction(type, value) {
                var t = (type||'').toLowerCase();
                if (t === 'h1' || t.indexOf('h1') !== -1) return 'Set the H1 heading to: "' + value + '"';
                if (t === 'h2' || t.indexOf('h2') !== -1) return 'Add or update an H2 heading to: "' + value + '"';
                if (t === 'h3' || t.indexOf('h3') !== -1) return 'Add or update an H3 heading to: "' + value + '"';
                if (t.indexOf('alt') !== -1 || t === 'images') return 'Add alt text to the image(s) missing it. Use: "' + value + '"';
                return 'Make this SEO improvement — ' + type + ': "' + value + '"';
            }

            // ---------------------------------------------------------------
            // Confirmation buttons
            // ---------------------------------------------------------------

            function isConfirmationQuestion(text) {
                var t = text.toLowerCase();
                return t.indexOf('shall i go ahead') !== -1
                    || t.indexOf('shall i proceed') !== -1
                    || t.indexOf('want me to go ahead') !== -1
                    || t.indexOf('would you like me to') !== -1
                    || t.indexOf('ready to proceed') !== -1
                    || t.indexOf('good to go ahead') !== -1
                    || /shall i (build|create|add|update|change|remove|replace|make)\b/.test(t);
            }

            function addConfirmButtons() {
                var row = document.createElement('div');
                row.className = 'cd-confirm-row';
                var yesBtn = document.createElement('button'); yesBtn.className='cd-btn-yes'; yesBtn.textContent='\u2713 Yes, go ahead';
                yesBtn.onclick = function() { row.remove(); send('Yes, go ahead.'); };
                var noBtn = document.createElement('button'); noBtn.className='cd-btn-no'; noBtn.textContent='\u2717 No, I want to change my request';
                noBtn.onclick = function() {
                    row.remove(); input.placeholder='What would you like to change?'; input.focus();
                    input.addEventListener('input', function r() { input.placeholder='Type a change\u2026'; input.removeEventListener('input',r); });
                };
                row.appendChild(yesBtn); row.appendChild(noBtn); chat.appendChild(row); scrollBottom();
            }

            // ---------------------------------------------------------------
            // Main send — intercepts image intent before hitting AI
            // ---------------------------------------------------------------

            function send(msgOverride) {
                if(busy)return;
                var message=msgOverride||input.value.trim(); if(!message)return;
                if(!currentPageId){alert('Please select a page first.');return;}

                // Image intent: show picker instead of sending to AI
                if(!msgOverride && isImageIntent(message)) {
                    addBubble('user', null, message);
                    input.value=''; input.style.height='auto';
                    openImagePicker();
                    return;
                }

                addBubble('user',null,message); if(!msgOverride)input.value=''; input.style.height='auto'; setInputState(false);

                var typing=addTyping();
                var streamBubble=null;
                var streamText='';

                var params = new URLSearchParams();
                params.append('nonce',   nonce);
                params.append('page_id', currentPageId);
                params.append('field',   currentField);
                params.append('message', message);
                params.append('history', JSON.stringify(history));

                fetch(streamUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                }).then(function(response) {
                    if (!response.ok) { typing.remove(); addBubble('cd','ERROR','Server error: HTTP '+response.status); setInputState(true); return; }
                    var reader=response.body.getReader(), decoder=new TextDecoder(), buffer='';

                    function processChunk(value) {
                        buffer += decoder.decode(value||new Uint8Array(),{stream:!!value});
                        var lines=buffer.split('\n'); buffer=lines.pop();
                        for(var i=0;i<lines.length;i++){
                            var line=lines[i].trim(); if(!line) continue;
                            if(line.startsWith('event: ')) continue;
                            if(line.startsWith('data: ')){
                                var eventLine=lines[i-1]?lines[i-1].trim():'';
                                var eventType=eventLine.startsWith('event: ')?eventLine.slice(7):'token';
                                var jsonStr=line.slice(6);
                                try{var data=JSON.parse(jsonStr);}catch(e){continue;}

                                if(eventType==='token'){
                                    if(!streamBubble){typing.remove();streamBubble=addBubble('cd','CLIENTDESK','');}
                                    streamText+=data.text||'';
                                    var textEl=streamBubble.querySelector('.cd-bubble-text');
                                    if(textEl){
                                        // Strip any trailing JSON action payload from display
                                        // (server sends conversational text then appends JSON; don't show the JSON)
                                        var displayText = streamText.replace(/\{[\s\S]*$/, '').trim();
                                        textEl.innerHTML = (displayText || streamText).replace(/\n/g,'<br>');
                                    }
                                    scrollBottom();

                                }else if(eventType==='error'){
                                    streamEnded=true; typing.remove(); if(streamBubble)streamBubble.remove();
                                    addBubble('cd','ERROR',data.message||'Something went wrong.'); setInputState(true);

                                }else if(eventType==='done'){
                                    streamEnded=true; typing.remove(); history=data.history||history;

                                    if(data.action&&(data.action.action==='apply'||data.action.action==='patch'||data.action.action==='insert')){
                                        if(streamBubble)streamBubble.remove();
                                        var summary=data.action.summary||'Done';
                                        var extra=null;
                                        if(data.action.page_url){extra=document.createElement('div');extra.className='cd-view-link';extra.innerHTML='<a href="'+data.action.page_url+'" target="_blank">View page \u2192</a>';}
                                        var patchErrors=data.action.patch_errors||[];
                                        var insertError=data.action.insert_error||null;
                                        if(patchErrors.length){
                                            addBubble('cd','ERROR',patchErrors.join(' '));
                                        } else if(insertError){
                                            addBubble('cd','CLIENTDESK',insertError);
                                        } else {
                                            addBubble('cd','DONE',summary+' Live on your site now. Use "Undo" below if needed.',extra);
                                            addRollback(currentPageId);
                                        }
                                        // Always update usage display after any apply/patch/insert
                                        if(data.spent_fmt) updateUsage(data.spent_fmt,data.budget_fmt,data.remaining_fmt,data.show_warning,data.contact_phone,data.contact_email);
                                        else fetchUsage();
                                        loadPageImages(currentPageId,currentField);
                                        loadSeo(currentPageId); setInputState(true); return;
                                    }

                                    if(data.action&&data.action.action==='need_image'){
                                        if(streamBubble)streamBubble.remove();
                                        addBubble('cd','IMAGE',data.action.message||'Please select an image.');
                                        openImagePicker();
                                        setInputState(true); return;
                                    }

                                    if(data.action&&data.action.action==='update_meta'){
                                        if(streamBubble)streamBubble.remove();
                                        applyMetaUpdate(data.action,data); return;
                                    }

                                    if(!streamBubble)addBubble('cd','CLIENTDESK',data.raw||'');
                                    updateUsage(data.spent_fmt,data.budget_fmt,data.remaining_fmt,data.show_warning,data.contact_phone,data.contact_email);
                                    if(isConfirmationQuestion(streamText||data.raw||''))addConfirmButtons();
                                    setInputState(true);
                                }
                            }
                        }
                    }

                    var streamEnded = false;
                    function pump(){return reader.read().then(function(result){processChunk(result.value);if(!result.done)return pump(); else if(!streamEnded){streamEnded=true; typing.remove(); if(!streamBubble)addBubble('cd','ERROR','Response ended unexpectedly.'); setInputState(true);}});}
                    return pump();

                }).catch(function(){typing.remove();addBubble('cd','ERROR','Network error. Please try again.');setInputState(true);});
            }

            function applyHtmlChange(action, data, extra) {
                var d=new FormData();
                d.append('action','cdc_apply_streamed'); d.append('nonce',nonce); d.append('page_id',currentPageId);
                d.append('field',currentField); d.append('html',action.html||''); d.append('summary',action.summary||'');
                fetch(ajaxUrl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(res){
                    if(!res.success){addBubble('cd','ERROR',res.data.message||'Could not save.');setInputState(true);return;}
                    addBubble('cd','DONE',(action.summary||'Done')+' Live on your site now. Use "Undo" below if needed.',extra);
                    addRollback(currentPageId);
                    updateUsage(data.spent_fmt,data.budget_fmt,data.remaining_fmt,data.show_warning,data.contact_phone,data.contact_email);
                    loadPageImages(currentPageId,currentField);
                    loadSeo(currentPageId); setInputState(true);
                }).catch(function(){addBubble('cd','ERROR','Network error saving change.');setInputState(true);});
            }

            function applyMetaUpdate(action, data) {
                var mf=action.field||'', mv=action.value||'';
                var d=new FormData(); d.append('action','cdc_apply_seo'); d.append('nonce',nonce); d.append('page_id',currentPageId); d.append(mf,mv);
                fetch(ajaxUrl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(res){
                    if(res.success){addBubble('cd','DONE',(mf==='meta_title'?'Meta title':'Meta description')+' updated. Live on your site now.');updateUsage(data.spent_fmt,data.budget_fmt,data.remaining_fmt,data.show_warning,data.contact_phone,data.contact_email);loadSeo(currentPageId);}
                    else addBubble('cd','ERROR','Could not save: '+(res.data.message||'error'));
                    setInputState(true);
                });
            }

            function doRollback(pageId){
                setInputState(false); var typing=addTyping();
                var d=new FormData(); d.append('action','cdc_rollback'); d.append('nonce',nonce); d.append('page_id',pageId);
                fetch(ajaxUrl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(res){
                    typing.remove(); addBubble('cd',res.success?'UNDONE':'ERROR',res.data.message||'Done.');
                    if(res.success){loadPageImages(currentPageId,currentField);loadSeo(currentPageId);}
                    setInputState(true);
                }).catch(function(){typing.remove();addBubble('cd','ERROR','Network error during undo.');setInputState(true);});
            }

            function openMedia(callback) {
                var frame = wp.media({title:'Select Image', button:{text:'Use this image'}, multiple:false, library:{type:'image'}});
                // WP media library opens at z-index ~160000 but our modal is 999999 — step down temporarily
                var cdModal = document.getElementById('cd-modal');
                frame.on('open', function() {
                    if (cdModal) cdModal.style.zIndex = '99999';
                });
                frame.on('select', function() {
                    if (cdModal) cdModal.style.zIndex = '999999';
                    callback(frame.state().get('selection').first().toJSON().url);
                });
                frame.on('close', function() {
                    if (cdModal) cdModal.style.zIndex = '999999';
                });
                frame.open();
            }

            sendBtn.addEventListener('click',function(){send();});
            input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
            input.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';});

            // ---------------------------------------------------------------
            // SEO / Schema panel tab toggle
            // ---------------------------------------------------------------

            document.querySelectorAll('.cd-panel-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.cd-panel-tab').forEach(function(t) { t.classList.remove('cd-panel-tab--active'); });
                    tab.classList.add('cd-panel-tab--active');
                    var panel = tab.dataset.panel;
                    document.getElementById('cd-pane-seo').style.display    = panel === 'seo'    ? '' : 'none';
                    document.getElementById('cd-pane-schema').style.display = panel === 'schema' ? '' : 'none';
                    if (panel === 'schema') loadSchemaTab();
                });
            });

            // ---------------------------------------------------------------
            // Schema tab
            // ---------------------------------------------------------------

            var schemaData = {};

            function loadSchemaTab() {
                var statusEl = document.getElementById('cd-schema-status');
                statusEl.innerHTML = '<div class="cd-schema-loading"><div class="cd-dots"><span></span><span></span><span></span></div></div>';

                var d = new FormData();
                d.append('action', 'cdc_get_schema');
                d.append('nonce', nonce);
                if (currentPageId > 0) d.append('page_id', currentPageId);

                fetch(ajaxUrl, {method:'POST', body:d})
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success) { statusEl.innerHTML = '<p>Could not load schema.</p>'; return; }
                        schemaData = res.data.schema || {};
                        renderSchemaTab(statusEl, res.data.page_schema || {});
                    })
                    .catch(function() { statusEl.innerHTML = '<p>Network error.</p>'; });
            }

            function renderSchemaTab(el, pageSchema) {
                var configured = !!schemaData.type;
                var statusDot  = configured ? '<span class="cd-schema-dot cd-schema-dot--good">●</span>' : '<span class="cd-schema-dot cd-schema-dot--bad">●</span>';
                var statusText = configured
                    ? schemaData.name + ' — ' + schemaData.type
                    : 'Schema not configured.';

                var reviewCount = (schemaData.reviews || []).length;
                var reviewHtml = '';
                if (configured) {
                    reviewHtml = '<div class="cd-schema-section-title">Reviews <span class="cd-schema-review-count">' + reviewCount + '</span>'
                        + '<button class="cd-schema-add-review" id="cd-tab-add-review">＋ Add</button></div>'
                        + '<div class="cd-schema-reviews" id="cd-tab-reviews">' + renderSchemaReviewList(schemaData.reviews || []) + '</div>';
                }

                var pageSchemaHtml = '';
                if (currentPageId > 0 && configured) {
                    var psEnabled = pageSchema.enabled || false;
                    var psJson    = pageSchema.json    || '';
                    pageSchemaHtml = '<div class="cd-schema-section-title" style="margin-top:14px;">Page-specific schema</div>'
                        + '<label class="cd-schema-ps-toggle"><input type="checkbox" id="cd-ps-enabled" ' + (psEnabled ? 'checked' : '') + '> Override global schema for this page</label>'
                        + '<div id="cd-ps-json-wrap" style="' + (psEnabled ? '' : 'display:none;') + 'margin-top:8px;">'
                        + '<textarea class="cd-ps-json-input" id="cd-ps-json" rows="6" placeholder=\'{"@context":"https://schema.org","@type":"Service","name":"…"}\'>' + psJson + '</textarea>'
                        + '<button class="cd-seo-edit-meta-btn" id="cd-ps-save" style="margin-top:6px;">Save page schema</button>'
                        + '</div>';
                }

                el.innerHTML = '<div class="cd-schema-summary">'
                    + '<div class="cd-schema-status-row">' + statusDot + '<span>' + statusText + '</span>'
                    + '<button class="cd-schema-edit-btn" id="cd-schema-edit-btn">' + (configured ? 'Edit setup' : 'Run setup wizard') + '</button>'
                    + '</div></div>'
                    + reviewHtml
                    + pageSchemaHtml;

                document.getElementById('cd-schema-edit-btn').addEventListener('click', openWizard);

                if (configured) {
                    document.getElementById('cd-tab-add-review').addEventListener('click', function() {
                        addTabReview();
                    });
                    bindTabReviewDelete();
                }

                if (currentPageId > 0 && configured) {
                    var psToggle = document.getElementById('cd-ps-enabled');
                    var psWrap   = document.getElementById('cd-ps-json-wrap');
                    psToggle.addEventListener('change', function() { psWrap.style.display = psToggle.checked ? '' : 'none'; });
                    document.getElementById('cd-ps-save').addEventListener('click', function() {
                        savePageSchema(currentPageId, psToggle.checked, document.getElementById('cd-ps-json').value);
                    });
                }
            }

            function renderSchemaReviewList(reviews) {
                if (!reviews.length) return '<div class="cd-schema-no-reviews">No reviews yet.</div>';
                return reviews.map(function(r, i) {
                    var stars = '★'.repeat(r.rating || 5) + '☆'.repeat(5 - (r.rating || 5));
                    return '<div class="cd-schema-review-item" data-index="' + i + '">'
                        + '<div class="cd-schema-review-top"><span class="cd-schema-review-author">' + (r.author||'') + '</span>'
                        + '<span class="cd-schema-review-stars">' + stars + '</span>'
                        + '<button class="cd-schema-review-del" data-index="' + i + '">✕</button></div>'
                        + (r.text ? '<div class="cd-schema-review-text">' + r.text + '</div>' : '')
                        + '</div>';
                }).join('');
            }

            function bindTabReviewDelete() {
                document.querySelectorAll('.cd-schema-review-del').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var i = parseInt(btn.dataset.index);
                        schemaData.reviews = (schemaData.reviews || []).filter(function(_,idx){ return idx !== i; });
                        saveSchema(schemaData, function() {
                            document.getElementById('cd-tab-reviews').innerHTML = renderSchemaReviewList(schemaData.reviews || []);
                            document.querySelector('.cd-schema-review-count').textContent = (schemaData.reviews||[]).length;
                            bindTabReviewDelete();
                        });
                    });
                });
            }

            function addTabReview() {
                var author = prompt('Reviewer name:');
                if (!author) return;
                var rating = parseInt(prompt('Rating (1-5):', '5'));
                if (isNaN(rating) || rating < 1 || rating > 5) rating = 5;
                var text = prompt('Review text (optional):') || '';
                var date = new Date().toISOString().split('T')[0];
                schemaData.reviews = schemaData.reviews || [];
                schemaData.reviews.push({author: author, rating: rating, text: text, date: date});
                saveSchema(schemaData, function() {
                    document.getElementById('cd-tab-reviews').innerHTML = renderSchemaReviewList(schemaData.reviews);
                    document.querySelector('.cd-schema-review-count').textContent = schemaData.reviews.length;
                    bindTabReviewDelete();
                });
            }

            function savePageSchema(pageId, enabled, json) {
                var d = new FormData();
                d.append('action', 'cdc_save_schema');
                d.append('nonce', nonce);
                d.append('schema', JSON.stringify({
                    page_schema_id: pageId,
                    page_schema_enabled: enabled,
                    page_schema_json: json
                }));
                fetch(ajaxUrl, {method:'POST', body:d}).then(function(r){return r.json();}).then(function(res){
                    var btn = document.getElementById('cd-ps-save');
                    btn.textContent = res.success ? '✓ Saved' : '✕ Error';
                    setTimeout(function(){btn.textContent='Save page schema';}, 2000);
                });
            }

            function saveSchema(data, callback) {
                var params = new URLSearchParams();
                params.append('action', 'cdc_save_schema');
                params.append('nonce', nonce);
                params.append('schema', JSON.stringify(data));
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                }).then(function(r) { return r.json(); }).then(function(res) {
                    if (callback) callback(res);
                });
            }

            function savePageSchema(pageId, enabled, json) {
                var params = new URLSearchParams();
                params.append('action', 'cdc_save_schema');
                params.append('nonce', nonce);
                params.append('schema', JSON.stringify({
                    page_schema_id: pageId,
                    page_schema_enabled: enabled,
                    page_schema_json: json
                }));
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                }).then(function(r) { return r.json(); }).then(function(res) {
                    var btn = document.getElementById('cd-ps-save');
                    if (btn) { btn.textContent = res.success ? '✓ Saved' : '✕ Error'; setTimeout(function(){btn.textContent='Save page schema';}, 2000); }
                });
            }

            // ---------------------------------------------------------------
            // Schema wizard
            // ---------------------------------------------------------------

            var wizard        = document.getElementById('cd-wizard');
            var wizardStep    = 1;
            var wizardTrigger = document.getElementById('cd-wizard-trigger');
            var wizardSkip    = document.getElementById('cd-wizard-skip');
            var wizardBack    = document.getElementById('cd-wizard-back');
            var wizardNext    = document.getElementById('cd-wizard-next');
            var wizardSave    = document.getElementById('cd-wizard-save');
            var wizardSaving  = document.getElementById('cd-wizard-saving');

            function openWizard() {
                // Pre-populate from saved data
                populateWizard(schemaData);
                wizard.style.display = 'flex';
                goToWizardStep(1);

                // If schema not yet configured, check if wizard should auto-open
            }

            function closeWizard() {
                wizard.style.display = 'none';
            }

            if (wizardTrigger) wizardTrigger.addEventListener('click', function() {
                // Load schema first then open
                var d = new FormData();
                d.append('action', 'cdc_get_schema');
                d.append('nonce', nonce);
                fetch(ajaxUrl, {method:'POST', body:d}).then(function(r){return r.json();}).then(function(res){
                    schemaData = (res.data && res.data.schema) ? res.data.schema : {};
                    openWizard();
                });
            });

            wizardSkip.addEventListener('click', function() {
                // Save wizard_complete flag so it doesn't auto-open again
                schemaData.wizard_complete = true;
                saveSchema(schemaData, null);
                closeWizard();
                updateSchemaTrigger();
            });

            wizardBack.addEventListener('click', function() { goToWizardStep(wizardStep - 1); });
            wizardNext.addEventListener('click', function() { saveWizardStep(wizardStep, function() { goToWizardStep(wizardStep + 1); }); });
            wizardSave.addEventListener('click', function() {
                saveWizardStep(wizardStep, function() {
                    schemaData.wizard_complete = true;
                    saveSchema(schemaData, function(res) {
                        wizardSaving.style.display = 'none';
                        wizardSave.style.display   = 'inline-block';
                        if (res && res.success) {
                            closeWizard();
                            updateSchemaTrigger();
                            // Refresh schema tab if open
                            if (document.querySelector('.cd-panel-tab--active') && document.querySelector('.cd-panel-tab--active').dataset.panel === 'schema') {
                                loadSchemaTab();
                            }
                        }
                    });
                    wizardSave.style.display   = 'none';
                    wizardSaving.style.display = 'inline-block';
                });
            });

            function goToWizardStep(n) {
                wizardStep = n;
                for (var i = 1; i <= 4; i++) {
                    var el = document.getElementById('cd-wstep-' + i);
                    if (el) el.style.display = i === n ? '' : 'none';
                }
                document.getElementById('cd-wizard-step-label').textContent = 'Step ' + n + ' of 4';
                document.getElementById('cd-wizard-progress-bar').style.width = (n * 25) + '%';
                wizardBack.style.display    = n > 1 ? 'inline-block' : 'none';
                wizardNext.style.display    = n < 4 ? 'inline-block' : 'none';
                wizardSave.style.display    = n === 4 ? 'inline-block' : 'none';
                wizardSaving.style.display  = 'none';
            }

            function saveWizardStep(step, callback) {
                if (step === 1) {
                    var selected = document.querySelector('input[name="cdc_biz_type"]:checked');
                    if (selected) {
                        schemaData.type = selected.value === '__other__'
                            ? (document.getElementById('cd-wizard-other-type').value.trim() || 'LocalBusiness')
                            : selected.value;
                    }
                } else if (step === 2) {
                    schemaData.name    = document.getElementById('cd-wf-name').value.trim()    || '<?php echo esc_js( get_bloginfo('name') ); ?>';
                    schemaData.url     = document.getElementById('cd-wf-url').value.trim()     || '<?php echo esc_js( home_url() ); ?>';
                    schemaData.phone   = document.getElementById('cd-wf-phone').value.trim();
                    schemaData.email   = document.getElementById('cd-wf-email').value.trim();
                    schemaData.desc    = document.getElementById('cd-wf-desc').value.trim();
                    schemaData.logo    = document.getElementById('cd-wf-logo').value.trim();
                    schemaData.addr    = document.getElementById('cd-wf-addr').value.trim();
                    schemaData.city    = document.getElementById('cd-wf-city').value.trim();
                    schemaData.region  = document.getElementById('cd-wf-region').value.trim();
                    schemaData.post    = document.getElementById('cd-wf-post').value.trim();
                    schemaData.country = document.getElementById('cd-wf-country').value.trim() || 'NZ';
                    schemaData.area    = document.getElementById('cd-wf-area').value.trim();
                } else if (step === 3) {
                    var days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
                    days.forEach(function(day) {
                        var closedEl = document.querySelector('.cd-hours-closed[data-day="' + day + '"]');
                        var openEl   = document.querySelector('.cd-hours-open[data-day="' + day + '"]');
                        var closeEl  = document.querySelector('.cd-hours-close[data-day="' + day + '"]');
                        if (closedEl) schemaData['hours_' + day + '_closed'] = closedEl.checked;
                        if (openEl)   schemaData['hours_' + day + '_open']   = openEl.value;
                        if (closeEl)  schemaData['hours_' + day + '_close']  = closeEl.value;
                    });
                } else if (step === 4) {
                    schemaData.socials = {
                        facebook:  document.getElementById('cd-wf-facebook').value.trim(),
                        instagram: document.getElementById('cd-wf-instagram').value.trim(),
                        linkedin:  document.getElementById('cd-wf-linkedin').value.trim(),
                        youtube:   document.getElementById('cd-wf-youtube').value.trim(),
                    };
                }
                // Save progressively on each step
                saveSchema(schemaData, function() { if (callback) callback(); });
            }

            function populateWizard(data) {
                if (!data || !data.type) return;

                // Step 1
                var radio = document.querySelector('input[name="cdc_biz_type"][value="' + data.type + '"]');
                if (radio) {
                    radio.checked = true;
                } else {
                    var otherRadio = document.querySelector('input[name="cdc_biz_type"][value="__other__"]');
                    if (otherRadio) { otherRadio.checked = true; document.getElementById('cd-wizard-other-wrap').style.display=''; }
                    var otherInput = document.getElementById('cd-wizard-other-type');
                    if (otherInput) otherInput.value = data.type;
                }

                // Step 2
                var f = function(id, val) { var el = document.getElementById(id); if (el && val) el.value = val; };
                f('cd-wf-name', data.name); f('cd-wf-url', data.url); f('cd-wf-phone', data.phone);
                f('cd-wf-email', data.email); f('cd-wf-desc', data.desc); f('cd-wf-logo', data.logo);
                f('cd-wf-addr', data.addr); f('cd-wf-city', data.city); f('cd-wf-region', data.region);
                f('cd-wf-post', data.post); f('cd-wf-country', data.country); f('cd-wf-area', data.area);

                // Step 3 — hours
                var days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
                days.forEach(function(day) {
                    var closedEl = document.querySelector('.cd-hours-closed[data-day="' + day + '"]');
                    var openEl   = document.querySelector('.cd-hours-open[data-day="' + day + '"]');
                    var closeEl  = document.querySelector('.cd-hours-close[data-day="' + day + '"]');
                    var timesEl  = document.getElementById('cd-hours-times-' + day);
                    if (closedEl && data['hours_' + day + '_closed']) { closedEl.checked = true; if (timesEl) timesEl.style.display='none'; }
                    if (openEl && data['hours_' + day + '_open'])   openEl.value  = data['hours_' + day + '_open'];
                    if (closeEl && data['hours_' + day + '_close']) closeEl.value = data['hours_' + day + '_close'];
                });

                // Step 4 — socials
                var socials = data.socials || {};
                f('cd-wf-facebook', socials.facebook); f('cd-wf-instagram', socials.instagram);
                f('cd-wf-linkedin', socials.linkedin); f('cd-wf-youtube', socials.youtube);

                // Step 4 — reviews
                var revList = document.getElementById('cd-reviews-list');
                if (revList) revList.innerHTML = renderWizardReviews(data.reviews || []);
                bindWizardReviewDelete();
            }

            // Business type cards
            document.querySelectorAll('.cd-wizard-type-card').forEach(function(card) {
                card.addEventListener('click', function() {
                    document.querySelectorAll('.cd-wizard-type-card').forEach(function(c) { c.classList.remove('cd-wizard-type-card--selected'); });
                    card.classList.add('cd-wizard-type-card--selected');
                    var otherWrap = document.getElementById('cd-wizard-other-wrap');
                    otherWrap.style.display = card.dataset.type === '__other__' ? '' : 'none';
                    card.querySelector('input[type="radio"]').checked = true;
                });
            });

            // Closed checkboxes
            document.querySelectorAll('.cd-hours-closed').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var timesEl = document.getElementById('cd-hours-times-' + cb.dataset.day);
                    if (timesEl) timesEl.style.display = cb.checked ? 'none' : '';
                });
            });

            // Wizard reviews
            document.getElementById('cd-add-review-btn').addEventListener('click', function() {
                var author = prompt('Reviewer name:');
                if (!author) return;
                var rating = parseInt(prompt('Rating (1-5):', '5'));
                if (isNaN(rating) || rating < 1 || rating > 5) rating = 5;
                var text = prompt('Review text (optional):') || '';
                var date = new Date().toISOString().split('T')[0];
                schemaData.reviews = schemaData.reviews || [];
                schemaData.reviews.push({author: author, rating: rating, text: text, date: date});
                var revList = document.getElementById('cd-reviews-list');
                if (revList) { revList.innerHTML = renderWizardReviews(schemaData.reviews); bindWizardReviewDelete(); }
            });

            function renderWizardReviews(reviews) {
                if (!reviews.length) return '<div class="cd-no-reviews">No reviews added yet.</div>';
                return reviews.map(function(r, i) {
                    return '<div class="cd-wizard-review-item"><span>' + (r.author||'') + ' — ' + (r.rating||5) + '★</span>'
                        + '<button class="cd-wizard-review-del" data-index="' + i + '">✕</button>'
                        + (r.text ? '<div class="cd-wizard-review-text">' + r.text + '</div>' : '')
                        + '</div>';
                }).join('');
            }

            function bindWizardReviewDelete() {
                document.querySelectorAll('.cd-wizard-review-del').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var i = parseInt(btn.dataset.index);
                        schemaData.reviews = (schemaData.reviews||[]).filter(function(_,idx){ return idx !== i; });
                        var revList = document.getElementById('cd-reviews-list');
                        if (revList) { revList.innerHTML = renderWizardReviews(schemaData.reviews); bindWizardReviewDelete(); }
                    });
                });
            }

            // Schema trigger state — set after fetching schema (no auto-open)
            (function() {
                var d = new FormData();
                d.append('action', 'cdc_get_schema');
                d.append('nonce', nonce);
                fetch(ajaxUrl, {method:'POST', body:d}).then(function(r){return r.json();}).then(function(res){
                    schemaData = (res.data && res.data.schema) ? res.data.schema : {};
                    updateSchemaTrigger();
                });
            })();
            // ---------------------------------------------------------------
            // Inline font selectors
            // ---------------------------------------------------------------
            var fontSave  = document.getElementById('cd-font-save');
            var fontSaved = document.getElementById('cd-font-saved');

            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.wpColorPicker) {
                var $linkColor = window.jQuery('#cd-link-color');
                if ($linkColor.length) {
                    $linkColor.wpColorPicker({ palettes: true });
                }
            }

            function saveFonts() {
                var heading = document.getElementById('cd-font-heading').value;
                var body    = document.getElementById('cd-font-body').value;
                var linkColorEl = document.getElementById('cd-link-color');
                var linkColor = linkColorEl ? linkColorEl.value : '';
                fontSave.disabled = true;
                var d = new FormData();
                d.append('action',       'cdc_save_fonts');
                d.append('nonce',        nonce);
                d.append('font_heading', heading);
                d.append('font_body',    body);
                d.append('link_color',   linkColor);
                fetch(ajaxUrl, { method: 'POST', body: d })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        fontSave.disabled = false;
                        if (res.success) {
                            fontSaved.style.display = 'inline';
                            setTimeout(function() { fontSaved.style.display = 'none'; }, 1400);
                        }
                    })
                    .catch(function() { fontSave.disabled = false; });
            }

            fontSave.addEventListener('click', saveFonts);

            // ---------------------------------------------------------------
            // Schema trigger state
            // ---------------------------------------------------------------
            function updateSchemaTrigger() {
                var btn = document.getElementById('cd-wizard-trigger');
                if (!btn) return;
                var complete = schemaData && schemaData.wizard_complete && schemaData.type;
                if (complete) {
                    btn.textContent = 'Schema';
                    btn.classList.remove('cd-wizard-trigger--incomplete');
                } else {
                    btn.textContent = 'Schema incomplete';
                    btn.classList.add('cd-wizard-trigger--incomplete');
                }
            }

            var modal = document.getElementById('cd-modal');
            var launchBtn = document.getElementById('cd-launch-btn');
            var closeBtn  = document.getElementById('cd-modal-close');

            if (launchBtn) launchBtn.addEventListener('click', function() {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            });

            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display !== 'none') {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            });
        })();
        </script>
        <?php
    }

    // ---------------------------------------------------------------
    // History page
    // ---------------------------------------------------------------

    public function render_history(): void {
        if ( ! current_user_can( $this->cap() ) ) wp_die( 'Permission denied.' );
        $changes = get_posts( [ 'post_type' => 'cdc_change', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC' ] );
        $nonce   = wp_create_nonce( 'cdc_nonce' );
        ?>
        <div class="cd-wrap cd-wrap--history">
            <div class="cd-topbar">
                <span class="cd-logo"><span class="cd-logo-icon">≡</span><strong>Client</strong>Desk</span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="cd-topbar-link">← Make a change</a>
                <span class="cd-iw-credit">An <strong>Impact Websites</strong> exclusive</span>
            </div>
            <div class="cd-history">
                <h2>Change History</h2>
                <p style="color:#666;margin-top:-8px;margin-bottom:20px;">Each entry shows what was changed. Use <strong>Restore</strong> to roll the page back to how it was <em>before</em> that change.</p>
                <div id="cd-history-msg" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:6px;font-weight:500;"></div>
                <?php if ( empty( $changes ) ) : ?>
                    <p class="cd-history-empty">No changes recorded yet.</p>
                <?php else : ?>
                    <table class="cd-history-table">
                        <thead><tr><th>Date</th><th>What changed</th><th>Page</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ( $changes as $c ) :
                            $pid         = (int) get_post_meta( $c->ID, '_cdc_page_id', true );
                            $sum         = (string) get_post_meta( $c->ID, '_cdc_summary', true );
                            $has_restore = '' !== (string) get_post_meta( $c->ID, '_cdc_restore_content', true );
                        ?>
                            <tr>
                                <td><?php echo esc_html( get_date_from_gmt( $c->post_date_gmt, get_option('date_format') . ' ' . get_option('time_format') ) ); ?></td>
                                <td><?php echo esc_html( $sum ?: $c->post_title ); ?></td>
                                <td><?php echo $pid ? '<a href="' . esc_url( get_permalink( $pid ) ) . '" target="_blank">' . esc_html( get_the_title( $pid ) ) . '</a>' : '—'; ?></td>
                                <td>
                                    <?php if ( $has_restore ) : ?>
                                        <button class="cd-btn-restore button button-secondary" data-change-id="<?php echo esc_attr( $c->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Restore</button>
                                    <?php else : ?>
                                        <span style="color:#aaa;font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            var ajaxUrl = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            document.querySelectorAll('.cd-btn-restore').forEach(function(btn){
                btn.addEventListener('click', function(){
                    if ( ! confirm('Are you sure? This will restore the page to how it was before this change.') ) return;
                    btn.disabled = true;
                    btn.textContent = 'Restoring…';
                    var fd = new FormData();
                    fd.append('action',    'cdc_restore_change');
                    fd.append('nonce',     btn.dataset.nonce);
                    fd.append('change_id', btn.dataset.changeId);
                    fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(data){
                            var msgEl = document.getElementById('cd-history-msg');
                            if ( data.success ) {
                                msgEl.style.display    = 'block';
                                msgEl.style.background = '#d4edda';
                                msgEl.style.color      = '#155724';
                                msgEl.textContent      = data.data && data.data.message ? data.data.message : 'Page restored.';
                                btn.textContent = 'Restored ✓';
                            } else {
                                msgEl.style.display    = 'block';
                                msgEl.style.background = '#f8d7da';
                                msgEl.style.color      = '#721c24';
                                msgEl.textContent      = data.data && data.data.message ? data.data.message : 'Restore failed.';
                                btn.disabled    = false;
                                btn.textContent = 'Restore';
                            }
                            msgEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        })
                        .catch(function(){
                            btn.disabled    = false;
                            btn.textContent = 'Restore';
                            alert('Network error. Please try again.');
                        });
                });
            });
        })();
        </script>
        <?php
    }

    // ---------------------------------------------------------------
    // Settings page
    // ---------------------------------------------------------------

    public function render_settings(): void {
        if ( ! current_user_can( $this->cap() ) ) wp_die( 'Permission denied.' );
        ?>
        <div class="cd-wrap cd-wrap--settings">
            <div class="cd-topbar">
                <span class="cd-logo"><span class="cd-logo-icon">≡</span><strong>Client</strong>Desk</span>
                <span class="cd-iw-credit">An <strong>Impact Websites</strong> exclusive</span>
            </div>
            <div class="cd-settings">
                <h2>Settings</h2>
                <?php settings_errors('cdc_settings'); ?>
                <form method="post" action="<?php echo esc_url( admin_url('options.php') ); ?>">
                    <?php settings_fields('cdc_settings'); ?>

                    <h3 style="margin-top:24px;">Connection</h3>
                    <div class="cd-settings-row">
                        <label>Licence Key</label>
                        <input type="password" name="<?php echo esc_attr( self::OPT_KEY ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_KEY, '' ) ); ?>" autocomplete="off" />
                        <p class="cd-settings-desc">Provided by Impact Websites.</p>
                    </div>
                    <div class="cd-settings-row">
                        <label>Server Endpoint</label>
                        <input type="text" name="<?php echo esc_attr( self::OPT_ENDPOINT ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_ENDPOINT, '' ) ); ?>" placeholder="https://impactwebsites.co.nz/wp-json/clientdesk/v1/chat" />
                        <p class="cd-settings-desc">Provided by Impact Websites. Do not change unless instructed.</p>
                    </div>
                    <div class="cd-settings-row">
                        <label>Domain override <span style="font-weight:400;text-transform:none;">(optional)</span></label>
                        <input type="text" name="<?php echo esc_attr( self::OPT_DOMAIN ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_DOMAIN, '' ) ); ?>" placeholder="Leave blank to auto-detect" />
                        <p class="cd-settings-desc">Only needed if your site is behind a proxy.</p>
                    </div>

                    <h3 style="margin-top:32px;">Debug</h3>
                    <div class="cd-settings-row">
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( self::OPT_DEBUG ); ?>" value="1" <?php checked( get_option( self::OPT_DEBUG ) ); ?> />
                            Enable debug logging
                        </label>
                        <p class="cd-settings-desc">Logs all ClientDesk requests and errors to a file in the plugin directory. Disable when not needed.</p>
                    </div>

                    <button type="submit" class="cd-settings-save" style="margin-top:24px;">Save</button>
                </form>

                <?php
                $log_path = CDC_PATH . self::LOG_FILE;
                if ( file_exists( $log_path ) ) :
                    $log_content = file_get_contents( $log_path );
                    $log_size    = filesize( $log_path );
                ?>
                <div style="margin-top:32px;">
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:10px;">
                        <h3 style="margin:0;">Debug Log</h3>
                        <span style="font-size:12px;color:#aaa;"><?php echo esc_html( number_format( $log_size / 1024, 1 ) ); ?> KB</span>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                            <?php wp_nonce_field( 'cdc_clear_log' ); ?>
                            <input type="hidden" name="action" value="cdc_clear_log" />
                            <button type="submit" class="button button-small">Clear log</button>
                        </form>
                    </div>
                    <textarea readonly style="width:100%;height:400px;font-family:monospace;font-size:11px;background:#1a1a1a;color:#00ff00;border:none;padding:12px;box-sizing:border-box;resize:vertical;"><?php echo esc_textarea( $log_content ); ?></textarea>
                </div>
                <?php elseif ( get_option( self::OPT_DEBUG ) ) : ?>
                <p style="margin-top:16px;font-size:12px;color:#aaa;">Debug mode is on. Log will appear here after the first request.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // AJAX — create a new page (optionally add to primary nav menu)
    // ---------------------------------------------------------------

    public function ajax_create_page(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $title    = isset( $_POST['title'] )    ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $add_menu = isset( $_POST['add_menu'] ) && $_POST['add_menu'] === '1';

        if ( '' === $title ) wp_send_json_error( [ 'message' => 'Page title is required.' ] );

        // Create the page
        $page_id = wp_insert_post( [
            'post_title'   => $title,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
            'post_content' => '',
        ], true );

        if ( is_wp_error( $page_id ) ) {
            wp_send_json_error( [ 'message' => 'Could not create page: ' . $page_id->get_error_message() ] );
        }

        // Auto-populate fields via existing method
        $post = get_post( $page_id );
        if ( $post ) $this->auto_populate( $page_id, $post );

        $nav_added = false;
        if ( $add_menu ) {
            // Find the primary nav menu (first registered location, or first menu)
            $locations = get_nav_menu_locations();
            $menu_id   = 0;

            if ( ! empty( $locations ) ) {
                // Prefer 'primary', 'main-menu', 'header-menu', or just take first
                $priority = [ 'primary', 'main-menu', 'main', 'header-menu', 'header', 'top-menu' ];
                foreach ( $priority as $slug ) {
                    if ( ! empty( $locations[ $slug ] ) ) {
                        $menu_id = (int) $locations[ $slug ];
                        break;
                    }
                }
                if ( ! $menu_id ) $menu_id = (int) reset( $locations );
            }

            if ( ! $menu_id ) {
                // Fall back to first registered menu
                $menus = wp_get_nav_menus();
                if ( ! empty( $menus ) ) $menu_id = (int) $menus[0]->term_id;
            }

            if ( $menu_id ) {
                $item = wp_update_nav_menu_item( $menu_id, 0, [
                    'menu-item-title'     => $title,
                    'menu-item-object'    => 'page',
                    'menu-item-object-id' => $page_id,
                    'menu-item-type'      => 'post_type',
                    'menu-item-status'    => 'publish',
                ] );
                $nav_added = ! is_wp_error( $item );
            }
        }

        wp_send_json_success( [
            'page_id'   => $page_id,
            'title'     => $title,
            'permalink' => get_permalink( $page_id ) ?: '',
            'nav_added' => $nav_added,
        ] );
    }

    // ---------------------------------------------------------------
    // Google Fonts curated list
    // ---------------------------------------------------------------

    public static function google_fonts_list(): array {
        $fonts = [
            // Sans-serif
            'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'Nunito',
            'Raleway', 'Ubuntu', 'Noto Sans', 'Source Sans 3', 'PT Sans', 'Oxygen',
            'Mulish', 'Quicksand', 'Cabin', 'Hind', 'Barlow', 'DM Sans', 'Figtree',
            'Outfit', 'Plus Jakarta Sans', 'Sora', 'Josefin Sans', 'Karla', 'Rubik',
            'Work Sans', 'Exo 2', 'Manrope',
            // Serif
            'Lora', 'Merriweather', 'Playfair Display', 'PT Serif', 'EB Garamond',
            'Libre Baskerville', 'Cormorant Garamond', 'Crimson Text', 'DM Serif Display',
            'Spectral', 'Noto Serif', 'Source Serif 4', 'Bitter', 'Libre Caslon Text',
            // Display / decorative
            'Bebas Neue', 'Anton', 'Oswald', 'Abril Fatface', 'Righteous', 'Pacifico',
            'Dancing Script', 'Great Vibes',
            // Monospace
            'JetBrains Mono', 'Fira Code', 'Source Code Pro',
        ];
        natcasesort( $fonts );
        return array_values( $fonts );
    }

    // ---------------------------------------------------------------
    // AJAX — save font settings from the chat UI
    // ---------------------------------------------------------------

    public function ajax_save_fonts(): void {
        check_ajax_referer( 'cdc_nonce', 'nonce' );
        if ( ! current_user_can( $this->cap() ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $heading = sanitize_text_field( wp_unslash( $_POST['font_heading'] ?? '' ) );
        $body    = sanitize_text_field( wp_unslash( $_POST['font_body']    ?? '' ) );
        $link_color = sanitize_hex_color( wp_unslash( $_POST['link_color'] ?? '' ) ) ?: '';

        update_option( self::OPT_FONT_HEADING, $heading );
        update_option( self::OPT_FONT_BODY,    $body );
        update_option( self::OPT_LINK_COLOR,   $link_color );

        wp_send_json_success( [ 'font_heading' => $heading, 'font_body' => $body, 'link_color' => $link_color ] );
    }

    private function cap(): string {
        return (string) apply_filters( 'clientdesk_capability', 'edit_pages' );
    }
}

new ClientDesk();

// Theme render functions — called from impact-websites theme index.php
function iw_render_header(): void {
    $id      = is_singular('page') ? get_the_ID() : 0;
    $per_page = $id ? (string) get_post_meta( $id, CDC_FIELD_HEADER, true ) : '';
    echo do_shortcode( '' !== trim( $per_page ) ? $per_page : get_option( CDC_GLOBAL_HEADER, '' ) ); // phpcs:ignore
}
function iw_render_body(): void {
    if ( function_exists( 'is_woocommerce' ) && ( is_cart() || is_checkout() || is_woocommerce() ) ) {
        echo '<main id="iw-woocommerce">';
        if ( is_cart() || is_checkout() ) {
            while ( have_posts() ) : the_post();
                the_content();
            endwhile;
        } else {
            woocommerce_content();
        }
        echo '</main>';
    } elseif ( is_singular( 'page' ) ) {
        echo do_shortcode( (string) get_post_meta( get_the_ID(), CDC_FIELD_BODY, true ) ); // phpcs:ignore
    }
}
function iw_render_footer(): void {
    $id       = is_singular('page') ? get_the_ID() : 0;
    $per_page = $id ? (string) get_post_meta( $id, CDC_FIELD_FOOTER, true ) : '';
    echo do_shortcode( '' !== trim( $per_page ) ? $per_page : get_option( CDC_GLOBAL_FOOTER, '' ) ); // phpcs:ignore
}
