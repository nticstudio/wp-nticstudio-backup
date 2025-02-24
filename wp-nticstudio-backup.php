<?php
/*
Plugin Name: Nticstudio Backup
Description: Plugin de sauvegarde automatique des fichiers (wp-content) et de la base de données, puis transfert via SFTP vers un serveur distant. L'exécution est lancée par un cron. Le plugin intègre également un test de connexion SFTP et un système de mise à jour automatique via GitHub.
Version: 1.3
Author: Nticstudio
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// =====================
// Configuration SFTP et backup
// =====================
define( 'WBSFTP_SFTP_HOST', 'sftp.example.com' );
define( 'WBSFTP_SFTP_PORT', 22 );
define( 'WBSFTP_SFTP_USER', 'username' );
define( 'WBSFTP_SFTP_PASS', 'password' );
define( 'WBSFTP_SFTP_REMOTE_PATH', '/remote/backup/path/' ); // Doit se terminer par /

define( 'WBSFTP_BACKUP_DIR', wp_upload_dir()['basedir'] . '/wp-backup-sftp' );

// Nombre de sauvegardes à conserver (paramètre de rétention), par défaut 4
define( 'WBSFTP_RETENTION', 4 );

// ========================================
// Activation et désactivation du plugin
// ========================================
function wbsftp_activate() {
    if ( ! wp_next_scheduled( 'wbsftp_cron_hook' ) ) {
        wp_schedule_event( time(), 'daily', 'wbsftp_cron_hook' ); // Exécution quotidienne (modifiable)
    }
}
register_activation_hook( __FILE__, 'wbsftp_activate' );

function wbsftp_deactivate() {
    $timestamp = wp_next_scheduled( 'wbsftp_cron_hook' );
    wp_unschedule_event( $timestamp, 'wbsftp_cron_hook' );
}
register_deactivation_hook( __FILE__, 'wbsftp_deactivate' );

// =====================================
// Lancement de la sauvegarde via cron
// =====================================
add_action( 'wbsftp_cron_hook', 'wbsftp_run_backup' );

function wbsftp_run_backup() {
    // Création du dossier de sauvegarde si nécessaire
    if ( ! file_exists( WBSFTP_BACKUP_DIR ) ) {
        wp_mkdir_p( WBSFTP_BACKUP_DIR );
    }
    $backup_file = 'backup_' . date( 'Y-m-d_H-i-s' ) . '.zip';
    $backup_file_path = WBSFTP_BACKUP_DIR . '/' . $backup_file;

    // ============================
    // 1. Sauvegarde de la BDD
    // ============================
    $db_dump_file = tempnam( sys_get_temp_dir(), 'wbsftp_db_' ) . '.sql';
    // La commande mysqldump nécessite que exec soit activé
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
        error_log( 'Nticstudio Backup: Erreur lors de la sauvegarde de la base de données.' );
        return;
    }

    // ============================
    // 2. Création de l'archive ZIP
    // ============================
    $zip = new ZipArchive();
    if ( $zip->open( $backup_file_path, ZipArchive::CREATE ) !== TRUE ) {
        error_log( 'Nticstudio Backup: Impossible de créer le fichier zip.' );
        return;
    }

    // Ajout du dump de la BDD dans le dossier "db" de l'archive
    $zip->addFile( $db_dump_file, 'db/backup.sql' );

    // Ajout des fichiers (ici le dossier wp-content) dans le dossier "files" de l'archive
    $source = realpath( WP_CONTENT_DIR );
    if ( is_dir( $source ) ) {
        wbsftp_add_folder_to_zip( $source, $zip, 'files' );
    } else {
        error_log( 'Nticstudio Backup: Le dossier wp-content introuvable.' );
    }
    $zip->close();

    // Suppression du fichier temporaire de la BDD
    unlink( $db_dump_file );

    // ====================================
    // 3. Transfert de l'archive via SFTP
    // ====================================
    wbsftp_send_via_sftp( $backup_file_path );

    // ====================================
    // 4. Gestion de la rétention des sauvegardes
    // ====================================
    wbsftp_cleanup_backups();
}

/**
 * Ajoute récursivement un dossier dans l'archive ZIP.
 *
 * @param string       $folder     Chemin du dossier à ajouter.
 * @param ZipArchive   $zip        Instance de ZipArchive.
 * @param string       $zip_folder Dossier dans l'archive où ajouter les fichiers.
 */
function wbsftp_add_folder_to_zip( $folder, &$zip, $zip_folder ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $folder ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ( $files as $file ) {
        if ( ! $file->isDir() ) {
            $filePath     = $file->getRealPath();
            $relativePath = $zip_folder . '/' . substr( $filePath, strlen( $folder ) + 1 );
            $zip->addFile( $filePath, $relativePath );
        }
    }
}

/**
 * Transfère le fichier de sauvegarde vers le serveur distant via SFTP.
 *
 * @param string $local_file Chemin local du fichier de sauvegarde.
 */
function wbsftp_send_via_sftp( $local_file ) {
    if ( ! function_exists( 'ssh2_connect' ) ) {
        error_log( 'Nticstudio Backup: L’extension SSH2 n’est pas installée.' );
        return;
    }

    $connection = ssh2_connect( WBSFTP_SFTP_HOST, WBSFTP_SFTP_PORT );
    if ( ! $connection ) {
        error_log( 'Nticstudio Backup: Impossible de se connecter au serveur SFTP.' );
        return;
    }
    if ( ! ssh2_auth_password( $connection, WBSFTP_SFTP_USER, WBSFTP_SFTP_PASS ) ) {
        error_log( 'Nticstudio Backup: Authentification SFTP échouée.' );
        return;
    }

    // Chemin complet sur le serveur distant
    $remote_file = WBSFTP_SFTP_REMOTE_PATH . basename( $local_file );

    // Transfert via SCP (vous pouvez également utiliser ssh2_sftp pour un transfert SFTP plus fin)
    if ( ! ssh2_scp_send( $connection, $local_file, $remote_file, 0644 ) ) {
        error_log( 'Nticstudio Backup: Échec du transfert SFTP.' );
    } else {
        error_log( 'Nticstudio Backup: Sauvegarde transférée avec succès vers ' . $remote_file );
    }
}

/**
 * Nettoie le dossier de sauvegarde en supprimant les anciennes archives
 * si leur nombre dépasse la valeur définie par WBSFTP_RETENTION.
 */
function wbsftp_cleanup_backups() {
    $files = glob( WBSFTP_BACKUP_DIR . '/backup_*.zip' );
    if ( $files !== false && count( $files ) > WBSFTP_RETENTION ) {
        // Tri des fichiers par date de modification croissante (les plus anciens en premier)
        usort( $files, function( $a, $b ) {
            return filemtime( $a ) - filemtime( $b );
        });
        // Suppression des fichiers en excès
        while ( count( $files ) > WBSFTP_RETENTION ) {
            $oldest = array_shift( $files );
            unlink( $oldest );
            error_log( 'Nticstudio Backup: Suppression de l\'ancienne sauvegarde ' . basename( $oldest ) );
        }
    }
}

/**
 * Teste la connexion SFTP en créant puis supprimant un fichier de test sur le serveur distant.
 *
 * @return true|WP_Error Retourne true en cas de succès ou un WP_Error en cas d'erreur.
 */
function wbsftp_test_sftp() {
    if ( ! function_exists( 'ssh2_connect' ) ) {
        return new WP_Error( 'ssh2_missing', "L'extension SSH2 n'est pas installée." );
    }

    $connection = ssh2_connect( WBSFTP_SFTP_HOST, WBSFTP_SFTP_PORT );
    if ( ! $connection ) {
        return new WP_Error( 'connection_failed', "Impossible de se connecter au serveur SFTP." );
    }
    if ( ! ssh2_auth_password( $connection, WBSFTP_SFTP_USER, WBSFTP_SFTP_PASS ) ) {
        return new WP_Error( 'auth_failed', "L'authentification SFTP a échoué." );
    }
    $sftp = ssh2_sftp( $connection );
    $remote_file = WBSFTP_SFTP_REMOTE_PATH . 'test_sftp_' . time() . '.txt';
    $stream = @fopen( "ssh2.sftp://{$sftp}{$remote_file}", 'w' );
    if ( ! $stream ) {
        return new WP_Error( 'write_failed', "Échec de la création du fichier test sur le serveur SFTP." );
    }
    fwrite( $stream, "Test SFTP " . date( 'Y-m-d H:i:s' ) );
    fclose( $stream );

    // Suppression du fichier de test
    if ( ! unlink( "ssh2.sftp://{$sftp}{$remote_file}" ) ) {
        return new WP_Error( 'delete_failed', "Échec de la suppression du fichier test sur le serveur SFTP." );
    }
    return true;
}

// ==================================================
// Ajout d'une page d'administration pour tester SFTP
// ==================================================
add_action( 'admin_menu', 'wbsftp_add_admin_menu' );
function wbsftp_add_admin_menu() {
    add_management_page( 'Test SFTP', 'Test SFTP', 'manage_options', 'wbsftp-test', 'wbsftp_test_page' );
}

function wbsftp_test_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Vous n\'avez pas les droits suffisants pour accéder à cette page.' );
    }
    $result = null;
    if ( isset( $_POST['wbsftp_test'] ) ) {
        $result = wbsftp_test_sftp();
    }
    ?>
    <div class="wrap">
        <h1>Test SFTP</h1>
        <?php if ( $result === true ) : ?>
            <div class="updated"><p>La connexion SFTP a réussi.</p></div>
        <?php elseif ( is_wp_error( $result ) ) : ?>
            <div class="error"><p>Erreur : <?php echo $result->get_error_message(); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php submit_button( 'Tester la connexion SFTP', 'primary', 'wbsftp_test' ); ?>
        </form>
    </div>
    <?php
}

// ==================================================
// Mise à jour automatique via GitHub
// ==================================================
// Assurez-vous que le dossier "plugin-update-checker" est présent dans le répertoire du plugin
if ( ! class_exists( 'Puc_v4_Factory' ) ) {
    require_once dirname( __FILE__ ) . '/plugin-update-checker/plugin-update-checker.php';
}

$updateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://github.com/Nticstudio/wp-nticstudio-backup', // URL du dépôt GitHub
    __FILE__,
    'wp-nticstudio-backup'
);
$updateChecker->setBranch('main'); // Modifier si votre branche par défaut n'est pas "main"
