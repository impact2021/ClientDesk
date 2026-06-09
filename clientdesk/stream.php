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
$page_id           = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
$field             = isset( $_POST['field'] )   ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'body';
$message           = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
$history           = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : [];
$conversation_mode = isset( $_POST['conversation_mode'] ) ? sanitize_key( wp_unslash( $_POST['conversation_mode'] ) ) : 'chat';
$pending_action    = isset( $_POST['pending_action'] ) ? json_decode( wp_unslash( $_POST['pending_action'] ), true ) : [];
$local_api_key = isset( $_POST['local_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['local_api_key'] ) ) ) : '';

if ( ! in_array( $conversation_mode, [ 'chat', 'apply_pending' ], true ) ) {
    $conversation_mode = 'chat';
}
if ( ! is_array( $pending_action ) ) {
    $pending_action = [];
}

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

function cdc_parse_action( string $response ): ?array {
    $trimmed = trim( $response );
    if ( cdc_is_json_response( $trimmed ) ) {
        $data = json_decode( $trimmed, true );
        if ( is_array( $data ) && isset( $data['action'] ) ) return $data;
    }
    $embedded_json = cdc_extract_first_json_object( $trimmed );
    if ( $embedded_json !== null ) {
        $data = json_decode( $embedded_json, true );
        if ( is_array( $data ) && isset( $data['action'] ) ) return $data;
    }
    if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m ) ) {
        $data = json_decode( $m[1], true );
        if ( is_array( $data ) && isset( $data['action'] ) ) return $data;
    }
    return null;
}

function cdc_extract_first_json_object( string $text ): ?string {
    $start = strpos( $text, '{' );
    if ( $start === false ) {
        return null;
    }

    $len       = strlen( $text );
    $depth     = 0;
    $in_string = false;
    $escaped   = false;

    for ( $i = $start; $i < $len; $i++ ) {
        $char = $text[ $i ];

        if ( $in_string ) {
            if ( $escaped ) {
                $escaped = false;
                continue;
            }
            if ( $char === '\\' ) {
                $escaped = true;
                continue;
            }
            if ( $char === '"' ) {
                $in_string = false;
            }
            continue;
        }

        if ( $char === '"' ) {
            $in_string = true;
            continue;
        }
        if ( $char === '{' ) {
            $depth++;
            continue;
        }
        if ( $char === '}' ) {
            $depth--;
            if ( $depth === 0 ) {
                return substr( $text, $start, $i - $start + 1 );
            }
        }
    }

    return null;
}

function cdc_history_entry( string $role, string $content ): ?array {
    $role    = in_array( $role, [ 'user', 'assistant' ], true ) ? $role : '';
    $content = trim( $content );
    if ( '' === $role || '' === $content ) {
        return null;
    }
    return [ 'role' => $role, 'content' => $content ];
}

function cdc_is_json_response( string $text ): bool {
    return substr( ltrim( $text ), 0, 1 ) === '{';
}

function cdc_is_truncated_code_block( string $content, ?array $action = null ): bool {
    if ( $action ) {
        return false;
    }
    $fence_openers = preg_match_all( '/```(?:json|html|css|php|javascript)?/i', $content, $matches );
    return $fence_openers === 1 && substr_count( $content, '```' ) === 1;
}

function cdc_clean_history( array $history, int $max_turns = 8 ): array {
    $clean_history = [];
    foreach ( $history as $turn ) {
        $role    = isset( $turn['role'] ) ? sanitize_text_field( $turn['role'] ) : '';
        $content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
        if ( ! in_array( $role, [ 'user', 'assistant' ], true ) || '' === trim( $content ) ) {
            continue;
        }

        if ( $role === 'assistant' ) {
            $action = cdc_parse_action( $content );
            if ( is_array( $action ) ) {
                $content = (string) ( $action['message'] ?? $action['summary'] ?? '' );
            }
            // Drop obviously truncated assistant code-block replies so they do not poison the next model turn.
            if ( cdc_is_truncated_code_block( $content, $action ) ) {
                continue;
            }
        }

        $entry = cdc_history_entry( $role, $content );
        if ( $entry ) {
            $clean_history[] = $entry;
        }
    }

    if ( count( $clean_history ) > $max_turns ) {
        $clean_history = array_slice( $clean_history, -$max_turns );
    }

    return $clean_history;
}

function cdc_clean_pending_action( array $pending_action ): array {
    $user_message      = isset( $pending_action['user_message'] ) ? sanitize_textarea_field( (string) $pending_action['user_message'] ) : '';
    $assistant_message = isset( $pending_action['assistant_message'] ) ? sanitize_textarea_field( (string) $pending_action['assistant_message'] ) : '';
    if ( '' === $user_message || '' === $assistant_message ) {
        return [];
    }
    return [
        'user_message'      => $user_message,
        'assistant_message' => $assistant_message,
    ];
}

function cdc_is_valid_action_for_mode( ?array $action_data, string $conversation_mode ): bool {
    if ( ! is_array( $action_data ) || empty( $action_data['action'] ) ) {
        return false;
    }

    $action = (string) $action_data['action'];
    if ( $conversation_mode === 'apply_pending' ) {
        return in_array( $action, [ 'patch', 'insert', 'update_meta', 'apply' ], true );
    }

    return in_array( $action, [ 'confirm', 'clarify', 'need_image' ], true );
}

function cdc_retry_instruction_for_mode( string $conversation_mode ): string {
    if ( $conversation_mode === 'apply_pending' ) {
        return 'That was invalid. Return only one JSON action right now. No prose, no code fences, no explanation. Allowed actions: patch, insert, update_meta, apply.';
    }

    return 'That was invalid. Return only one JSON action right now. No prose, no code fences, no explanation. Allowed actions: confirm, clarify, need_image.';
}

function cdc_normalize_chat_action( ?array $action_data ): ?array {
    if ( ! is_array( $action_data ) || empty( $action_data['action'] ) ) {
        return $action_data;
    }

    $action = (string) $action_data['action'];
    if ( in_array( $action, [ 'confirm', 'clarify', 'need_image' ], true ) ) {
        return $action_data;
    }

    if ( ! in_array( $action, [ 'patch', 'insert', 'update_meta', 'apply' ], true ) ) {
        return $action_data;
    }

    if ( $action === 'update_meta' ) {
        $field = (string) ( $action_data['field'] ?? '' );
        $label = $field === 'meta_title' ? 'meta title' : ( $field === 'meta_desc' ? 'meta description' : 'metadata' );
        $value = trim( (string) ( $action_data['value'] ?? '' ) );
        $desc  = $value !== '' ? "update the {$label} to \"{$value}\"" : "update the {$label}";
    } else {
        $summary = trim( (string) ( $action_data['summary'] ?? '' ) );
        $desc    = $summary !== '' ? $summary : 'apply that requested change';
    }

    return [
        'action'  => 'confirm',
        'message' => 'I can ' . rtrim( $desc, ". \t\n\r\0\x0B" ) . '. Shall I go ahead?',
    ];
}

function cdc_history_text_from_action( ?array $action_data, string $full_text ): string {
    if ( ! is_array( $action_data ) ) {
        return trim( $full_text );
    }

    $action = (string) ( $action_data['action'] ?? '' );
    if ( in_array( $action, [ 'confirm', 'clarify', 'need_image' ], true ) ) {
        return trim( (string) ( $action_data['message'] ?? '' ) );
    }
    if ( $action === 'update_meta' ) {
        return trim( (string) ( $action_data['value'] ?? 'Meta updated.' ) );
    }
    if ( in_array( $action, [ 'patch', 'insert', 'apply' ], true ) ) {
        return trim( (string) ( $action_data['summary'] ?? 'Change applied.' ) );
    }

    return trim( $full_text );
}

function cdc_build_pending_action( string $user_message, ?array $action_data, string $full_text ): ?array {
    if ( ! is_array( $action_data ) || ( $action_data['action'] ?? '' ) !== 'confirm' ) {
        return null;
    }

    $assistant_message = trim( (string) ( $action_data['message'] ?? $full_text ) );
    $user_message      = trim( $user_message );
    if ( '' === $assistant_message || '' === $user_message ) {
        return null;
    }

    return [
        'user_message'      => $user_message,
        'assistant_message' => $assistant_message,
    ];
}

function cdc_stream_claude( string $api_key, array $payload, bool $emit_tokens = false ): array {
    $full_text     = '';
    $input_tokens  = 0;
    $output_tokens = 0;
    $last_ping     = time();

    $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: '         . $api_key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function( $ch, $chunk ) use ( &$full_text, &$input_tokens, &$output_tokens, &$last_ping, $emit_tokens ) {
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
                        $trimmed_text = ltrim( $full_text );
                        if ( $emit_tokens && $trimmed_text !== '' && ! cdc_is_json_response( $trimmed_text ) ) {
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

    return [
        'full_text'     => $full_text,
        'input_tokens'  => $input_tokens,
        'output_tokens' => $output_tokens,
        'curl_error'    => $curl_error,
        'curl_errno'    => $curl_errno,
    ];
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

if ( ! $local_api_key && ( ! $licence_key || ! $endpoint ) ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo "event: error\ndata: {\"message\":\"ClientDesk is not configured.\"}\n\n";
    exit;
}

if ( $local_api_key !== '' ) {
    $session_token = '';
    $api_key       = $local_api_key;
    $model         = trim( (string) get_option( 'cds_model', 'claude-sonnet-4-6' ) );
    $spent_fmt     = null;
    $budget_fmt    = null;
    $remaining_fmt = null;
    $show_warning  = false;
    $contact_phone = '';
    $contact_email = '';
} else {
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

    $remote_latest_version = trim( (string) ( $token_body['clientdesk_latest_version'] ?? ( $token_body['clientdesk_version'] ?? '' ) ) );
    $remote_zip_url        = trim( (string) ( $token_body['clientdesk_zip_url'] ?? ( $token_body['clientdesk_download_url'] ?? '' ) ) );
    set_transient( 'cdc_clientdesk_latest_version', $remote_latest_version, 15 * MINUTE_IN_SECONDS );
    set_transient( 'cdc_clientdesk_zip_url', $remote_zip_url, 15 * MINUTE_IN_SECONDS );
}

cdc_slog( 'Token received — streaming directly to Anthropic' );

// Step 2: Build prompt and stream
$cleaned_html   = cdc_clean_for_claude( $page_html );
$is_blank       = '' === trim( $cleaned_html );
$clean_history  = cdc_clean_history( is_array( $history ) ? $history : [] );
$pending_action = cdc_clean_pending_action( $pending_action );

$font_context = '';
if ( $font_heading || $font_body ) {
    $font_context = "\n## Site typography\n";
    if ( $font_heading ) $font_context .= "- Heading font (H1–H6): {$font_heading}\n";
    if ( $font_body )    $font_context .= "- Body font (p, li, general text): {$font_body}\n";
    $font_context .= "Always use these font names in any CSS you write — never substitute or invent alternatives.\n";
}

$need_image_rule = 'Use need_image only when the user explicitly asks to upload, add, swap, or replace an image file. Do not use need_image for CSS, alignment, cropping, spacing, or layout fixes around existing images.';
$skip_apply = false;

if ( $conversation_mode === 'apply_pending' ) {
    if ( empty( $pending_action ) ) {
        $action_data = [
            'action'  => 'clarify',
            'message' => 'I lost the draft for that change. Please describe it again in one sentence.',
        ];
        $full_text        = $action_data['message'];
        $input_tokens     = 0;
        $output_tokens    = 0;
        $pending_payload  = null;
        $report_action    = 'chat';
        $report_summary   = $action_data['message'];
        $apply_result     = null;
        $clean_history[]  = [ 'role' => 'assistant', 'content' => $action_data['message'] ];
        $skip_apply       = true;
    }

    if ( ! $skip_apply && $is_blank ) {
        $system = <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners build website content from scratch.

The user already approved a proposed new build for "{$page_title}".
This area currently has no HTML.
{$font_context}
Return ONLY one JSON object and nothing else.

Allowed action:
{"action":"apply","html":"[COMPLETE HTML]","summary":"[one plain English sentence]"}

Rules:
- Do not ask follow-up questions
- Do not reconfirm
- Do not wrap the JSON in code fences
- Write complete semantic HTML with any needed inline CSS or a <style> block
- Make it mobile responsive
PROMPT;
    } elseif ( ! $skip_apply ) {
        $system = <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners update website content.

The user already approved a previously confirmed change for "{$page_title}".
Apply that approved change now against the current HTML below.
{$font_context}
Return ONLY one JSON object and nothing else.

Allowed actions:
{"action":"patch","patches":[{"find":"[exact verbatim substring from the HTML]","replace":"[replacement]"}],"summary":"[one plain English sentence]"}
{"action":"insert","position":"after","anchor":"[exact unique string already in the HTML]","html":"[new HTML]","summary":"[one plain English sentence]"}
{"action":"update_meta","field":"meta_title","value":"the new title"}
{"action":"update_meta","field":"meta_desc","value":"the new description"}

Rules:
- Do not ask follow-up questions
- Do not reconfirm
- Do not explain your work
- Do not wrap the JSON in code fences
- Never return the full HTML
- For patch actions, "find" must match the current HTML exactly
- Preserve classes, IDs, and structure unless the approved change requires it

## Current page HTML
```html
{$cleaned_html}
```
PROMPT;
    }

    if ( ! $skip_apply ) {
        $messages = [];
        $messages[] = [ 'role' => 'user', 'content' => $pending_action['user_message'] ];
        $messages[] = [ 'role' => 'assistant', 'content' => $pending_action['assistant_message'] ];
        $messages[] = [ 'role' => 'user', 'content' => 'The user approved that exact change. Apply it now and return only the JSON action.' ];
    }
} elseif ( $is_blank ) {
    $system = <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners build website content.

## Your context
The user is building: "{$page_title}"
This area currently has no HTML.
{$font_context}
Return ONLY one JSON object and nothing else.

Allowed actions:
{"action":"confirm","message":"[brief bullet-style outline in plain English ending with: Shall I go ahead and build this?]"}
{"action":"clarify","message":"[one short plain-English question asking for the missing detail]"}
{"action":"need_image","message":"[plain English request to choose an image only if the user asked to add or replace an actual image asset]"}

Rules:
- Never output HTML in this mode
- Never output CSS examples or code fences
- Keep confirm and clarify messages brief
- {$need_image_rule}
PROMPT;
    $messages   = $clean_history;
    $messages[] = [ 'role' => 'user', 'content' => $message ];
} else {
    $system = <<<PROMPT
You are ClientDesk, an AI assistant that helps website owners update website content.

## Your context
The user is editing: "{$page_title}"
The current full HTML is provided below — read it carefully.
{$font_context}
Return ONLY one JSON object and nothing else.

Allowed actions in this mode:
{"action":"confirm","message":"[brief plain-English confirmation of the exact change you found and what you will change it to, ending with: Shall I go ahead?]"}
{"action":"clarify","message":"[one short plain-English question asking for the missing detail]"}
{"action":"need_image","message":"[plain English request to choose an image only if the user asked to add or replace an actual image asset]"}

Rules:
- Never apply the change in this mode
- Never output patch, insert, update_meta, or full HTML in this mode
- Never include code fences, CSS snippets, or HTML examples in confirm or clarify messages
- Quote the current text only when the user is changing text
- Keep confirm messages brief and specific
- {$need_image_rule}

## Current page HTML
```html
{$cleaned_html}
```
PROMPT;
    $messages   = $clean_history;
    $messages[] = [ 'role' => 'user', 'content' => $message ];
}

// SSE headers
header( 'Content-Type: text/event-stream' );
header( 'Cache-Control: no-cache' );
header( 'X-Accel-Buffering: no' );
while ( ob_get_level() > 0 ) ob_end_clean();

if ( ! $skip_apply ) {
    $payload = [
        'model'      => $model,
        'max_tokens' => 4000,
        'stream'     => true,
        'system'     => $system,
        'messages'   => $messages,
    ];

    $result        = cdc_stream_claude( $api_key, $payload, false );
    $full_text     = $result['full_text'];
    $input_tokens  = $result['input_tokens'];
    $output_tokens = $result['output_tokens'];
    $curl_error    = $result['curl_error'];
    $curl_errno    = $result['curl_errno'];

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

    $action_data = cdc_parse_action( $full_text );
    if ( $conversation_mode === 'chat' ) {
        $action_data = cdc_normalize_chat_action( $action_data );
    }

    if ( ! cdc_is_valid_action_for_mode( $action_data, $conversation_mode ) ) {
        $retry_payload               = $payload;
        $retry_payload['messages'][] = [ 'role' => 'assistant', 'content' => $full_text ];
        $retry_payload['messages'][] = [ 'role' => 'user', 'content' => cdc_retry_instruction_for_mode( $conversation_mode ) ];

        $retry_result = cdc_stream_claude( $api_key, $retry_payload, false );
        $input_tokens += $retry_result['input_tokens'];
        $output_tokens += $retry_result['output_tokens'];

        if ( ! $retry_result['curl_errno'] && '' !== $retry_result['full_text'] ) {
            $full_text    = $retry_result['full_text'];
            $action_data  = cdc_parse_action( $full_text );
            if ( $conversation_mode === 'chat' ) {
                $action_data = cdc_normalize_chat_action( $action_data );
            }
            $curl_error   = $retry_result['curl_error'];
            $curl_errno   = $retry_result['curl_errno'];
        } else {
            $curl_error = $retry_result['curl_error'];
            $curl_errno = $retry_result['curl_errno'];
        }
    }

    if ( $curl_errno ) {
        cdc_slog( 'Anthropic retry cURL error ' . $curl_errno . ': ' . $curl_error );
        echo 'event: error' . "\n";
        echo 'data: ' . json_encode( [ 'message' => 'Could not reach Anthropic: ' . $curl_error ] ) . "\n\n";
        flush();
        exit;
    }

    if ( ! cdc_is_valid_action_for_mode( $action_data, $conversation_mode ) ) {
        if ( $conversation_mode === 'apply_pending' ) {
            $action_data = [
                'action'  => 'clarify',
                'message' => 'I lost the draft for that change. Please describe it again in one sentence and I’ll redo it cleanly.',
            ];
        } else {
            $action_data = [
                'action'  => 'clarify',
                'message' => 'I could not parse that response. Please repeat your requested change in one sentence.',
            ];
        }
        $full_text = $action_data['message'];
    }

    cdc_slog( 'Stream complete — tokens in=' . $input_tokens . ' out=' . $output_tokens . ' mode=' . $conversation_mode );

    $updated_history = $clean_history;
    if ( $conversation_mode !== 'apply_pending' ) {
        $updated_history[] = [ 'role' => 'user', 'content' => $message ];
    }

    $assistant_history_text = cdc_history_text_from_action( $action_data, $full_text );
    if ( '' !== $assistant_history_text ) {
        $updated_history[] = [ 'role' => 'assistant', 'content' => $assistant_history_text ];
    }

    $clean_history   = cdc_clean_history( $updated_history );
    $pending_payload = cdc_build_pending_action( $message, $action_data, $full_text );
}

$report_action  = 'chat';
$report_summary = substr( $assistant_history_text !== '' ? $assistant_history_text : $full_text, 0, 80 );
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
if ( $session_token !== '' && $endpoint !== '' ) {
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
}

// Step 6: Send done event
echo 'event: done' . "\n";
echo 'data: ' . json_encode( [
    'success'       => true,
    'raw'           => $full_text,
    'action'        => $action_data,
    'pending_action'=> $pending_payload,
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
