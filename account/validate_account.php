<?php
function validateAccountData($data)
{
    $errors = [];

    // 入力値の前後から空白を除去
    $name = isset($data['account_name']) ? trim($data['account_name']) : '';
    $identifier = isset($data['account_identifier']) ? trim($data['account_identifier']) : '';

    // ★修正: カラム名に合わせて 'note' で統一
    $note = isset($data['note']) ? trim($data['note']) : '';


    // --- 勘定科目名のバリデーション ---
    if ($name === '') {
        $errors['account_name'] = '勘定科目名は必須です。';
    } elseif (mb_strlen($name) > 100) {
        $errors['account_name'] = '勘定科目名は100文字以内で入力してください。';
    }

    // --- 一意識別子のバリデーション ---
    if ($identifier === '') {
        $errors['account_identifier'] = '一意識別子は必須です。';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
        $errors['account_identifier'] = '一意識別子は英数字、ハイフン、アンダースコアのみが使用可能です。';
    } elseif (mb_strlen($identifier) > 50) {
        $errors['account_identifier'] = '一意識別子は50文字以内で入力してください。';
    }

    // --- 説明のバリデーション ---
    if ($note !== '' && mb_strlen($note) > 255) {
        $errors['note'] = '説明は255文字以内で入力してください。';
    }

    return $errors;
}
