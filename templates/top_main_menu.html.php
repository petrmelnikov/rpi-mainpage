<div class="row mb-2">
    <div class="col-sm d-flex flex-wrap gap-2">
        <?php
            if (isset($topMainMenu) && is_array($topMainMenu)) {
                foreach ($topMainMenu as $menuItem) {
                    /** @var \App\Dto\MenuItemDto $menuItem */
                    ?>
                    <a class="btn btn-primary" href="<?= $menuItem->url ?>"><?= $menuItem->name ?></a>
                    <?php
                }
            }
        ?>
    </div>
</div>
<div class="row mb-2">
    <div class="col-sm d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="/">index</a>
        <a class="btn btn-primary" href="/top">top</a>
        <a class="btn btn-primary" href="/file-index">file index</a>
        <a class="btn btn-primary" href="/tools">tools</a>
        <a class="btn btn-primary" href="/youtube-player">YouTube Player</a>
    </div>
</div>
