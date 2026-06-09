<?php
/**
 * ClientDesk — Sitewide change endpoint (v1.0.0)
 *
 * 2-step AI matching:
 *   Step 1 (cheap) — sends page titles + short previews to Claude to identify
 *                    which pages are affected by the instruction.
 *   Step 2          — sends full HTML of each matched page to Claude and applies
 *                    the returned patches.
 *
 * Streams Server-Sent Events:
 *   event: sitewide_status  — progress messages
 *   event: done             — final result (success + changed_pages array)
 *   event: error            — fatal error
 */

register_shutdown_function( function () {
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

// ---------------------------------------------------------------
// Bootstrap WordPress
// ---------------------------------------------------------------

function cdw_find_wp_load(): ?string {
    $dir = __DIR__;
    for ( $i = 0; $i < 10; $i++ ) {
        $parent = dirname( $dir );
        if ( $parent === $dir ) break;
        $dir = $parent;
        if ( file_exists( $dir . '/wp-load.php' ) ) return $dir . '/wp-load.php';
    }
    return null;
}

$wp_load = cdw_find_wp_load();
if ( ! $wp_load ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo "event: error\ndata: {\"message\":\"WordPress not found.\"}\n\n";
    exit;
}
require_once $wp_load;

function cdw_log( string $msg ): void {
    if ( ! get_option( 'cdc_debug_mode' ) ) return;
    $log = dirname( __FILE__ ) . '/cdc-debug.log';
    file_put_contents( $log, '[' . gmdate( 'Y-m-d H:i:s' ) . '] [sitewide.php] ' . $msg . "\n", FILE_APPEND | LOCK_EX );
}

// ---------------------------------------------------------------
// Auth
// ---------------------------------------------------------------

$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
$user_id = wp_verify_nonce( $nonce, 'cdc_nonce' );
if ( ! $user_id || ! user_can( (int) $user_id, 'edit_pages' ) ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo "event: error\ndata: {\"message\":\"Permission denied.\"}\n\n";
    exit;
}

// ---------------------------------------------------------------
// Params
// ---------------------------------------------------------------

$message       = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
$local_api_key = isset( $_POST['local_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['local_api_key'] ) ) ) : '';

if ( '' === $message ) {
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    echo "event: error\ndata: {\"message\":\"Missing message.\"}\n\n";
    exit;
}

// ---------------------------------------------------------------
// Credentials
// ---------------------------------------------------------------

$licence_key = trim( (string) get_option( 'cdc_licence_key', '' ) );
$endpoint    = trim( (string) get_option( 'cdc_server_endpoint', '' ) );
$domain      = trim( (string) get_option( 'cdc_domain', '' ) );
if ( ! $domain ) $domain = parse_url( home_url(), PHP_URL_HOST ) ?: '';

$session_token = '';
$spent_fmt = $budget_fmt = $remaining_fmt = null;
$show_warning  = false;
$contact_phone = $contact_email = '';

if ( $local_api_key !== '' ) {
    $api_key = $local_api_key;
    $model   = trim( (string) get_option( 'cds_model', 'claude-sonnet-4-6' ) );
} else {
    if ( ! $licence_key || ! $endpoint ) {
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        echo "event: error\ndata: {\"message\":\"ClientDesk is not configured.\"}\n\n";
        exit;
    }

    $token_endpoint = preg_replace( '/\/chat$/', '/token', $endpoint );
    cdw_log( 'Requesting token from: ' . $token_endpoint );

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

// ---------------------------------------------------------------
// SSE helpers
// ---------------------------------------------------------------

header( 'Content-Type: text/event-stream' );
header( 'Cache-Control: no-cache' );
header( 'X-Accel-Buffering: no' );
while ( ob_get_level() > 0 ) ob_end_clean();

function cdw_send( string $event, array $data ): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode( $data ) . "\n\n";
    flush();
}

// ---------------------------------------------------------------
// Claude helper — non-streaming single call
// ---------------------------------------------------------------

function cdw_call_claude( string $api_key, string $model, array $messages, string $system, int $max_tokens = 8000 ): array {
    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => $messages,
    ];

    $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode( $payload ),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ] );

    $raw   = curl_exec( $ch );
    $errno = curl_errno( $ch );
    $error = curl_error( $ch );
    curl_close( $ch );

    if ( $errno ) {
        return [ 'error' => 'cURL error: ' . $error, 'text' => '', 'input_tokens' => 0, 'output_tokens' => 0 ];
    }

    $resp = json_decode( $raw, true );
    if ( ! is_array( $resp ) ) {
        return [ 'error' => 'Invalid API response.', 'text' => '', 'input_tokens' => 0, 'output_tokens' => 0 ];
    }
    if ( isset( $resp['error'] ) ) {
        return [ 'error' => $resp['error']['message'] ?? 'API error.', 'text' => '', 'input_tokens' => 0, 'output_tokens' => 0 ];
    }

    $text = trim( (string) ( $resp['content'][0]['text'] ?? '' ) );
    return [
        'error'         => null,
        'text'          => $text,
        'input_tokens'  => (int) ( $resp['usage']['input_tokens']  ?? 0 ),
        'output_tokens' => (int) ( $resp['usage']['output_tokens'] ?? 0 ),
    ];
}

// Strip a JSON code fence if Claude wrapped it
function cdw_unwrap_json( string $text ): string {
    $text = trim( $text );
    $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
    $text = preg_replace( '/\s*```$/', '', $text );
    return trim( $text );
}

// ---------------------------------------------------------------
// Build page index
// ---------------------------------------------------------------

$page_index = [];

// Special: site header
$header_html = (string) get_option( 'iw_global_header', '' );
if ( '' !== trim( $header_html ) ) {
    $page_index[] = [
        'id'      => -1,
        'title'   => 'Site Header',
        'slug'    => '(site header)',
        'raw'     => $header_html,
        'preview' => substr( wp_strip_all_tags( $header_html ), 0, 300 ),
    ];
}

// Special: site footer
$footer_html = (string) get_option( 'iw_global_footer', '' );
if ( '' !== trim( $footer_html ) ) {
    $page_index[] = [
        'id'      => -2,
        'title'   => 'Site Footer',
        'slug'    => '(site footer)',
        'raw'     => $footer_html,
        'preview' => substr( wp_strip_all_tags( $footer_html ), 0, 300 ),
    ];
}

// All published pages
$all_pages = get_posts( [
    'post_type'      => 'page',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );

foreach ( $all_pages as $p ) {
    $raw = (string) get_post_meta( $p->ID, '_iw_body', true );
    if ( '' === trim( $raw ) ) {
        if ( preg_match( '/\[et_pb_code[^\]]*\](.*?)\[\/et_pb_code\]/s', $p->post_content, $m ) ) {
            $raw = trim( $m[1] );
        } else {
            $raw = trim( $p->post_content );
        }
    }
    $page_index[] = [
        'id'      => $p->ID,
        'title'   => $p->post_title,
        'slug'    => '/' . $p->post_name . '/',
        'raw'     => $raw,
        'preview' => substr( wp_strip_all_tags( $raw ), 0, 400 ),
    ];
}

if ( empty( $page_index ) ) {
    cdw_send( 'error', [ 'message' => 'No pages found on this site.' ] );
    exit;
}

// ---------------------------------------------------------------
// Step 1: Discovery — identify which pages are affected (cheap)
// ---------------------------------------------------------------

cdw_send( 'sitewide_status', [ 'message' => 'Checking which pages are affected…' ] );

$page_list_text = '';
foreach ( $page_index as $p ) {
    $page_list_text .= "ID:{$p['id']} | \"{$p['title']}\" ({$p['slug']})\n";
    if ( '' !== $p['preview'] ) {
        $page_list_text .= '  Content preview: ' . $p['preview'] . "\n";
    }
    $page_list_text .= "\n";
}

$discovery_system = <<<SYS
You are a WordPress content assistant. Your job is to identify which pages need to be updated based on a change instruction.

Return ONLY a valid JSON array of integer page IDs that need to be changed. Nothing else — no explanation, no prose, no code fences.
If no pages need changes, return an empty array: []

Examples of valid responses:
[42, 17, 83]
[-1, 42]
[]
SYS;

$discovery_user = "Change instruction: \"{$message}\"\n\nAvailable pages:\n{$page_list_text}";

cdw_log( 'Discovery call — ' . count( $page_index ) . ' pages' );

$discovery = cdw_call_claude( $api_key, $model, [ [ 'role' => 'user', 'content' => $discovery_user ] ], $discovery_system, 256 );

$total_input_tokens  = $discovery['input_tokens'];
$total_output_tokens = $discovery['output_tokens'];

if ( $discovery['error'] ) {
    cdw_send( 'error', [ 'message' => 'Could not reach AI: ' . $discovery['error'] ] );
    exit;
}

cdw_log( 'Discovery response: ' . $discovery['text'] );

// Parse matched IDs
$matched_ids = [];
$disc_arr    = json_decode( cdw_unwrap_json( $discovery['text'] ), true );
if ( is_array( $disc_arr ) ) {
    foreach ( $disc_arr as $id ) {
        $matched_ids[] = (int) $id;
    }
    $matched_ids = array_unique( $matched_ids );
}

// Validate against actual page IDs
$valid_ids    = array_column( $page_index, 'id' );
$matched_ids  = array_values( array_filter( $matched_ids, fn( $id ) => in_array( $id, $valid_ids, true ) ) );

if ( empty( $matched_ids ) ) {
    cdw_send( 'done', [
        'success'       => true,
        'changed_pages' => [],
        'message'       => 'No pages on your site match that instruction — nothing was changed.',
        'spent_fmt'     => $spent_fmt,
        'budget_fmt'    => $budget_fmt,
        'remaining_fmt' => $remaining_fmt,
        'show_warning'  => $show_warning,
        'contact_phone' => $contact_phone,
        'contact_email' => $contact_email,
    ] );
    exit;
}

// Build lookup map for matched pages
$page_map = [];
foreach ( $page_index as $p ) {
    if ( in_array( $p['id'], $matched_ids, true ) ) {
        $page_map[ $p['id'] ] = $p;
    }
}

$matched_names = array_map( fn( $p ) => '"' . $p['title'] . '"', array_values( $page_map ) );
$n_found       = count( $matched_ids );
cdw_send( 'sitewide_status', [
    'message' => 'Found ' . $n_found . ' ' . ( $n_found === 1 ? 'page' : 'pages' ) . ' to update: ' . implode( ', ', $matched_names ) . '. Applying changes…',
] );

// ---------------------------------------------------------------
// Step 2: Apply changes to each matched page
// ---------------------------------------------------------------

$patch_system = <<<SYS
You are a WordPress content editor. Apply the given change instruction to the page HTML and return a JSON patch.

Return ONLY one JSON object — nothing before or after:
{"action":"patch","patches":[{"find":"[exact verbatim substring from the current HTML]","replace":"[replacement string]"}],"summary":"[one plain English sentence describing what changed]"}

Rules:
- "find" must be an exact verbatim substring of the current HTML — copy character-for-character
- Include enough surrounding text in "find" so it is unique on the page
- Only make changes described by the instruction — preserve everything else exactly
- Multiple patches are allowed in one array
- If nothing on this page matches the instruction, return: {"action":"none","summary":"No matching content on this page"}
SYS;

$changed_pages = [];
$failed_pages  = [];

foreach ( $matched_ids as $pid ) {
    $pinfo = $page_map[ $pid ] ?? null;
    if ( ! $pinfo ) continue;

    $raw_html = $pinfo['raw'];

    // Strip scripts and HTML comments for the Claude context only
    $clean_html = preg_replace( '/<script[^>]*>[\s\S]*?<\/script>/i', '', $raw_html );
    $clean_html = preg_replace( '/<!--[\s\S]*?-->/', '', $clean_html );
    $clean_html = trim( $clean_html );

    $patch_user = "Instruction: \"{$message}\"\n\nPage: \"{$pinfo['title']}\"\n\nCurrent HTML:\n```html\n{$clean_html}\n```";

    cdw_log( 'Patch call for page ID ' . $pid . ' (' . $pinfo['title'] . ')' );

    $result = cdw_call_claude( $api_key, $model, [ [ 'role' => 'user', 'content' => $patch_user ] ], $patch_system );
    $total_input_tokens  += $result['input_tokens'];
    $total_output_tokens += $result['output_tokens'];

    if ( $result['error'] ) {
        cdw_log( 'Patch error for page ' . $pid . ': ' . $result['error'] );
        $failed_pages[] = $pinfo['title'];
        continue;
    }

    $action_data = json_decode( cdw_unwrap_json( $result['text'] ), true );
    if ( ! is_array( $action_data ) || ( $action_data['action'] ?? '' ) === 'none' ) {
        cdw_log( 'No patches for page ' . $pid );
        continue;
    }

    if ( ( $action_data['action'] ?? '' ) === 'patch' && ! empty( $action_data['patches'] ) ) {
        $new_html = $raw_html;
        $applied  = 0;

        foreach ( $action_data['patches'] as $patch ) {
            $find    = (string) ( $patch['find']    ?? '' );
            $replace = (string) ( $patch['replace'] ?? '' );
            if ( '' === $find || false === strpos( $new_html, $find ) ) continue;
            $new_html = str_replace( $find, $replace, $new_html );
            $applied++;
        }

        if ( $applied > 0 ) {
            // Write back and create snapshot
            if ( $pid === -1 ) {
                update_option( 'iw_global_header', $new_html );
            } elseif ( $pid === -2 ) {
                update_option( 'iw_global_footer', $new_html );
            } else {
                $key   = '_iw_body';
                $snaps = (array) get_post_meta( $pid, '_cdc_snapshots', true );
                $snaps[] = [ 'ts' => time(), 'key' => $key, 'content' => get_post_meta( $pid, $key, true ) ];
                if ( count( $snaps ) > 30 ) $snaps = array_slice( $snaps, -30 );
                update_post_meta( $pid, '_cdc_snapshots', $snaps );
                update_post_meta( $pid, $key, $new_html );
            }

            $changed_pages[] = [
                'id'      => $pid,
                'title'   => $pinfo['title'],
                'url'     => $pid > 0 ? ( get_permalink( $pid ) ?: '' ) : '',
                'summary' => $action_data['summary'] ?? 'Updated.',
            ];

            cdw_log( 'Applied ' . $applied . ' patch(es) to page ' . $pid );
        }
    }
}

// ---------------------------------------------------------------
// Step 3: Report usage to MasterDesk
// ---------------------------------------------------------------

if ( $session_token !== '' && $endpoint !== '' ) {
    $report_endpoint = preg_replace( '/\/chat$/', '/report', $endpoint );
    $report_ch = curl_init( $report_endpoint );
    curl_setopt_array( $report_ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => wp_json_encode( [
            'token'         => $session_token,
            'input_tokens'  => $total_input_tokens,
            'output_tokens' => $total_output_tokens,
            'action'        => 'apply',
            'summary'       => 'Sitewide: ' . substr( $message, 0, 80 ),
        ] ),
        CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOSIGNAL       => true,
    ] );
    curl_exec( $report_ch );
    curl_close( $report_ch );
}

// ---------------------------------------------------------------
// Step 4: Send final done event
// ---------------------------------------------------------------

$n_changed = count( $changed_pages );

if ( $n_changed === 0 ) {
    $done_msg = empty( $failed_pages )
        ? 'No changes were needed — the content on the affected pages already matches the instruction.'
        : 'Something went wrong applying the changes. Please try again.';
} else {
    $done_msg = 'Done — updated ' . $n_changed . ' ' . ( $n_changed === 1 ? 'page' : 'pages' ) . '.';
}

cdw_send( 'done', [
    'success'       => true,
    'changed_pages' => $changed_pages,
    'message'       => $done_msg,
    'spent_fmt'     => $spent_fmt,
    'budget_fmt'    => $budget_fmt,
    'remaining_fmt' => $remaining_fmt,
    'show_warning'  => $show_warning,
    'contact_phone' => $contact_phone,
    'contact_email' => $contact_email,
] );
