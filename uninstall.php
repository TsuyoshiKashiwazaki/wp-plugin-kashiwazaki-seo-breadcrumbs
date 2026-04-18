<?php
/**
 * Kashiwazaki SEO Perfect Breadcrumbs — uninstall cleanup.
 *
 * WordPress はプラグイン完全削除時にこのファイルを自動実行する (register_uninstall_hook
 * より信頼性が高い。hook はコールバックを DB に serialize するため、class 名変更や
 * ファイル構造変更で silent fail する)。
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * 1 サイト分の option / transient を削除。multisite の network uninstall では
 * 各 blog で呼び出される。single-site でもそのまま使える。
 */
function kspb_uninstall_site_cleanup() {
    global $wpdb;

    delete_option('kspb_options');
    delete_option('kspb_version');
    delete_option('kspb_cache_version');

    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_kspb\\_%' ESCAPE '\\\\'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_kspb\\_%' ESCAPE '\\\\'"
    );
}

if (is_multisite()) {
    global $wpdb;

    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        kspb_uninstall_site_cleanup();
        restore_current_blog();
    }

    // network レベルの site transient も cleanup
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_kspb\\_%' ESCAPE '\\\\'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_timeout\\_kspb\\_%' ESCAPE '\\\\'"
    );
} else {
    kspb_uninstall_site_cleanup();
}
