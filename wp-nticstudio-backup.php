<?php
/*
Plugin Name: Nticstudio Backup
Description: Plugin de sauvegarde automatique (BDD et wp-content) et transfert via SFTP (cURL) vers un serveur distant, lancé via cron. Intègre également un test SFTP, une mise à jour automatique via GitHub et une interface de configuration sécurisée.
Version: 1.0.0
Author: Nticstudio
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Le dossier de backup est défini par rapport au dossier uploads de WordPress.
define( 'WBSFTP_BACKUP_DIR', wp_upload_dir()['basedir'] . '/wp-backup-sftp' );

/**
 * Récupère les options du plugin avec des valeurs par défaut.
 */
function nticstudio_backup_get_options() {
    $defaults = array(
        'sftp_host'         => 'sftp.hidrive.ionos.com',
        'sftp_port'         => 22,
        'sftp_user'         => '',
        'sftp_pass'         => '',
        'sftp_remote_path'  => '/sites/',  // Doit se terminer par /
        'retention'         => 10,
    );
    $options = get_option( 'nticstudio_backup_options', array() );
    return wp_parse_args( $options, $defaults );
}

/* ========================================
   Activation / Désactivation et Cron
======================================== */
function nticstudio_backup_activate() {
    if ( ! wp_next_scheduled( 'nticstudio_backup_cron_hook' ) ) {
        wp_schedule_event( time(), 'daily', 'nticstudio_backup_cron_hook' );
    }
}
register_activation_hook( __FILE__, 'nticstudio_backup_activate' );

function nticstudio_backup_deactivate() {
    $timestamp = wp_next_scheduled( 'nticstudio_backup_cron_hook' );
    wp_unschedule_event( $timestamp, 'nticstudio_backup_cron_hook' );
}
register_deactivation_hook( __FILE__, 'nticstudio_backup_deactivate' );

add_action( 'nticstudio_backup_cron_hook', 'nticstudio_backup_run' );

/**
 * Exécute la sauvegarde (BDD + fichiers) puis transfère l'archive via SFTP.
 */
function nticstudio_backup_run() {
    // Création du dossier de sauvegarde si nécessaire.
    if ( ! file_exists( WBSFTP_BACKUP_DIR ) ) {
        wp_mkdir_p( WBSFTP_BACKUP_DIR );
    }
    $backup_file = 'backup_' . date( 'Y-m-d_H-i-s' ) . '.zip';
    $backup_file_path = WBSFTP_BACKUP_DIR . '/' . $backup_file;

    // 1. Sauvegarde de la base de données.
    $db_dump_file = tempnam( sys_get_temp_dir(), 'nticstudio_db_' ) . '.sql';
    $command = sprintf(
        'mysqldump --host=%s --user=%s --password=%s %s > %s',
        DB_HOST,
        DB_USER,
        DB_PASSWORD,
        DB_NAME,
        escapeshellarg( $db_dump_file )
    );
    exec( $command, $output, $return_var );
    if ( $return_var !== 0 ) {
        error_log( 'Nticstudio Backup: Erreur lors de la sauvegarde de la BDD.' );
        return;
    }

    // 2. Création de l'archive ZIP.
    $zip = new ZipArchive();
    if ( $zip->open( $backup_file_path, ZipArchive::CREATE ) !== TRUE ) {
        error_log( 'Nticstudio Backup: Impossible de créer l\'archive ZIP.' );
        return;
    }
    $zip->addFile( $db_dump_file, 'db/backup.sql' );
    $source = realpath( WP_CONTENT_DIR );
    if ( is_dir( $source ) ) {
        nticstudio_backup_add_folder_to_zip( $source, $zip, 'files' );
    } else {
        error_log( 'Nticstudio Backup: Dossier wp-content introuvable.' );
    }
    $zip->close();
    unlink( $db_dump_file );

    // 3. Transfert via SFTP (cURL).
    nticstudio_backup_send_via_sftp( $backup_file_path );

    // 4. Gestion de la rétention des sauvegardes.
    nticstudio_backup_cleanup();
}

/**
 * Ajoute récursivement un dossier dans l'archive ZIP.
 */
function nticstudio_backup_add_folder_to_zip( $folder, &$zip, $zip_folder ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $folder ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ( $files as $file ) {
        if ( ! $file->isDir() ) {
            $filePath = $file->getRealPath();
            $relativePath = $zip_folder . '/' . substr( $filePath, strlen( $folder ) + 1 );
            $zip->addFile( $filePath, $relativePath );
        }
    }
}

/**
 * Transfère l'archive de sauvegarde vers le serveur distant via SFTP (cURL).
 */
function nticstudio_backup_send_via_sftp( $local_file ) {
    $options = nticstudio_backup_get_options();
    $remote_file = $options['sftp_remote_path'] . basename( $local_file );
    $url = "sftp://{$options['sftp_host']}:{$options['sftp_port']}{$remote_file}";

    $fp = fopen( $local_file, 'r' );
    if ( ! $fp ) {
        error_log( 'Nticstudio Backup: Impossible d\'ouvrir le fichier local.' );
        return;
    }
    $ch = curl_init();
    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_USERPWD, $options['sftp_user'] . ':' . $options['sftp_pass'] );
    curl_setopt( $ch, CURLOPT_UPLOAD, 1 );
    curl_setopt( $ch, CURLOPT_INFILE, $fp );
    curl_setopt( $ch, CURLOPT_INFILESIZE, filesize( $local_file ) );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    
    $result = curl_exec( $ch );
    if ( curl_errno( $ch ) ) {
        error_log( 'Nticstudio Backup: Erreur cURL lors du transfert SFTP: ' . curl_error( $ch ) );
    } else {
        error_log( 'Nticstudio Backup: Sauvegarde transférée vers ' . $remote_file );
    }
    curl_close( $ch );
    fclose( $fp );
}

/**
 * Supprime les anciennes sauvegardes si leur nombre dépasse la valeur de rétention.
 */
function nticstudio_backup_cleanup() {
    $options = nticstudio_backup_get_options();
    $files = glob( WBSFTP_BACKUP_DIR . '/backup_*.zip' );
    if ( $files !== false && count( $files ) > $options['retention'] ) {
        usort( $files, function( $a, $b ) {
            return filemtime( $a ) - filemtime( $b );
        });
        while ( count( $files ) > $options['retention'] ) {
            $oldest = array_shift( $files );
            unlink( $oldest );
            error_log( 'Nticstudio Backup: Suppression de ' . basename( $oldest ) );
        }
    }
}

/**
 * Teste la connexion SFTP en uploadant puis supprimant un fichier test via cURL.
 */
function nticstudio_backup_test_sftp() {
    $options = nticstudio_backup_get_options();
    $temp_file = tempnam( sys_get_temp_dir(), 'nticstudio_test_' ) . '.txt';
    file_put_contents( $temp_file, "Test SFTP " . date( 'Y-m-d H:i:s' ) );
    $remote_file = $options['sftp_remote_path'] . 'test_sftp_' . time() . '.txt';
    $upload_url = "sftp://{$options['sftp_host']}:{$options['sftp_port']}{$remote_file}";

    $fp = fopen( $temp_file, 'r' );
    if ( ! $fp ) {
        unlink( $temp_file );
        return new WP_Error( 'temp_file_error', "Impossible d'ouvrir le fichier temporaire." );
    }
    $ch = curl_init();
    curl_setopt( $ch, CURLOPT_URL, $upload_url );
    curl_setopt( $ch, CURLOPT_USERPWD, $options['sftp_user'] . ':' . $options['sftp_pass'] );
    curl_setopt( $ch, CURLOPT_UPLOAD, 1 );
    curl_setopt( $ch, CURLOPT_INFILE, $fp );
    curl_setopt( $ch, CURLOPT_INFILESIZE, filesize( $temp_file ) );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    $result = curl_exec( $ch );
    if ( curl_errno( $ch ) ) {
        $err = curl_error( $ch );
        curl_close( $ch );
        fclose( $fp );
        unlink( $temp_file );
        return new WP_Error( 'upload_failed', "Erreur lors de l'upload test SFTP: " . $err );
    }
    curl_close( $ch );
    fclose( $fp );

    // Suppression du fichier de test via cURL (en envoyant une commande "rm")
    $ch = curl_init();
    $delete_url = "sftp://{$options['sftp_host']}:{$options['sftp_port']}{$options['sftp_remote_path']}";
    curl_setopt( $ch, CURLOPT_URL, $delete_url );
    curl_setopt( $ch, CURLOPT_USERPWD, $options['sftp_user'] . ':' . $options['sftp_pass'] );
    curl_setopt( $ch, CURLOPT_QUOTE, array( "rm " .  $remote_file  ) );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    $result = curl_exec( $ch );
    if ( curl_errno( $ch ) ) {
        $err = curl_error( $ch );
        curl_close( $ch );
        unlink( $temp_file );
        return new WP_Error( 'delete_failed', "Erreur lors de la suppression du fichier test SFTP: " . $err );
    }
    curl_close( $ch );
    unlink( $temp_file );
    return true;
}

/* ========================================
   Admin Menu et Pages de Configuration
======================================== */
add_action( 'admin_menu', 'nticstudio_backup_add_admin_menu' );
function nticstudio_backup_add_admin_menu() {
    add_menu_page(
        'Nticstudio Backup',
        'Nticstudio Backup',
        'manage_options',
        'nticstudio-backup',
        'nticstudio_backup_dashboard_page',
        'dashicons-backup',
        80
    );
    add_submenu_page(
        'nticstudio-backup',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'nticstudio-backup',
        'nticstudio_backup_dashboard_page'
    );
    add_submenu_page(
        'nticstudio-backup',
        'Test SFTP',
        'Test SFTP',
        'manage_options',
        'nticstudio-backup-test',
        'nticstudio_backup_test_page'
    );
    add_submenu_page(
        'nticstudio-backup',
        'Settings',
        'Settings',
        'manage_options',
        'nticstudio-backup-settings',
        'nticstudio_backup_settings_page'
    );
}

function nticstudio_backup_dashboard_page() {
    // Vérification de la soumission du formulaire pour lancer le backup
    if ( isset( $_POST['nticstudio_backup_run'] ) && check_admin_referer( 'nticstudio_backup_run', 'nticstudio_backup_nonce' ) ) {
        // Lancement du backup
        nticstudio_backup_run();
        echo '<div class="updated"><p>La sauvegarde a été lancée.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Nticstudio Backup Dashboard</h1>
        <p>Utilisez ce dashboard pour lancer des sauvegardes et vérifier l'état du système.</p>
        <form method="post">
            <?php wp_nonce_field( 'nticstudio_backup_run', 'nticstudio_backup_nonce' ); ?>
            <?php submit_button( 'Lancer la sauvegarde', 'primary', 'nticstudio_backup_run' ); ?>
        </form>
    </div>
    <?php
}

function nticstudio_backup_test_page() {
    if ( isset( $_POST['nticstudio_backup_test'] ) ) {
        $result = nticstudio_backup_test_sftp();
    }
    ?>
    <div class="wrap">
        <h1>Nticstudio Test SFTP</h1>
        <?php if ( isset( $result ) && $result === true ) : ?>
            <div class="updated"><p>Connexion SFTP réussie.</p></div>
        <?php elseif ( isset( $result ) && is_wp_error( $result ) ) : ?>
            <div class="error"><p>Erreur: <?php echo $result->get_error_message(); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php submit_button( 'Tester la connexion SFTP', 'primary', 'nticstudio_backup_test' ); ?>
        </form>
    </div>
    <?php
}

function nticstudio_backup_settings_page() {
    ?>
    <div class="wrap">
        <h1>Nticstudio Backup Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'nticstudio_backup_options_group' );
            do_settings_sections( 'nticstudio-backup-settings' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action( 'admin_init', 'nticstudio_backup_register_settings' );
function nticstudio_backup_register_settings() {
    register_setting( 'nticstudio_backup_options_group', 'nticstudio_backup_options', 'nticstudio_backup_options_validate' );

    add_settings_section(
        'nticstudio_backup_settings_section',
        'Configuration SFTP et Backup',
        'nticstudio_backup_section_text',
        'nticstudio-backup-settings'
    );

    add_settings_field(
        'nticstudio_backup_options[sftp_host]',
        'SFTP Host',
        'nticstudio_backup_setting_sftp_host',
        'nticstudio-backup-settings',
        'nticstudio_backup_settings_section'
    );
    add_settings_field(
        'nticstudio_backup_options[sftp_port]',
        'SFTP Port',
        'nticstudio_backup_setting_sftp_port',
        'nticstudio-backup-settings',
        'nticstudio_backup_settings_section'
    );
    add_settings_field(
        'nticstudio_backup_options[sftp_user]',
        'SFTP User',
        'nticstudio_backup_setting_sftp_user',
        'nticstudio-backup-settings',
        'nticstudio_backup_settings_section'
    );
    add_settings_field(
        'nticstudio_backup_options[sftp_pass]',
        'SFTP Password',
        'nticstudio_backup_setting_sftp_pass',
        'nticstudio-backup-settings',
        'nticstudio_backup_settings_section'
    );
    add_settings_field(
        'nticstudio_backup_options[sftp_remote_path]',
        'SFTP Remote Path',
        'nticstudio_backup_setting_sftp_remote_path',
        'nticstudio-backup-settings',
        'nticstudio_backup_settings_section'
    );
    add_settings_field(
        'nticstudio_backup_options[retention]',
        'Backup Retention (nombre)',
        'nticstudio_backup_setting_retention',
        'nticstudio-backup-settings',
        'nticstudio_backup_settings_section'
    );
}

function nticstudio_backup_section_text() {
    echo '<p>Entrez les paramètres de connexion SFTP et de configuration de sauvegarde.</p>';
}

function nticstudio_backup_setting_sftp_host() {
    $options = get_option( 'nticstudio_backup_options' );
    $value = isset( $options['sftp_host'] ) ? esc_attr( $options['sftp_host'] ) : 'sftp.hidrive.ionos.com';
    echo "<input id='nticstudio_backup_sftp_host' name='nticstudio_backup_options[sftp_host]' type='text' value='{$value}' />";
}

function nticstudio_backup_setting_sftp_port() {
    $options = get_option( 'nticstudio_backup_options' );
    $value = isset( $options['sftp_port'] ) ? absint( $options['sftp_port'] ) : 22;
    echo "<input id='nticstudio_backup_sftp_port' name='nticstudio_backup_options[sftp_port]' type='number' value='{$value}' />";
}

function nticstudio_backup_setting_sftp_user() {
    $options = get_option( 'nticstudio_backup_options' );
    $value = isset( $options['sftp_user'] ) ? esc_attr( $options['sftp_user'] ) : '';
    echo "<input id='nticstudio_backup_sftp_user' name='nticstudio_backup_options[sftp_user]' type='text' value='{$value}' />";
}

function nticstudio_backup_setting_sftp_pass() {
    $options = get_option( 'nticstudio_backup_options' );
    $value = isset( $options['sftp_pass'] ) ? esc_attr( $options['sftp_pass'] ) : '';
    echo "<input id='nticstudio_backup_sftp_pass' name='nticstudio_backup_options[sftp_pass]' type='password' value='{$value}' />";
}

function nticstudio_backup_setting_sftp_remote_path() {
    $options = get_option( 'nticstudio_backup_options' );
    $value = isset( $options['sftp_remote_path'] ) ? esc_attr( $options['sftp_remote_path'] ) : '/sites/';
    echo "<input id='nticstudio_backup_sftp_remote_path' name='nticstudio_backup_options[sftp_remote_path]' type='text' value='{$value}' />";
}

function nticstudio_backup_setting_retention() {
    $options = get_option( 'nticstudio_backup_options' );
    $value = isset( $options['retention'] ) ? absint( $options['retention'] ) : 4;
    echo "<input id='nticstudio_backup_retention' name='nticstudio_backup_options[retention]' type='number' value='{$value}' />";
}

function nticstudio_backup_options_validate( $input ) {
    $new_input = array();
    $new_input['sftp_host'] = sanitize_text_field( $input['sftp_host'] );
    $new_input['sftp_port'] = absint( $input['sftp_port'] );
    $new_input['sftp_user'] = sanitize_text_field( $input['sftp_user'] );
    $new_input['sftp_pass'] = sanitize_text_field( $input['sftp_pass'] );
    $new_input['sftp_remote_path'] = sanitize_text_field( $input['sftp_remote_path'] );
    $new_input['retention'] = absint( $input['retention'] );
    return $new_input;
}

/* ========================================
   Mise à jour automatique via GitHub
======================================== */

require_once __DIR__ . '/updater-checker.php'; 

use NticstudioWpBackup\Updater_Checker; 

$github_username = 'nticstudio'; 
$github_repository = 'wp-ntictudio-backup';
$plugin_basename = plugin_basename( __FILE__ ); 
$plugin_current_version = '1.0.0'; 

$updater = new Updater_Checker(
    $github_username,
    $github_repository,
    $plugin_basename,
    $plugin_current_version
);
$updater->set_hooks();