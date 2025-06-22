<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>File Index</h2>
            <div>
                <a href="/file-index/download?path=<?= urlencode($currentPath ?? '') ?>" 
                   class="btn btn-outline-success btn-sm me-2 download-btn"
                   onclick="showDownloadProgress(this)">
                    📦 Download Current Directory
                </a>
                <a href="/file-index<?= !empty($currentPath) ? '?path=' . urlencode($currentPath) : '' ?>" 
                   class="btn btn-outline-primary btn-sm">
                    🔄 Refresh
                </a>
            </div>
        </div>
        
        <!-- Breadcrumb Navigation -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <?php if ($index === count($breadcrumbs) - 1): ?>
                        <li class="breadcrumb-item active" aria-current="page">
                            <?= htmlspecialchars($crumb['name']) ?>
                        </li>
                    <?php else: ?>
                        <li class="breadcrumb-item">
                            <a href="/file-index<?= !empty($crumb['path']) ? '?path=' . urlencode($crumb['path']) : '' ?>">
                                <?= htmlspecialchars($crumb['name']) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
        
        <?php if (!empty($currentPath)): ?>
            <div class="mb-3">
                <?php
                // Calculate parent path
                $pathParts = explode('/', $currentPath);
                array_pop($pathParts);
                $parentPath = implode('/', $pathParts);
                ?>
                <a href="/file-index<?= !empty($parentPath) ? '?path=' . urlencode($parentPath) : '' ?>" 
                   class="btn btn-outline-secondary btn-sm">
                    ⬆️ Parent Directory
                </a>
            </div>
        <?php endif; ?>
        
        <p class="text-muted">
            Current Path: <code><?= htmlspecialchars($currentFullPath ?? $catalogPath) ?></code>
        </p>
        
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                            <tr>
                                <td>
                                    <?php if ($file['isDir']): ?>
                                        <i class="text-warning">📁</i> 
                                        <span class="badge bg-warning text-dark">
                                            <?= $file['isNavigable'] ? 'DIR' : 'DIR (restricted)' ?>
                                        </span>
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
                                    <span class="fw-medium">
                                        <?php if ($file['isDir'] && $file['isNavigable']): ?>
                                            <a href="/file-index?path=<?= urlencode($file['path']) ?>" 
                                               class="text-decoration-none">
                                                <?= htmlspecialchars($file['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($file['name']) ?>
                                        <?php endif; ?>
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
                                <td>
                                    <?php if ($file['isDir']): ?>
                                        <a href="/file-index/download?path=<?= urlencode($file['path']) ?>" 
                                           class="btn btn-sm btn-outline-primary download-btn" 
                                           title="Download directory as .tar.gz archive"
                                           onclick="showDownloadProgress(this)">
                                            📦 Archive
                                        </a>
                                    <?php else: ?>
                                        <a href="/file-index/download/file?path=<?= urlencode($file['path']) ?>" 
                                           class="btn btn-sm btn-outline-success download-btn" 
                                           title="Download file"
                                           download>
                                            💾 Download
                                        </a>
                                    <?php endif; ?>
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

<script>
function showDownloadProgress(button) {
    // Store original content
    const originalText = button.innerHTML;
    const originalClass = button.className;
    
    // Show loading state
    button.innerHTML = '⏳ Creating Archive...';
    button.className = button.className.replace('btn-outline-success', 'btn-secondary');
    button.disabled = true;
    
    // Reset after download starts (browsers will handle the actual download)
    setTimeout(() => {
        button.innerHTML = originalText;
        button.className = originalClass;
        button.disabled = false;
    }, 3000);
}
</script>

<script>
function showDownloadProgress(button) {
    const originalText = button.innerHTML;
    const originalClass = button.className;
    
    // Show loading state
    button.innerHTML = '⏳ Preparing Download...';
    button.className = button.className.replace('btn-outline-', 'btn-');
    button.disabled = true;
    
    // Reset button after a delay (in case download starts immediately)
    setTimeout(function() {
        button.innerHTML = originalText;
        button.className = originalClass;
        button.disabled = false;
    }, 3000);
}
</script>
