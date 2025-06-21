<div class="row">
    <div class="col-sm">
        <?php
        /** @var array | string $shellCommandRawContent */
        if (is_array($shellCommandRawContent)) {
            ?>
            <table class="table">
                <?php
                foreach ($shellCommandRawContent as $row) {
                    ?>
                    <tr>
                        <td><pre><?= $row ?></pre></td>
                    </tr>
                    <?php
                }
                ?>
            </table>
            <?php
        } else {
            ?>
            <pre><?= $shellCommandRawContent ?></pre>
            <?php
        }
        ?>
    </div>
</div>