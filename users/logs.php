<?php
// users/logs.php
require_once '../includes/auth_check.php';
require_once '../includes/database.php';

// 1. 権限チェック (管理者以外はアクセス拒否)
if ($_SESSION['role'] !== 'admin') {
    die("アクセス権限がありません。");
}

$dbh = getDb();

// 2. ログ一覧を取得 (office_id, ip_address も取得)
// officesテーブルのカラム名を 'name' と想定して取得し、'office_name' という別名をつけます
$sql = "
    SELECT a.*, u.last_name, u.first_name, u.username, o.name as office_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN offices o ON a.office_id = o.id
    ORDER BY a.created_at DESC
    LIMIT 2000
";
$stmt = $dbh->query($sql);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>操作ログ | 採算管理システム</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

    <style>
        body {
            font-size: 0.9rem;
        }

        /* 詳細モーダル内のJSON表示用 */
        pre.json-display {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 0.85rem;
            max-height: 500px;
            overflow-y: auto;
        }

        .table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        /* 概要カラムだけは折り返しを許可 */
        .col-summary {
            white-space: normal !important;
            max-width: 300px;
        }
    </style>
</head>

<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">採算管理システム - 操作ログ</span>
            <div>
                <span class="text-light me-3">
                    <i class="bi bi-person-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['display_name']); ?> さん
                </span>
                <a href="index.php" class="btn btn-outline-light btn-sm me-2">ユーザー管理へ戻る</a>
                <a href="../index.html" class="btn btn-outline-light btn-sm">ダッシュボードへ戻る</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2><i class="bi bi-clock-history"></i> 操作ログ一覧</h2>
            <button class="btn btn-outline-secondary btn-sm" onclick="location.reload();">
                <i class="bi bi-arrow-clockwise"></i> 最新情報を取得
            </button>
        </div>

        <div class="card shadow-sm mb-5">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="logsTable" class="table table-hover table-bordered table-sm">
                        <thead class="table-light text-center">
                            <tr>
                                <th style="width: 140px;">日時</th>
                                <th style="width: 120px;">実行ユーザー</th>
                                <th style="width: 100px;">対象営業所</th>
                                <th style="width: 80px;">Phase</th>
                                <th style="width: 60px;">ID</th>
                                <th style="width: 80px;">Action</th>
                                <th>概要 / メッセージ</th>
                                <th style="width: 80px;">詳細</th>
                                <th style="width: 80px;">復元</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                // JSONデコード
                                $contentRaw = $log['content'] ?? $log['details'] ?? '{}';
                                $contentData = json_decode($contentRaw, true);
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    $contentData = ['raw' => $contentRaw];
                                }

                                // 概要欄に表示するテキストを作成
                                $summary = '';
                                if (isset($contentData['msg'])) {
                                    $summary = $contentData['msg'];
                                } elseif (isset($contentData['action_type'])) {
                                    $summary = 'Action: ' . $contentData['action_type'];
                                } else {
                                    // データ量が多い場合は件数などを表示
                                    $keys = array_keys($contentData);
                                    $summary = implode(', ', array_slice($keys, 0, 3));
                                    if (count($keys) > 3) $summary .= '...';
                                }

                                // 営業所名
                                $officeName = $log['office_name'] ? $log['office_name'] : '-';
                                if (!$log['office_name'] && isset($contentData['office_id'])) {
                                    $officeName = '(OfficeID: ' . $contentData['office_id'] . ')';
                                }

                                // アクションの色分け (Bootstrapクラス)
                                // fix:青, update:黄, reject/unlock/parent_fixed:グレー, create:緑, delete:赤
                                $actionColor = match ($log['action']) {
                                    'create' => 'success',                 // 緑
                                    'update' => 'warning text-dark',       // 黄色
                                    'fix'    => 'primary',                 // 青
                                    'delete' => 'danger',                  // 赤
                                    'reject', 'parent_unlock', 'parent_fixed', 'parent_fix' => 'secondary', // 薄いグレー
                                    default => 'light text-dark border'    // その他
                                };
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                    <td>
                                        <?php if ($log['username']): ?>
                                            <div><?php echo htmlspecialchars($log['last_name'] . ' ' . $log['first_name']); ?></div>
                                            <div class="text-muted small" style="font-size:0.75rem;"><?php echo htmlspecialchars($log['ip_address']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">System/Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo htmlspecialchars($officeName); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($log['phase']); ?></span>
                                    </td>
                                    <td class="text-end"><?php echo htmlspecialchars($log['target_id']); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $actionColor; ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td class="col-summary text-truncate" title="<?php echo htmlspecialchars($summary); ?>">
                                        <?php echo htmlspecialchars($summary); ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-secondary view-detail-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#detailModal"
                                            data-json="<?php echo htmlspecialchars($contentRaw, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-id="<?php echo $log['id']; ?>"
                                            data-action="<?php echo htmlspecialchars($log['action']); ?>">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                    <td class="text-center">
                                        <?php if (
                                            in_array($log['phase'], ['cp', 'forecast', 'plan', 'outlook', 'result']) &&
                                            $log['action'] === 'update' &&
                                            !empty($contentData['officeTimeData'])
                                        ): ?>
                                            <button class="btn btn-sm btn-outline-danger restore-btn"
                                                onclick="confirmRestore(<?php echo $log['id']; ?>, '<?php echo $log['phase']; ?>', '<?php echo $log['created_at']; ?>')">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ログ詳細データ (JSON)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2 text-muted small">ログID: <span id="modalLogId"></span> / Action: <span id="modalLogAction"></span></div>
                    <pre class="json-display" id="modalJsonContent"></pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            // DataTables初期化
            $('#logsTable').DataTable({
                language: {
                    url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/ja.json"
                },
                order: [
                    [0, "desc"]
                ],
                pageLength: 50,
                lengthMenu: [25, 50, 100, 200],
                stateSave: true,
                columnDefs: [{
                    targets: [7, 8],
                    orderable: false
                }]
            });

            // 詳細ボタンクリック時の処理
            $(document).on('click', '.view-detail-btn', function() {
                const logId = $(this).data('id');
                const action = $(this).data('action');
                const jsonStr = $(this).attr('data-json');

                $('#modalLogId').text(logId);
                $('#modalLogAction').text(action);

                try {
                    const jsonObj = JSON.parse(jsonStr);
                    const prettyJson = JSON.stringify(jsonObj, null, 4);
                    $('#modalJsonContent').text(prettyJson);
                } catch (e) {
                    $('#modalJsonContent').text(jsonStr);
                }
            });
            // ページ読み込み完了後にURLからクエリパラメータ(error/success)を削除する
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                if (url.searchParams.has('error') || url.searchParams.has('success')) {
                    url.searchParams.delete('error');
                    url.searchParams.delete('success');
                    window.history.replaceState(null, '', url.toString());
                }
            }
        });

        // 復元ボタンクリック時の処理 (仮実装)
        // 復元ボタンクリック時の処理
        function confirmRestore(logId, phase, date) {
            const msg = '【確認】\nこの時点(' + date + ')のデータに復元しますか？\n\n' +
                '・現在のデータは上書きされます。\n' +
                '・この復元操作自体も新しいログとして記録されます。';

            if (confirm(msg)) {
                // 動的にフォームを作成してPOST送信
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'restore_log.php';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'log_id';
                input.value = logId;

                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>

</html>