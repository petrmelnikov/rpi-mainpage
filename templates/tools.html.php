<?php
    /** @var array $hostedTools */
    /** @var string $successMessage */
    /** @var string $errorMessage */
    /** @var int $maxUploadBytes */

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $formatBytes = static function (int $bytes): string {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit === 0 ? (string)$value : number_format($value, $value >= 10 ? 1 : 2, ',', ' ')) . ' ' . $units[$unit];
    };
?>

<div class="row mb-4">
    <div class="col-lg-8">
        <h1 class="h3 mb-2">Tools</h1>
        <p class="text-secondary mb-0">
            Локальный каталог небольших HTML/JS-инструментов. Можно загрузить один автономный HTML-файл
            или ZIP с HTML, стилями, скриптами и другими ресурсами.
        </p>
    </div>
</div>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success" role="alert"><?= $escape($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 card-title">Опубликовать инструмент</h2>
                <form method="post" action="/tools/upload" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label" for="tool-name">Название</label>
                        <input
                            class="form-control"
                            id="tool-name"
                            name="tool_name"
                            type="text"
                            maxlength="100"
                            placeholder="Если не указать, возьмём имя файла"
                        >
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="tool-file">HTML или ZIP</label>
                        <input
                            class="form-control"
                            id="tool-file"
                            name="tool_file"
                            type="file"
                            accept=".html,.htm,.zip,text/html,application/zip"
                            required
                        >
                        <div class="form-text">
                            До <?= $escape($formatBytes($maxUploadBytes)) ?>. В ZIP автоматически выбирается ближайший
                            <code>index.html</code>, а если его нет — первый HTML-файл.
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit">Загрузить и опубликовать</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-warning-subtle h-100">
            <div class="card-body">
                <h2 class="h5 card-title">Важно</h2>
                <p class="card-text mb-2">
                    Загруженный HTML и JavaScript открывается в браузере с этого локального сервера.
                    Загружайте только файлы, которым доверяете.
                </p>
                <p class="card-text text-secondary small mb-0">
                    PHP и другие серверные файлы не исполняются: содержимое пакета раздаётся как статические файлы.
                    ZIP с небезопасными путями или символическими ссылками будет отклонён.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="d-flex align-items-center justify-content-between gap-3 mb-3">
    <h2 class="h4 mb-0">Опубликованные инструменты</h2>
    <span class="badge text-bg-secondary"><?= count($hostedTools) ?></span>
</div>

<?php if ($hostedTools === []): ?>
    <div class="card mb-4">
        <div class="card-body text-secondary">
            Пока ничего не загружено. Первый инструмент появится здесь сразу после публикации.
        </div>
    </div>
<?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4">
        <?php foreach ($hostedTools as $tool): ?>
            <?php
                $uploadedAt = strtotime((string)($tool['uploadedAt'] ?? ''));
                $uploadedLabel = $uploadedAt === false ? 'дата неизвестна' : date('d.m.Y H:i', $uploadedAt);
            ?>
            <div class="col">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column">
                        <h3 class="h5 card-title"><?= $escape((string)$tool['name']) ?></h3>
                        <p class="card-text text-secondary small mb-3">
                            <?= $escape((string)$tool['sourceName']) ?><br>
                            <?= (int)$tool['filesCount'] ?> файл(ов) ·
                            <?= $escape($formatBytes((int)$tool['sizeBytes'])) ?><br>
                            <?= $escape($uploadedLabel) ?>
                        </p>
                        <div class="d-flex flex-wrap gap-2 mt-auto">
                            <a
                                class="btn btn-primary"
                                href="<?= $escape((string)$tool['url']) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >Открыть</a>
                            <form
                                method="post"
                                action="/tools/delete"
                                onsubmit="return confirm('Удалить этот инструмент и все его файлы?');"
                            >
                                <input type="hidden" name="tool_id" value="<?= $escape((string)$tool['id']) ?>">
                                <button class="btn btn-outline-danger" type="submit">Удалить</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 card-title">Встроенные действия</h2>
        <p class="card-text text-secondary small">
            Это прежнее содержимое страницы Tools: две демонстрационные страницы и обновление кода приложения.
        </p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-secondary" href="/tools/example1">example1</a>
            <a class="btn btn-secondary" href="/tools/example2">example2</a>
            <form method="post" action="/update-code">
                <button class="btn btn-outline-primary" type="submit">git pull</button>
            </form>
        </div>
    </div>
</div>
