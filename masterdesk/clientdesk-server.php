<?php
/**
 * Plugin Name: MasterDesk
 * Description: MasterDesk — Impact Websites central hub. Manages ClientDesk licences, usage tracking, and AI for all client sites.
 * Version: 2.4.2
 * Tested up to: 6.8
 * Author: impact2021
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CDS_VERSION',   '2.4.2' );
define( 'CDS_TOKEN_TABLE', 'clientdesk_tokens' );
define( 'CDS_TABLE',     'clientdesk_sites' );
define( 'CDS_LOG_TABLE', 'clientdesk_usage' );

// ---------------------------------------------------------------
// Activation — create / update DB tables
// ---------------------------------------------------------------

register_activation_hook( __FILE__, 'cds_activate' );

function cds_activate(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $sites   = $wpdb->prefix . CDS_TABLE;
    $log     = $wpdb->prefix . CDS_LOG_TABLE;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE {$sites} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        domain          VARCHAR(255)    NOT NULL,
        licence_key     VARCHAR(64)     NOT NULL,
        label           VARCHAR(255)    NOT NULL DEFAULT '',
        status          VARCHAR(16)     NOT NULL DEFAULT 'active',
        monthly_budget  INT UNSIGNED    NOT NULL DEFAULT 1000,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY licence_key (licence_key),
        KEY domain (domain),
        KEY status (status)
    ) {$charset};" );

    dbDelta( "CREATE TABLE {$log} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        site_id         BIGINT UNSIGNED NOT NULL,
        domain          VARCHAR(255)    NOT NULL,
        action          VARCHAR(32)     NOT NULL DEFAULT 'chat',
        summary         TEXT,
        input_tokens    INT UNSIGNED    NOT NULL DEFAULT 0,
        output_tokens   INT UNSIGNED    NOT NULL DEFAULT 0,
        cost_cents      INT UNSIGNED    NOT NULL DEFAULT 0,
        used_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY site_id (site_id),
        KEY used_at (used_at)
    ) {$charset};" );

    $tokens = $wpdb->prefix . CDS_TOKEN_TABLE;
    dbDelta( "CREATE TABLE {$tokens} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token       VARCHAR(64)     NOT NULL,
        site_id     BIGINT UNSIGNED NOT NULL,
        domain      VARCHAR(255)    NOT NULL,
        model       VARCHAR(64)     NOT NULL DEFAULT '',
        expires_at  DATETIME        NOT NULL,
        used        TINYINT(1)      NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY token (token),
        KEY expires_at (expires_at)
    ) {$charset};" );
}

// ---------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------

add_action( 'rest_api_init', 'cds_register_endpoint' );
add_action( 'admin_menu',            'cds_register_menu' );
add_action( 'admin_init',            'cds_register_settings' );
add_action( 'admin_enqueue_scripts', 'cds_enqueue_assets' );
add_action( 'wp_ajax_cds_add_site',    'cds_ajax_add_site' );
add_action( 'wp_ajax_cds_remove_site', 'cds_ajax_remove_site' );
add_action( 'wp_ajax_cds_update_site', 'cds_ajax_update_site' );

// ---------------------------------------------------------------
// REST endpoint — /wp-json/clientdesk/v1/chat
// ---------------------------------------------------------------

function cds_register_endpoint(): void {
    register_rest_route( 'clientdesk/v1', '/chat', [
        'methods'             => 'POST',
        'callback'            => 'cds_handle_chat',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'clientdesk/v1', '/usage', [
        'methods'             => 'POST',
        'callback'            => 'cds_handle_usage',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'clientdesk/v1', '/seo', [
        'methods'             => 'POST',
        'callback'            => 'cds_handle_seo',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'clientdesk/v1', '/token', [
        'methods'             => 'POST',
        'callback'            => 'cds_handle_token',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'clientdesk/v1', '/report', [
        'methods'             => 'POST',
        'callback'            => 'cds_handle_report',
        'permission_callback' => '__return_true',
    ] );
}

function cds_handle_chat( WP_REST_Request $request ): void {
    $licence_key  = sanitize_text_field( (string) $request->get_param( 'licence_key' ) );
    $domain       = sanitize_text_field( (string) $request->get_param( 'domain' ) );
    $page_title   = sanitize_text_field( (string) $request->get_param( 'page_title' ) );
    $page_html    = (string) $request->get_param( 'page_html' );
    $history      = (array)  $request->get_param( 'history' );
    $message      = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
    $font_heading = sanitize_text_field( (string) $request->get_param( 'font_heading' ) );
    $font_body    = sanitize_text_field( (string) $request->get_param( 'font_body' ) );

    $send = function( string $event, array $data ) {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . wp_json_encode( $data ) . "\n\n";
        if ( ob_get_level() > 0 ) ob_flush();
        flush();
    };

    $error = function( string $message ) use ( $send ) {
        $send( 'error', [ 'message' => $message ] );
        exit;
    };

    $site = cds_get_site_by_key( $licence_key );
    if ( ! $site )                    { $error( 'Invalid licence key.' ); return; }
    if ( $site->status !== 'active' ) { $error( 'ClientDesk access for this site has been suspended. Please contact Impact Websites.' ); return; }

    $registered = cds_normalise_domain( $site->domain );
    $calling    = cds_normalise_domain( $domain );
    if ( $registered !== $calling )   { $error( 'Domain mismatch. This licence key is not valid for ' . $domain . '.' ); return; }

    $budget_cents = (int) $site->monthly_budget;
    $spent_cents  = cds_monthly_spent_cents( $site->id );

    if ( $spent_cents >= $budget_cents ) {
        $error( "You've reached your monthly budget of " . cds_format_dollars( $budget_cents ) . ". Contact Impact Websites to increase your limit." );
        return;
    }

    if ( '' === $message ) { $error( 'No message provided.' ); return; }
    if ( '' === $page_title ) $page_title = 'this page';
    $is_blank = '' === trim( $page_html );

    $clean_history = [];
    foreach ( $history as $turn ) {
        $role    = isset( $turn['role'] )    ? sanitize_text_field( $turn['role'] ) : '';
        $content = isset( $turn['content'] ) ? (string) $turn['content']            : '';
        if ( in_array( $role, [ 'user', 'assistant' ], true ) && '' !== $content ) {
            $clean_history[] = [ 'role' => $role, 'content' => $content ];
        }
    }
    $clean_history[] = [ 'role' => 'user', 'content' => $message ];

    $page_html   = cds_strip_html_for_claude( $page_html );
    $is_blank    = '' === trim( $page_html );
    $system = cds_system_prompt( $page_title, $page_html, $is_blank, $font_heading, $font_body );

    if ( ! headers_sent() ) {
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'X-Accel-Buffering: no' );
    }

    while ( ob_get_level() > 0 ) ob_end_flush();

    $api_key = trim( (string) get_option( 'cds_anthropic_api_key', '' ) );
    if ( '' === $api_key ) { $error( 'Anthropic API key not configured.' ); return; }

    $model = trim( (string) get_option( 'cds_model', 'claude-sonnet-4-6' ) );

    $post_body = wp_json_encode( [
        'model'      => $model,
        'max_tokens' => 16000,
        'stream'     => true,
        'system'     => $system,
        'messages'   => $clean_history,
    ] );

    $last_ping = time();

    $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_body,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: '         . $api_key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function( $ch, $chunk ) use ( $send, &$full_text, &$input_tokens, &$output_tokens, &$last_ping ) {
            $lines = explode( "\n", $chunk );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( strpos( $line, 'data: ' ) !== 0 ) continue;
                $json = json_decode( substr( $line, 6 ), true );
                if ( ! is_array( $json ) ) continue;

                $type = $json['type'] ?? '';

                if ( $type === 'content_block_delta' ) {
                    $token = $json['delta']['text'] ?? '';
                    if ( $token !== '' ) {
                        $full_text .= $token;
                        if ( $full_text[0] !== '{' ) {
                            $send( 'token', [ 'text' => $token ] );
                        } else {
                            $now = time();
                            if ( $now - $last_ping >= 10 ) {
                                echo ": ping\n\n";
                                if ( ob_get_level() > 0 ) ob_flush();
                                flush();
                                $last_ping = $now;
                            }
                        }
                    }
                } elseif ( $type === 'message_delta' ) {
                    $output_tokens = $json['usage']['output_tokens'] ?? $output_tokens;
                } elseif ( $type === 'message_start' ) {
                    $input_tokens = $json['message']['usage']['input_tokens'] ?? 0;
                }
            }
            return strlen( $chunk );
        },
    ] );

    $full_text     = '';
    $input_tokens  = 0;
    $output_tokens = 0;

    $curl_result = curl_exec( $ch );
    $curl_error  = curl_error( $ch );
    $curl_errno  = curl_errno( $ch );
    curl_close( $ch );

    if ( $curl_result === false || $curl_errno ) {
        $error( 'Could not reach Anthropic API: ' . $curl_error );
        return;
    }

    if ( '' === $full_text ) {
        $error( 'Claude returned an empty response.' );
        return;
    }

    $action_data   = cds_parse_action( $full_text );
    $cost_cents    = cds_calculate_cost_cents( $input_tokens, $output_tokens );

    if ( $action_data && in_array( $action_data['action'] ?? '', [ 'apply', 'patch', 'insert' ], true ) ) {
        cds_log_usage( $site->id, $domain, 'apply', $action_data['summary'] ?? '', $input_tokens, $output_tokens, $cost_cents );
        $spent_cents += $cost_cents;
    } elseif ( ! $action_data ) {
        cds_log_usage( $site->id, $domain, 'chat', substr( $full_text, 0, 80 ), $input_tokens, $output_tokens, $cost_cents );
        $spent_cents += $cost_cents;
    }

    $clean_history[] = [ 'role' => 'assistant', 'content' => $full_text ];

    $remaining_cents = max( 0, $budget_cents - $spent_cents );
    $warn_threshold  = (float) get_option( 'cds_warn_threshold', '2.00' );
    $warn_cents      = (int) round( $warn_threshold * 100 );
    $contact_phone   = (string) get_option( 'cds_contact_phone', '0210559077' );
    $contact_email   = (string) get_option( 'cds_contact_email', 'impact@impactwebsites.co.nz' );

    $send( 'done', [
        'success'         => true,
        'raw'             => $full_text,
        'action'          => $action_data,
        'history'         => $clean_history,
        'spent_fmt'       => cds_format_dollars( $spent_cents ),
        'budget_fmt'      => cds_format_dollars( $budget_cents ),
        'remaining_fmt'   => cds_format_dollars( $remaining_cents ),
        'show_warning'    => $remaining_cents > 0 && $remaining_cents <= $warn_cents,
        'contact_phone'   => $contact_phone,
        'contact_email'   => $contact_email,
    ] );

    exit;
}

// ---------------------------------------------------------------
// Claude API call
// ---------------------------------------------------------------

function cds_call_claude( array $messages, string $system ): array|WP_Error {
    $api_key = trim( (string) get_option( 'cds_anthropic_api_key', '' ) );
    if ( '' === $api_key ) {
        return new WP_Error( 'cds_no_key', 'Anthropic API key not configured on the ClientDesk server.' );
    }

    $model = trim( (string) get_option( 'cds_model', 'claude-sonnet-4-6' ) );

    $body = wp_json_encode( [
        'model'      => $model,
        'max_tokens' => 16000,
        'system'     => $system,
        'messages'   => $messages,
    ] );

    for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 90,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( $attempt < 3 ) { sleep( $attempt * 2 ); continue; }
            return $response;
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );

        if ( $code === 429 || $code >= 500 ) {
            if ( $attempt < 3 ) { sleep( $attempt * 3 ); continue; }
            return new WP_Error( 'cds_api', "Anthropic API returned HTTP {$code}" );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'cds_api', "Anthropic API returned HTTP {$code}: " . wp_strip_all_tags( $raw_body ) );
        }

        $envelope      = json_decode( $raw_body, true );
        $text          = (string) ( $envelope['content'][0]['text'] ?? '' );
        $input_tokens  = (int) ( $envelope['usage']['input_tokens']  ?? 0 );
        $output_tokens = (int) ( $envelope['usage']['output_tokens'] ?? 0 );

        if ( '' === $text ) return new WP_Error( 'cds_empty', 'Claude returned an empty response.' );

        return [
            'text'          => $text,
            'input_tokens'  => $input_tokens,
            'output_tokens' => $output_tokens,
        ];
    }

    return new WP_Error( 'cds_failed', 'API call failed after all retry attempts.' );
}

// ---------------------------------------------------------------
// Pricing helpers
// ---------------------------------------------------------------

function cds_calculate_cost_cents( int $input_tokens, int $output_tokens ): int {
    $input_rate  = (float) get_option( 'cds_rate_input',  '3.00' );
    $output_rate = (float) get_option( 'cds_rate_output', '15.00' );

    $input_cost  = ( $input_tokens  / 1_000_000 ) * $input_rate;
    $output_cost = ( $output_tokens / 1_000_000 ) * $output_rate;

    return (int) ceil( ( $input_cost + $output_cost ) * 100 );
}

function cds_format_dollars( int $cents ): string {
    return '$' . number_format( $cents / 100, 2 );
}

// ---------------------------------------------------------------
// Strip HTML for Claude
// ---------------------------------------------------------------

function cds_is_style_intent( string $message ): bool {
    $msg = strtolower( $message );
    $keywords = [
        'color', 'colour', 'font', 'size', 'bold', 'italic', 'underline',
        'background', 'padding', 'margin', 'border', 'shadow', 'opacity',
        'width', 'height', 'align', 'centre', 'center', 'style', 'css',
        'weight', 'spacing', 'radius', 'gradient', 'display', 'flex',
        'visible', 'hidden', 'position', 'layout', 'dark', 'light',
        'bigger', 'smaller', 'larger', 'bolder', 'thinner', 'wider',
        'narrow', 'thick', 'thin', 'transparent', 'solid', 'dashed',
    ];
    foreach ( $keywords as $kw ) {
        if ( str_contains( $msg, $kw ) ) return true;
    }
    return false;
}

function cds_strip_html_for_claude( string $html ): string {
    $html = preg_replace( '/<style[^>]*>[\s\S]*?<\/style>/i', '', $html );
    $html = preg_replace( '/<script[^>]*>[\s\S]*?<\/script>/i', '', $html );
    $html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );
    $html = preg_replace( '/\s*\n\s*/', "\n", $html );
    $html = preg_replace( '/\n{2,}/', "\n", $html );
    return trim( $html );
}

// ---------------------------------------------------------------
// System prompt — plain English, no technical jargon to clients
// ---------------------------------------------------------------

function cds_system_prompt( string $page_title, string $page_html, bool $is_blank = false, string $font_heading = '', string $font_body = '' ): string {

    $font_context = '';
    if ( $font_heading || $font_body ) {
        $font_context = "\n## Site fonts\n";
        if ( $font_heading ) $font_context .= "- Heading font: {$font_heading}\n";
        if ( $font_body )    $font_context .= "- Body font: {$font_body}\n";
        $font_context .= "Always use these font names in any CSS you write — never substitute alternatives.\n";
    }

    if ( $is_blank ) {
        return <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners build and update their website content. You are talking directly with the business owner — not a developer.

## Critical language rules
- NEVER mention HTML, CSS, code, tags, elements, divs, classes, IDs, attributes, or any technical terms
- NEVER show or quote code of any kind in your responses
- NEVER use backticks or code formatting
- Describe changes in plain business language only
- "Make it bold" not "wrap in strong tags"
- "Add a new section" not "insert a div"
- "Change the heading" not "update the h1"
- "Update the button colour" not "change the background-color property"
- If something went wrong, say so plainly — never explain the technical reason
{$font_context}
## Your context
The user is building: "{$page_title}"
This page is currently empty — you will be creating it from scratch.

## Your conversation flow

**Step 1 — Outline and confirm**
When the user describes what they want, respond with a brief plain-English outline of what you'll build — layout, key sections, any assumptions. Keep it to 4–6 bullet points maximum. End with: "Shall I go ahead and build this?"

Do NOT write or show any code yet. Just the outline in plain text.

If the request needs an image and none has been provided, respond with this exact JSON and nothing else:
{"action":"need_image","message":"[Plain English message asking them to select an image from their library]"}

When the user sends image URLs, they may send multiple — use them in order or place them contextually.

When the user says yes, go ahead, or similar, output the complete HTML wrapped exactly like this — nothing before or after:
{"action":"apply","html":"[COMPLETE HTML]","summary":"[One plain English sentence describing what was built — no technical terms]"}

## Rules
- Write clean, semantic HTML with proper structure
- Use inline CSS — no external stylesheets
- Mobile responsive from the start — include media queries
- No placeholder lorem ipsum — use realistic content based on the page title and context
- Never apply without confirmation first
PROMPT;
    }

    return <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners update their website content. You are talking directly with the business owner — not a developer.

## Critical language rules
- NEVER mention HTML, CSS, code, tags, elements, divs, classes, IDs, attributes, selectors, or any technical terms
- NEVER show or quote code of any kind in your responses
- NEVER use backticks or code formatting
- Describe changes in plain business language only — as if explaining to someone with no technical knowledge
- "Make it bold" not "wrap in strong tags"
- "Change the heading" not "update the h1 element"
- "Update the button colour" not "change the background-color"
- "Add a new section below the intro" not "insert a div after the header"
- "I'll move the image to sit flush at the bottom" not "I'll set align-self: flex-end"
- After applying a change, confirm in plain English what was done — e.g. "Done — I've made that text bold."
- If something didn't work as expected, say so plainly and try a different approach — never explain the technical reason
- NEVER ask the user to inspect elements, check CSS, or do anything technical
{$font_context}
## Your context
The user is editing: "{$page_title}"
The current full page content is provided below — read it carefully before responding.

## Your capabilities
You can change text, images, colours, styles, layout — anything on the page.

## Your conversation flow

**Step 1 — Understand and confirm**
When the user describes a change, confirm in plain English exactly what you found and what you will change it to. Quote the current text if relevant. Be specific and brief.

If the request involves adding or swapping an image and no URL has been provided, respond with this exact JSON and nothing else:
{"action":"need_image","message":"[Plain English message asking them to select an image — no technical terms]"}

If you need clarification, ask in plain text only — no code, no JSON.

**Step 2 — Apply on approval**
When the user says yes, go ahead, or similar:

For changes to EXISTING content:
Output ONLY this JSON — nothing before or after:
{"action":"patch","patches":[{"find":"[exact string from the HTML to find]","replace":"[the replacement string]"},{"find":"...","replace":"..."}],"summary":"[one plain English sentence — no technical terms]"}

- "find" must be an exact verbatim substring of the current HTML — copy character-for-character
- "find" must be long enough to be unique — include surrounding markup if needed
- Multiple patches allowed in one array
- Never return the full HTML — only the patch instructions

For ADDING NEW content:
{"action":"insert","position":"after","anchor":"[exact unique string already in the HTML]","html":"[the new HTML to insert]","summary":"[one plain English sentence]"}

For REMOVING content:
{"action":"patch","patches":[{"find":"[exact HTML block to remove]","replace":""}],"summary":"[one plain English sentence]"}

For meta title or description — confirm first, then on approval:
{"action":"update_meta","field":"meta_title","value":"the new title"}
or {"action":"update_meta","field":"meta_desc","value":"the new description"}

**Step 3 — Confirm success clearly**
After a change action is returned, the system applies it automatically. Assume it worked unless told otherwise. Do not say "I've sent the patch" or "I've applied the change" — the done event message handles that. On any follow-up message from the user, respond as if the previous change succeeded.

## Important behaviour rules
- Never apply without user confirmation first
- For patches: "find" must be verbatim from the HTML — never paraphrase
- For patches: include enough surrounding context in "find" to be unique on the page
- Preserve all layout and structure unless asked to change it
- Keep confirmation messages brief and jargon-free
- Meta descriptions must be 50–155 characters
- If a patch fails to match, try a broader find string on the next attempt — do not explain why it failed technically

## Current page content
```html
{$page_html}
```
PROMPT;
}

// ---------------------------------------------------------------
// Action parser
// ---------------------------------------------------------------

function cds_parse_action( string $response ): ?array {
    $trimmed = trim( $response );
    if ( str_starts_with( $trimmed, '{' ) ) {
        $data = json_decode( $trimmed, true );
        if ( is_array( $data ) && isset( $data['action'] ) ) return $data;
    }
    if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m ) ) {
        $data = json_decode( $m[1], true );
        if ( is_array( $data ) && isset( $data['action'] ) ) return $data;
    }
    return null;
}

// ---------------------------------------------------------------
// Token endpoint
// ---------------------------------------------------------------

function cds_handle_token( WP_REST_Request $request ): WP_REST_Response {
    $licence_key = sanitize_text_field( (string) $request->get_param( 'licence_key' ) );
    $domain      = sanitize_text_field( (string) $request->get_param( 'domain' ) );

    $site = cds_get_site_by_key( $licence_key );
    if ( ! $site )                    return cds_error( 'Invalid licence key.' );
    if ( $site->status !== 'active' ) return cds_error( 'ClientDesk access for this site has been suspended. Please contact Impact Websites.' );

    $registered = cds_normalise_domain( $site->domain );
    $calling    = cds_normalise_domain( $domain );
    if ( $registered !== $calling )   return cds_error( 'Domain mismatch. This licence key is not valid for ' . $domain . '.' );

    $budget_cents = (int) $site->monthly_budget;
    $spent_cents  = cds_monthly_spent_cents( $site->id );
    if ( $spent_cents >= $budget_cents ) {
        return cds_error( "You've reached your monthly budget of " . cds_format_dollars( $budget_cents ) . ". Contact Impact Websites to increase your limit." );
    }

    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->prefix}" . CDS_TOKEN_TABLE . " WHERE expires_at < NOW() OR used = 1" );

    $token     = 'cdt_' . bin2hex( random_bytes( 24 ) );
    $model     = trim( (string) get_option( 'cds_model', 'claude-sonnet-4-6' ) );
    $api_key   = trim( (string) get_option( 'cds_anthropic_api_key', '' ) );
    $expires   = gmdate( 'Y-m-d H:i:s', time() + 90 );

    if ( ! $api_key ) return cds_error( 'Anthropic API key not configured.' );

    $wpdb->insert( $wpdb->prefix . CDS_TOKEN_TABLE, [
        'token'      => $token,
        'site_id'    => $site->id,
        'domain'     => $calling,
        'model'      => $model,
        'expires_at' => $expires,
        'used'       => 0,
    ] );

    $remaining_cents = max( 0, $budget_cents - $spent_cents );
    $warn_threshold  = (float) get_option( 'cds_warn_threshold', '2.00' );
    $warn_cents      = (int) round( $warn_threshold * 100 );

    return new WP_REST_Response( [
        'success'         => true,
        'token'           => $token,
        'api_key'         => $api_key,
        'model'           => $model,
        'expires_in'      => 90,
        'spent_fmt'       => cds_format_dollars( $spent_cents ),
        'budget_fmt'      => cds_format_dollars( $budget_cents ),
        'remaining_fmt'   => cds_format_dollars( $remaining_cents ),
        'show_warning'    => $remaining_cents > 0 && $remaining_cents <= $warn_cents,
        'contact_phone'   => (string) get_option( 'cds_contact_phone', '0210559077' ),
        'contact_email'   => (string) get_option( 'cds_contact_email', 'impact@impactwebsites.co.nz' ),
        'clientdesk_version'      => trim( (string) get_option( 'cds_clientdesk_version', '' ) ),
        'clientdesk_download_url' => trim( (string) get_option( 'cds_clientdesk_download_url', '' ) ),
    ], 200 );
}

// ---------------------------------------------------------------
// Report endpoint
// ---------------------------------------------------------------

function cds_handle_report( WP_REST_Request $request ): WP_REST_Response {
    $token         = sanitize_text_field( (string) $request->get_param( 'token' ) );
    $input_tokens  = (int) $request->get_param( 'input_tokens' );
    $output_tokens = (int) $request->get_param( 'output_tokens' );
    $action        = sanitize_text_field( (string) $request->get_param( 'action' ) );
    $summary       = sanitize_text_field( (string) $request->get_param( 'summary' ) );

    if ( ! $token ) return cds_error( 'No token provided.' );

    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . CDS_TOKEN_TABLE . " WHERE token = %s AND used = 0 AND expires_at >= NOW() LIMIT 1",
        $token
    ) );

    if ( ! $row ) return cds_error( 'Token invalid, expired, or already used.' );

    $wpdb->update( $wpdb->prefix . CDS_TOKEN_TABLE, [ 'used' => 1 ], [ 'token' => $token ] );

    $cost_cents = cds_calculate_cost_cents( $input_tokens, $output_tokens );
    cds_log_usage( (int) $row->site_id, $row->domain, $action ?: 'chat', $summary ?: '', $input_tokens, $output_tokens, $cost_cents );

    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . CDS_TABLE . " WHERE id = %d LIMIT 1",
        (int) $row->site_id
    ) );

    $budget_cents    = $site ? (int) $site->monthly_budget : 0;
    $spent_cents     = cds_monthly_spent_cents( (int) $row->site_id );
    $remaining_cents = max( 0, $budget_cents - $spent_cents );
    $warn_threshold  = (float) get_option( 'cds_warn_threshold', '2.00' );
    $warn_cents      = (int) round( $warn_threshold * 100 );

    return new WP_REST_Response( [
        'success'       => true,
        'cost_cents'    => $cost_cents,
        'spent_fmt'     => cds_format_dollars( $spent_cents ),
        'budget_fmt'    => cds_format_dollars( $budget_cents ),
        'remaining_fmt' => cds_format_dollars( $remaining_cents ),
        'show_warning'  => $remaining_cents > 0 && $remaining_cents <= $warn_cents,
        'contact_phone' => (string) get_option( 'cds_contact_phone', '0210559077' ),
        'contact_email' => (string) get_option( 'cds_contact_email', 'impact@impactwebsites.co.nz' ),
    ], 200 );
}

// ---------------------------------------------------------------
// Usage endpoint
// ---------------------------------------------------------------

function cds_handle_usage( WP_REST_Request $request ): WP_REST_Response {
    $licence_key = sanitize_text_field( (string) $request->get_param( 'licence_key' ) );
    $domain      = sanitize_text_field( (string) $request->get_param( 'domain' ) );

    $site = cds_get_site_by_key( $licence_key );
    if ( ! $site )                    return cds_error( 'Invalid licence key.' );
    if ( $site->status !== 'active' ) return cds_error( 'Access suspended.' );

    $registered = cds_normalise_domain( $site->domain );
    $calling    = cds_normalise_domain( $domain );
    if ( $registered !== $calling )   return cds_error( 'Domain mismatch.' );

    $budget_cents    = (int) $site->monthly_budget;
    $spent_cents     = cds_monthly_spent_cents( $site->id );
    $remaining_cents = max( 0, $budget_cents - $spent_cents );
    $warn_threshold  = (float) get_option( 'cds_warn_threshold', '2.00' );
    $warn_cents      = (int) round( $warn_threshold * 100 );

    return new WP_REST_Response( [
        'success'         => true,
        'spent_cents'     => $spent_cents,
        'budget_cents'    => $budget_cents,
        'remaining_cents' => $remaining_cents,
        'spent_fmt'       => cds_format_dollars( $spent_cents ),
        'budget_fmt'      => cds_format_dollars( $budget_cents ),
        'remaining_fmt'   => cds_format_dollars( $remaining_cents ),
        'show_warning'    => $remaining_cents > 0 && $remaining_cents <= $warn_cents,
        'contact_phone'   => (string) get_option( 'cds_contact_phone', '0210559077' ),
        'contact_email'   => (string) get_option( 'cds_contact_email', 'impact@impactwebsites.co.nz' ),
    ], 200 );
}

// ---------------------------------------------------------------
// SEO analysis endpoint
// ---------------------------------------------------------------

function cds_handle_seo( WP_REST_Request $request ): WP_REST_Response {
    $licence_key = sanitize_text_field( (string) $request->get_param( 'licence_key' ) );
    $domain      = sanitize_text_field( (string) $request->get_param( 'domain' ) );
    $page_title  = sanitize_text_field( (string) $request->get_param( 'page_title' ) );
    $page_html   = (string) $request->get_param( 'page_html' );
    $meta_title  = sanitize_text_field( (string) $request->get_param( 'meta_title' ) );
    $meta_desc   = sanitize_textarea_field( (string) $request->get_param( 'meta_desc' ) );
    $keyword     = sanitize_text_field( (string) $request->get_param( 'keyword' ) );
    $word_count  = (int) $request->get_param( 'word_count' );

    $site = cds_get_site_by_key( $licence_key );
    if ( ! $site )                    return cds_error( 'Invalid licence key.' );
    if ( $site->status !== 'active' ) return cds_error( 'ClientDesk access has been suspended. Please contact Impact Websites.' );

    $registered = cds_normalise_domain( $site->domain );
    $calling    = cds_normalise_domain( $domain );
    if ( $registered !== $calling )   return cds_error( 'Domain mismatch.' );

    $budget_cents = (int) $site->monthly_budget;
    $spent_cents  = cds_monthly_spent_cents( $site->id );
    if ( $spent_cents >= $budget_cents ) {
        return cds_error( 'Monthly budget reached. Contact Impact Websites to increase your limit.' );
    }

    if ( '' === $keyword )   return cds_error( 'No keyword provided.' );
    if ( '' === trim( $page_html ) ) return cds_error( 'This page has no content yet. Add some HTML first, then run the analysis.' );

    $system = 'You are an SEO specialist reviewing a website page for a small New Zealand business. '
        . 'Analyse the page content and existing meta fields against the target keyword. '
        . 'Return a JSON array of high-impact suggestions only. Each suggestion must follow this schema exactly: '
        . '{"type":"[short label e.g. Meta Title / Meta Description / H1 / H2 / Image Alt / New Section]",'
        . '"field":"[one of: meta_title | meta_desc | content | null]",'
        . '"value":"[the exact replacement text or new content]"}'
        . "\n\nRules:"
        . "\n- Return only the JSON array, no other text, no markdown fences."
        . "\n- Maximum 5 suggestions, prioritised by SEO impact."
        . "\n- For meta_title and meta_desc, always provide the complete suggested value."
        . "\n- For content suggestions, set field to 'content' and provide the specific suggested text."
        . "\n- Do NOT include any explanation — only the type label and the suggested value."
        . "\n- ONLY suggest a change if it meaningfully improves keyword relevance, click-through rate, or search visibility."
        . "\n- Do NOT suggest purely cosmetic changes."
        . ( $word_count < 300
            ? "\n- The page has only {$word_count} words which is low for SEO. Include a suggestion with type 'New Section' and field 'content' — suggest a specific new section topic and a short paragraph of starter copy that incorporates the keyword naturally."
            : "\n- The page has {$word_count} words which is adequate. Do NOT suggest adding new sections solely to increase word count." )
        . "\n- If meta title or description is already well-optimised for the keyword, do not suggest changing it."
        . "\n- Be ruthless about quality — 2 great suggestions beat 5 mediocre ones. Return an empty array [] if nothing meaningful needs changing.";

    $user = "Target keyword: \"{$keyword}\"\n\n"
        . "Page title: {$page_title}\n"
        . "Current meta title: " . ( $meta_title ?: '(not set)' ) . "\n"
        . "Current meta description: " . ( $meta_desc ?: '(not set)' ) . "\n\n"
        . "Page HTML:\n```html\n{$page_html}\n```";

    $result = cds_call_claude( [ [ 'role' => 'user', 'content' => $user ] ], $system );

    if ( is_wp_error( $result ) ) return cds_error( $result->get_error_message() );

    $text          = $result['text'];
    $input_tokens  = $result['input_tokens'];
    $output_tokens = $result['output_tokens'];
    $cost_cents    = cds_calculate_cost_cents( $input_tokens, $output_tokens );

    cds_log_usage( $site->id, $domain, 'apply', "SEO analysis for keyword: {$keyword}", $input_tokens, $output_tokens, $cost_cents );
    $spent_cents += $cost_cents;

    $clean = trim( $text );
    $clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
    $clean = preg_replace( '/\s*```$/', '', $clean );
    $suggestions = json_decode( $clean, true );
    if ( ! is_array( $suggestions ) ) $suggestions = [];

    $remaining_cents   = max( 0, $budget_cents - $spent_cents );
    $warn_threshold    = (float) get_option( 'cds_warn_threshold', '2.00' );
    $warn_cents        = (int) round( $warn_threshold * 100 );
    $contact_phone     = (string) get_option( 'cds_contact_phone', '0210559077' );
    $contact_email     = (string) get_option( 'cds_contact_email', 'impact@impactwebsites.co.nz' );

    return new WP_REST_Response( [
        'success'         => true,
        'suggestions'     => $suggestions,
        'spent_cents'     => $spent_cents,
        'budget_cents'    => $budget_cents,
        'remaining_cents' => $remaining_cents,
        'spent_fmt'       => cds_format_dollars( $spent_cents ),
        'budget_fmt'      => cds_format_dollars( $budget_cents ),
        'remaining_fmt'   => cds_format_dollars( $remaining_cents ),
        'show_warning'    => $remaining_cents > 0 && $remaining_cents <= $warn_cents,
        'contact_phone'   => $contact_phone,
        'contact_email'   => $contact_email,
    ], 200 );
}

// ---------------------------------------------------------------
// DB helpers
// ---------------------------------------------------------------

function cds_get_site_by_key( string $key ): ?object {
    global $wpdb;
    $table = $wpdb->prefix . CDS_TABLE;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE licence_key = %s LIMIT 1", $key ) ) ?: null;
}

function cds_normalise_domain( string $domain ): string {
    $domain = strtolower( trim( $domain ) );
    $domain = preg_replace( '#^https?://#', '', $domain );
    $domain = preg_replace( '#^www\.#', '', $domain );
    return rtrim( $domain, '/' );
}

function cds_monthly_spent_cents( int $site_id ): int {
    global $wpdb;
    $table = $wpdb->prefix . CDS_LOG_TABLE;
    $start = gmdate( 'Y-m-01 00:00:00' );
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(cost_cents),0) FROM {$table} WHERE site_id = %d AND action = 'apply' AND used_at >= %s",
        $site_id, $start
    ) );
}

function cds_log_usage( int $site_id, string $domain, string $action, string $summary, int $input_tokens, int $output_tokens, int $cost_cents ): void {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . CDS_LOG_TABLE, [
        'site_id'       => $site_id,
        'domain'        => $domain,
        'action'        => $action,
        'summary'       => $summary,
        'input_tokens'  => $input_tokens,
        'output_tokens' => $output_tokens,
        'cost_cents'    => $cost_cents,
    ] );
}

function cds_generate_key(): string {
    return 'cd_' . bin2hex( random_bytes( 20 ) );
}

function cds_error( string $message ): WP_REST_Response {
    return new WP_REST_Response( [ 'success' => false, 'error' => $message ], 200 );
}

// ---------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------

function cds_register_menu(): void {
    add_menu_page( 'ClientDesk', 'ClientDesk', 'manage_options', 'clientdesk-server', 'cds_render_panel', 'dashicons-rest-api', 30 );
    add_submenu_page( 'clientdesk-server', 'Sites',    'Sites',    'manage_options', 'clientdesk-server',          'cds_render_panel' );
    add_submenu_page( 'clientdesk-server', 'Settings', 'Settings', 'manage_options', 'clientdesk-server-settings', 'cds_render_settings' );
}

function cds_register_settings(): void {
    foreach ( [
        'cds_anthropic_api_key',
        'cds_model',
        'cds_rate_input',
        'cds_rate_output',
        'cds_warn_threshold',
        'cds_contact_phone',
        'cds_contact_email',
        'cds_clientdesk_version',
        'cds_clientdesk_download_url',
    ] as $opt ) {
        register_setting( 'cds_settings', $opt, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
    }
}

function cds_enqueue_assets( string $hook ): void {
    if ( false === strpos( $hook, 'clientdesk-server' ) ) return;
    wp_enqueue_style( 'cds-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', [], CDS_VERSION );
}

// ---------------------------------------------------------------
// Management panel
// ---------------------------------------------------------------

function cds_render_panel(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );

    global $wpdb;
    $sites = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}" . CDS_TABLE . " ORDER BY created_at DESC" );

    $cost_map = [];
    $start    = gmdate( 'Y-m-01 00:00:00' );
    if ( $sites ) {
        $ids  = implode( ',', array_map( 'intval', array_column( (array) $sites, 'id' ) ) );
        $rows = $wpdb->get_results(
            "SELECT site_id, COALESCE(SUM(cost_cents),0) as total, COALESCE(SUM(input_tokens),0) as input_t, COALESCE(SUM(output_tokens),0) as output_t, COUNT(*) as changes
             FROM {$wpdb->prefix}" . CDS_LOG_TABLE . "
             WHERE action = 'apply' AND used_at >= '{$start}' AND site_id IN ({$ids})
             GROUP BY site_id"
        );
        foreach ( $rows as $r ) {
            $cost_map[ (int) $r->site_id ] = [
                'cost_cents'    => (int) $r->total,
                'input_tokens'  => (int) $r->input_t,
                'output_tokens' => (int) $r->output_t,
                'changes'       => (int) $r->changes,
            ];
        }
    }

    $total_cost_cents = array_sum( array_column( $cost_map, 'cost_cents' ) );
    ?>
    <div class="cds-wrap">
        <div class="cds-header">
            <span class="cds-logo"><span class="cds-logo-icon">≡</span><strong>Master</strong>Desk</span>
            <span class="cds-header-total">Total cost this month: <strong><?php echo esc_html( cds_format_dollars( $total_cost_cents ) ); ?></strong></span>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientdesk-server-settings' ) ); ?>" class="cds-header-link">Settings</a>
        </div>

        <div class="cds-settings-section-label">ClientDesk updates</div>

        <div class="cds-settings-row">
            <label>Latest ClientDesk version</label>
            <input type="text" name="cds_clientdesk_version" value="<?php echo esc_attr( $clientdesk_version ); ?>" />
        </div>

        <div class="cds-settings-row">
            <label>ClientDesk download URL</label>
            <input type="text" name="cds_clientdesk_download_url" value="<?php echo esc_attr( $clientdesk_download_url ); ?>" />
        </div>

        <div class="cds-panel">

            <div class="cds-panel-top">
                <h2>Authorised Sites</h2>
                <button class="cds-btn cds-btn--primary" id="cds-add-btn">+ Add site</button>
            </div>

            <div class="cds-add-form" id="cds-add-form" style="display:none;">
                <div class="cds-add-form-inner">
                    <div class="cds-field">
                        <label>Domain</label>
                        <input type="text" id="cds-new-domain" placeholder="e.g. example.co.nz" />
                    </div>
                    <div class="cds-field">
                        <label>Client name</label>
                        <input type="text" id="cds-new-label" placeholder="e.g. Craftwork Garage Doors" />
                    </div>
                    <div class="cds-field">
                        <label>Monthly budget ($)</label>
                        <input type="number" id="cds-new-budget" value="10.00" min="0.01" step="0.01" />
                    </div>
                    <div class="cds-add-form-actions">
                        <button class="cds-btn cds-btn--primary" id="cds-add-submit">Generate licence &amp; add</button>
                        <button class="cds-btn" id="cds-add-cancel">Cancel</button>
                    </div>
                    <div class="cds-add-result" id="cds-add-result" style="display:none;"></div>
                </div>
            </div>

            <?php if ( empty( $sites ) ) : ?>
                <p class="cds-empty">No sites authorised yet. Add one above.</p>
            <?php else : ?>
            <table class="cds-table">
                <thead>
                    <tr>
                        <th>Client / Domain</th>
                        <th>Licence key</th>
                        <th>This month</th>
                        <th>Budget / mo</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sites as $site ) :
                    $stats         = $cost_map[ (int) $site->id ] ?? [ 'cost_cents' => 0, 'changes' => 0, 'input_tokens' => 0, 'output_tokens' => 0 ];
                    $spent_cents   = $stats['cost_cents'];
                    $budget_cents  = (int) $site->monthly_budget;
                    $pct           = $budget_cents > 0 ? round( ( $spent_cents / $budget_cents ) * 100 ) : 0;
                    $bar_class     = $pct >= 90 ? 'cds-bar--red' : ( $pct >= 70 ? 'cds-bar--amber' : 'cds-bar--green' );
                    $total_tokens  = $stats['input_tokens'] + $stats['output_tokens'];
                ?>
                    <tr data-site-id="<?php echo esc_attr( (string) $site->id ); ?>">
                        <td class="cds-col-client">
                            <div class="cds-editable-wrap">
                                <span class="cds-site-label cds-editable-display"><?php echo esc_html( $site->label ?: $site->domain ); ?></span>
                                <input class="cds-site-label-input cds-editable-input" type="text"
                                    value="<?php echo esc_attr( $site->label ); ?>"
                                    data-site-id="<?php echo esc_attr( (string) $site->id ); ?>"
                                    data-field="label"
                                    placeholder="<?php echo esc_attr( $site->domain ); ?>"
                                    style="display:none;" />
                                <button class="cds-edit-inline-btn" title="Edit client name" aria-label="Edit client name">✎</button>
                            </div>
                            <div class="cds-editable-wrap cds-domain-wrap" style="margin-top:3px;">
                                <span class="cds-site-domain cds-editable-display"><?php echo esc_html( $site->domain ); ?></span>
                                <input class="cds-site-domain-input cds-editable-input" type="text"
                                    value="<?php echo esc_attr( $site->domain ); ?>"
                                    data-site-id="<?php echo esc_attr( (string) $site->id ); ?>"
                                    data-field="domain"
                                    style="display:none;" />
                                <button class="cds-edit-inline-btn cds-edit-domain-btn" title="Edit domain" aria-label="Edit domain">✎</button>
                            </div>
                        </td>
                        <td>
                            <code class="cds-key"><?php echo esc_html( $site->licence_key ); ?></code>
                            <button class="cds-copy-btn" data-copy="<?php echo esc_attr( $site->licence_key ); ?>" title="Copy key">⎘</button>
                        </td>
                        <td>
                            <div class="cds-usage-row">
                                <span class="cds-cost-spent"><?php echo esc_html( cds_format_dollars( $spent_cents ) ); ?></span>
                                <span class="cds-cost-sep"> of </span>
                                <span class="cds-cost-budget"><?php echo esc_html( cds_format_dollars( $budget_cents ) ); ?></span>
                            </div>
                            <div class="cds-bar-track">
                                <div class="cds-bar <?php echo esc_attr( $bar_class ); ?>" style="width:<?php echo esc_attr( min( 100, $pct ) . '%' ); ?>"></div>
                            </div>
                            <div class="cds-token-detail"><?php echo esc_html( number_format( $total_tokens ) ); ?> tokens &middot; <?php echo esc_html( (string) $stats['changes'] ); ?> changes</div>
                        </td>
                        <td>
                            <div class="cds-budget-input-wrap">
                                <span class="cds-budget-prefix">$</span>
                                <input class="cds-budget-input" type="number" step="0.01" min="0.01"
                                    value="<?php echo esc_attr( number_format( $budget_cents / 100, 2, '.', '' ) ); ?>"
                                    data-site-id="<?php echo esc_attr( (string) $site->id ); ?>" />
                            </div>
                        </td>
                        <td>
                            <span class="cds-status cds-status--<?php echo esc_attr( $site->status ); ?>"><?php echo esc_html( ucfirst( $site->status ) ); ?></span>
                        </td>
                        <td class="cds-actions">
                            <?php if ( $site->status === 'active' ) : ?>
                                <button class="cds-btn cds-btn--danger cds-action-btn" data-action="suspend" data-id="<?php echo esc_attr( (string) $site->id ); ?>">Suspend</button>
                            <?php else : ?>
                                <button class="cds-btn cds-btn--success cds-action-btn" data-action="activate" data-id="<?php echo esc_attr( (string) $site->id ); ?>">Activate</button>
                            <?php endif; ?>
                            <button class="cds-btn cds-btn--danger cds-action-btn" data-action="remove" data-id="<?php echo esc_attr( (string) $site->id ); ?>">Remove</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        </div>
    </div>

    <script>
    (function() {
        var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'cds_nonce' ) ); ?>;
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

        document.getElementById('cds-add-btn').addEventListener('click', function() {
            var f = document.getElementById('cds-add-form');
            f.style.display = f.style.display === 'none' ? 'block' : 'none';
        });
        document.getElementById('cds-add-cancel').addEventListener('click', function() {
            document.getElementById('cds-add-form').style.display = 'none';
        });

        document.getElementById('cds-add-submit').addEventListener('click', function() {
            var domain = document.getElementById('cds-new-domain').value.trim();
            var label  = document.getElementById('cds-new-label').value.trim();
            var budget = document.getElementById('cds-new-budget').value.trim();
            var result = document.getElementById('cds-add-result');

            if (!domain) { alert('Domain is required.'); return; }

            var data = new FormData();
            data.append('action', 'cds_add_site');
            data.append('nonce',  nonce);
            data.append('domain', domain);
            data.append('label',  label);
            data.append('budget', budget || '10.00');

            fetch(ajaxUrl, { method:'POST', body:data })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        result.style.display = 'block';
                        result.className = 'cds-add-result cds-add-result--success';
                        result.innerHTML = '<strong>Site added.</strong> Licence key: <code>' + res.data.key + '</code> <button class="cds-copy-btn" data-copy="' + res.data.key + '">⎘ Copy</button><br><small>Paste this key into the client site\'s ClientDesk Settings.</small>';
                        bindCopy(result.querySelector('.cds-copy-btn'));
                        setTimeout(function() { location.reload(); }, 4000);
                    } else {
                        result.style.display = 'block';
                        result.className = 'cds-add-result cds-add-result--error';
                        result.textContent = res.data.message || 'Error adding site.';
                    }
                });
        });

        document.querySelectorAll('.cds-action-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = btn.dataset.action;
                var id     = btn.dataset.id;
                if (action === 'remove' && !confirm('Remove this site? The licence key will stop working immediately.')) return;

                var data = new FormData();
                data.append('action',     'cds_' + (action === 'remove' ? 'remove' : 'update') + '_site');
                data.append('nonce',      nonce);
                data.append('site_id',    id);
                data.append('new_status', action === 'suspend' ? 'suspended' : 'active');

                fetch(ajaxUrl, { method:'POST', body:data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) location.reload();
                        else alert(res.data.message || 'Error.');
                    });
            });
        });

        document.querySelectorAll('.cds-budget-input').forEach(function(input) {
            input.addEventListener('blur', function() {
                var dollars = parseFloat(input.value);
                if (isNaN(dollars) || dollars < 0.01) return;
                var cents = Math.round(dollars * 100);

                var data = new FormData();
                data.append('action',         'cds_update_site');
                data.append('nonce',          nonce);
                data.append('site_id',        input.dataset.siteId);
                data.append('monthly_budget', cents);

                fetch(ajaxUrl, { method:'POST', body:data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success) alert('Could not save budget: ' + (res.data.message || 'error'));
                    });
            });
        });

        function bindCopy(btn) {
            if (!btn) return;
            btn.addEventListener('click', function() {
                navigator.clipboard.writeText(btn.dataset.copy).then(function() {
                    var orig = btn.textContent;
                    btn.textContent = '✓ Copied';
                    setTimeout(function() { btn.textContent = orig; }, 1500);
                });
            });
        }
        document.querySelectorAll('.cds-copy-btn').forEach(bindCopy);

        function saveInlineField(siteId, field, value, displayEl, inputEl, editBtn) {
            var data = new FormData();
            data.append('action',   'cds_update_site');
            data.append('nonce',    nonce);
            data.append('site_id',  siteId);
            data.append(field,      value);

            editBtn.textContent = '…';

            fetch(ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    editBtn.textContent = '✎';
                    if (!res.success) {
                        alert('Could not save: ' + (res.data.message || 'error'));
                        return;
                    }
                    if (field === 'label') {
                        var domainEl = inputEl.closest('td').querySelector('.cds-site-domain');
                        displayEl.textContent = value || (domainEl ? domainEl.textContent : value);
                    } else {
                        displayEl.textContent = value;
                        var labelDisplay = inputEl.closest('td').querySelector('.cds-site-label.cds-editable-display');
                        var labelInput   = inputEl.closest('td').querySelector('.cds-site-label-input');
                        if (labelInput && labelInput.value.trim() === '' && labelDisplay) {
                            labelDisplay.textContent = value;
                        }
                    }
                    exitEditMode(displayEl, inputEl, editBtn);
                })
                .catch(function() {
                    editBtn.textContent = '✎';
                    alert('Network error saving field.');
                });
        }

        function enterEditMode(displayEl, inputEl, editBtn) {
            displayEl.style.display = 'none';
            inputEl.style.display   = 'inline-block';
            editBtn.title = 'Cancel';
            editBtn.style.color = '#c0392b';
            inputEl.focus();
            inputEl.select();
        }

        function exitEditMode(displayEl, inputEl, editBtn) {
            inputEl.style.display   = 'none';
            displayEl.style.display = '';
            editBtn.title = 'Edit';
            editBtn.style.color = '';
        }

        document.querySelectorAll('.cds-edit-inline-btn').forEach(function(editBtn) {
            editBtn.addEventListener('click', function() {
                var wrap       = editBtn.closest('.cds-editable-wrap');
                var displayEl  = wrap.querySelector('.cds-editable-display');
                var inputEl    = wrap.querySelector('.cds-editable-input');

                if (inputEl.style.display !== 'none') {
                    inputEl.value = displayEl.textContent;
                    exitEditMode(displayEl, inputEl, editBtn);
                    return;
                }
                enterEditMode(displayEl, inputEl, editBtn);
            });
        });

        document.querySelectorAll('.cds-editable-input').forEach(function(inputEl) {
            var wrap      = inputEl.closest('.cds-editable-wrap');
            var displayEl = wrap.querySelector('.cds-editable-display');
            var editBtn   = wrap.querySelector('.cds-edit-inline-btn');
            var siteId    = inputEl.dataset.siteId;
            var field     = inputEl.dataset.field;

            inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var val = inputEl.value.trim();
                    saveInlineField(siteId, field, val, displayEl, inputEl, editBtn);
                }
                if (e.key === 'Escape') {
                    inputEl.value = displayEl.textContent;
                    exitEditMode(displayEl, inputEl, editBtn);
                }
            });

            inputEl.addEventListener('blur', function() {
                setTimeout(function() {
                    if (inputEl.style.display === 'none') return;
                    var val = inputEl.value.trim();
                    if (val !== displayEl.textContent) {
                        saveInlineField(siteId, field, val, displayEl, inputEl, editBtn);
                    } else {
                        exitEditMode(displayEl, inputEl, editBtn);
                    }
                }, 150);
            });
        });

    })();
    </script>
    <?php
}

// ---------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------

function cds_render_settings(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );

    $api_key        = (string) get_option( 'cds_anthropic_api_key', '' );
    $model          = (string) get_option( 'cds_model', 'claude-sonnet-4-6' );
    $rate_input     = (string) get_option( 'cds_rate_input', '3.00' );
    $rate_output    = (string) get_option( 'cds_rate_output', '15.00' );
    $warn_threshold = (string) get_option( 'cds_warn_threshold', '2.00' );
    $contact_phone  = (string) get_option( 'cds_contact_phone', '0210559077' );
    $contact_email  = (string) get_option( 'cds_contact_email', 'impact@impactwebsites.co.nz' );
    $clientdesk_version = (string) get_option( 'cds_clientdesk_version', '' );
    $clientdesk_download_url = (string) get_option( 'cds_clientdesk_download_url', '' );
    $endpoint       = rest_url( 'clientdesk/v1/chat' );
    ?>
    <div class="cds-wrap cds-wrap--settings">
        <div class="cds-header">
            <span class="cds-logo"><span class="cds-logo-icon">≡</span><strong>Master</strong>Desk</span>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=clientdesk-server' ) ); ?>" class="cds-header-link">← Sites</a>
        </div>
        <div class="cds-settings">
            <h2>Settings</h2>
            <?php settings_errors('cds_settings'); ?>
            <form method="post" action="<?php echo esc_url( admin_url('options.php') ); ?>">
                <?php settings_fields('cds_settings'); ?>

                <div class="cds-settings-row">
                    <label>Anthropic API Key</label>
                    <input type="password" name="cds_anthropic_api_key" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off" spellcheck="false" />
                    <p class="cds-settings-desc">Get your key at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a> → API Keys.</p>
                </div>

                <div class="cds-settings-row">
                    <label>Model</label>
                    <input type="text" name="cds_model" value="<?php echo esc_attr( $model ); ?>" />
                    <p class="cds-settings-desc">Current: <code>claude-sonnet-4-6</code>.</p>
                </div>

                <div class="cds-settings-section-label">Token pricing</div>

                <div class="cds-settings-row cds-settings-row--inline">
                    <div>
                        <label>Input token rate ($ per 1M tokens)</label>
                        <input type="number" name="cds_rate_input" value="<?php echo esc_attr( $rate_input ); ?>" step="0.01" min="0" />
                        <p class="cds-settings-desc">Sonnet 4.6 default: $3.00</p>
                    </div>
                    <div>
                        <label>Output token rate ($ per 1M tokens)</label>
                        <input type="number" name="cds_rate_output" value="<?php echo esc_attr( $rate_output ); ?>" step="0.01" min="0" />
                        <p class="cds-settings-desc">Sonnet 4.6 default: $15.00</p>
                    </div>
                </div>

                <div class="cds-settings-section-label">Client warnings</div>

                <div class="cds-settings-row">
                    <label>Low budget warning threshold ($)</label>
                    <input type="number" name="cds_warn_threshold" value="<?php echo esc_attr( $warn_threshold ); ?>" step="0.50" min="0.50" />
                    <p class="cds-settings-desc">Clients see a warning when remaining budget drops to this amount.</p>
                </div>

                <div class="cds-settings-row cds-settings-row--inline">
                    <div>
                        <label>Your phone number</label>
                        <input type="text" name="cds_contact_phone" value="<?php echo esc_attr( $contact_phone ); ?>" placeholder="e.g. 0210559077" />
                    </div>
                    <div>
                        <label>Your email address</label>
                        <input type="text" name="cds_contact_email" value="<?php echo esc_attr( $contact_email ); ?>" placeholder="e.g. impact@impactwebsites.co.nz" />
                    </div>
                </div>

                <div class="cds-settings-row">
                    <label>API Endpoint <span style="font-weight:400;text-transform:none;">(paste into each client site's ClientDesk Settings)</span></label>
                    <input type="text" value="<?php echo esc_attr( $endpoint ); ?>" readonly onclick="this.select()" />
                </div>

                <button type="submit" class="cds-btn cds-btn--primary">Save</button>
            </form>
        </div>
    </div>
    <?php
}

// ---------------------------------------------------------------
// AJAX handlers
// ---------------------------------------------------------------

function cds_ajax_add_site(): void {
    check_ajax_referer( 'cds_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

    global $wpdb;
    $domain  = cds_normalise_domain( sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ) );
    $label   = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
    $dollars = (float) ( $_POST['budget'] ?? 10.00 );
    $cents   = max( 1, (int) round( $dollars * 100 ) );

    if ( '' === $domain ) wp_send_json_error( [ 'message' => 'Domain is required.' ] );

    $key      = cds_generate_key();
    $inserted = $wpdb->insert( $wpdb->prefix . CDS_TABLE, [
        'domain'         => $domain,
        'licence_key'    => $key,
        'label'          => $label,
        'status'         => 'active',
        'monthly_budget' => $cents,
    ] );

    if ( ! $inserted ) wp_send_json_error( [ 'message' => 'Could not insert site. Domain may already exist.' ] );

    wp_send_json_success( [ 'key' => $key ] );
}

function cds_ajax_remove_site(): void {
    check_ajax_referer( 'cds_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

    global $wpdb;
    $id = (int) ( $_POST['site_id'] ?? 0 );
    $wpdb->delete( $wpdb->prefix . CDS_TABLE, [ 'id' => $id ] );
    wp_send_json_success();
}

function cds_ajax_update_site(): void {
    check_ajax_referer( 'cds_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

    global $wpdb;
    $id   = (int) ( $_POST['site_id'] ?? 0 );
    $data = [];

    if ( isset( $_POST['new_status'] ) ) {
        $status = sanitize_text_field( $_POST['new_status'] );
        if ( in_array( $status, [ 'active', 'suspended' ], true ) ) $data['status'] = $status;
    }
    if ( isset( $_POST['monthly_budget'] ) ) {
        $data['monthly_budget'] = max( 1, (int) $_POST['monthly_budget'] );
    }
    if ( isset( $_POST['label'] ) ) {
        $data['label'] = sanitize_text_field( wp_unslash( $_POST['label'] ) );
    }
    if ( isset( $_POST['domain'] ) ) {
        $domain = sanitize_text_field( wp_unslash( $_POST['domain'] ) );
        $domain = preg_replace( '#^https?://#', '', $domain );
        $domain = rtrim( $domain, '/' );
        if ( '' !== $domain ) $data['domain'] = $domain;
    }

    if ( empty( $data ) ) wp_send_json_error( [ 'message' => 'Nothing to update.' ] );

    $wpdb->update( $wpdb->prefix . CDS_TABLE, $data, [ 'id' => $id ] );
    wp_send_json_success();
}
