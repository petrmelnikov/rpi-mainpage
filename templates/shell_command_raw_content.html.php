<div class="row">
    <div class="col-sm">
        <?php
        if (is_array($shellCommandRawContent)) {
            ?>
            <table class="table">
                <?php
                foreach ($shellCommandRawContent as $row) {
                    ?>
                    <tr>
                        <td><?= $row ?></td>
                    </tr>
                    <?php
                }
                ?>
            </table>
            <?php
        } else {
            echo $shellCommandRawContent;
        }
        ?>
    </div>
</div>