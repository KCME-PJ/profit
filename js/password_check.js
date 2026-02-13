document.addEventListener('DOMContentLoaded', function () {
    const newPass = document.getElementById('newPassword');
    const confirmPass = document.getElementById('confirmPassword');

    // エラー表示要素
    const matchError = document.getElementById('matchError');
    const charError = document.getElementById('charError');

    const submitBtn = document.getElementById('submitBtn');

    submitBtn.disabled = true;

    function validatePassword() {
        const val1 = newPass.value;
        const val2 = confirmPass.value;

        // -----------------------------------------
        // 1. 禁止文字チェック
        // -----------------------------------------
        // 許可するもの:
        // a-z, A-Z, 0-9 (半角英数字)
        // ａ-ｚ, Ａ-Ｚ, ０-９ (全角英数字)
        //
        // 否定(^): 上記「以外」が含まれていたら true (つまりエラー)
        // ※ここにスペースや記号は含まれていないため、入力されるとエラーになります
        const forbiddenPattern = /[^a-zA-Z0-9ａ-ｚＡ-Ｚ０-９]/;

        const hasForbiddenChar = forbiddenPattern.test(val1) || forbiddenPattern.test(val2);

        // 文字種エラーの表示制御
        if (hasForbiddenChar) {
            if (charError) charError.style.display = 'block';
            newPass.classList.add('is-invalid');
            if (val2.length > 0) confirmPass.classList.add('is-invalid');
        } else {
            if (charError) charError.style.display = 'none';
            newPass.classList.remove('is-invalid');
        }

        // -----------------------------------------
        // 2. 文字数・一致チェック
        // -----------------------------------------
        const isLengthOk = val1.length >= 8;
        const isMatch = val1 === val2;
        const isConfirmEntered = val2.length > 0;
        const isCharOk = !hasForbiddenChar;

        // 一致エラー制御
        if (isConfirmEntered && !isMatch) {
            matchError.style.display = 'flex';
            confirmPass.classList.add('is-invalid');
            confirmPass.classList.remove('is-valid');
        } else {
            matchError.style.display = 'none';
            confirmPass.classList.remove('is-invalid');

            if (isConfirmEntered && isMatch && isCharOk) {
                confirmPass.classList.add('is-valid');
            } else {
                confirmPass.classList.remove('is-valid');
            }
        }

        // 緑枠制御
        if (isLengthOk && isCharOk) {
            newPass.classList.add('is-valid');
        } else {
            newPass.classList.remove('is-valid');
        }

        // -----------------------------------------
        // 3. ボタン有効化
        // -----------------------------------------
        if (isLengthOk && isCharOk && isMatch && isConfirmEntered) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }

    newPass.addEventListener('input', validatePassword);
    confirmPass.addEventListener('input', validatePassword);
});