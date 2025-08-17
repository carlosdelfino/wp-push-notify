<?php
/*
Plugin Name: WP Push Notify
Description: Plugin para notificações push e integração com phpList.
Version: 0.0.3
Author: Seu Nome
*/

// Bloqueia acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

// ===== GitHub Update Metadata =====
if (!defined('WPPN_GH_OWNER')) { define('WPPN_GH_OWNER', 'RapportTecnologia'); }
if (!defined('WPPN_GH_REPO'))  { define('WPPN_GH_REPO',  'wp-push-notify'); }

// ===== Update Checker (GitHub) =====
function wppn_get_github_latest_release() {
    $transient_key = 'wppn_github_latest_release';
    $cached = get_transient($transient_key);
    if ($cached) { return $cached; }
    $api = sprintf('https://api.github.com/repos/%s/%s/releases/latest', WPPN_GH_OWNER, WPPN_GH_REPO);
    $resp = wp_remote_get($api, [
        'headers' => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress-WPPN' ],
        'timeout' => 15,
    ]);
    if (is_wp_error($resp)) { return false; }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) { return false; }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body) || empty($body['tag_name'])) { return false; }
    $ver = ltrim(trim($body['tag_name']), 'vV');
    $zip = sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip', WPPN_GH_OWNER, WPPN_GH_REPO, rawurlencode($body['tag_name']));
    $result = [
        'version' => $ver,
        'tag' => $body['tag_name'],
        'zipball' => $zip,
        'html_url' => isset($body['html_url']) ? $body['html_url'] : sprintf('https://github.com/%s/%s/releases', WPPN_GH_OWNER, WPPN_GH_REPO),
    ];
    set_transient($transient_key, $result, WEEK_IN_SECONDS);
    set_transient('wppn_github_last_check', time(), WEEK_IN_SECONDS);
    return $result;
}

function wppn_inject_update_info($transient) {
    if (empty($transient) || empty($transient->checked)) { return $transient; }
    if (!function_exists('get_plugin_data')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    $plugin_file = plugin_basename(__FILE__);
    $plugin_data = get_plugin_data(__FILE__, false, false);
    $current_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';
    $release = wppn_get_github_latest_release();
    if (!$release) { return $transient; }
    if (version_compare($release['version'], $current_version, '>')) {
        $obj = (object) [
            'slug' => dirname($plugin_file),
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'package' => $release['zipball'],
            'url' => $release['html_url'],
            'tested' => '6.5',
            'requires' => '5.8',
        ];
        $transient->response[$plugin_file] = $obj;
        update_option('wppn_latest_available', $release, false);
    } else {
        delete_option('wppn_latest_available');
    }
    return $transient;
}
add_filter('pre_set_site_transient_update_plugins', 'wppn_inject_update_info');

function wppn_plugins_api($result, $action, $args) {
    if ($action !== 'plugin_information') { return $result; }
    $plugin_file = plugin_basename(__FILE__);
    if (empty($args->slug) || $args->slug !== dirname($plugin_file)) { return $result; }
    $release = wppn_get_github_latest_release();
    if (!$release) { return $result; }
    return (object) [
        'name' => 'WP Push Notify',
        'slug' => dirname($plugin_file),
        'version' => $release['version'],
        'download_link' => $release['zipball'],
        'homepage' => $release['html_url'],
        'sections' => [
            'description' => __('Plugin de notificações push com integração phpList.', 'wp-push-notify'),
            'changelog' => __('Consulte o CHANGELOG.md no repositório.', 'wp-push-notify'),
        ],
    ];
}
add_filter('plugins_api', 'wppn_plugins_api', 10, 3);

function wppn_admin_notice_new_release() {
    if (!current_user_can('update_plugins')) { return; }
    $release = get_option('wppn_latest_available');
    if (!$release || empty($release['version'])) { return; }
    if (!function_exists('get_plugin_data')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    $plugin_data = get_plugin_data(__FILE__, false, false);
    $current_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';
    if (version_compare($release['version'], $current_version, '>')) {
        $update_url = admin_url('plugins.php');
        echo '<div class="notice notice-info is-dismissible"><p>'
            . esc_html(sprintf(__('Nova versão do WP Push Notify disponível: %s (você está na %s). Atualize em Plugins.', 'wp-push-notify'), $release['version'], $current_version))
            . ' <a href="' . esc_url($release['html_url']) . '" target="_blank">' . esc_html__('Notas da versão', 'wp-push-notify') . '</a>'
            . ' | <a href="' . esc_url($update_url) . '">' . esc_html__('Ir para Plugins', 'wp-push-notify') . '</a>'
            . '</p></div>';
    } else {
        delete_option('wppn_latest_available');
    }
}
add_action('admin_notices', 'wppn_admin_notice_new_release');

function wppn_maybe_auto_update($update, $item) {
    $plugin_file = plugin_basename(__FILE__);
    if (!empty($item->plugin) && $item->plugin === $plugin_file) {
        $enabled = get_option('wppn_enable_auto_update') ? true : false;
        return $enabled;
    }
    return $update;
}
add_filter('auto_update_plugin', 'wppn_maybe_auto_update', 10, 2);

// Action links (Settings + Toggle auto-update)
function wppn_plugin_action_links($links) {
    $settings_url = add_query_arg(['page' => 'wp-push-notify'], admin_url('options-general.php'));
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Configurações', 'wp-push-notify') . '</a>';
    $enabled = (bool) get_option('wppn_enable_auto_update');
    $action = $enabled ? 'disable' : 'enable';
    $label = $enabled ? __('Desativar atualização automática', 'wp-push-notify') : __('Ativar atualização automática', 'wp-push-notify');
    $toggle_url = wp_nonce_url(admin_url('admin-post.php?action=wppn_toggle_auto_update&do=' . $action), 'wppn_toggle_auto_update', 'wppn_nonce');
    $toggle_link = '<a href="' . esc_url($toggle_url) . '">' . esc_html($label) . '</a>';
    array_unshift($links, $settings_link, $toggle_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wppn_plugin_action_links');

function wppn_toggle_auto_update() {
    if (!current_user_can('manage_options')) { wp_die(__('Sem permissão.', 'wp-push-notify')); }
    if (!isset($_GET['wppn_nonce']) || !wp_verify_nonce($_GET['wppn_nonce'], 'wppn_toggle_auto_update')) { wp_die(__('Nonce inválido.', 'wp-push-notify')); }
    $do = isset($_GET['do']) ? sanitize_key($_GET['do']) : '';
    if ($do === 'enable') { update_option('wppn_enable_auto_update', '1'); }
    elseif ($do === 'disable') { update_option('wppn_enable_auto_update', '0'); }
    wp_safe_redirect(admin_url('plugins.php'));
    exit;
}
add_action('admin_post_wppn_toggle_auto_update', 'wppn_toggle_auto_update');

class WPPushNotify {

    public function init_hooks() {
        add_shortcode('wp_push_notify_form', array($this, 'render_optin_form'));
        add_action('init', array($this, 'handle_optin_form_submission'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('parse_request', array($this, 'serve_service_worker'));
        add_action('publish_post', array($this, 'notify_push_on_new_post'), 10, 2);
    }
    public function __construct() {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
        }

        // Hooks de ativação/desativação
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function add_admin_menu() {
        add_options_page(
            'WP Push Notify',
            'WP Push Notify',
            'manage_options',
            'wp-push-notify',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        require_once plugin_dir_path(__FILE__) . 'admin.php';
if (function_exists('wp_push_notify_admin_page')) {
    wp_push_notify_admin_page();
}
    }

    /**
     * Executa tarefas de ativação do plugin: cria tabelas, gera chaves VAPID e integra com phpList.
     */
    public function activate() {
        global $wpdb;
        // Criação das tabelas necessárias
        $table1 = $wpdb->prefix . 'wp_push_notify_push_subs';
        $table2 = $wpdb->prefix . 'wp_push_notify_local_emails';
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta("CREATE TABLE IF NOT EXISTS $table1 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            endpoint TEXT,
            p256dh TEXT,
            auth TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;");
        dbDelta("CREATE TABLE IF NOT EXISTS $table2 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;");
        // Gera chaves VAPID automaticamente se não existirem
        if (!get_option('wp_push_notify_vapid_public') || !get_option('wp_push_notify_vapid_private')) {
            if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                require_once __DIR__ . '/vendor/autoload.php';
                if (class_exists('Minishlink\\WebPush\\VAPID')) {
                    $keys = Minishlink\WebPush\VAPID::createVapidKeys();
                    update_option('wp_push_notify_vapid_public', $keys['publicKey']);
                    update_option('wp_push_notify_vapid_private', $keys['privateKey']);
                }
            }
        }
        // Ao ativar, tenta integrar com phpList se a URL estiver configurada
        $phplist_url = get_option('wp_push_notify_phplist_url', '');
        $admin_email = get_option('wp_push_notify_admin_email', get_option('admin_email'));
        if (!empty($phplist_url)) {
            $this->phplist_integrate($phplist_url, $admin_email);
        }
    }

    private function phplist_integrate($phplist_url, $admin_email) {
        // 1. Checar conexão com a API
        $ping = wp_remote_get(rtrim($phplist_url, '/') . '/ping');
        if (is_wp_error($ping) || wp_remote_retrieve_response_code($ping) !== 200) {
            error_log('WP Push Notify: Não foi possível conectar à API do phpList.');
            return;
        }
        // 2. Criar categoria se não existir
        $category_name = 'wordpress notification push';
        $categories = wp_remote_get(rtrim($phplist_url, '/') . '/categories');
        $cat_id = null;
        if (!is_wp_error($categories) && wp_remote_retrieve_response_code($categories) === 200) {
            $body = json_decode(wp_remote_retrieve_body($categories), true);
            if (is_array($body)) {
                foreach ($body as $cat) {
                    if (isset($cat['name']) && $cat['name'] === $category_name) {
                        $cat_id = $cat['id'];
                        break;
                    }
                }
            }
        }
        if (!$cat_id) {
            $resp = wp_remote_post(rtrim($phplist_url, '/') . '/categories', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['name' => $category_name])
            ]);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 201) {
                $cat = json_decode(wp_remote_retrieve_body($resp), true);
                $cat_id = $cat['id'] ?? null;
            }
        }
        // 3. Adicionar e-mail do admin à categoria
        if ($cat_id && is_email($admin_email)) {
            wp_remote_post(rtrim($phplist_url, '/') . '/subscribers', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'email' => $admin_email,
                    'categories' => [$cat_id]
                ])
            ]);
        }
    }

    public function deactivate() {
        // Código para rodar na desativação
    }

    public function render_optin_form() {
        ob_start();
        $nonce = wp_create_nonce('wp_push_notify_optin');
        ?>
        <div id="wp-push-notify-modal-overlay" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999;display:none;">
            <div id="wp-push-notify-modal" style="background:#fff;padding:2rem 2.5rem;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,0.2);max-width:90vw;width:350px;text-align:center;position:relative;">
                <h2 style="margin-top:0"><?php _e('Ative as notificações', 'wp-push-notify'); ?></h2>
                <p style="font-size:1rem;color:#333;"><?php _e('Deseja receber notificações deste site? Usamos notificações para avisar sobre novidades, atualizações ou alertas importantes. Você pode desativar a qualquer momento nas configurações do navegador.', 'wp-push-notify'); ?></p>
                <input type="text" id="wp-push-notify-optin-name" placeholder="<?php _e('Seu nome (opcional)', 'wp-push-notify'); ?>" style="width:90%;margin-bottom:0.7em;padding:0.6em;font-size:1em;border:1px solid #ccc;border-radius:5px;" />
                <input type="email" id="wp-push-notify-optin-email" placeholder="<?php _e('Seu e-mail (opcional)', 'wp-push-notify'); ?>" style="width:90%;margin-bottom:0.7em;padding:0.6em;font-size:1em;border:1px solid #ccc;border-radius:5px;" />
                <button id="wp-push-notify-permission-btn" style="margin-top:1.2em;padding:0.7em 2em;font-size:1.1em;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;"><?php _e('Permitir notificações', 'wp-push-notify'); ?></button>
                <div id="wp-push-notify-feedback" style="margin-top:1em;font-size:0.97em;"></div>
                <button id="wp-push-notify-close-modal" style="position:absolute;top:0.7em;right:1em;background:transparent;border:none;font-size:1.5em;line-height:1;color:#888;cursor:pointer;">&times;</button>
            </div>
        </div>
        <button id="wp-push-notify-fab" style="display:none;position:fixed;top:32px;left:32px;z-index:99999;background:#0073aa;color:#fff;border:none;border-radius:50%;width:28px;height:28px;box-shadow:0 2px 8px rgba(0,0,0,0.2);font-size:1em;cursor:pointer;align-items:center;justify-content:center;" aria-label="<?php _e('Ativar notificações', 'wp-push-notify'); ?>" title="<?php _e('Clique para ativar as notificações deste site.', 'wp-push-notify'); ?>">🔔</button>
        <span id="wp-push-notify-fab-tooltip" style="display:none;position:fixed;top:66px;left:32px;z-index:100000;background:#222;color:#fff;padding:7px 16px;border-radius:6px;font-size:0.85em;box-shadow:0 2px 8px rgba(0,0,0,0.12);white-space:nowrap;"><?php _e('Clique para ativar as notificações deste site.', 'wp-push-notify'); ?></span>
        <script>
        (function() {
            // Utilitário para status no localStorage
            var STORAGE_KEY = 'wpPushNotifyStatus';
            var modal = document.getElementById('wp-push-notify-modal-overlay');
            var fab = document.getElementById('wp-push-notify-fab');
            // Exibe modal só se não houver status
            function updateFabAndModal() {
                var status = localStorage.getItem(STORAGE_KEY);
                var modalVisible = modal.style.display === 'flex';
                if (!status) {
                    // Primeira visita: exibe modal
                    modal.style.display = 'flex';
                    fab.style.display = 'none';
                } else if (status === 'denied') {
                    // Recusou: nunca mais mostrar automaticamente
                    modal.style.display = 'none';
                    fab.style.display = 'block';
                } else {
                    // Já aceitou: só mostra FAB
                    modal.style.display = 'none';
                    fab.style.display = 'block';
                }
            }
            // FAB reabre o modal manualmente
            fab.onclick = function() {
                modal.style.display = 'flex';
                fab.style.display = 'none';
                // Permite o usuário tentar novamente mesmo após recusa
                localStorage.removeItem(STORAGE_KEY);
            };
            // Permitir arrastar o sininho
            (function() {
                var isDragging = false, offsetX = 0, offsetY = 0;
                fab.onmousedown = function(e) {
                    isDragging = true;
                    offsetX = e.clientX - fab.offsetLeft;
                    offsetY = e.clientY - fab.offsetTop;
                    document.body.style.userSelect = 'none';
                };
                document.addEventListener('mousemove', function(e) {
                    if (isDragging) {
                        fab.style.left = (e.clientX - offsetX) + 'px';
                        fab.style.top = (e.clientY - offsetY) + 'px';
                    }
                });
                document.addEventListener('mouseup', function() {
                    isDragging = false;
                    document.body.style.userSelect = '';
                });
            })();
            // Tooltip FAB
            var fabTooltip = document.getElementById('wp-push-notify-fab-tooltip');
            fab.onmouseenter = function() {
                fabTooltip.style.display = 'block';
            };
            fab.onmouseleave = function() {
                fabTooltip.style.display = 'none';
            };

            // Fecha o modal
            document.getElementById('wp-push-notify-close-modal').onclick = function() {
                // Sempre registra como denied ao fechar sem aceitar
                localStorage.setItem(STORAGE_KEY, 'denied');
                modal.style.display = 'none';
                fab.style.display = 'block';
            };



            var vapidPublicKey = '<?php echo esc_js(get_option('wp_push_notify_vapid_public', '')); ?>';
            function urlBase64ToUint8Array(base64String) {
                const padding = '='.repeat((4 - base64String.length % 4) % 4);
                const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                const rawData = window.atob(base64);
                const outputArray = new Uint8Array(rawData.length);
                for (let i = 0; i < rawData.length; ++i) {
                    outputArray[i] = rawData.charCodeAt(i);
                }
                return outputArray;
            }
            var feedback = document.getElementById('wp-push-notify-feedback');
            var swPromise = ('serviceWorker' in navigator)
                ? navigator.serviceWorker.register('<?php echo esc_url(home_url('/wp-push-notify-service-worker.js')); ?>')
                    .then(function(reg) {
                        console.log('Service Worker registrado:', reg);
                        return reg;
                    })
                    .catch(function(err) {
                        console.error('Erro ao registrar Service Worker:', err);
                        feedback.textContent = 'Erro ao registrar Service Worker: ' + err;
                        feedback.style.color = 'red';
                        throw err;
                    })
                : Promise.reject('Service Worker não suportado');

            // Fecha o modal


            // Solicita permissão ao clicar no botão
            document.getElementById('wp-push-notify-permission-btn').onclick = function() {
                feedback.textContent = '';
                if (!('Notification' in window)) {
                    feedback.textContent = 'Este navegador não suporta notificações.';
                    feedback.style.color = 'red';
                    console.log('Notification API não suportada');
                    return;
                }
                console.log('Solicitando permissão de push...');
                feedback.textContent = 'Solicitando permissão de push...';
                feedback.style.color = 'black';
                Notification.requestPermission().then(function(permission) {
                    console.log('Permissão retornada:', permission);
                    // Salva status no localStorage
                    localStorage.setItem(STORAGE_KEY, permission);
                    if (permission !== 'granted') {
                        feedback.textContent = 'Permissão para notificações não foi concedida.';
                        feedback.style.color = 'orange';
                        fab.style.display = 'block';
                        return;
                    }
                    feedback.textContent = 'Permissão concedida, inscrevendo no push...';
                    feedback.style.color = 'black';
                    swPromise.then(function(reg) {
                        return reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                        });
                    }).then(function(sub) {
                        console.log('Subscription criada:', sub);
                        feedback.textContent = 'Inscrição criada, salvando no servidor...';
                        feedback.style.color = 'black';
                        // Salva inscrição push
                        var name = document.getElementById('wp-push-notify-optin-name').value;
                        var email = document.getElementById('wp-push-notify-optin-email').value;
                        var subWithData = Object.assign({}, sub, { name: name, email: email });
                        return fetch('<?php echo esc_url(rest_url('wp-push-notify/v1/subscribe')); ?>', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(subWithData)
                        });
                    }).then(function(resp) {
                        if (resp.ok) {
                            feedback.textContent = 'Notificações ativadas! Você receberá avisos deste site.';
                            feedback.style.color = 'green';
                            console.log('Inscrição salva no servidor com sucesso');
                            setTimeout(function(){
                                modal.style.display = 'none';
                                fab.style.display = 'none';
                            }, 1500);
                        } else {
                            feedback.textContent = 'Erro ao salvar inscrição push.';
                            feedback.style.color = 'red';
                            console.error('Erro ao salvar inscrição push:', resp.status, resp.statusText);
                        }
                    }).catch(function(err) {
                        feedback.textContent = 'Erro: ' + err;
                        feedback.style.color = 'red';
                        console.error('Erro geral no fluxo de inscrição:', err);
                    });
                });
            };
            // Exibe modal ou FAB conforme status
            document.addEventListener('DOMContentLoaded', function() {
                // Pequeno delay para garantir leitura correta do localStorage
                setTimeout(updateFabAndModal, 10);
            });

        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function handle_optin_form_submission() {
        if (
            isset($_POST['wp_push_notify_optin_email'], $_POST['wp_push_notify_optin_nonce']) &&
            is_string($_POST['wp_push_notify_optin_email']) &&
            is_string($_POST['wp_push_notify_optin_nonce']) &&
            wp_verify_nonce($_POST['wp_push_notify_optin_nonce'], 'wp_push_notify_optin')
        ) {
            $email = trim(sanitize_email($_POST['wp_push_notify_optin_email']));
            if (is_email($email)) {
                // Previne múltiplos cadastros duplicados
                if ($this->email_already_registered($email)) {
                    add_action('wp_footer', function() {
                        echo '<div class="wp-push-notify-error" style="color:orange;">Este e-mail já está cadastrado.</div>';
                    });
                    return;
                }
                $phplist_url = get_option('wp_push_notify_phplist_url', '');
                if (!empty($phplist_url)) {
                    // Integrar com phpList
                    $this->phplist_register_visitor($phplist_url, $email);
                } else {
                    // Salvar localmente
                    $this->save_local_email($email);
                }
                add_action('wp_footer', function() {
                    echo '<div class="wp-push-notify-success" style="color:green;">Inscrição realizada com sucesso!</div>';
                });
            } else {
                add_action('wp_footer', function() {
                    echo '<div class="wp-push-notify-error" style="color:red;">E-mail inválido!</div>';
                });
            }
        }
    }

    private function email_already_registered($email) {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_local_emails';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s", $email));
        return $exists > 0;
    }

    private function phplist_register_visitor($phplist_url, $email) {
        // Buscar categoria
        $categories = wp_remote_get(rtrim($phplist_url, '/') . '/categories');
        $cat_id = null;
        if (!is_wp_error($categories) && wp_remote_retrieve_response_code($categories) === 200) {
            $body = json_decode(wp_remote_retrieve_body($categories), true);
            if (is_array($body)) {
                foreach ($body as $cat) {
                    if (isset($cat['name']) && $cat['name'] === 'wordpress notification push') {
                        $cat_id = $cat['id'];
                        break;
                    }
                }
            }
        }
        if ($cat_id) {
            wp_remote_post(rtrim($phplist_url, '/') . '/subscribers', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'email' => $email,
                    'categories' => [$cat_id]
                ])
            ]);
        }
    }

    public function enqueue_scripts() {
        // scripts já estão injetados inline no formulário
    }

    public function serve_service_worker(
        $wp = null
    ) {
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-push-notify-service-worker.js') !== false) {
            header('Content-Type: application/javascript');
            readfile(plugin_dir_path(__FILE__) . 'service-worker.js');
            exit;
        }
    }

    public function register_rest_routes() {
        register_rest_route('wp-push-notify/v1', '/subscribe', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_push_subscription'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Salva uma inscrição de push recebida via REST API.
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function save_push_subscription($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_push_subs';
        $body = $request->get_json_params();
        if (isset($body['endpoint'], $body['keys']['p256dh'], $body['keys']['auth'])) {
            $data = [
                'endpoint' => sanitize_text_field($body['endpoint']),
                'p256dh' => sanitize_text_field($body['keys']['p256dh']),
                'auth' => sanitize_text_field($body['keys']['auth'])
            ];
            if (!empty($body['name'])) {
                $data['name'] = sanitize_text_field($body['name']);
            }
            if (!empty($body['email'])) {
                $data['email'] = sanitize_email($body['email']);
            }
            // Adiciona coluna 'name' e 'email' se não existirem ainda
            $columns = $wpdb->get_col("DESC $table", 0);
            if (!in_array('name', $columns)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN name VARCHAR(191) NULL");
            }
            if (!in_array('email', $columns)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN email VARCHAR(191) NULL");
            }
            $wpdb->insert($table, $data);
            return rest_ensure_response(['success' => true]);
        }
        return rest_ensure_response(['success' => false]);
    }

    /**
     * Salva um e-mail localmente na tabela do plugin.
     * @param string $email
     */
    private function save_local_email($email) {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_local_emails';
        $wpdb->insert($table, [ 'email' => $email ]);
    }
    /**
     * Envia push notification para todos os inscritos ao publicar novo post
     */
    public function notify_push_on_new_post($post_ID, $post) {
        if ($post->post_status !== 'publish') return;
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_push_subs';
        $subs = $wpdb->get_results("SELECT * FROM $table");
        if (!$subs) return;
        $title = get_the_title($post_ID);
        $url = get_permalink($post_ID);
        foreach ($subs as $sub) {
            $body = 'Novo artigo publicado: ' . $title;
            if (!empty($sub->name)) {
                $body = $sub->name . ', há um novo artigo para você: ' . $title;
            }
            wp_push_notify_send_webpush($sub, $title, $body, $url);
        }
    }
}

$wp_push_notify = new WPPushNotify();
$wp_push_notify->init_hooks();

// Exibe o formulário de opt-in conforme configuração do usuário
$form_display = get_option('wp_push_notify_form_display', 'footer');
if ($form_display === 'footer') {
    add_action('wp_footer', function() use ($wp_push_notify) {
        echo $wp_push_notify->render_optin_form();
    });
}

// Inclui stub/função de envio de Web Push (substitua por implementação real em produção)
require_once plugin_dir_path(__FILE__) . 'webpush-lib-stub.php';
