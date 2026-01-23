<?php
if (!defined('ABSPATH')) { exit; }

function abc_b2b_designer_upload_base_dir(): string {
    $upload = wp_upload_dir();
    $base = trailingslashit($upload['basedir']) . 'abc-b2b-designer/';
    if (!file_exists($base)) {
        wp_mkdir_p($base);
    }
    return $base;
}

function abc_b2b_designer_upload_base_url(): string {
    $upload = wp_upload_dir();
    return trailingslashit($upload['baseurl']) . 'abc-b2b-designer/';
}

function abc_b2b_designer_token(int $len = 20): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i=0; $i<$len; $i++) {
        $out .= $chars[random_int(0, strlen($chars)-1)];
    }
    return $out;
}

function abc_b2b_designer_safe_float($v, $default = 0.0): float {
    if ($v === null || $v === '') return (float)$default;
    if (is_numeric($v)) return (float)$v;
    return (float)$default;
}

function abc_b2b_designer_current_user_org_id(): int {
    $user_id = get_current_user_id();
    if (!$user_id) return 0;
    $org_id = (int) get_user_meta($user_id, 'abc_b2b_org_id', true);
    return $org_id > 0 ? $org_id : 0;
}

/**
 * Decode a data URL (data:image/png;base64,...) into raw bytes.
 * Returns ['mime' => string, 'bytes' => string] or null.
 */
function abc_b2b_designer_decode_data_url(string $data_url) {
    if (strpos($data_url, 'data:') !== 0) return null;
    $parts = explode(',', $data_url, 2);
    if (count($parts) !== 2) return null;
    $meta = $parts[0];
    $b64 = $parts[1];
    if (!preg_match('#^data:([^;]+);base64$#', $meta, $m)) return null;
    $mime = $m[1];
    $bytes = base64_decode($b64);
    if ($bytes === false) return null;
    return ['mime' => $mime, 'bytes' => $bytes];
}


function abc_b2b_designer_current_user_is_approved(): bool {
    $user_id = get_current_user_id();
    if (!$user_id) return false;
    return (int) get_user_meta($user_id, 'abc_b2b_org_approved', true) === 1;
}

function abc_b2b_designer_current_user_is_org_admin(): bool {
    $user_id = get_current_user_id();
    if (!$user_id) return false;
    return get_user_meta($user_id, 'abc_b2b_org_role', true) === 'admin';
}

/**
 * Organizer first name for an org: first Org Admin in that org, else a site admin.
 */
function abc_b2b_designer_org_organizer_first_name(int $org_id): string {
    if ($org_id <= 0) return 'the organizer';

    $users = get_users([
        'meta_key' => 'abc_b2b_org_id',
        'meta_value' => (int) $org_id,
        'number' => 200,
        'fields' => ['ID', 'display_name'],
    ]);

    $organizer_id = 0;
    foreach ($users as $u) {
        if (get_user_meta($u->ID, 'abc_b2b_org_role', true) === 'admin') { $organizer_id = (int) $u->ID; break; }
    }

    if (!$organizer_id) {
        $admins = get_users(['role__in' => ['administrator'], 'number' => 1, 'fields' => ['ID', 'display_name']]);
        if (!empty($admins)) $organizer_id = (int) $admins[0]->ID;
    }

    if (!$organizer_id) return 'the organizer';

    $first = get_user_meta($organizer_id, 'first_name', true);
    if ($first) return (string) $first;

    $dn = get_userdata($organizer_id)->display_name ?? '';
    $first = trim(explode(' ', trim($dn))[0] ?? '');
    return $first ?: 'the organizer';
}



function abc_b2b_designer_upload_drafts_dir(): string {
    $base = abc_b2b_designer_upload_base_dir();
    $dir = trailingslashit($base) . 'drafts/';
    if (!file_exists($dir)) { wp_mkdir_p($dir); }
    return $dir;
}

function abc_b2b_designer_upload_drafts_url(): string {
    $base = abc_b2b_designer_upload_base_url();
    return trailingslashit($base) . 'drafts/';
}

function abc_b2b_designer_get_draft_editor_page_id(): int {
    $pid = (int) get_option('abc_b2b_draft_editor_page_id', 0);
    if ($pid > 0 && get_post($pid)) return $pid;
    return 0;
}

function abc_b2b_designer_get_draft_editor_url(int $draft_id): string {
    $pid = abc_b2b_designer_get_draft_editor_page_id();
    if ($pid > 0) {
        return add_query_arg(['draft_id' => (int)$draft_id], get_permalink($pid));
    }
    return add_query_arg(['draft_id' => (int)$draft_id], home_url('/'));
}

function abc_b2b_designer_reset_draft_ready_flags(int $draft_id): void {
    update_post_meta($draft_id, '_abc_employee_ready', 0);
    update_post_meta($draft_id, '_abc_admin_ready', 0);
    update_post_meta($draft_id, '_abc_ready_override', 0);
}

