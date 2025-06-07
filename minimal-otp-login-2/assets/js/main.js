jQuery(function ($) {
    'use strict';

    const form = $('#minimal-otp-form');
    if (!form.length) {
        return;
    }

    // ذخیره کردن عناصر برای عملکرد بهتر
    const step1 = $('#otp-step-1'),
          step2 = $('#otp-step-2'),
          step3 = $('#otp-step-3');
          
    const phoneInput = $('#otp_phone_number'),
          otpInput = $('#otp_code');
          
    const sendBtn = $('#send-otp-btn'),
          verifyBtn = $('#verify-otp-btn'),
          completeRegBtn = $('#complete-reg-btn');
          
    const resendBtn = $('#resend-otp-btn'),
          changeNumberBtn = $('#change-number-btn');
          
    const statusMessage = $('.otp-status-message'),
          description = $('.form-description');
    
    let resendTimer, userExists;

    // --- رویداد ۱: کلیک روی دکمه "ارسال کد تایید" ---
    sendBtn.on('click', function () {
        const phone = phoneInput.val();
        if (!phone.match(/^09[0-9]{9}$/)) {
            showMessage('لطفاً یک شماره موبایل معتبر وارد کنید.', 'error');
            return;
        }
        setButtonLoading(sendBtn, true, 'در حال ارسال...');
        
        $.ajax({
            type: 'POST',
            url: otp_object.ajax_url,
            data: {
                action: 'send_otp',
                phone: phone,
                nonce: otp_object.nonce
            },
            success: function (response) {
                setButtonLoading(sendBtn, false, 'ارسال کد تایید');
                if (response.success) {
                    showMessage('کد تایید به شماره شما ارسال شد.', 'success');
                    description.text('کد ۵ رقمی ارسال شده را وارد نمایید.');
                    userExists = response.data.user_exists;
                    step1.hide();
                    step2.show();
                    startResendTimer();
                    otpInput.focus();
                } else {
                    showMessage(response.data.message, 'error');
                }
            },
            error: function () {
                setButtonLoading(sendBtn, false, 'ارسال کد تایید');
                showMessage('یک خطای پیش‌بینی نشده رخ داد.', 'error');
            }
        });
    });

    // --- رویداد ۲: کلیک روی دکمه "تایید کد" ---
    verifyBtn.on('click', function(){
        if ($(this).prop('disabled')) return;
        
        const phone = phoneInput.val();
        const otp = otpInput.val();

        if (otp.length !== 5) {
            showMessage('کد تایید باید ۵ رقم باشد.', 'error');
            return;
        }
        setButtonLoading(verifyBtn, true, 'در حال بررسی...');

        const action = userExists ? 'verify_and_login' : 'verify_otp_step';

        $.ajax({
            type: 'POST',
            url: otp_object.ajax_url,
            data: {
                action: action,
                phone: phone,
                otp: otp,
                nonce: otp_object.nonce
            },
            success: function(response){
                if (response.success) {
                    if (userExists) {
                        showMessage(response.data.message, 'success');
                        setTimeout(() => window.location.href = response.data.redirect, 1000);
                    } else {
                        showMessage('شماره شما تایید شد. لطفاً اطلاعات خود را تکمیل کنید.', 'success');
                        description.text('برای تکمیل ثبت‌نام، نام و نام خانوادگی خود را وارد کنید.');
                        step2.hide();
                        step3.show();
                        $('#otp_fname').focus();
                    }
                } else {
                    setButtonLoading(verifyBtn, false, 'تایید کد');
                    showMessage(response.data.message, 'error');
                    // این خط به کاربر اجازه اصلاح می‌دهد
                    otpInput.val('').focus();
                }
            },
            error: function(){
                setButtonLoading(verifyBtn, false, 'تایید کد');
                showMessage('یک خطای پیش‌بینی نشده رخ داد.', 'error');
                otpInput.val('').focus(); // در صورت بروز خطای کلی نیز فیلد پاک شود
            }
        });
    });

    // --- رویداد ۳: کلیک روی دکمه "تکمیل ثبت نام" ---
    completeRegBtn.on('click', function(){
        if ($(this).prop('disabled')) return;

        const fname = $('#otp_fname').val();
        const lname = $('#otp_lname').val();

        if (!fname || !lname) {
            showMessage('نام و نام خانوادگی نمی‌تواند خالی باشد.', 'error');
            return;
        }

        setButtonLoading(completeRegBtn, true, 'در حال تکمیل ثبت نام...');
        $.ajax({
            type: 'POST',
            url: otp_object.ajax_url,
            data: {
                action: 'complete_registration',
                phone: phoneInput.val(),
                fname: fname,
                lname: lname,
                nonce: otp_object.nonce
            },
            success: function(response){
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    setTimeout(() => window.location.href = response.data.redirect, 1000);
                } else {
                    setButtonLoading(completeRegBtn, false, 'تکمیل ثبت نام و ورود');
                    showMessage(response.data.message, 'error');
                }
            },
            error: function(){
                setButtonLoading(completeRegBtn, false, 'تکمیل ثبت نام و ورود');
                showMessage('یک خطای پیش‌بینی نشده رخ داد.', 'error');
            }
        });
    });
    
    // --- رویداد ۴: بررسی خودکار کد با ورود ۵ رقم ---
    otpInput.on('input', function() {
        if ($(this).val().length === 5) {
            verifyBtn.trigger('click');
        }
    });

    // --- سایر رویدادها و توابع کمکی ---
    resendBtn.on('click', function(e){
        e.preventDefault();
        const phone = phoneInput.val();
        $(this).css('pointer-events', 'none');
        showMessage('در حال ارسال کد جدید...', 'success');
        $.ajax({
            type: 'POST', url: otp_object.ajax_url,
            data: { action: 'send_otp', phone: phone, nonce: otp_object.nonce },
            success: function (response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    startResendTimer();
                } else {
                    showMessage(response.data.message, 'error');
                    resendBtn.css('pointer-events', 'auto');
                }
            },
            error: function () {
                showMessage('یک خطای پیش‌بینی نشده رخ داد.', 'error');
                resendBtn.css('pointer-events', 'auto');
            }
        });
    });
    
    changeNumberBtn.on('click', function(e){
        e.preventDefault();
        step1.show();
        step2.hide();
        step3.hide();
        description.text('برای ورود یا ثبت‌نام، شماره موبایل خود را وارد کنید.');
        phoneInput.val('').focus();
        otpInput.val('');
        setButtonLoading(sendBtn, false, 'ارسال کد تایید');
        showMessage('', '');
        clearInterval(resendTimer);
        resendBtn.css('pointer-events', 'auto').text('ارسال مجدد کد');
    });

    function showMessage(message, type) {
        statusMessage.text(message).removeClass('success error').addClass(type).show();
    }

    function setButtonLoading(button, isLoading, text) {
        button.prop('disabled', isLoading).text(text);
    }
    
    function startResendTimer() {
        let seconds = 60;
        resendBtn.css('pointer-events', 'none').text(`ارسال مجدد تا ${seconds} ثانیه`);
        clearInterval(resendTimer);
        resendTimer = setInterval(function() {
            seconds--;
            if (seconds > 0) {
                resendBtn.text(`ارسال مجدد تا ${seconds} ثانیه`);
            } else {
                clearInterval(resendTimer);
                resendBtn.css('pointer-events', 'auto').text('ارسال مجدد کد');
            }
        }, 1000);
    }
});