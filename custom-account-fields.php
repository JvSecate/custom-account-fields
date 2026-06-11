<?php
/**
 * Plugin Name: Custom Account Fields
 * Description: Adds configurable customer account fields for WooCommerce registration, account editing, and admin user profiles.
 * Version: 0.3.2
 * Author: Jv Secate
 * Requires Plugins: woocommerce
 * Text Domain: custom-account-fields
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

final class Custom_Account_Fields_Plugin {
    private const OPTION_NAME    = 'custom_account_fields';
    private const VERSION_OPTION = 'custom_account_fields_version';
    private const NONCE_ACTION   = 'custom_account_fields_save';
    private const NONCE_NAME     = 'custom_account_fields_nonce';
    private const VERSION        = '0.3.2';

    private static ?self $instance = null;

    /** @return self */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', [$this, 'maybe_migrate']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        add_action('user_register', [$this, 'apply_defaults_to_user']);

        add_action('woocommerce_register_form', [$this, 'render_registration_fields']);
        add_filter('woocommerce_registration_errors', [$this, 'validate_registration_fields'], 10, 3);
        add_action('woocommerce_created_customer', [$this, 'save_registration_fields']);

        add_action('woocommerce_edit_account_form', [$this, 'render_account_fields']);
        add_action('woocommerce_save_account_details_errors', [$this, 'validate_account_fields'], 10, 2);
        add_action('woocommerce_save_account_details', [$this, 'save_account_fields']);

        add_action('show_user_profile', [$this, 'render_admin_profile_fields']);
        add_action('edit_user_profile', [$this, 'render_admin_profile_fields']);
        add_action('user_profile_update_errors', [$this, 'validate_admin_profile_fields'], 10, 3);
        add_action('personal_options_update', [$this, 'save_admin_profile_fields']);
        add_action('edit_user_profile_update', [$this, 'save_admin_profile_fields']);
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('custom-account-fields', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    // -------------------------------------------------------------------------
    // Activation and migration
    // -------------------------------------------------------------------------

    public static function activate(): void {
        load_plugin_textdomain('custom-account-fields', false, dirname(plugin_basename(__FILE__)) . '/languages');

        $fields = self::load_default_fields_from_json();
        if (get_option(self::OPTION_NAME, null) === null) {
            add_option(self::OPTION_NAME, $fields);
        }

        update_option(self::VERSION_OPTION, self::VERSION);
        self::apply_missing_defaults_to_all_users($fields);
    }

    public function maybe_migrate(): void {
        if (get_option(self::VERSION_OPTION, '') === self::VERSION) {
            return;
        }

        $existing = get_option(self::OPTION_NAME, null);
        $fields = [];

        if (is_array($existing)) {
            foreach ($existing as $field) {
                $normalized = $this->normalize_field(is_array($field) ? $field : []);
                if ($normalized !== null) {
                    $fields[] = $normalized;
                }
            }
        }

        $fields = $this->merge_missing_fields($fields, self::load_default_fields_from_json());

        update_option(self::OPTION_NAME, $fields);
        update_option(self::VERSION_OPTION, self::VERSION);
        self::apply_missing_defaults_to_all_users($fields);
    }

    /** @return array<int,array<string,mixed>> */
    private static function load_default_fields_from_json(): array {
        $file = __DIR__ . '/config/default-fields.json';
        if (!is_readable($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $fields = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : $decoded;
        if (!is_array($fields)) {
            return [];
        }

        return array_values(array_filter($fields, 'is_array'));
    }

    /** @param array<int,array<string,mixed>> $fields @param array<int,array<string,mixed>> $defaults @return array<int,array<string,mixed>> */
    private function merge_missing_fields(array $fields, array $defaults): array {
        $keys = [];
        foreach ($fields as $field) {
            $keys[(string) ($field['key'] ?? '')] = true;
        }

        foreach ($defaults as $default) {
            $normalized = $this->normalize_field($default);
            if ($normalized === null) {
                continue;
            }
            if (!isset($keys[$normalized['key']])) {
                $fields[] = $normalized;
                $keys[$normalized['key']] = true;
            }
        }

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Field normalisation
    // -------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function get_fields(): array {
        $fields = get_option(self::OPTION_NAME, []);

        if (!is_array($fields)) {
            return [];
        }

        return array_values(array_filter(array_map([$this, 'normalize_field'], $fields)));
    }

    /** @param mixed $field @return array<string,mixed>|null */
    private function normalize_field($field): ?array {
        if (!is_array($field)) {
            return null;
        }

        $key   = isset($field['key'])   ? sanitize_key((string) $field['key'])         : '';
        $label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';

        if ($key === '' || $label === '') {
            return null;
        }

        $allowed_types = ['text', 'email', 'tel', 'number', 'date', 'textarea', 'select', 'checkbox'];
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : 'text';
        if (!in_array($type, $allowed_types, true)) {
            $type = 'text';
        }

        $allowed_validations = ['', 'phone_br', 'cpf', 'cnpj', 'cpf_cnpj', 'email', 'url', 'cep', 'custom'];
        $validation = isset($field['validation']) ? sanitize_key((string) $field['validation']) : '';
        if (!in_array($validation, $allowed_validations, true)) {
            $validation = '';
        }

        $normalized = [
            'key'                => $key,
            'label'              => $label,
            'type'               => $type,
            'required'           => !empty($field['required']) ? '1' : '0',
            'register'           => !empty($field['register']) ? '1' : '0',
            'account'            => !empty($field['account'])  ? '1' : '0',
            'admin'              => array_key_exists('admin', $field) ? (!empty($field['admin']) ? '1' : '0') : '1',
            'default_value'      => isset($field['default_value']) ? (string) $field['default_value'] : '',
            'placeholder'        => isset($field['placeholder']) ? sanitize_text_field((string) $field['placeholder']) : '',
            'autocomplete'       => isset($field['autocomplete']) ? sanitize_text_field((string) $field['autocomplete']) : '',
            'options'            => isset($field['options']) ? sanitize_textarea_field((string) $field['options']) : '',
            'validation'         => $validation,
            'validation_regex'   => isset($field['validation_regex']) ? sanitize_text_field((string) $field['validation_regex']) : '',
            'validation_message' => isset($field['validation_message']) ? sanitize_text_field((string) $field['validation_message']) : '',
        ];

        $normalized['default_value'] = $this->sanitize_field_value($normalized, $normalized['default_value']);

        return $normalized;
    }

    /** @return array<int,array<string,mixed>> */
    private function fields_for_location(string $location): array {
        return array_values(array_filter($this->get_fields(), static function (array $field) use ($location): bool {
            return !empty($field[$location]) && $field[$location] === '1';
        }));
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    private function validate_field_value(array $field, string $value): string {
        $rule       = (string) ($field['validation'] ?? '');
        $custom_msg = trim((string) ($field['validation_message'] ?? ''));

        if ($rule === '' || $value === '') {
            return '';
        }

        $label = esc_html((string) $field['label']);
        $ok = true;

        switch ($rule) {
            case 'phone_br':
                $digits = preg_replace('/\D/', '', $value);
                $ok = $digits !== null && strlen($digits) >= 10 && strlen($digits) <= 13;
                if (!$ok && $custom_msg === '') {
                    $custom_msg = sprintf(__('%s must be a valid phone number (example: (11) 91234-5678).', 'custom-account-fields'), $label);
                }
                break;

            case 'cpf':
                $ok = $this->validate_cpf(preg_replace('/\D/', '', $value) ?? '');
                if (!$ok && $custom_msg === '') {
                    $custom_msg = sprintf(__('%s must be a valid CPF.', 'custom-account-fields'), $label);
                }
                break;

            case 'cnpj':
                $ok = $this->validate_cnpj(preg_replace('/\D/', '', $value) ?? '');
                if (!$ok && $custom_msg === '') {
                    $custom_msg = sprintf(__('%s must be a valid CNPJ.', 'custom-account-fields'), $label);
                }
                break;

            case 'cpf_cnpj':
                $digits = preg_replace('/\D/', '', $value) ?? '';
                $ok = (strlen($digits) === 11 && $this->validate_cpf($digits))
                   || (strlen($digits) === 14 && $this->validate_cnpj($digits));
                if (!$ok && $custom_msg === '') {
                    $custom_msg = sprintf(__('%s must be a valid CPF or CNPJ.', 'custom-account-fields'), $label);
                }
                break;

            case 'email':
                $ok = is_email($value) !== false;
                if (!$ok && $custom_msg === '') {
                    $custom_msg = sprintf(__('%s must be a valid email address.', 'custom-account-fields'), $label);
                }
                break;

            case 'url':
                $ok = filter_var($value, FILTER_VALIDATE_URL) !== false;
                if (!$ok && $custom_msg === '') {
                    $custom_msg = sprintf(__('%s must be a valid URL.', 'custom-account-fields'), $label);
                }
                break;

            case 'cep':
                $digits = preg_replace('/\D/', '', $value) ?? '';
                $ok = strlen($digits) === 8;
                if (!$ok && $custom_msg === '') {
                    $custom_msg = sprintf(__('%s must be a valid postal code (example: 01310-100).', 'custom-account-fields'), $label);
                }
                break;

            case 'custom':
                $regex = trim((string) ($field['validation_regex'] ?? ''));
                if ($regex !== '') {
                    $ok = @preg_match($regex, $value) === 1;
                    if (!$ok && $custom_msg === '') {
                        $custom_msg = sprintf(__('%s has an invalid format.', 'custom-account-fields'), $label);
                    }
                }
                break;
        }

        return $ok ? '' : ($custom_msg !== '' ? $custom_msg : sprintf(__('%s is invalid.', 'custom-account-fields'), $label));
    }

    private function validate_cpf(string $digits): bool {
        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $digits[$i] * (10 - $i);
        }
        $r = $sum % 11;
        if ((int) $digits[9] !== ($r < 2 ? 0 : 11 - $r)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $digits[$i] * (11 - $i);
        }
        $r = $sum % 11;

        return (int) $digits[10] === ($r < 2 ? 0 : 11 - $r);
    }

    private function validate_cnpj(string $digits): bool {
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }

        $calc = static function (string $digits, int $len): int {
            $sum = 0;
            $pos = $len - 7;
            for ($i = $len; $i >= 1; $i--) {
                $sum += (int) $digits[$len - $i] * $pos--;
                if ($pos < 2) {
                    $pos = 9;
                }
            }
            $r = $sum % 11;
            return $r < 2 ? 0 : 11 - $r;
        };

        return (int) $digits[12] === $calc($digits, 12)
            && (int) $digits[13] === $calc($digits, 13);
    }

    // -------------------------------------------------------------------------
    // Admin settings
    // -------------------------------------------------------------------------

    public function register_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Account Fields', 'custom-account-fields'),
            __('Account Fields', 'custom-account-fields'),
            'manage_woocommerce',
            'custom-account-fields',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_frontend_assets(): void {
        wp_enqueue_style(
            'custom-account-fields-frontend',
            plugin_dir_url(__FILE__) . 'assets/custom-account-fields-frontend.css',
            [],
            self::VERSION
        );

        if (function_exists('is_account_page') && !is_account_page()) {
            return;
        }

        wp_enqueue_script(
            'custom-account-fields-frontend',
            plugin_dir_url(__FILE__) . 'assets/custom-account-fields-frontend.js',
            [],
            self::VERSION,
            true
        );

        wp_localize_script('custom-account-fields-frontend', 'customAccountFieldsValidation', [
            'messages' => [
                'required' => __('Campo obrigatório.', 'custom-account-fields'),
                'email'    => __('Informe um e-mail válido.', 'custom-account-fields'),
                'url'      => __('Informe uma URL válida.', 'custom-account-fields'),
                'tel'      => __('Informe um telefone válido.', 'custom-account-fields'),
                'number'   => __('Informe um número válido.', 'custom-account-fields'),
                'date'     => __('Informe uma data válida.', 'custom-account-fields'),
                'cpf'      => __('Informe um CPF válido.', 'custom-account-fields'),
                'cnpj'     => __('Informe um CNPJ válido.', 'custom-account-fields'),
                'cpf_cnpj' => __('Informe um CPF ou CNPJ válido.', 'custom-account-fields'),
                'cep'      => __('Informe um CEP válido.', 'custom-account-fields'),
                'custom'   => __('Formato inválido.', 'custom-account-fields'),
                'invalid'  => __('Verifique este campo.', 'custom-account-fields'),
            ],
        ]);
    }

    public function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'woocommerce_page_custom-account-fields') {
            return;
        }

        wp_enqueue_style(
            'custom-account-fields-admin',
            plugin_dir_url(__FILE__) . 'assets/custom-account-fields-admin.css',
            [],
            self::VERSION
        );

        wp_add_inline_script('jquery', $this->admin_js());
    }

    private function admin_js(): string {
        return <<<'JS'
(function () {
    function updateCardTitle(card) {
        var keyEl   = card.querySelector('[data-caf-key]');
        var labelEl = card.querySelector('[data-caf-label]');
        var typeEl  = card.querySelector('[data-caf-type]');
        var titleEl = card.querySelector('.caf-card-title');
        var metaEl  = card.querySelector('.caf-card-meta');
        if (!titleEl) return;
        var lbl = (labelEl && labelEl.value) ? labelEl.value : ((keyEl && keyEl.value) ? keyEl.value : cafData.newField);
        titleEl.textContent = lbl;
        if (metaEl && typeEl) {
            metaEl.textContent = typeEl.value;
        }
    }

    function toggleRegexRow(card) {
        var validEl = card.querySelector('[data-caf-validation]');
        var regexRow = card.querySelector('.caf-regex-row');
        if (!validEl || !regexRow) return;
        regexRow.classList.toggle('visible', validEl.value === 'custom');
    }

    function bindCard(card) {
        var header = card.querySelector('.caf-field-card-header');
        if (header) {
            header.addEventListener('click', function (e) {
                if (e.target.closest('button, input, select, textarea, a')) return;
                card.classList.toggle('is-collapsed');
            });
        }

        card.querySelectorAll('[data-caf-key],[data-caf-label],[data-caf-type],[data-caf-validation]').forEach(function (el) {
            el.addEventListener('change', function () {
                updateCardTitle(card);
                toggleRegexRow(card);
            });
            el.addEventListener('input', function () { updateCardTitle(card); });
        });

        var removeBtn = card.querySelector('[data-caf-remove]');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (confirm(cafData.confirmRemove)) { card.remove(); }
            });
        }
        toggleRegexRow(card);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var list   = document.getElementById('caf-fields-list');
        var addBtn = document.getElementById('caf-add-field');
        var tmpl   = document.getElementById('caf-field-template');

        if (!list || !addBtn || !tmpl) return;

        list.querySelectorAll('.caf-field-card').forEach(bindCard);

        addBtn.addEventListener('click', function () {
            var idx = Date.now().toString();
            var div = document.createElement('div');
            div.innerHTML = tmpl.innerHTML.replace(/__IDX__/g, idx);
            var card = div.firstElementChild;
            list.appendChild(card);
            bindCard(card);
            card.scrollIntoView({ behavior:'smooth', block:'start' });
        });

        var dragging = null;
        list.addEventListener('dragstart', function (e) {
            dragging = e.target.closest('.caf-field-card');
            if (dragging) { setTimeout(function(){ dragging.style.opacity = '.4'; }, 0); }
        });
        list.addEventListener('dragend', function () {
            if (dragging) { dragging.style.opacity = ''; dragging = null; }
        });
        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            var over = e.target.closest('.caf-field-card');
            if (over && dragging && over !== dragging) {
                var rect = over.getBoundingClientRect();
                if (e.clientY < rect.top + rect.height / 2) { list.insertBefore(dragging, over); }
                else { list.insertBefore(dragging, over.nextSibling); }
            }
        });
    });
}());
JS;
    }

    public function handle_admin_save(): void {
        if (!is_admin() || (!isset($_POST['custom_account_fields_submit']) && !isset($_POST['custom_account_fields_import_submit']))) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage account fields.', 'custom-account-fields'));
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (isset($_POST['custom_account_fields_import_submit'])) {
            $fields = $this->read_imported_fields();
            if ($fields === []) {
                $this->redirect_admin(['caf_error' => 'import']);
            }

            update_option(self::OPTION_NAME, $fields);
            self::apply_missing_defaults_to_all_users($fields);
            $this->redirect_admin(['imported' => '1']);
        }

        $raw_fields = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : [];
        $fields = [];

        foreach ($raw_fields as $raw_field) {
            $normalized = $this->normalize_field(is_array($raw_field) ? $raw_field : []);
            if ($normalized !== null) {
                $fields[] = $normalized;
            }
        }

        update_option(self::OPTION_NAME, $fields);
        self::apply_missing_defaults_to_all_users($fields);
        $this->redirect_admin(['updated' => '1']);
    }

    /** @return array<int,array<string,mixed>> */
    private function read_imported_fields(): array {
        if (empty($_FILES['custom_account_fields_json']['tmp_name']) || !is_uploaded_file($_FILES['custom_account_fields_json']['tmp_name'])) {
            return [];
        }

        $name = isset($_FILES['custom_account_fields_json']['name']) ? sanitize_file_name((string) $_FILES['custom_account_fields_json']['name']) : '';
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
            return [];
        }

        $contents = file_get_contents((string) $_FILES['custom_account_fields_json']['tmp_name']);
        if ($contents === false || strlen($contents) > 1048576) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $raw_fields = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : $decoded;
        $fields = [];

        foreach ($raw_fields as $raw_field) {
            $normalized = $this->normalize_field(is_array($raw_field) ? $raw_field : []);
            if ($normalized !== null) {
                $fields[] = $normalized;
            }
        }

        return $fields;
    }

    /** @param array<string,string> $args */
    private function redirect_admin(array $args): void {
        wp_safe_redirect(add_query_arg(array_merge(['page' => 'custom-account-fields'], $args), admin_url('admin.php')));
        exit;
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $fields = $this->get_fields();
        $types = ['text', 'email', 'tel', 'number', 'date', 'textarea', 'select', 'checkbox'];
        $validations = [
            ''         => __('None', 'custom-account-fields'),
            'phone_br' => __('Brazilian phone', 'custom-account-fields'),
            'cpf'      => __('CPF', 'custom-account-fields'),
            'cnpj'     => __('CNPJ', 'custom-account-fields'),
            'cpf_cnpj' => __('CPF / CNPJ', 'custom-account-fields'),
            'email'    => __('Email', 'custom-account-fields'),
            'url'      => __('URL', 'custom-account-fields'),
            'cep'      => __('CEP', 'custom-account-fields'),
            'custom'   => __('Custom regex', 'custom-account-fields'),
        ];
        ?>
        <div class="wrap" id="caf-wrap">
            <div class="caf-header">
                <h1><?php esc_html_e('Account Fields', 'custom-account-fields'); ?></h1>
                <span class="caf-badge">v<?php echo esc_html(self::VERSION); ?></span>
            </div>
            <p class="caf-desc"><?php esc_html_e('Configure account fields for registration, account editing, and admin user profiles.', 'custom-account-fields'); ?></p>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fields saved successfully. Missing user values were filled with each field default.', 'custom-account-fields'); ?></p></div>
            <?php elseif (isset($_GET['imported'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fields imported successfully. Missing user values were filled with each field default.', 'custom-account-fields'); ?></p></div>
            <?php elseif (isset($_GET['caf_error'])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('The JSON import failed. Check that the file is valid JSON and contains field definitions.', 'custom-account-fields'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                <div id="caf-fields-list">
                    <?php foreach ($fields as $index => $field) : ?>
                        <?php $this->render_admin_card($index, $field, $types, $validations); ?>
                    <?php endforeach; ?>
                </div>

                <div class="caf-footer">
                    <button type="button" class="button" id="caf-add-field">+ <?php esc_html_e('Add field', 'custom-account-fields'); ?></button>
                    <button type="submit" class="button button-primary" name="custom_account_fields_submit" value="1"><?php esc_html_e('Save fields', 'custom-account-fields'); ?></button>
                    <span class="caf-key-hint"><?php esc_html_e('Fields not enabled for registration or account editing stay private to the admin user profile UI.', 'custom-account-fields'); ?></span>
                </div>

                <div class="caf-import-box">
                    <h2><?php esc_html_e('Import fields from JSON', 'custom-account-fields'); ?></h2>
                    <p><?php esc_html_e('Import replaces the current field configuration. Existing user values are not overwritten; missing values receive the imported default value.', 'custom-account-fields'); ?></p>
                    <input type="file" name="custom_account_fields_json" accept="application/json,.json" />
                    <button type="submit" class="button" name="custom_account_fields_import_submit" value="1"><?php esc_html_e('Import JSON', 'custom-account-fields'); ?></button>
                </div>
            </form>
        </div>

        <template id="caf-field-template">
            <?php $this->render_admin_card('__IDX__', [
                'key'                => '',
                'label'              => '',
                'type'               => 'text',
                'required'           => '0',
                'register'           => '0',
                'account'            => '0',
                'admin'              => '1',
                'default_value'      => '',
                'placeholder'        => '',
                'autocomplete'       => '',
                'options'            => '',
                'validation'         => '',
                'validation_regex'   => '',
                'validation_message' => '',
            ], $types, $validations); ?>
        </template>

        <script>
        var cafData = <?php echo wp_json_encode([
            'confirmRemove' => __('Remove this field?', 'custom-account-fields'),
            'newField'      => __('(new field)', 'custom-account-fields'),
        ]); ?>;
        </script>
        <?php
    }

    /** @param int|string $index */
    private function render_admin_card($index, array $field, array $types, array $validations): void {
        $n = 'fields[' . esc_attr((string) $index) . ']';
        $label = $field['label'] !== '' ? $field['label'] : ($field['key'] !== '' ? $field['key'] : esc_html__('(new field)', 'custom-account-fields'));
        $meta = esc_html($field['type'] ?? 'text');
        ?>
        <div class="caf-field-card" draggable="true">
            <div class="caf-field-card-header">
                <span class="caf-drag dashicons dashicons-menu" title="<?php esc_attr_e('Drag', 'custom-account-fields'); ?>"></span>
                <span class="caf-card-title"><?php echo esc_html($label); ?></span>
                <span class="caf-card-meta"><?php echo esc_html($meta); ?></span>
                <span class="caf-toggle dashicons dashicons-arrow-down-alt2"></span>
                <button type="button" class="button-link button-link-delete" data-caf-remove title="<?php esc_attr_e('Remove field', 'custom-account-fields'); ?>"><span class="dashicons dashicons-trash"></span></button>
            </div>

            <div class="caf-field-card-body">
                <div class="caf-form-group">
                    <label><?php esc_html_e('Key (meta key)', 'custom-account-fields'); ?> <span class="caf-tip" data-tip="<?php esc_attr_e("Unique identifier used to save the user meta value. Example: is_affiliated.", 'custom-account-fields'); ?>">&#9432;</span></label>
                    <input type="text" name="<?php echo $n; ?>[key]" value="<?php echo esc_attr((string) $field['key']); ?>" placeholder="is_affiliated" data-caf-key />
                </div>

                <div class="caf-form-group">
                    <label><?php esc_html_e('Label', 'custom-account-fields'); ?></label>
                    <input type="text" name="<?php echo $n; ?>[label]" value="<?php echo esc_attr((string) $field['label']); ?>" placeholder="<?php esc_attr_e('Affiliated', 'custom-account-fields'); ?>" data-caf-label />
                </div>

                <div class="caf-form-group">
                    <label><?php esc_html_e('Type', 'custom-account-fields'); ?></label>
                    <select name="<?php echo $n; ?>[type]" data-caf-type>
                        <?php foreach ($types as $type) : ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($field['type'] ?? 'text', $type); ?>><?php echo esc_html($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="caf-form-group">
                    <label><?php esc_html_e('Default value', 'custom-account-fields'); ?> <span class="caf-tip" data-tip="<?php esc_attr_e('Applied to new users and to existing users that do not yet have this meta key. For checkboxes, use 1 or 0.', 'custom-account-fields'); ?>">&#9432;</span></label>
                    <input type="text" name="<?php echo $n; ?>[default_value]" value="<?php echo esc_attr((string) ($field['default_value'] ?? '')); ?>" placeholder="0" />
                </div>

                <div class="caf-form-group">
                    <label><?php esc_html_e('Validation', 'custom-account-fields'); ?></label>
                    <select name="<?php echo $n; ?>[validation]" data-caf-validation>
                        <?php foreach ($validations as $value => $label_text) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($field['validation'] ?? '', $value); ?>><?php echo esc_html($label_text); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="caf-form-group caf-regex-row<?php echo ($field['validation'] ?? '') === 'custom' ? ' visible' : ''; ?>">
                    <label><?php esc_html_e('Regex', 'custom-account-fields'); ?></label>
                    <input type="text" name="<?php echo $n; ?>[validation_regex]" value="<?php echo esc_attr((string) ($field['validation_regex'] ?? '')); ?>" placeholder="/^[A-Z]{2}\d{5}$/" />
                </div>

                <div class="caf-form-group">
                    <label><?php esc_html_e('Error message', 'custom-account-fields'); ?></label>
                    <input type="text" name="<?php echo $n; ?>[validation_message]" value="<?php echo esc_attr((string) ($field['validation_message'] ?? '')); ?>" />
                </div>

                <div class="caf-form-group">
                    <label><?php esc_html_e('Placeholder', 'custom-account-fields'); ?></label>
                    <input type="text" name="<?php echo $n; ?>[placeholder]" value="<?php echo esc_attr((string) $field['placeholder']); ?>" />
                </div>

                <div class="caf-form-group">
                    <label><?php esc_html_e('Autocomplete', 'custom-account-fields'); ?></label>
                    <input type="text" name="<?php echo $n; ?>[autocomplete]" value="<?php echo esc_attr((string) $field['autocomplete']); ?>" />
                </div>

                <div class="caf-form-group span-2">
                    <label><?php esc_html_e('Select options', 'custom-account-fields'); ?></label>
                    <textarea name="<?php echo $n; ?>[options]" rows="3" placeholder="small|Small&#10;large|Large"><?php echo esc_textarea((string) $field['options']); ?></textarea>
                </div>

                <div class="caf-form-group span-3">
                    <label><?php esc_html_e('Display in', 'custom-account-fields'); ?></label>
                    <div class="caf-checks">
                        <label><input type="checkbox" name="<?php echo $n; ?>[required]" value="1" <?php checked($field['required'] ?? '0', '1'); ?> /> <?php esc_html_e('Required', 'custom-account-fields'); ?></label>
                        <label><input type="checkbox" name="<?php echo $n; ?>[register]" value="1" <?php checked($field['register'] ?? '0', '1'); ?> /> <?php esc_html_e('Registration', 'custom-account-fields'); ?></label>
                        <label><input type="checkbox" name="<?php echo $n; ?>[account]" value="1" <?php checked($field['account'] ?? '0', '1'); ?> /> <?php esc_html_e('Account editing', 'custom-account-fields'); ?></label>
                        <label><input type="checkbox" name="<?php echo $n; ?>[admin]" value="1" <?php checked($field['admin'] ?? '0', '1'); ?> /> <?php esc_html_e('Admin user profile', 'custom-account-fields'); ?></label>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Frontend rendering
    // -------------------------------------------------------------------------

    public function render_registration_fields(): void {
        foreach ($this->fields_for_location('register') as $field) {
            $this->render_frontend_field($field, 'register');
        }
    }

    public function render_account_fields(): void {
        $customer_id = get_current_user_id();
        if (!$customer_id) {
            return;
        }

        echo '<fieldset class="custom-account-extra-fields"><legend>' . esc_html__('Additional information', 'custom-account-fields') . '</legend>';

        foreach ($this->fields_for_location('account') as $field) {
            $this->render_frontend_field($field, 'account', get_user_meta($customer_id, (string) $field['key'], true));
        }

        echo '</fieldset>';
    }

    /** @param array<string,mixed> $field */
    private function render_frontend_field(array $field, string $context, $current_value = null): void {
        $key = (string) $field['key'];
        $type = (string) $field['type'];
        $posted_value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : null;
        $value = $posted_value !== null ? $posted_value : $current_value;
        $required = $field['required'] === '1';
        $field_id = 'caf_' . $context . '_' . $key;
        $required_html = $required ? ' <span class="required">*</span>' : '';
        echo '<p class="form-row form-row-wide custom-account-field custom-account-field--' . esc_attr($key) . '">';
        echo '<label for="' . esc_attr($field_id) . '">' . esc_html((string) $field['label']) . $required_html . '</label>';
        $this->render_field_input($field, $field_id, $value, $required, 'input-text');
        echo '</p>';
    }

    // -------------------------------------------------------------------------
    // Admin user profile rendering
    // -------------------------------------------------------------------------

    public function render_admin_profile_fields(WP_User $user): void {
        if (!$this->can_manage_user_fields((int) $user->ID)) {
            return;
        }

        $fields = $this->fields_for_location('admin');
        if ($fields === []) {
            return;
        }
        ?>
        <h2><?php esc_html_e('Custom account fields', 'custom-account-fields'); ?></h2>
        <table class="form-table" role="presentation">
            <tbody>
                <?php foreach ($fields as $field) :
                    $key = (string) $field['key'];
                    $field_id = 'caf_admin_' . $key;
                    $value = get_user_meta((int) $user->ID, $key, true);
                    ?>
                    <tr>
                        <th><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html((string) $field['label']); ?></label></th>
                        <td><?php $this->render_field_input($field, $field_id, $value, false, 'regular-text'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** @param array<string,string> $attributes */
    private function attributes_to_html(array $attributes): string {
        $html = '';

        foreach ($attributes as $name => $value) {
            if ($value === '') {
                continue;
            }

            $html .= ' ' . esc_attr($name) . '="' . esc_attr($value) . '"';
        }

        return $html;
    }

    /** @param array<string,mixed> $field @return array<string,string> */
    private function field_validation_attributes(array $field): array {
        $attributes = [
            'data-caf-field' => '1',
            'data-caf-key'   => (string) $field['key'],
            'data-caf-label' => (string) $field['label'],
        ];

        if (!empty($field['validation'])) {
            $attributes['data-caf-validation'] = (string) $field['validation'];
        }

        if (!empty($field['validation_message'])) {
            $attributes['data-caf-validation-message'] = (string) $field['validation_message'];
        }

        if (($field['validation'] ?? '') === 'custom' && !empty($field['validation_regex'])) {
            $attributes['data-caf-validation-regex'] = (string) $field['validation_regex'];
        }

        return $attributes;
    }

    /** @param array<string,mixed> $field */
    private function render_field_input(array $field, string $field_id, $value, bool $required, string $class): void {
        $key = (string) $field['key'];
        $type = (string) $field['type'];
        $validation_attrs = $this->attributes_to_html($this->field_validation_attributes($field));
        $required_attr = $required ? ' required aria-required="true"' : '';

        if ($type === 'textarea') {
            echo '<textarea class="' . esc_attr($class) . '" name="' . esc_attr($key) . '" id="' . esc_attr($field_id) . '" placeholder="' . esc_attr((string) $field['placeholder']) . '" autocomplete="' . esc_attr((string) $field['autocomplete']) . '"' . $required_attr . $validation_attrs . '>' . esc_textarea((string) $value) . '</textarea>';
            return;
        }

        if ($type === 'select') {
            echo '<select class="' . esc_attr($class) . '" name="' . esc_attr($key) . '" id="' . esc_attr($field_id) . '"' . $required_attr . $validation_attrs . '>';
            echo '<option value="">' . esc_html__('Select', 'custom-account-fields') . '</option>';
            foreach ($this->parse_select_options((string) $field['options']) as $option_value => $option_label) {
                echo '<option value="' . esc_attr($option_value) . '" ' . selected((string) $value, (string) $option_value, false) . '>' . esc_html($option_label) . '</option>';
            }
            echo '</select>';
            return;
        }

        if ($type === 'checkbox') {
            echo '<label><input type="checkbox" name="' . esc_attr($key) . '" id="' . esc_attr($field_id) . '" value="1" ' . checked((string) $value, '1', false) . $required_attr . $validation_attrs . ' /> ' . esc_html((string) $field['label']) . '</label>';
            return;
        }

        $html_type = in_array($type, ['email', 'tel', 'number', 'date'], true) ? $type : 'text';
        echo '<input type="' . esc_attr($html_type) . '" class="' . esc_attr($class) . '" name="' . esc_attr($key) . '" id="' . esc_attr($field_id) . '" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr((string) $field['placeholder']) . '" autocomplete="' . esc_attr((string) $field['autocomplete']) . '"' . $required_attr . $validation_attrs . ' />';
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    private function validate_posted_field(WP_Error $errors, array $field): void {
        $key = (string) $field['key'];
        $value = isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
        $label = esc_html((string) $field['label']);

        if ($field['required'] === '1' && $value === '') {
            $errors->add($key . '_required', sprintf(__('%s is required.', 'custom-account-fields'), $label));
            return;
        }

        if ($value !== '') {
            $error_msg = $this->validate_field_value($field, $value);
            if ($error_msg !== '') {
                $errors->add($key . '_invalid', $error_msg);
            }
        }
    }

    public function validate_registration_fields(WP_Error $errors, string $username, string $email): WP_Error {
        foreach ($this->fields_for_location('register') as $field) {
            $this->validate_posted_field($errors, $field);
        }
        return $errors;
    }

    public function validate_account_fields(WP_Error $errors, WP_User $user): void {
        foreach ($this->fields_for_location('account') as $field) {
            $this->validate_posted_field($errors, $field);
        }
    }

    public function validate_admin_profile_fields(WP_Error $errors, bool $update, stdClass $user): void {
        $user_id = isset($user->ID) ? (int) $user->ID : 0;
        if (!$user_id || !$this->can_manage_user_fields($user_id)) {
            return;
        }

        foreach ($this->fields_for_location('admin') as $field) {
            $this->validate_posted_field($errors, $field);
        }
    }

    // -------------------------------------------------------------------------
    // Save
    // -------------------------------------------------------------------------

    public function save_registration_fields(int $customer_id): void {
        foreach ($this->fields_for_location('register') as $field) {
            $this->save_field_to_user($customer_id, $field);
        }
    }

    public function save_account_fields(int $user_id): void {
        foreach ($this->fields_for_location('account') as $field) {
            $this->save_field_to_user($user_id, $field);
        }
    }

    public function save_admin_profile_fields(int $user_id): void {
        if (!$this->can_manage_user_fields($user_id) || !$this->verify_user_profile_nonce($user_id)) {
            return;
        }

        foreach ($this->fields_for_location('admin') as $field) {
            $this->save_field_to_user($user_id, $field);
        }
    }

    public function apply_defaults_to_user(int $user_id): void {
        self::apply_missing_defaults_to_user($user_id, $this->get_fields());
    }

    /** @param array<string,mixed> $field */
    private function save_field_to_user(int $user_id, array $field): void {
        $key = (string) $field['key'];

        if (!isset($_POST[$key])) {
            if ($field['type'] === 'checkbox') {
                update_user_meta($user_id, $key, '0');
            }
            return;
        }

        $value = $this->sanitize_field_value($field, wp_unslash($_POST[$key]));
        update_user_meta($user_id, $key, $value);
    }

    /** @param array<int,array<string,mixed>> $fields */
    private static function apply_missing_defaults_to_all_users(array $fields): void {
        $paged = 1;

        do {
            $query = new WP_User_Query([
                'fields' => 'ID',
                'number' => 500,
                'paged'  => $paged,
            ]);
            $user_ids = array_map('intval', $query->get_results());

            foreach ($user_ids as $user_id) {
                self::apply_missing_defaults_to_user($user_id, $fields);
            }

            $paged++;
        } while ($user_ids !== []);
    }

    /** @param array<int,array<string,mixed>> $fields */
    private static function apply_missing_defaults_to_user(int $user_id, array $fields): void {
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['key'])) {
                continue;
            }

            $key = sanitize_key((string) $field['key']);
            if ($key === '' || metadata_exists('user', $user_id, $key)) {
                continue;
            }

            $default = isset($field['default_value']) ? (string) $field['default_value'] : '';
            if (($field['type'] ?? '') === 'checkbox') {
                $default = $default === '1' ? '1' : '0';
            }

            update_user_meta($user_id, $key, $default);
        }
    }

    private function can_manage_user_fields(int $user_id): bool {
        return current_user_can('edit_user', $user_id) && (current_user_can('manage_woocommerce') || current_user_can('edit_users'));
    }

    private function verify_user_profile_nonce(int $user_id): bool {
        return isset($_POST['_wpnonce']) && wp_verify_nonce((string) wp_unslash($_POST['_wpnonce']), 'update-user_' . $user_id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $field @param mixed $raw_value */
    private function sanitize_field_value(array $field, $raw_value): string {
        if ($field['type'] === 'checkbox') {
            return !empty($raw_value) && (string) $raw_value !== '0' ? '1' : '0';
        }
        if ($field['type'] === 'email') {
            return sanitize_email((string) $raw_value);
        }
        if ($field['type'] === 'textarea') {
            return sanitize_textarea_field((string) $raw_value);
        }
        return sanitize_text_field((string) $raw_value);
    }

    /** @return array<string,string> */
    private function parse_select_options(string $options): array {
        $parsed = [];
        $lines = preg_split('/\r\n|\r|\n/', $options) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '|') !== false) {
                [$value, $label] = array_map('trim', explode('|', $line, 2));
            } else {
                $value = $line;
                $label = $line;
            }
            if ($value !== '') {
                $parsed[$value] = $label !== '' ? $label : $value;
            }
        }

        return $parsed;
    }
}

register_activation_hook(__FILE__, ['Custom_Account_Fields_Plugin', 'activate']);
add_action('plugins_loaded', ['Custom_Account_Fields_Plugin', 'instance']);
