<?php
/**
 * Plugin Name: OWOXA Role Manager
 * Description: Ajoute, modifie et supprime des rôles utilisateurs + édition des capabilities + capabilities personnalisées.
 * Version: 1.1.0
 * Author: Édouard - OWOXA
 * Text Domain: owoxa-role-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWOXA_Role_Manager {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    public function add_admin_menu() {
        add_users_page(
            'Gestion des Rôles',
            'Gestion des Rôles',
            'manage_options',
            'owoxa-role-manager',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Récupère toutes les capabilities connues sur le site
     */
    private function get_all_capabilities() {
        $all_caps = [];

        // On récupère toutes les caps déjà présentes dans les rôles
        $roles = wp_roles()->roles;
        foreach ( $roles as $role ) {
            if ( ! empty( $role['capabilities'] ) && is_array( $role['capabilities'] ) ) {
                $all_caps = array_merge( $all_caps, array_keys( $role['capabilities'] ) );
            }
        }

        // Liste de base WordPress (au cas où certaines manquent)
        $core_caps = [
            'read', 'read_private_posts', 'read_private_pages',
            'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts',
            'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages',
            'manage_categories', 'manage_links', 'moderate_comments', 'upload_files',
            'edit_users', 'list_users', 'create_users', 'delete_users', 'promote_users',
            'edit_theme_options', 'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes',
            'activate_plugins', 'edit_plugins', 'install_plugins', 'update_plugins', 'delete_plugins',
            'manage_options', 'export', 'import', 'unfiltered_html', 'unfiltered_upload',
            'edit_dashboard', 'update_core', 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'level_6', 'level_7', 'level_8', 'level_9', 'level_10',
        ];

        $all_caps = array_unique( array_merge( $all_caps, $core_caps ) );
        sort( $all_caps );

        return $all_caps;
    }

    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // === Création d'un rôle ===
        if ( isset( $_POST['owoxa_add_role'] ) && check_admin_referer( 'owoxa_role_action' ) ) {
            $role_slug = sanitize_key( $_POST['role_slug'] ?? '' );
            $role_name = sanitize_text_field( $_POST['role_name'] ?? '' );
            $caps      = isset( $_POST['capabilities'] ) ? array_map( 'sanitize_text_field', (array) $_POST['capabilities'] ) : [];

            if ( empty( $role_slug ) || empty( $role_name ) ) {
                add_settings_error( 'owoxa_roles', 'missing_fields', 'Slug et nom du rôle sont obligatoires.', 'error' );
            } else {
                $capabilities = [];
                foreach ( $caps as $cap ) {
                    $capabilities[ $cap ] = true;
                }
                if ( empty( $capabilities ) ) {
                    $capabilities = [ 'read' => true ];
                }

                $result = add_role( $role_slug, $role_name, $capabilities );

                if ( null === $result ) {
                    add_settings_error( 'owoxa_roles', 'role_exists', 'Ce rôle existe déjà.', 'error' );
                } else {
                    add_settings_error( 'owoxa_roles', 'role_added', 'Rôle ajouté avec succès.', 'success' );
                }
            }
        }

        // === Suppression d'un rôle ===
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['role'] ) ) {
            check_admin_referer( 'owoxa_delete_role_' . $_GET['role'] );

            $role = sanitize_key( $_GET['role'] );
            $protected = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];

            if ( in_array( $role, $protected, true ) ) {
                add_settings_error( 'owoxa_roles', 'protected_role', 'Impossible de supprimer un rôle système.', 'error' );
            } else {
                remove_role( $role );
                add_settings_error( 'owoxa_roles', 'role_deleted', 'Rôle supprimé.', 'success' );
            }
        }

        // === Mise à jour des capabilities d'un rôle ===
        if ( isset( $_POST['owoxa_update_caps'] ) && check_admin_referer( 'owoxa_update_caps_action' ) ) {
            $role_slug = sanitize_key( $_POST['role_slug'] ?? '' );
            $selected_caps = isset( $_POST['capabilities'] ) ? array_map( 'sanitize_text_field', (array) $_POST['capabilities'] ) : [];

            $role = get_role( $role_slug );

            if ( ! $role ) {
                add_settings_error( 'owoxa_roles', 'role_not_found', 'Rôle introuvable.', 'error' );
            } else {
                $all_caps = $this->get_all_capabilities();

                // On ajoute / retire selon les cases cochées
                foreach ( $all_caps as $cap ) {
                    if ( in_array( $cap, $selected_caps, true ) ) {
                        $role->add_cap( $cap );
                    } else {
                        $role->remove_cap( $cap );
                    }
                }

                // Gestion d'une nouvelle capability personnalisée
                if ( ! empty( $_POST['new_custom_cap'] ) ) {
                    $new_cap = sanitize_key( $_POST['new_custom_cap'] );
                    if ( $new_cap ) {
                        $role->add_cap( $new_cap );
                        add_settings_error( 'owoxa_roles', 'custom_cap_added', 'Capability personnalisée « ' . esc_html( $new_cap ) . ' » ajoutée au rôle.', 'success' );
                    }
                }

                add_settings_error( 'owoxa_roles', 'caps_updated', 'Capabilities mises à jour avec succès.', 'success' );
            }
        }
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }

        // Mode édition d'un rôle
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && ! empty( $_GET['role'] ) ) {
            $this->render_edit_capabilities_page( sanitize_key( $_GET['role'] ) );
            return;
        }

        // Page principale
        $wp_roles = wp_roles();
        $roles    = $wp_roles->roles;

        settings_errors( 'owoxa_roles' );
        ?>
        <div class="wrap">
            <h1>Gestion des Rôles Utilisateurs — OWOXA</h1>

            <h2>Ajouter un nouveau rôle</h2>
            <form method="post">
                <?php wp_nonce_field( 'owoxa_role_action' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="role_slug">Slug du rôle</label></th>
                        <td><input type="text" name="role_slug" id="role_slug" class="regular-text" required placeholder="ex: commercial"></td>
                    </tr>
                    <tr>
                        <th><label for="role_name">Nom affiché</label></th>
                        <td><input type="text" name="role_name" id="role_name" class="regular-text" required placeholder="ex: Commercial"></td>
                    </tr>
                    <tr>
                        <th>Capabilities de base</th>
                        <td>
                            <label><input type="checkbox" name="capabilities[]" value="read" checked> read</label><br>
                            <label><input type="checkbox" name="capabilities[]" value="edit_posts"> edit_posts</label><br>
                            <label><input type="checkbox" name="capabilities[]" value="publish_posts"> publish_posts</label><br>
                            <label><input type="checkbox" name="capabilities[]" value="upload_files"> upload_files</label><br>
                            <label><input type="checkbox" name="capabilities[]" value="edit_pages"> edit_pages</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Créer le rôle', 'primary', 'owoxa_add_role' ); ?>
            </form>

            <hr>

            <h2>Rôles existants</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Nom</th>
                        <th>Capabilities</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $roles as $slug => $role ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $slug ); ?></code></td>
                            <td><?php echo esc_html( $role['name'] ); ?></td>
                            <td><?php echo count( $role['capabilities'] ?? [] ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'users.php?page=owoxa-role-manager&action=edit&role=' . $slug ) ); ?>" class="button button-small">Éditer capabilities</a>

                                <?php
                                $protected = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
                                if ( ! in_array( $slug, $protected, true ) ) :
                                    $delete_url = wp_nonce_url(
                                        admin_url( 'users.php?page=owoxa-role-manager&action=delete&role=' . $slug ),
                                        'owoxa_delete_role_' . $slug
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small" onclick="return confirm('Supprimer ce rôle ?');">Supprimer</a>
                                <?php else : ?>
                                    <span class="description">Protégé</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Page d'édition des capabilities d'un rôle
     */
    private function render_edit_capabilities_page( $role_slug ) {
        $role_obj = get_role( $role_slug );
        $roles    = wp_roles()->roles;

        if ( ! $role_obj || ! isset( $roles[ $role_slug ] ) ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Rôle introuvable.</p></div></div>';
            return;
        }

        $role_name     = $roles[ $role_slug ]['name'];
        $current_caps  = $role_obj->capabilities;
        $all_caps      = $this->get_all_capabilities();

        settings_errors( 'owoxa_roles' );
        ?>
        <div class="wrap">
            <h1>Éditer les capabilities — <?php echo esc_html( $role_name ); ?> <code>(<?php echo esc_html( $role_slug ); ?>)</code></h1>

            <p><a href="<?php echo esc_url( admin_url( 'users.php?page=owoxa-role-manager' ) ); ?>" class="button">← Retour à la liste des rôles</a></p>

            <form method="post">
                <?php wp_nonce_field( 'owoxa_update_caps_action' ); ?>
                <input type="hidden" name="role_slug" value="<?php echo esc_attr( $role_slug ); ?>">

                <h2>Capabilities disponibles</h2>
                <p class="description">Cochez les capabilities que ce rôle doit posséder.</p>

                <div style="columns: 3; column-gap: 30px; margin: 20px 0;">
                    <?php foreach ( $all_caps as $cap ) : 
                        $checked = isset( $current_caps[ $cap ] ) && $current_caps[ $cap ];
                        ?>
                        <label style="display:block; margin-bottom: 6px;">
                            <input type="checkbox" name="capabilities[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( $checked ); ?>>
                            <code><?php echo esc_html( $cap ); ?></code>
                        </label>
                    <?php endforeach; ?>
                </div>

                <hr>

                <h2>Ajouter une capability personnalisée</h2>
                <p class="description">
                    Utile pour vos propres fonctionnalités (ex: <code>manage_owoxa_clients</code>, <code>view_reports</code>, etc.).<br>
                    La capability sera automatiquement ajoutée à ce rôle.
                </p>
                <p>
                    <input type="text" name="new_custom_cap" class="regular-text" placeholder="ex: manage_owoxa_clients">
                </p>

                <?php submit_button( 'Enregistrer les modifications', 'primary', 'owoxa_update_caps' ); ?>
            </form>
        </div>
        <?php
    }
}

new OWOXA_Role_Manager();