<div class="row">
    <div class="col-sm">
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
<div class="row">
    <div class="col-sm">
        <a class="btn btn-primary" href="/">index</a>
        <a class="btn btn-primary" href="/top">top</a>
        <a class="btn btn-primary" href="/file-index">file index</a>
        <a class="btn btn-primary" href="/tools">tools</a>
    </div>
</div>
