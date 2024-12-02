<?php
session_start(); // エラーメッセージを一時的に保存するために使用

function validateAccountData($data)
{
    $errors = [];
    // 勘定科目名のバリデーション
    if (empty($data['account_name'])) {
        $errors['account_name'] = '勘定科目名は必須です。';
    } elseif (mb_strlen($data['account_name']) > 100) {
        $errors['account_name'] = '勘定科目名は100文字以内で入力してください。';
    }

    // 一意識別子のバリデーション
    if (empty($data['account_identifier'])) {
        $errors['account_identifier'] = '一意識別子は必須です。';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $data['account_identifier'])) {
        $errors['account_identifier'] = '一意識別子は英数字、ハイフン、アンダースコアのみが使用可能です。';
    } elseif (mb_strlen($data['account_identifier']) > 50) {
        $errors['account_identifier'] = '一意識別子は50文字以内で入力してください。';
    }

    // 説明のバリデーション（任意）
    if (!empty($data['note']) && mb_strlen($data['note']) > 255) {
        $errors['note'] = '説明は255文字以内で入力してください。';
    }

    return $errors;
}
