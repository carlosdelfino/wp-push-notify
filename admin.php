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
        <h1>WP Push Notify - Configurações</h1>
        <div style="margin-bottom:16px;padding:10px;background:#f5f5f5;border:1px solid #ccc;">
            <strong>E-mail do administrador logado:</strong> <span style="color:#0073aa;"><?php echo esc_html($current_admin_email); ?></span><br />
            <strong>E-mail salvo no plugin:</strong> <span style="color:#333;"><?php echo esc_html($admin_email); ?></span><br />
            <small>O plugin utiliza o e-mail acima como padrão para notificações administrativas e integração com phpList.<br />Você pode alterá-lo abaixo, se desejar.</small>
        </div>
        <?php
        if (!empty($messages)) {
            foreach ($messages as $msg) {
                echo '<div class="' . esc_attr($msg['type']) . '"><p>' . esc_html($msg['text']) . '</p></div>';
            }
        }
        ?>
        <form method="post">
        </form>
        <hr />
        <h2>E-mails cadastrados localmente</h2>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_local_emails';
        $emails = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        if ($emails) {
            echo '<table class="widefat"><thead><tr><th>E-mail</th><th>Data/Hora</th></tr></thead><tbody>';
            foreach ($emails as $row) {
                echo '<tr><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->created_at) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<form method="post" style="margin-top:10px;"><button name="wp_push_notify_export_csv" value="1">Exportar CSV</button></form>';
        } else {
            echo '<p>Nenhum e-mail cadastrado localmente.</p>';
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
        <h2>Inscrições Web Push</h2>
        <?php
        $table_subs = $wpdb->prefix . 'wp_push_notify_push_subs';
        $subs = $wpdb->get_results("SELECT * FROM $table_subs ORDER BY created_at DESC LIMIT 50");
        if ($subs) {
            echo '<table class="widefat"><thead><tr><th>Endpoint</th><th>p256dh</th><th>auth</th><th>Data/Hora</th></tr></thead><tbody>';
            foreach ($subs as $row) {
                echo '<tr><td style="word-break:break-all;max-width:350px">' . esc_html($row->endpoint) . '</td><td style="word-break:break-all;max-width:220px">' . esc_html($row->p256dh) . '</td><td style="word-break:break-all;max-width:120px">' . esc_html($row->auth) . '</td><td>' . esc_html($row->created_at) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Nenhuma inscrição Web Push encontrada.</p>';
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
