<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>File Index</h2>
            <a href="/file-index" class="btn btn-outline-primary btn-sm">
                🔄 Refresh
            </a>
        </div>
        <p class="text-muted">Catalog Path: <code><?= htmlspecialchars($catalogPath) ?></code></p>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h5>Errors:</h5>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($files)): ?>
            <div class="mb-3">
                <span class="badge bg-primary"><?= $totalDirs ?> directories</span>
                <span class="badge bg-secondary"><?= $totalFiles ?> files</span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Path</th>
                            <th>Size</th>
                            <th>Modified</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                            <tr>
                                <td>
                                    <?php if ($file['isDir']): ?>
                                        <i class="text-warning">📁</i> <span class="badge bg-warning text-dark">DIR</span>
                                    <?php else: ?>
                                        <?php
                                        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                        $icon = match($extension) {
                                            'txt', 'md', 'readme' => '📄',
                                            'jpg', 'jpeg', 'png', 'gif', 'bmp' => '🖼️',
                                            'pdf' => '📕',
                                            'doc', 'docx' => '📘',
                                            'xls', 'xlsx' => '📗',
                                            'ppt', 'pptx' => '📙',
                                            'zip', 'rar', '7z', 'tar', 'gz' => '📦',
                                            'mp3', 'wav', 'ogg', 'flac' => '🎵',
                                            'mp4', 'avi', 'mkv', 'mov' => '🎬',
                                            'php', 'js', 'html', 'css', 'py', 'java', 'cpp', 'c' => '💻',
                                            default => '📄'
                                        };
                                        ?>
                                        <i><?= $icon ?></i> <span class="badge bg-info text-dark"><?= strtoupper($extension ?: 'FILE') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="margin-left: <?= $file['depth'] * 20 ?>px;">
                                        <?= htmlspecialchars($file['name']) ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?= htmlspecialchars($file['path']) ?>
                                </td>
                                <td>
                                    <?php if (!$file['isDir'] && $file['size'] > 0): ?>
                                        <?php
                                        $size = $file['size'];
                                        $units = ['B', 'KB', 'MB', 'GB'];
                                        $unitIndex = 0;
                                        while ($size >= 1024 && $unitIndex < count($units) - 1) {
                                            $size /= 1024;
                                            $unitIndex++;
                                        }
                                        echo number_format($size, 1) . ' ' . $units[$unitIndex];
                                        ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?= date('Y-m-d H:i:s', $file['modified']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                No files found in the catalog directory.
            </div>
        <?php endif; ?>
    </div>
</div>
