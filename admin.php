<?php
// admin.php - Página de configurações do WP Push Notify
if (!defined('ABSPATH')) {
    exit;
}

function wp_push_notify_admin_page() {
    $current_user = wp_get_current_user();
    $current_admin_email = $current_user->user_email ?? get_option('admin_email');
    $admin_email = get_option('wp_push_notify_admin_email', $current_admin_email);
    $phplist_url = get_option('wp_push_notify_phplist_url', '');
    $phplist_enabled = false;
    // Exclusão em massa de inscrições push
    if (isset($_POST['wp_push_notify_delete_selected']) && !empty($_POST['wp_push_notify_selected']) && is_array($_POST['wp_push_notify_selected'])) {
        global $wpdb;
        $table_subs = $wpdb->prefix . 'wp_push_notify_push_subs';
        $ids = array_map('intval', $_POST['wp_push_notify_selected']);
        $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table_subs WHERE id IN ($ids_placeholders)", ...$ids));
        if ($deleted) {
            echo '<div class="updated"><p>' . sprintf(_n('%d inscrição excluída.', '%d inscrições excluídas.', $deleted, 'wp-push-notify'), $deleted) . '</p></div>';
        } else {
            echo '<div class="error"><p>' . __('Nenhuma inscrição foi excluída.', 'wp-push-notify') . '</p></div>';
        }
    }
    if (isset($_POST['wp_push_notify_admin_email']) || isset($_POST['wp_push_notify_phplist_url'])) {
        $new_email = sanitize_email($_POST['wp_push_notify_admin_email']);
        if (is_email($new_email)) {
            update_option('wp_push_notify_admin_email', $new_email);
            $admin_email = $new_email;
            echo '<div class="updated"><p>Email salvo com sucesso!</p></div>';
        } else {
            echo '<div class="error"><p>Email inválido!</p></div>';
        }
    }
    // Salva as chaves VAPID se enviadas
    if (isset($_POST['wp_push_notify_vapid_public']) || isset($_POST['wp_push_notify_vapid_private'])) {
        $vapid_public = sanitize_text_field($_POST['wp_push_notify_vapid_public']);
        $vapid_private = sanitize_text_field($_POST['wp_push_notify_vapid_private']);
        update_option('wp_push_notify_vapid_public', $vapid_public);
        update_option('wp_push_notify_vapid_private', $vapid_private);
        echo '<div class="updated"><p>Chaves VAPID salvas!</p></div>';
    }
    $vapid_public = get_option('wp_push_notify_vapid_public', '');
    $vapid_private = get_option('wp_push_notify_vapid_private', '');
    ?>
    <div class="wrap">
        <h1><?php _e('WP Push Notify - Configurações', 'wp-push-notify'); ?></h1>
        <?php
        // Exibir versão do plugin e do WordPress
        $plugin_main_file = plugin_dir_path(__FILE__) . 'wp-push-notify.php';
        $plugin_data = get_file_data($plugin_main_file, array('Version' => 'Version'));
        $plugin_version = $plugin_data['Version'] ?? '';
        $wp_version = get_bloginfo('version');
        echo '<p style="margin-top:-10px;color:#666;font-size:1.1em">' . __('Versão do plugin', 'wp-push-notify') . ': <strong>' . esc_html($plugin_version) . '</strong> | ' . __('WordPress', 'wp-push-notify') . ': <strong>' . esc_html($wp_version) . '</strong></p>';
        ?>
        <form method="post">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('E-mail do Administrador', 'wp-push-notify'); ?></th>
                    <td>
                        <?php if (is_user_logged_in()) { ?>
                            <input type="email" name="wp_push_notify_admin_email" value="<?php echo esc_attr($admin_email); ?>" placeholder="<?php echo esc_attr($current_admin_email); ?>" />
                            <p class="description">
                                <?php _e('Este e-mail será usado para notificações e integração com phpList.', 'wp-push-notify'); ?><br />
                                <strong><?php printf(__('Padrão: %s (usuário logado)', 'wp-push-notify'), esc_html($current_admin_email)); ?></strong><br />
                                <?php _e('Você pode alterar e salvar outro e-mail se preferir.', 'wp-push-notify'); ?>
                            </p>
                        <?php } else {
                            $guest_email = get_option('wp_push_notify_guest_email', '');
                            if (isset($_POST['wp_push_notify_admin_email'])) {
                                $guest_email_post = sanitize_email($_POST['wp_push_notify_admin_email']);
                                if (is_email($guest_email_post)) {
                                    update_option('wp_push_notify_guest_email', $guest_email_post);
                                    $guest_email = $guest_email_post;
                                    echo '<div class="updated"><p>E-mail de visitante salvo como já informado anteriormente!</p></div>';
                                } elseif (!empty($guest_email_post)) {
                                    echo '<div class="error"><p>E-mail inválido!</p></div>';
                                }
                            }
                        ?>
                            <input type="email" name="wp_push_notify_admin_email" value="<?php echo esc_attr($guest_email); ?>" />
                            <p class="description"><?php _e('Este e-mail será usado para notificações e integração com phpList (opcional para visitantes).', 'wp-push-notify'); ?></p>
                            <?php if (!empty($guest_email)) { echo '<small>' . __('E-mail já informado anteriormente:', 'wp-push-notify') . ' ' . esc_html($guest_email) . '</small>'; } ?>
                        <?php } ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('Exibir formulário de opt-in', 'wp-push-notify'); ?></th>
                    <td>
                        <?php $form_display = get_option('wp_push_notify_form_display', 'footer'); ?>
                        <select name="wp_push_notify_form_display">
                            <option value="footer" <?php selected($form_display, 'footer'); ?>><?php _e('No rodapé (todas as páginas)', 'wp-push-notify'); ?></option>
                            <option value="shortcode" <?php selected($form_display, 'shortcode'); ?>><?php _e('Apenas via Shortcode', 'wp-push-notify'); ?></option>
                            <option value="both" <?php selected($form_display, 'both'); ?>><?php _e('Ambos (rodapé e Shortcode)', 'wp-push-notify'); ?></option>
                        </select>
                        <p class="description"><?php _e('Escolha onde o formulário de permissão/opt-in será exibido.', 'wp-push-notify'); ?></p>
                        <small><?php _e('Use o shortcode [wp_push_notify_form] para inserir manualmente.', 'wp-push-notify'); ?></small>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e('URL base do phpList', 'wp-push-notify'); ?></th>
                    <td>
                        <input type="url" name="wp_push_notify_phplist_url" value="<?php echo esc_attr($phplist_url); ?>" placeholder="https://seudominio.com/lists/api/v2/" style="width: 350px;" />
                        <p class="description">
                            <strong><?php _e('Por que precisamos da URL base do phpList?', 'wp-push-notify'); ?></strong><br>
                            <?php _e('Para integrar o WP Push Notify ao phpList, precisamos saber onde está localizada a sua instalação do phpList.', 'wp-push-notify'); ?><br>
                            <?php _e('A <b>URL base</b> é o endereço inicial da API REST do phpList, por exemplo:', 'wp-push-notify'); ?> <code>https://seudominio.com/lists/api/v2/</code>.<br>
                            <?php _e('Com ela, o plugin poderá cadastrar e gerenciar e-mails automaticamente.', 'wp-push-notify'); ?><br>
                            <b><?php _e('Se não quiser usar phpList', 'wp-push-notify'); ?></b>, <?php _e('basta deixar este campo vazio e a integração será desativada.', 'wp-push-notify'); ?>
                        </p>
                        <?php if ($phplist_enabled) { echo '<span style="color:green;">' . __('Integração ativa', 'wp-push-notify') . '</span>'; } else { echo '<span style="color:red;">' . __('Integração desativada', 'wp-push-notify') . '</span>'; } ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Salvar configurações', 'wp-push-notify')); ?>
        </form>
        <hr />
        <h2><?php _e('E-mails cadastrados localmente', 'wp-push-notify'); ?></h2>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_local_emails';
        $emails = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        if ($emails) {
            echo '<table class="widefat"><thead><tr><th>' . __('E-mail', 'wp-push-notify') . '</th><th>' . __('Data/Hora', 'wp-push-notify') . '</th></tr></thead><tbody>';
            foreach ($emails as $row) {
                echo '<tr><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->created_at) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<form method="post" style="margin-top:10px;"><button name="wp_push_notify_export_csv" value="1">' . __('Exportar CSV', 'wp-push-notify') . '</button></form>';
        } else {
            echo '<p>' . __('Nenhum e-mail cadastrado localmente.', 'wp-push-notify') . '</p>';
        }
        if (isset($_POST['wp_push_notify_export_csv'])) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="wp-push-notify-emails.csv"');
            echo "email,created_at\n";
            foreach ($emails as $row) {
                echo esc_html($row->email) . ',' . esc_html($row->created_at) . "\n";
            }
            exit;
        }
        ?>
        <hr />
        <h2>Enviar notificação Web Push</h2>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">Título</th>
                    <td><input type="text" name="wp_push_notify_push_title" required style="width:300px;" /></td>
                </tr>
                <tr>
                    <th scope="row">Mensagem</th>
                    <td><input type="text" name="wp_push_notify_push_body" required style="width:400px;" /></td>
                </tr>
                <tr>
                    <th scope="row">URL ao clicar</th>
                    <td><input type="url" name="wp_push_notify_push_url" style="width:400px;" placeholder="https://" /></td>
                </tr>
            </table>
            <button type="submit" name="wp_push_notify_send_push" value="1" class="button button-primary">Enviar Notificação</button>
        </form>
        <?php
        if (isset($_POST['wp_push_notify_send_push'])) {
            $title = sanitize_text_field($_POST['wp_push_notify_push_title'] ?? '');
            $body = sanitize_text_field($_POST['wp_push_notify_push_body'] ?? '');
            $url = esc_url_raw($_POST['wp_push_notify_push_url'] ?? '');
            $table_subs = $wpdb->prefix . 'wp_push_notify_push_subs';
            $subs = $wpdb->get_results("SELECT * FROM $table_subs");
            $success = 0; $fail = 0;
            foreach ($subs as $sub) {
                if (wp_push_notify_send_webpush($sub, $title, $body, $url)) {
                    $success++;
                } else {
                    $fail++;
                }
            }
            echo '<div class="updated"><p>Enviadas: ' . intval($success) . ' | Falhas: ' . intval($fail) . '</p></div>';
        }
        ?>
        <hr />
        <h2><?php _e('Inscrições Web Push', 'wp-push-notify'); ?></h2>
        <?php
        global $wpdb;
        $table_subs = $wpdb->prefix . 'wp_push_notify_push_subs';
        $subs = $wpdb->get_results("SELECT * FROM $table_subs ORDER BY created_at DESC LIMIT 50");
        if ($subs) {
            echo '<form method="post">';
            echo '<button type="submit" name="wp_push_notify_delete_selected" style="margin-bottom:10px;" onclick="return confirm(\'Tem certeza que deseja excluir os inscritos selecionados?\')">Excluir Selecionados</button>';
            echo '<table class="widefat"><thead><tr>';
            echo '<th><input type="checkbox" id="wp-push-notify-select-all" /></th>';
            echo '<th>' . __('Nome', 'wp-push-notify') . '</th><th>' . __('E-mail', 'wp-push-notify') . '</th><th>' . __('Endpoint', 'wp-push-notify') . '</th><th>p256dh</th><th>auth</th><th>' . __('Data/Hora', 'wp-push-notify') . '</th></tr></thead><tbody>';
            foreach ($subs as $row) {
                echo '<tr>';
                echo '<td><input type="checkbox" class="wp-push-notify-checkbox" name="wp_push_notify_selected[]" value="' . esc_attr($row->id) . '" /></td>';
                echo '<td>' . (!empty($row->name) ? esc_html($row->name) : '<span style="color:#bbb">—</span>') . '</td>';
                echo '<td>' . (!empty($row->email) ? esc_html($row->email) : '<span style="color:#bbb">—</span>') . '</td>';
                echo '<td style="word-break:break-all;max-width:350px">' . esc_html($row->endpoint) . '</td>';
                echo '<td style="word-break:break-all;max-width:220px">' . esc_html($row->p256dh) . '</td>';
                echo '<td style="word-break:break-all;max-width:120px">' . esc_html($row->auth) . '</td>';
                echo '<td>' . esc_html($row->created_at) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</form>';
            // JS para select all
            echo '<script>document.getElementById("wp-push-notify-select-all").onclick = function(){
                var cbs = document.querySelectorAll(".wp-push-notify-checkbox");
                for(var i=0;i<cbs.length;i++){cbs[i].checked=this.checked;}
            };</script>';
        } else {
            echo '<p>' . __('Nenhuma inscrição Web Push encontrada.', 'wp-push-notify') . '</p>';
        }
        ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">E-mail do Administrador</th>
                    <td>
                        <input type="email" name="wp_push_notify_admin_email" value="<?php echo esc_attr($admin_email); ?>" required />
                        <p class="description">Este e-mail será usado para notificações e integração com phpList.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">URL base do phpList</th>
                    <td>
                        <input type="url" name="wp_push_notify_phplist_url" value="<?php echo esc_attr($phplist_url); ?>" placeholder="https://seudominio.com/lists/api/v2/" style="width: 350px;" />
                        <p class="description">
                            <strong>Por que precisamos da URL base do phpList?</strong><br>
                            Para integrar o WP Push Notify ao phpList, precisamos saber onde está localizada a sua instalação do phpList.<br>
                            A <b>URL base</b> é o endereço inicial da API REST do phpList, por exemplo: <code>https://seudominio.com/lists/api/v2/</code>.<br>
                            Com ela, o plugin poderá cadastrar e gerenciar e-mails automaticamente.<br>
                            <b>Se não quiser usar phpList</b>, basta deixar este campo vazio e a integração será desativada.
                        </p>
                        <?php if ($phplist_enabled) { echo '<span style="color:green;">Integração ativa</span>'; } else { echo '<span style="color:red;">Integração desativada</span>'; } ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
