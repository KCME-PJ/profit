<?php
// 年度の初期値（3月末締め）
$today = new DateTime();
$fiscalCutoff = new DateTime(date('Y') . '-04-01');
$defaultYear = ($today < $fiscalCutoff) ? (int)date('Y') : (int)date('Y') + 1;

// GETパラメータ優先（編集画面ではURL指定が来る）
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : ($currentYear ?? $defaultYear);
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : ($currentMonth ?? null);
?>

<div class="info-box">
    <div class="row">
        <!-- 年度セレクト -->
        <div class="col-md-2">
            <label>年度</label>
            <select id="yearSelect" name="year" class="form-select form-select-sm">
                <option value="" disabled <?= is_null($currentYear) ? 'selected' : '' ?>>年度を選択</option>
                <?php
                // 年度選択範囲（過去2年〜翌年）
                $yearRange = range($defaultYear - 2, $defaultYear + 1);
                foreach ($yearRange as $year):
                ?>
                    <option value="<?= $year ?>" <?= ($year === $currentYear) ? 'selected' : '' ?>>
                        <?= $year ?>年度
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- 月セレクト -->
        <div class="col-md-2">
            <label>月</label>
            <select id="monthSelect" name="month" class="form-select form-select-sm" <?= isset($isEditMode) && $isEditMode ? 'disabled' : '' ?>>
                <?php if (!isset($isEditMode) || !$isEditMode): ?>
                    <?php
                    // 4月～12月 → 1月～3月 の順で並べる（会計年度用）
                    $selectedMonth = $currentMonth ?? 4;
                    for ($i = 4; $i <= 12; $i++): ?>
                        <option value="<?= $i ?>" <?= ($i == $selectedMonth) ? 'selected' : '' ?>>
                            <?= $i ?>月
                        </option>
                    <?php endfor;
                    for ($i = 1; $i <= 3; $i++): ?>
                        <option value="<?= $i ?>" <?= ($i == $selectedMonth) ? 'selected' : '' ?>>
                            <?= $i ?>月
                        </option>
                    <?php endfor; ?>
                <?php else: ?>
                    <option value="" disabled selected>月を選択</option>
                <?php endif; ?>
            </select>
        </div>

        <!-- 時間管理項目 -->
        <input type="hidden" id="monthlyCpId" name="monthly_cp_id">
        <div class="col-md-2">
            <label>定時間</label>
            <input type="number" step="0.01" id="standardHours" name="standard_hours" class="form-control form-control-sm" placeholder="0">
        </div>
        <div class="col-md-2">
            <label>残業時間</label>
            <input type="number" step="0.01" id="overtimeHours" name="overtime_hours" class="form-control form-control-sm" placeholder="0">
        </div>
        <div class="col-md-2">
            <label>時間移動</label>
            <input type="number" step="0.01" id="transferredHours" name="transferred_hours" class="form-control form-control-sm" placeholder="0">
        </div>
        <div class="col-md-2">
            <label>賃率</label>
            <input type="number" step="1" id="hourlyRate" name="hourly_rate" class="form-control form-control-sm" placeholder="0">
        </div>

        <!-- 社員数表示 + 合計情報 -->
        <div class="row mt-2 mb-5">
            <div class="col-md-2">
                <label>正社員</label>
                <input type="number" id="fulltimeCount" name="fulltime_count" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-md-2">
                <label>契約社員</label>
                <input type="number" id="contractCount" name="contract_count" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-md-2">
                <label>派遣社員</label>
                <input type="number" id="dispatchCount" name="dispatch_count" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-md-3">
                <strong>総時間：</strong> <span id="totalHours">0.00 時間</span><br>
                <strong>労務費：</strong> ¥<span id="laborCost">0</span>
            </div>
            <div class="col-md-3">
                <strong>経費合計：</strong> ¥<span id="expenseTotal">0</span><br>
                <strong>　総合計：</strong> ¥<span id="grandTotal">0</span>
            </div>
        </div>
    </div>

    <!-- 操作ボタン類（右下に絶対配置） -->
    <button type="button" class="btn btn-outline-danger btn-sm register-button1" data-bs-toggle="modal" data-bs-target="#confirmModal">修正</button>
    <button type="button" class="btn btn-outline-success btn-sm register-button2" data-bs-toggle="modal" data-bs-target="#cpFixModal">確定</button>
    <a href="#" id="excelExportBtn" class="btn btn-outline-primary btn-sm register-button3">Excel出力</a>
</div>