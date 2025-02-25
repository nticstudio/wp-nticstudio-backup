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
define( 'WBSFTP_SFTP_HOST', 'sftp.hidrive.ionos.com' );
define( 'WBSFTP_SFTP_PORT', 22 );
define( 'WBSFTP_SFTP_USER', 'nticstudio' );
define( 'WBSFTP_SFTP_PASS', '2Fb?*?ue_8M_b$#' );
define( 'WBSFTP_SFTP_REMOTE_PATH', '/sites/ucanfit/' ); // Doit se terminer par /

define( 'WBSFTP_BACKUP_DIR', wp_upload_dir()['basedir'] . '/wp-backup-sftp' );

// Nombre de sauvegardes à conserver (paramètre de rétention), par défaut 4
define( 'WBSFTP_RETENTION', 4 );

/**
 * Récupère les options du plugin avec des valeurs par défaut.
 */
function nticstudio_backup_get_options() {
    $defaults = array(
        'sftp_host'         => 'sftp.hidrive.ionos.com',
        'sftp_port'         => 22,
        'sftp_user'         => 'nticstudio',
        'sftp_pass'         => '2Fb?*?ue_8M_b$#',
        'sftp_remote_path'  => '/sites/',  // Doit se terminer par /
        'retention'         => 4,
    );
    $options = get_option( 'nticstudio_backup_options', array() );
    return wp_parse_args( $options, $defaults );
}

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
 * Transfère le fichier de sauvegarde vers le serveur distant via SFTP en utilisant cURL.
 *
 * @param string $local_file Chemin local du fichier de sauvegarde.
 */
function wbsftp_send_via_sftp( $local_file ) {
    $remote_file = WBSFTP_SFTP_REMOTE_PATH . basename( $local_file );
    $url = "sftp://" . WBSFTP_SFTP_HOST . ":" . WBSFTP_SFTP_PORT . $remote_file;

    $fp = fopen( $local_file, 'r' );
    if ( ! $fp ) {
        error_log( 'Nticstudio Backup: Impossible d\'ouvrir le fichier local pour le transfert.' );
        return;
    }

    $ch = curl_init();
    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_USERPWD, WBSFTP_SFTP_USER . ":" . WBSFTP_SFTP_PASS );
    curl_setopt( $ch, CURLOPT_UPLOAD, 1 );
    curl_setopt( $ch, CURLOPT_INFILE, $fp );
    curl_setopt( $ch, CURLOPT_INFILESIZE, filesize( $local_file ) );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    
    $result = curl_exec( $ch );
    if ( curl_errno( $ch ) ) {
        error_log( 'Nticstudio Backup: cURL error lors du transfert SFTP: ' . curl_error( $ch ) );
    } else {
        error_log( 'Nticstudio Backup: Sauvegarde transférée avec succès vers ' . $remote_file );
    }
    curl_close( $ch );
    fclose( $fp );
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
 * Teste la connexion SFTP en créant puis supprimant un fichier de test sur le serveur distant via cURL.
 *
 * @return true|WP_Error Retourne true en cas de succès ou un WP_Error en cas d'erreur.
 */
function wbsftp_test_sftp() {
    // Création d'un fichier temporaire pour le test
    $temp_file = tempnam( sys_get_temp_dir(), 'nticstudio_test_' ) . '.txt';
    file_put_contents( $temp_file, "Test SFTP " . date( 'Y-m-d H:i:s' ) );

    $remote_file = WBSFTP_SFTP_REMOTE_PATH . 'test_sftp_' . time() . '.txt';
    $upload_url = "sftp://" . WBSFTP_SFTP_HOST . ":" . WBSFTP_SFTP_PORT . $remote_file;

    // Upload du fichier test via cURL
    $fp = fopen( $temp_file, 'r' );
    if ( ! $fp ) {
        unlink( $temp_file );
        return new WP_Error( 'temp_file_error', "Impossible d'ouvrir le fichier temporaire pour le test SFTP." );
    }
    $ch = curl_init();
    curl_setopt( $ch, CURLOPT_URL, $upload_url );
    curl_setopt( $ch, CURLOPT_USERPWD, WBSFTP_SFTP_USER . ":" . WBSFTP_SFTP_PASS );
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

    // Suppression du fichier test via cURL en utilisant l'option CURLOPT_QUOTE
    $ch = curl_init();
    // L'URL doit pointer vers le répertoire distant
    $delete_url = "sftp://" . WBSFTP_SFTP_HOST . ":" . WBSFTP_SFTP_PORT . WBSFTP_SFTP_REMOTE_PATH;

    curl_setopt( $ch, CURLOPT_URL, $delete_url );
    curl_setopt( $ch, CURLOPT_USERPWD, WBSFTP_SFTP_USER . ":" . WBSFTP_SFTP_PASS );
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

// ==================================================
// Ajout d'une page d'administration pour tester SFTP
// ==================================================
add_action( 'admin_menu', 'wbsftp_add_admin_menu' );


function wbsftp_add_admin_menu() {
    // Menu principal
    add_menu_page(
        'Nticstudio Backup',         // Titre de la page
        'Nticstudio Backup',         // Titre du menu
        'manage_options',            // Capacité requise
        'nticstudio-backup',         // Slug du menu
        'wbsftp_backup_page',        // Fonction de rappel pour le contenu de la page (backup)
        'dashicons-backup',          // Icône (optionnel)
        80                           // Position dans le menu
    );
    
    // Sous-menu pour le tableau de bord (backup)
    add_submenu_page(
        'nticstudio-backup',         // Slug du menu parent
        'Nticstudio Backup',         // Titre de la page
        'Dashboard',                 // Titre du sous-menu
        'manage_options',            // Capacité requise
        'nticstudio-backup',         // Slug (identique à celui du menu principal pour rediriger vers la même page)
        'wbsftp_backup_page'         // Fonction de rappel pour le contenu
    );
    
    // Sous-menu pour le test SFTP
    add_submenu_page(
        'nticstudio-backup',         // Slug du menu parent
        'Nticstudio Test SFTP',      // Titre de la page
        'Test SFTP',                 // Titre du sous-menu
        'manage_options',            // Capacité requise
        'wbsftp-test',               // Slug unique pour cette page
        'wbsftp_test_page'           // Fonction de rappel pour le contenu
    );
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


function wbsftp_backup_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Vous n\'avez pas les droits suffisants pour accéder à cette page.' );
    }
    $result = null;
    if ( isset( $_POST['wbsftp_backup'] ) ) {
        wbsftp_run_backup();
    }
    ?>
    <div class="wrap">
        <h1>Backup SFTP</h1>
        <form method="post">
            <?php submit_button( 'Run backup ', 'primary', 'wbsftp_backup' ); ?>
        </form>
    </div>
    <?php
}

// ==================================================
// Mise à jour automatique via GitHub
// ==================================================
// Assurez-vous que le dossier "plugin-update-checker" est présent dans le répertoire du plugin
// if ( ! class_exists( 'Puc_v4_Factory' ) ) {
//     require_once dirname( __FILE__ ) . '/plugin-update-checker/plugin-update-checker.php';
// }


// $updateChecker = Puc_v4_Factory::buildUpdateChecker(
//     'https://github.com/Nticstudio/wp-nticstudio-backup', // URL du dépôt GitHub
//     __FILE__,
//     'wp-nticstudio-backup'
// );
// $updateChecker->setBranch('main'); // Modifier si votre branche par défaut n'est pas "main"
