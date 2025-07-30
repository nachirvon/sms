<?php
/**
 * Plugin Name: Minimal OTP Login (ippanel)
 * Description: Replaces WooCommerce login/registration with a secure OTP system using ippanel.
 * Version: 2.4 (Disable Default Auth Forms)
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * GitHub Plugin URI: https://github.com/nachirvon/sms/tree/main/minimal-otp-login
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly for security.
}

class Minimal_OTP_Login {

    const MAX_OTP_ATTEMPTS = 3; // حداکثر تلاش مجاز برای وارد کردن کد

    public function __construct() {
        // Load assets (CSS, JS)
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
        
        // Override WooCommerce login form template
        add_filter('wc_get_template', [$this, 'override_wc_login_form'], 10, 3);
        
        // هوک برای اجبار کاربر به لاگین در صفحه پرداخت
        add_action('template_redirect', [$this, 'force_login_on_checkout']);
        
        // ثبت تمام اکشن‌های ایجکس مورد نیاز
        $this->register_ajax_actions();

        // هوک‌ها برای غیرفعال کردن فرم‌های پیش‌فرض وردپرس
        add_action('login_init', [$this, 'disable_default_auth_actions']);
        add_action('login_head', [$this, 'hide_default_auth_links']);
        
        // اضافه کردن هدرهای امنیتی
        add_action('wp_head', [$this, 'add_security_headers']);
    }

    /**
     * غیرفعال کردن دسترسی به صفحات ثبت‌نام و فراموشی رمز عبور
     */
    public function disable_default_auth_actions() {
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
        
        if (
            $action === 'register' || 
            $action === 'lostpassword' || 
            $action === 'rp' || 
            $action === 'postpass'
        ) {
            wp_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
    }

    /**
     * مخفی کردن لینک‌های ثبت‌نام و فراموشی رمز عبور از صفحه ورود
     */
    public function hide_default_auth_links() {
        echo '<style>
            #nav, #backtoblog {
                display: none !important;
            }
        </style>';
    }
    
    /**
     * اگر کاربر لاگین نکرده باشد و بخواهد وارد صفحه پرداخت شود،
     * او را به صفحه حساب کاربری (که فرم ورود ما در آن است) هدایت می‌کنیم.
     */
    public function force_login_on_checkout() {
        if (is_checkout() && !is_user_logged_in()) {
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('redirect_after_login', wc_get_checkout_url());
            }
            wp_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
    }

    /**
     * Load JS and CSS files for the form.
     */
    public function load_assets() {
        if (is_account_page() || is_checkout()) {
            wp_enqueue_style('minimal-otp-style', plugin_dir_url(__FILE__) . 'assets/css/style.css', [], '2.4');
            wp_enqueue_script('minimal-otp-script', plugin_dir_url(__FILE__) . 'assets/js/main.js', ['jquery'], '2.4', true);
            wp_localize_script('minimal-otp-script', 'otp_object', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('otp_nonce_secret')]);
        }
    }

    /**
     * Override the default WooCommerce login form with our custom template.
     */
    public function override_wc_login_form($template, $template_name, $template_path) {
        if ($template_name === 'myaccount/form-login.php') {
            $template = plugin_dir_path(__FILE__) . 'templates/my-account/form-login.php';
        }
        return $template;
    }
    
    /**
     * ثبت تمام اکشن‌های ایجکس مورد نیاز
     */
    private function register_ajax_actions() {
        add_action('wp_ajax_nopriv_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_verify_and_login', [$this, 'ajax_verify_and_login']);
        add_action('wp_ajax_nopriv_verify_otp_step', [$this, 'ajax_verify_otp_step']);
        add_action('wp_ajax_nopriv_complete_registration', [$this, 'ajax_complete_registration']);
    }

    /**
     * AJAX handler برای ارسال کد OTP.
     */
    public function ajax_send_otp() {
        check_ajax_referer('otp_nonce_secret', 'nonce');
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            wp_send_json_error(['message' => 'فرمت شماره موبایل صحیح نیست.']);
        }
        if (get_transient('otp_rate_limit_' . $phone)) {
            wp_send_json_error(['message' => 'لطفاً ۶۰ ثانیه دیگر دوباره تلاش کنید.']);
        }
        delete_transient('otp_attempts_' . $phone);
        $otp = rand(10000, 99999);
        $otp_hash = password_hash($otp, PASSWORD_DEFAULT);
        $api_response = $this->send_ippanel_pattern($phone, $otp);
        if (is_wp_error($api_response) || (wp_remote_retrieve_response_code($api_response) < 200 || wp_remote_retrieve_response_code($api_response) >= 300)) {
            // بهبود ثبت خطا برای جلوگیری از افشای اطلاعات حساس
            $error_code = is_wp_error($api_response) ? $api_response->get_error_code() : wp_remote_retrieve_response_code($api_response);
            error_log('IPPANEL API Error: HTTP ' . $error_code . ' for phone: ' . substr($phone, 0, 4) . '****');
            wp_send_json_error(['message' => 'خطا در سیستم ارسال پیامک. لطفاً بعداً تلاش کنید.']);
        }
        set_transient('otp_hash_' . $phone, $otp_hash, 5 * MINUTE_IN_SECONDS);
        set_transient('otp_rate_limit_' . $phone, true, 60);
        $user_exists = $this->user_exists($phone);
        wp_send_json_success(['message' => 'کد تایید با موفقیت ارسال شد.', 'user_exists' => $user_exists]);
    }
    
    /**
     * Sends the pattern SMS using ippanel API.
     */
    private function send_ippanel_pattern($recipient, $code) {
        $api_key = defined('IPPANEL_API_KEY') ? IPPANEL_API_KEY : '';
        $pattern_code = defined('IPPANEL_PATTERN_CODE') ? IPPANEL_PATTERN_CODE : '';
        $sender = defined('IPPANEL_SENDER_NUMBER') ? IPPANEL_SENDER_NUMBER : '';
        $url = 'https://api2.ippanel.com/api/v1/sms/pattern/normal/send';
        $body = ['code' => $pattern_code, 'sender' => $sender, 'recipient' => $recipient, 'variable' => ['code' => (string) $code]];
        return wp_remote_post($url, ['method' => 'POST', 'headers' => ['Content-Type' => 'application/json', 'apikey' => $api_key], 'body' => json_encode($body), 'timeout' => 20]);
    }

    /**
     * AJAX handler برای ورود کاربران از قبل موجود.
     */
    public function ajax_verify_and_login() {
        check_ajax_referer('otp_nonce_secret', 'nonce');
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $otp   = isset($_POST['otp']) ? sanitize_text_field($_POST['otp']) : '';
        if (!$this->verify_otp_code($phone, $otp)) {
            return;
        }
        $user = get_user_by('login', $phone);
        if ($user) {
            $this->login_user($user->ID);
        } else {
            wp_send_json_error(['message' => 'خطای سیستمی: کاربر یافت نشد.']);
        }
    }

    /**
     * AJAX handler برای مرحله اول ثبت‌نام (تایید شماره)
     */
    public function ajax_verify_otp_step() {
        check_ajax_referer('otp_nonce_secret', 'nonce');
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $otp   = isset($_POST['otp']) ? sanitize_text_field($_POST['otp']) : '';
        if (!$this->verify_otp_code($phone, $otp)) {
            return;
        }
        set_transient('phone_verified_' . $phone, true, 10 * MINUTE_IN_SECONDS);
        wp_send_json_success(['message' => 'شماره شما با موفقیت تایید شد.']);
    }

    /**
     * AJAX handler برای مرحله نهایی ثبت‌نام (ساخت حساب)
     */
    public function ajax_complete_registration() {
        check_ajax_referer('otp_nonce_secret', 'nonce');
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $fname = isset($_POST['fname']) ? sanitize_text_field($_POST['fname']) : '';
        $lname = isset($_POST['lname']) ? sanitize_text_field($_POST['lname']) : '';
        
        // اعتبارسنجی طول ورودی
        if (strlen($fname) > 50 || strlen($lname) > 50) {
            wp_send_json_error(['message' => 'نام و نام خانوادگی نمی‌تواند بیشتر از ۵۰ کاراکتر باشد.']);
            return;
        }
        
        if (!get_transient('phone_verified_' . $phone)) {
            wp_send_json_error(['message' => 'خطای امنیتی: تایید شماره انجام نشده است.']);
            return;
        }
        if (empty($fname) || empty($lname)) {
            wp_send_json_error(['message' => 'نام و نام خانوادگی الزامی است.']);
            return;
        }
        $username = $phone;
        $password = wp_generate_password();
        $user_id = wp_create_user($username, $password);
        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => 'خطا در ایجاد حساب کاربری: ' . $user_id->get_error_message()]);
            return;
        }
        $display_name = trim($fname . ' ' . $lname);
        wp_update_user(['ID' => $user_id, 'first_name' => $fname, 'last_name' => $lname, 'display_name' => $display_name]);
        update_user_meta($user_id, 'billing_phone', $phone);
        delete_transient('phone_verified_' . $phone);
        $this->login_user($user_id);
    }

    /**
     * تابع کمکی برای تایید کد OTP
     * @return bool
     */
    private function verify_otp_code($phone, $otp) {
        $attempts = get_transient('otp_attempts_' . $phone) ?: 0;
        if ($attempts >= self::MAX_OTP_ATTEMPTS) {
            wp_send_json_error(['message' => 'تعداد تلاش‌های شما بیش از حد مجاز بوده است. لطفاً کد جدیدی دریافت کنید.']);
            return false;
        }
        $otp_hash = get_transient('otp_hash_' . $phone);
        if (!$otp_hash || !password_verify($otp, $otp_hash)) {
            $attempts++;
            set_transient('otp_attempts_' . $phone, $attempts, 15 * MINUTE_IN_SECONDS);
            $remaining = self::MAX_OTP_ATTEMPTS - $attempts;
            $error_msg = $remaining > 0 ? 'کد تایید نامعتبر است. ' . $remaining . ' تلاش دیگر باقی مانده است.' : 'کد نامعتبر است. شما دیگر تلاش مجازی ندارید. لطفاً کد جدید دریافت کنید.';
            wp_send_json_error(['message' => $error_msg]);
            return false;
        }
        delete_transient('otp_attempts_' . $phone);
        delete_transient('otp_hash_' . $phone);
        return true;
    }

    /**
     * تابع کمکی برای لاگین کردن کاربر و ارسال پاسخ نهایی
     */
    private function login_user($user_id) {
        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        $redirect_url = wc_get_page_permalink('myaccount');
        if (function_exists('WC') && WC()->session && WC()->session->get('redirect_after_login')) {
            $redirect_url = WC()->session->get('redirect_after_login');
            WC()->session->__unset('redirect_after_login');
        }
        wp_send_json_success(['message' => 'ورود با موفقیت انجام شد. در حال انتقال...', 'redirect' => $redirect_url]);
    }
    
    /**
     * Checks if a user exists with the given phone number.
     */
    private function user_exists($phone) {
        if ($user = get_user_by('login', $phone)) {
            return $user->ID;
        }
        $user_query = new WP_User_Query(['meta_key' => 'billing_phone', 'meta_value' => $phone, 'number' => 1, 'fields' => 'ID']);
        $users = $user_query->get_results();
        return !empty($users) ? $users[0] : false;
    }

    /**
     * تابع کمکی برای اعتبارسنجی و پاکسازی ورودی‌ها
     */
    private function validate_and_sanitize_input($input, $type = 'text', $max_length = 255) {
        if (empty($input)) {
            return false;
        }
        
        $sanitized = sanitize_text_field($input);
        
        // اعتبارسنجی طول
        if (strlen($sanitized) > $max_length) {
            return false;
        }
        
        // اعتبارسنجی نوع
        switch ($type) {
            case 'phone':
                return preg_match('/^09[0-9]{9}$/', $sanitized) ? $sanitized : false;
            case 'otp':
                return preg_match('/^[0-9]{5}$/', $sanitized) ? $sanitized : false;
            case 'name':
                // فقط حروف، فاصله و کاراکترهای فارسی
                return preg_match('/^[\p{L}\s]+$/u', $sanitized) ? $sanitized : false;
            default:
                return $sanitized;
        }
    }

    /**
     * اضافه کردن هدرهای امنیتی برای محافظت بیشتر
     */
    public function add_security_headers() {
        if (is_account_page() || is_checkout()) {
            // جلوگیری از clickjacking
            header('X-Frame-Options: DENY');
            // جلوگیری از MIME type sniffing
            header('X-Content-Type-Options: nosniff');
            // محافظت پایه XSS
            header('X-XSS-Protection: 1; mode=block');
        }
    }
}

new Minimal_OTP_Login();