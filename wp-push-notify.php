<?php
/*
Plugin Name: WP Push Notify
Description: Plugin para notificações push e integração com phpList.
Version: 1.0.0
Author: Seu Nome
*/

// Bloqueia acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

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
        include plugin_dir_path(__FILE__) . 'admin.php';
    }

    public function activate() {
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
                <h2 style="margin-top:0">Ative as notificações</h2>
                <p style="font-size:1rem;color:#333;">Deseja receber notificações deste site? Usamos notificações para avisar sobre novidades, atualizações ou alertas importantes. Você pode desativar a qualquer momento nas configurações do navegador.</p>
                <button id="wp-push-notify-permission-btn" style="margin-top:1.2em;padding:0.7em 2em;font-size:1.1em;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;">Permitir notificações</button>
                <div id="wp-push-notify-feedback" style="margin-top:1em;font-size:0.97em;"></div>
                <button id="wp-push-notify-close-modal" style="position:absolute;top:0.7em;right:1em;background:transparent;border:none;font-size:1.5em;line-height:1;color:#888;cursor:pointer;">&times;</button>
            </div>
        </div>
        <button id="wp-push-notify-fab" style="display:none;position:fixed;top:32px;left:32px;z-index:99999;background:#0073aa;color:#fff;border:none;border-radius:50%;width:28px;height:28px;box-shadow:0 2px 8px rgba(0,0,0,0.2);font-size:1em;cursor:pointer;align-items:center;justify-content:center;" aria-label="Ativar notificações" title="Clique para ativar as notificações deste site.">🔔</button>
        <span id="wp-push-notify-fab-tooltip" style="display:none;position:fixed;top:66px;left:32px;z-index:100000;background:#222;color:#fff;padding:7px 16px;border-radius:6px;font-size:0.85em;box-shadow:0 2px 8px rgba(0,0,0,0.12);white-space:nowrap;">Clique para ativar as notificações deste site.</span>
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
                    modal.style.display = 'flex';
                    fab.style.display = 'none';
                } else if (status === 'denied') {
                    if (modalVisible) {
                        fab.style.display = 'none';
                    } else {
                        fab.style.display = 'block';
                    }
                    if (!modalVisible) {
                        modal.style.display = 'none';
                    }
                } else {
                    modal.style.display = 'none';
                    fab.style.display = 'block';
                }
            }
            // FAB reabre o modal
            fab.onclick = function() {
                modal.style.display = 'flex';
                fab.style.display = 'none';
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
                updateFabAndModal();
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
            document.getElementById('wp-push-notify-close-modal').onclick = function() {
                document.getElementById('wp-push-notify-modal-overlay').style.display = 'none';
            };

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
                        return fetch('<?php echo esc_url(rest_url('wp-push-notify/v1/subscribe')); ?>', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(sub)
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

    public function save_push_subscription($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_push_subs';
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $table (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                endpoint TEXT,
                p256dh TEXT,
                auth TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $body = $request->get_json_params();
        if (isset($body['endpoint'], $body['keys']['p256dh'], $body['keys']['auth'])) {
            $wpdb->insert($table, [
                'endpoint' => sanitize_text_field($body['endpoint']),
                'p256dh' => sanitize_text_field($body['keys']['p256dh']),
                'auth' => sanitize_text_field($body['keys']['auth'])
            ]);
            return rest_ensure_response(['success' => true]);
        }
        return rest_ensure_response(['success' => false]);
    }

    private function save_local_email($email) {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_push_notify_local_emails';
        $wpdb->query(
            $wpdb->prepare(
                "CREATE TABLE IF NOT EXISTS $table (id BIGINT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)")
        );
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
        $body = 'Novo artigo publicado: ' . $title;
        $url = get_permalink($post_ID);
        foreach ($subs as $sub) {
            wp_push_notify_send_webpush($sub, $title, $body, $url);
        }
    }
}

$wp_push_notify = new WPPushNotify();
$wp_push_notify->init_hooks();

// Exibe o formulário de opt-in em todas as páginas, antes do </body>
add_action('wp_footer', function() use ($wp_push_notify) {
    echo $wp_push_notify->render_optin_form();
});

// Inclui stub/função de envio de Web Push (substitua por implementação real em produção)
require_once plugin_dir_path(__FILE__) . 'webpush-lib-stub.php';
