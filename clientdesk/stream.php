<?php
/**
 * ClientDesk — Direct streaming endpoint (v2.1.0)
 * Gets a short-lived token from MasterDesk, streams directly from Anthropic.
 * Uses find/replace patching — never sends full HTML back.
 */

register_shutdown_function( function() {
    $err = error_get_last();
    if ( $err && in_array( $err['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/event-stream' );
            header( 'Cache-Control: no-cache' );
        }
        echo 'event: error' . "\n";
        echo 'data: ' . json_encode( [ 'message' => 'Fatal error: ' . $err['message'] ] ) . "\n\n";
        flush();
    }
} );

function cdc_find_wp_load() {
    $dir = __DIR__;
    for ( $i = 0; $i < 10; $i++ ) {
        $parent = dirname( $dir );
        if ( $parent === $dir ) break;
        $dir = $parent;
        if ( file_exists( $dir . '/wp-load.php' ) ) return $dir . '/wp-load.php';
    }
    return null;
}

$wp_load = cdc_find_wp_load();
if ( ! $wp_load ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo 'event: error' . "\n";
    echo 'data: ' . json_encode( [ 'message' => 'WordPress not found.' ] ) . "\n\n";
    exit;
}
require_once $wp_load;

function cdc_slog( $msg ) {
    if ( ! get_option( 'cdc_debug_mode' ) ) return;
    $log = dirname( __FILE__ ) . '/cdc-debug.log';
    file_put_contents( $log, '[' . gmdate( 'Y-m-d H:i:s' ) . '] [stream.php] ' . $msg . "\n", FILE_APPEND | LOCK_EX );
}
cdc_slog( 'stream.php loaded — POST page_id=' . ( $_POST['page_id'] ?? 'unset' ) );

// Auth
$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
$user_id = wp_verify_nonce( $nonce, 'cdc_nonce' );
if ( ! $user_id || ! user_can( (int) $user_id, 'edit_pages' ) ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo "event: error\ndata: {\"message\":\"Permission denied.\"}\n\n";
    exit;
}

// Params
$page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
$field   = isset( $_POST['field'] )   ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'body';
$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
$history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : [];

if ( ! $page_id || ! $message ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo "event: error\ndata: {\"message\":\"Missing parameters.\"}\n\n";
    exit;
}

// Get page content
function cdc_stream_get_content( $page_id, $field ) {
    if ( $page_id === -1 ) return (string) get_option( 'iw_global_header', '' );
    if ( $page_id === -2 ) return (string) get_option( 'iw_global_footer', '' );
    $post = get_post( $page_id );
    if ( ! $post ) return new WP_Error( 'not_found', 'Page not found.' );
    $key_map = [ 'header' => '_iw_header', 'footer' => '_iw_footer', 'scripts' => '_iw_scripts' ];
    $key     = isset( $key_map[ $field ] ) ? $key_map[ $field ] : '_iw_body';
    $content = (string) get_post_meta( $page_id, $key, true );
    if ( '' !== trim( $content ) ) return $content;
    if ( preg_match( '/\[et_pb_code[^\]]*\](.*?)\[\/et_pb_code\]/s', $post->post_content, $m ) ) return trim( $m[1] );
    return trim( $post->post_content );
}

function cdc_stream_page_title( $page_id ) {
    if ( $page_id === -1 ) return 'Site Header';
    if ( $page_id === -2 ) return 'Site Footer';
    $p = get_post( $page_id );
    return $p ? $p->post_title : 'this page';
}

// Minimal cleanup — only remove script blocks and comments, never styles
function cdc_clean_for_claude( $html ) {
    $html = preg_replace( '/<script[^>]*>[\s\S]*?<\/script>/i', '', $html );
    $html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );
    return trim( $html );
}

// Apply patch action — find/replace pairs in the stored HTML
function cdc_apply_patches( string $html, array $patches ): array {
    $applied = 0;
    $errors  = [];
    foreach ( $patches as $i => $patch ) {
        $find    = $patch['find']    ?? '';
        $replace = $patch['replace'] ?? '';
        if ( '' === $find ) { $errors[] = "Patch {$i}: empty find string."; continue; }

        // Try exact match first
        if ( str_contains( $html, $find ) ) {
            $count = substr_count( $html, $find );
            if ( $count > 1 ) {
                $html = substr_replace( $html, $replace, strpos( $html, $find ), strlen( $find ) );
            } else {
                $html = str_replace( $find, $replace, $html );
            }
            $applied++;
            continue;
        }

        // Whitespace-flexible fallback: normalize whitespace in find string and try regex match
        // Handles cases where Claude gets indentation slightly different from stored HTML
        $find_norm = preg_replace( '/\s+/', ' ', trim( $find ) );
        $pattern   = preg_replace( '/\ /', '\\s+', preg_quote( $find_norm, '/' ) );
        if ( preg_match( '/' . $pattern . '/s', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
            $matched = $matches[0][0];
            $offset  = $matches[0][1];
            $html    = substr_replace( $html, $replace, $offset, strlen( $matched ) );
            $applied++;
            continue;
        }

        $errors[] = "Patch {$i}: could not find the text to replace. Please try describing the change differently.";
    }
    return [ 'html' => $html, 'applied' => $applied, 'errors' => $errors ];
}

// Apply insert action — insert new HTML after/before an anchor string
function cdc_apply_insert( string $html, string $position, string $anchor, string $new_html ): array {
    if ( '' === $anchor ) return [ 'html' => $html, 'error' => 'No anchor provided for insert.' ];

    // Try exact match first
    if ( str_contains( $html, $anchor ) ) {
        if ( $position === 'before' ) {
            $html = str_replace( $anchor, $new_html . $anchor, $html );
        } else {
            $html = str_replace( $anchor, $anchor . $new_html, $html );
        }
        return [ 'html' => $html, 'error' => null ];
    }

    // Whitespace-flexible fallback: normalize whitespace and try regex match
    $anchor_norm = preg_replace( '/\s+/', ' ', trim( $anchor ) );
    $pattern     = preg_replace( '/ /', '\\s+', preg_quote( $anchor_norm, '/' ) );
    if ( preg_match( '/' . $pattern . '/s', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
        $matched = $matches[0][0];
        $offset  = $matches[0][1];
        if ( $position === 'before' ) {
            $html = substr_replace( $html, $new_html . $matched, $offset, strlen( $matched ) );
        } else {
            $html = substr_replace( $html, $matched . $new_html, $offset, strlen( $matched ) );
        }
        return [ 'html' => $html, 'error' => null ];
    }

    return [ 'html' => $html, 'error' => 'I could not find the exact place to add that section. Where would you like it: at the top, at the bottom, or after a specific heading?' ];
}

$page_html = cdc_stream_get_content( $page_id, $field );
if ( is_wp_error( $page_html ) ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo 'event: error' . "\n";
    echo 'data: ' . json_encode( [ 'message' => $page_html->get_error_message() ] ) . "\n\n";
    exit;
}

$page_title   = cdc_stream_page_title( $page_id );
$licence_key  = trim( (string) get_option( 'cdc_licence_key', '' ) );
$endpoint     = trim( (string) get_option( 'cdc_server_endpoint', '' ) );
$domain       = trim( (string) get_option( 'cdc_domain', '' ) );
$font_heading = trim( (string) get_option( 'cdc_font_heading', '' ) );
$font_body    = trim( (string) get_option( 'cdc_font_body', '' ) );

if ( ! $domain ) $domain = parse_url( home_url(), PHP_URL_HOST ) ?: '';

if ( ! $licence_key || ! $endpoint ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo "event: error\ndata: {\"message\":\"ClientDesk is not configured.\"}\n\n";
    exit;
}

// Step 1: Get token from MasterDesk
$token_endpoint = preg_replace( '/\/chat$/', '/token', $endpoint );
cdc_slog( 'Requesting token from: ' . $token_endpoint );

$token_response = wp_remote_post( $token_endpoint, [
    'headers' => [ 'Content-Type' => 'application/json' ],
    'body'    => wp_json_encode( [ 'licence_key' => $licence_key, 'domain' => $domain ] ),
    'timeout' => 15,
] );

if ( is_wp_error( $token_response ) ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo 'event: error' . "\n";
    echo 'data: ' . json_encode( [ 'message' => 'Could not reach ClientDesk server: ' . $token_response->get_error_message() ] ) . "\n\n";
    exit;
}

$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
if ( ! is_array( $token_body ) || ! ( $token_body['success'] ?? false ) ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo 'event: error' . "\n";
    echo 'data: ' . json_encode( [ 'message' => $token_body['error'] ?? 'Could not get access token.' ] ) . "\n\n";
    exit;
}

$session_token = $token_body['token'];
$api_key       = $token_body['api_key'];
$model         = $token_body['model'];
$spent_fmt     = $token_body['spent_fmt'] ?? null;
$budget_fmt    = $token_body['budget_fmt'] ?? null;
$remaining_fmt = $token_body['remaining_fmt'] ?? null;
$show_warning  = $token_body['show_warning'] ?? false;
$contact_phone = $token_body['contact_phone'] ?? '';
$contact_email = $token_body['contact_email'] ?? '';

cdc_slog( 'Token received — streaming directly to Anthropic' );

// Step 2: Build prompt and stream
$cleaned_html = cdc_clean_for_claude( $page_html );
$is_blank     = '' === trim( $cleaned_html );

$font_context = '';
if ( $font_heading || $font_body ) {
    $font_context = "\n## Site typography\n";
    if ( $font_heading ) $font_context .= "- Heading font (H1–H6): {$font_heading}\n";
    if ( $font_body )    $font_context .= "- Body font (p, li, general text): {$font_body}\n";
    $font_context .= "Always use these font names in any CSS you write — never substitute or invent alternatives.\n";
}

if ( $is_blank ) {
    $system = <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners build and update their website content.

## Your context
The user is building: "{$page_title}"
This area currently has no HTML — you will be creating it from scratch.
{$font_context}
## Your conversation flow

**Step 1 — Outline and confirm**
When the user describes what they want, respond with a brief bullet-point outline of what you'll build — structure, key elements, assumptions. Max 6 bullets. Ask: "Shall I go ahead and build this?"

Do NOT write any HTML yet. Just the outline in plain text.

If the request involves an image and no URL has been provided:
{"action":"need_image","message":"[Plain English message asking them to select an image]"}

When the user confirms, output the complete HTML wrapped exactly like this — nothing before or after:
{"action":"apply","html":"[COMPLETE HTML]","summary":"[One plain English sentence — no technical terms]"}

## Rules
- Write clean semantic HTML with inline CSS or a <style> block
- Mobile responsive — include media queries
- Use CSS custom properties for colours
- No lorem ipsum — use realistic content based on context
- Never apply without confirmation
PROMPT;
} else {
    $system = <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners update their website content.

## Your context
The user is editing: "{$page_title}"
The current full HTML is provided below — read it carefully.
{$font_context}
## Your conversation flow

**Step 1 — Understand and confirm**
When the user describes a change, confirm exactly what you found and what you will change it to. Quote the current text if changing text. Be specific and brief. Ask for approval.

If an image swap/add is needed and no URL provided:
{"action":"need_image","message":"[Plain English — no technical terms]"}

If you need clarification, ask in plain text.

**Step 2 — Apply on approval**
When the user confirms, respond with ONLY a JSON action — never return the full HTML.

For changes to EXISTING content (text edits, colour changes, attribute updates, removing elements):
{"action":"patch","patches":[{"find":"[exact verbatim substring from the HTML]","replace":"[replacement]"},{"find":"...","replace":"..."}],"summary":"[one plain English sentence]"}

Rules for patches:
- "find" MUST be copied verbatim from the HTML — exact characters, exact whitespace
- "find" must be unique enough to match only one place — include surrounding tags if needed
- Multiple patches allowed in one action
- To remove something: set "replace" to ""

For ADDING NEW content that doesn't exist yet:
{"action":"insert","position":"after","anchor":"[exact unique string already in the HTML]","html":"[new HTML]","summary":"[one plain English sentence]"}

For meta title/description — confirm value first, then:
{"action":"update_meta","field":"meta_title","value":"the new title"}
{"action":"update_meta","field":"meta_desc","value":"the new description"}

**Step 3 — Follow up**
After a change, offer to help further if useful.

## Rules
- Never apply without user confirmation
- NEVER return the full HTML — only patch/insert/update_meta actions
- Preserve all classes, IDs, structure unless asked to change them
- Keep confirmation messages brief

## Current page HTML
```html
{$cleaned_html}
```
PROMPT;
}

// Build history
$clean_history = [];
if ( is_array( $history ) ) {
    foreach ( $history as $turn ) {
        $role    = isset( $turn['role'] )    ? sanitize_text_field( $turn['role'] ) : '';
        $content = isset( $turn['content'] ) ? (string) $turn['content']            : '';
        if ( in_array( $role, [ 'user', 'assistant' ], true ) && '' !== $content ) {
            $clean_history[] = [ 'role' => $role, 'content' => $content ];
        }
    }
}
$clean_history[] = [ 'role' => 'user', 'content' => $message ];

// SSE headers
header( 'Content-Type: text/event-stream' );
header( 'Cache-Control: no-cache' );
header( 'X-Accel-Buffering: no' );
while ( ob_get_level() > 0 ) ob_end_clean();

$post_body = json_encode( [
    'model'      => $model,
    'max_tokens' => 4000,
    'stream'     => true,
    'system'     => $system,
    'messages'   => $clean_history,
] );

$full_text     = '';
$input_tokens  = 0;
$output_tokens = 0;
$last_ping     = time();

$ch = curl_init( 'https://api.anthropic.com/v1/messages' );
curl_setopt_array( $ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_body,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: '         . $api_key,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION  => function( $ch, $chunk ) use ( &$full_text, &$input_tokens, &$output_tokens, &$last_ping ) {
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
                        echo 'event: token' . "\n";
                        echo 'data: ' . json_encode( [ 'text' => $token ] ) . "\n\n";
                        if ( ob_get_level() > 0 ) ob_flush();
                        flush();
                    } else {
                        $now = time();
                        if ( $now - $last_ping >= 8 ) {
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

curl_exec( $ch );
$curl_error = curl_error( $ch );
$curl_errno = curl_errno( $ch );
curl_close( $ch );

if ( $curl_errno ) {
    cdc_slog( 'Anthropic cURL error ' . $curl_errno . ': ' . $curl_error );
    echo 'event: error' . "\n";
    echo 'data: ' . json_encode( [ 'message' => 'Could not reach Anthropic: ' . $curl_error ] ) . "\n\n";
    flush();
    exit;
}

if ( '' === $full_text ) {
    echo 'event: error' . "\n";
    echo 'data: ' . json_encode( [ 'message' => 'Claude returned an empty response.' ] ) . "\n\n";
    flush();
    exit;
}

cdc_slog( 'Stream complete — tokens in=' . $input_tokens . ' out=' . $output_tokens );

// Step 3: Parse action
function cdc_parse_action( string $response ): ?array {
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

$action_data     = cdc_parse_action( $full_text );
$clean_history[] = [ 'role' => 'assistant', 'content' => $full_text ];

$report_action  = 'chat';
$report_summary = substr( $full_text, 0, 80 );
$apply_result   = null;

// Step 4: Apply patch/insert actions server-side against the stored HTML
if ( $action_data ) {
    $action_type = $action_data['action'] ?? '';

    if ( $action_type === 'patch' ) {
        $patches = $action_data['patches'] ?? [];
        $result  = cdc_apply_patches( $page_html, $patches );
        if ( $result['applied'] > 0 ) {
            $apply_result   = $result['html'];
            $report_action  = 'apply';
            $report_summary = $action_data['summary'] ?? '';
        }
        if ( ! empty( $result['errors'] ) ) {
            $action_data['patch_errors'] = $result['errors'];
        }

    } elseif ( $action_type === 'insert' ) {
        $result = cdc_apply_insert(
            $page_html,
            $action_data['position'] ?? 'after',
            $action_data['anchor']   ?? '',
            $action_data['html']     ?? ''
        );
        if ( ! $result['error'] ) {
            $apply_result   = $result['html'];
            $report_action  = 'apply';
            $report_summary = $action_data['summary'] ?? '';
        } else {
            $action_data['insert_error'] = $result['error'];
        }

    } elseif ( $action_type === 'apply' ) {
        // Blank page — full HTML returned, use as-is
        $apply_result   = $action_data['html'] ?? '';
        $report_action  = 'apply';
        $report_summary = $action_data['summary'] ?? '';

    } elseif ( $action_type === 'update_meta' || $action_type === 'need_image' ) {
        // Handled client-side
    }
}

// If we have HTML to save, write it now and add page_url to action_data
if ( $apply_result !== null && $apply_result !== '' ) {
    // Save to post meta
    $page_id_save = $page_id;
    $field_save   = $field;

    function cdc_write_content( $page_id, $field, $html ) {
        if ( $page_id === -1 ) { update_option( 'iw_global_header', $html ); return true; }
        if ( $page_id === -2 ) { update_option( 'iw_global_footer', $html ); return true; }
        $post = get_post( $page_id );
        if ( ! $post ) return false;
        $key_map = [ 'header' => '_iw_header', 'footer' => '_iw_footer', 'scripts' => '_iw_scripts' ];
        $key = isset( $key_map[ $field ] ) ? $key_map[ $field ] : '_iw_body';
        // Snapshot
        $snaps = (array) get_post_meta( $page_id, '_cdc_snapshots', true );
        $snaps[] = [ 'ts' => time(), 'key' => $key, 'content' => get_post_meta( $page_id, $key, true ) ];
        if ( count( $snaps ) > 30 ) $snaps = array_slice( $snaps, -30 );
        update_post_meta( $page_id, '_cdc_snapshots', $snaps );
        update_post_meta( $page_id, $key, $html );
        return true;
    }

    cdc_write_content( $page_id, $field, $apply_result );
    $action_data['page_url'] = get_permalink( $page_id ) ?: '';
}

// Step 5: Report usage to MasterDesk
$report_endpoint = preg_replace( '/\/chat$/', '/report', $endpoint );
$report_ch = curl_init( $report_endpoint );
curl_setopt_array( $report_ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => wp_json_encode( [
        'token'         => $session_token,
        'input_tokens'  => $input_tokens,
        'output_tokens' => $output_tokens,
        'action'        => $report_action,
        'summary'       => $report_summary,
    ] ),
    CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOSIGNAL       => true,
] );
curl_exec( $report_ch );
curl_close( $report_ch );

// Step 6: Send done event
echo 'event: done' . "\n";
echo 'data: ' . json_encode( [
    'success'       => true,
    'raw'           => $full_text,
    'action'        => $action_data,
    'history'       => $clean_history,
    'spent_fmt'     => $spent_fmt,
    'budget_fmt'    => $budget_fmt,
    'remaining_fmt' => $remaining_fmt,
    'show_warning'  => $show_warning,
    'contact_phone' => $contact_phone,
    'contact_email' => $contact_email,
] ) . "\n\n";
flush();

cdc_slog( 'Done' );
exit;
