<?php
/**
 * Custom Login Form for Minimal OTP Plugin
 * @version 2.3
 */
if (!defined('ABSPATH')) {
    exit;
}
wc_print_notices(); 
?>

<div class="otp-login-form-container">
    <h2>ورود یا ثبت‌نام با شماره موبایل</h2>
    <p class="form-description">برای ورود یا ثبت‌نام، شماره موبایل خود را وارد کنید.</p>

    <form id="minimal-otp-form">
        <?php wp_nonce_field('otp_nonce_secret', 'otp_nonce_field'); ?>
        <p class="otp-status-message"></p>
        
        <div class="otp-step" id="otp-step-1">
            <label for="otp_phone_number">شماره موبایل</label>
            <input type="tel" class="input-text" name="phone" id="otp_phone_number" autocomplete="tel" inputmode="numeric" placeholder="مثال: 09123456789" />
            <button type="button" id="send-otp-btn" class="button">ارسال کد تایید</button>
        </div>

        <div class="otp-step" id="otp-step-2" style="display: none;">
            <label for="otp_code">کد تایید</label>
            <input type="tel" class="input-text" name="otp" id="otp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="5" placeholder="کد ۵ رقمی" />
            <button type="button" id="verify-otp-btn" class="button">تایید کد</button>
            <div class="otp-form-footer">
                 <a href="#" id="change-number-btn">تغییر شماره</a>
                 <a href="#" id="resend-otp-btn">ارسال مجدد کد</a>
            </div>
        </div>
        
        <div class="otp-step" id="otp-step-3" style="display: none;">
            <label for="otp_fname">نام</label>
            <input type="text" class="input-text" name="fname" id="otp_fname" autocomplete="given-name" />
            <label for="otp_lname">نام خانوادگی</label>
            <input type="text" class="input-text" name="lname" id="otp_lname" autocomplete="family-name" />
            <button type="button" id="complete-reg-btn" class="button">تکمیل ثبت نام و ورود</button>
        </div>
    </form>
</div>