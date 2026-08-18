<?php
/**
 * Plugin Name: OWOXA Role Manager
 * Description: Ajoute, modifie et supprime des rôles utilisateurs WordPress facilement.
 * Version: 1.0.0
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

    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Ajout d'un rôle
        if ( isset( $_POST['owoxa_add_role'] ) && check_admin_referer( 'owoxa_role_action' ) ) {
            $role_slug  = sanitize_key( $_POST['role_slug'] );
            $role_name  = sanitize_text_field( $_POST['role_name'] );
            $caps       = isset( $_POST['capabilities'] ) ? array_map( 'sanitize_text_field', $_POST['capabilities'] ) : [];

            $capabilities = [];
            foreach ( $caps as $cap ) {
                $capabilities[ $cap ] = true;
            }

            // Capabilities de base recommandées
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

        // Suppression d'un rôle
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['role'] ) ) {
            check_admin_referer( 'owoxa_delete_role_' . $_GET['role'] );

            $role = sanitize_key( $_GET['role'] );

            // On ne touche pas aux rôles protégés
            $protected = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
            if ( in_array( $role, $protected, true ) ) {
                add_settings_error( 'owoxa_roles', 'protected_role', 'Impossible de supprimer un rôle système.', 'error' );
            } else {
                remove_role( $role );
                add_settings_error( 'owoxa_roles', 'role_deleted', 'Rôle supprimé.', 'success' );
            }
        }
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }

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
                        <th>Capabilities (nombre)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $roles as $slug => $role ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $slug ); ?></code></td>
                            <td><?php echo esc_html( $role['name'] ); ?></td>
                            <td><?php echo count( $role['capabilities'] ); ?></td>
                            <td>
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
}

new OWOXA_Role_Manager();