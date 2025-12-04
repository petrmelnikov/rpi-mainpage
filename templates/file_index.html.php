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
        
        <?php if (!empty($pinnedDirectories)): ?>
            <div class="card mb-3 border-warning">
                <div class="card-header bg-warning bg-opacity-25">
                    <strong>📌 Pinned Directories</strong>
                </div>
                <div class="card-body p-2">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($pinnedDirectories as $pinnedDir): ?>
                            <div class="btn-group" role="group">
                                <a href="/file-index?path=<?= urlencode($pinnedDir['path']) ?>" 
                                   class="btn btn-outline-warning btn-sm">
                                    📁 <?= htmlspecialchars($pinnedDir['name']) ?>
                                </a>
                                <form action="/file-index/unpin" method="POST" class="d-inline">
                                    <input type="hidden" name="path" value="<?= htmlspecialchars($pinnedDir['path']) ?>">
                                    <input type="hidden" name="returnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Unpin directory">
                                        ✕
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

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
                                        <?php
                                        $isPinned = false;
                                        foreach ($pinnedDirectories as $pinnedDir) {
                                            if ($pinnedDir['path'] === $file['path']) {
                                                $isPinned = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php if ($isPinned): ?>
                                            <form action="/file-index/unpin" method="POST" class="d-inline me-1">
                                                <input type="hidden" name="path" value="<?= htmlspecialchars($file['path']) ?>">
                                                <input type="hidden" name="returnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                                <button type="submit" class="btn btn-sm btn-warning" title="Unpin directory">
                                                    📌 Pinned
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form action="/file-index/pin" method="POST" class="d-inline me-1">
                                                <input type="hidden" name="path" value="<?= htmlspecialchars($file['path']) ?>">
                                                <input type="hidden" name="name" value="<?= htmlspecialchars($file['name']) ?>">
                                                <input type="hidden" name="returnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Pin directory">
                                                    📌 Pin
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="/file-index/download?path=<?= urlencode($file['path']) ?>" 
                                           class="btn btn-sm btn-outline-primary download-btn" 
                                           title="Download directory as .tar.gz archive"
                                           onclick="showDownloadProgress(this)">
                                            📦 Archive
                                        </a>
                                    <?php else: ?>
                                        <?php
                                        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                        $isPlayableVideo = in_array($fileExt, ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi', 'm4v']);
                                        ?>
                                        <?php if ($isPlayableVideo): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-success btn-play-video me-1"
                                                    data-video-path="<?= htmlspecialchars($file['path']) ?>"
                                                    data-video-name="<?= htmlspecialchars($file['name']) ?>"
                                                    title="Play video">
                                                ▶️ Play
                                            </button>
                                        <?php endif; ?>
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

<!-- Video Player Modal -->
<div class="modal fade video-modal" id="videoPlayerModal" tabindex="-1" aria-labelledby="videoPlayerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0 py-2">
                <h6 class="modal-title text-white" id="videoPlayerModalLabel">Video Player</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="video-container">
                    <video id="videoPlayer" playsinline controls>
                        <source id="videoSource" src="" type="video/mp4">
                        Your browser does not support HTML5 video.
                    </video>
                    <!-- Double-tap seek zones -->
                    <div class="seek-zone seek-zone-left" id="seekZoneLeft">
                        <div class="seek-indicator" id="seekIndicatorLeft">
                            <svg viewBox="0 0 24 24"><path d="M12.5 3C17.15 3 21.08 6.03 22.47 10.22L20.1 11C19.05 7.81 16.04 5.5 12.5 5.5C10.54 5.5 8.77 6.22 7.38 7.38L10 10H3V3L5.6 5.6C7.45 4 9.85 3 12.5 3M10 12L8 14H11V22H13V14H16L14 12H10Z"/></svg>
                            <span>10s</span>
                        </div>
                    </div>
                    <div class="seek-zone seek-zone-right" id="seekZoneRight">
                        <div class="seek-indicator" id="seekIndicatorRight">
                            <svg viewBox="0 0 24 24"><path d="M11.5 3C6.85 3 2.92 6.03 1.53 10.22L3.9 11C4.95 7.81 7.96 5.5 11.5 5.5C13.46 5.5 15.23 6.22 16.62 7.38L14 10H21V3L18.4 5.6C16.55 4 14.15 3 11.5 3M10 12L8 14H11V22H13V14H16L14 12H10Z"/></svg>
                            <span>10s</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    let player = null;
    const videoModal = document.getElementById('videoPlayerModal');
    const videoElement = document.getElementById('videoPlayer');
    const videoSource = document.getElementById('videoSource');
    const modalTitle = document.getElementById('videoPlayerModalLabel');
    
    // Video MIME types mapping
    const mimeTypes = {
        'mp4': 'video/mp4',
        'm4v': 'video/mp4',
        'webm': 'video/webm',
        'ogg': 'video/ogg',
        'mov': 'video/quicktime',
        'mkv': 'video/x-matroska',
        'avi': 'video/x-msvideo'
    };
    
    // Seek zones
    const seekZoneLeft = document.getElementById('seekZoneLeft');
    const seekZoneRight = document.getElementById('seekZoneRight');
    const seekIndicatorLeft = document.getElementById('seekIndicatorLeft');
    const seekIndicatorRight = document.getElementById('seekIndicatorRight');
    const SEEK_TIME = 10; // seconds
    
    // Double-tap detection
    function createDoubleTapHandler(element, callback) {
        let lastTap = 0;
        let tapTimeout = null;
        const DOUBLE_TAP_DELAY = 300; // ms
        
        element.addEventListener('click', function(e) {
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastTap;
            
            clearTimeout(tapTimeout);
            
            if (tapLength < DOUBLE_TAP_DELAY && tapLength > 0) {
                // Double tap detected
                e.preventDefault();
                e.stopPropagation();
                callback(e);
                lastTap = 0;
            } else {
                // Single tap - wait to see if it becomes double
                lastTap = currentTime;
                tapTimeout = setTimeout(function() {
                    lastTap = 0;
                }, DOUBLE_TAP_DELAY);
            }
        });
        
        // Also handle touch events for mobile
        element.addEventListener('touchend', function(e) {
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastTap;
            
            clearTimeout(tapTimeout);
            
            if (tapLength < DOUBLE_TAP_DELAY && tapLength > 0) {
                // Double tap detected
                e.preventDefault();
                callback(e);
                lastTap = 0;
            } else {
                lastTap = currentTime;
                tapTimeout = setTimeout(function() {
                    lastTap = 0;
                }, DOUBLE_TAP_DELAY);
            }
        });
    }
    
    // Show ripple effect
    function showRipple(element, e) {
        const ripple = document.createElement('div');
        ripple.className = 'seek-ripple';
        const rect = element.getBoundingClientRect();
        const x = (e.clientX || (e.changedTouches && e.changedTouches[0].clientX) || rect.width / 2) - rect.left;
        const y = (e.clientY || (e.changedTouches && e.changedTouches[0].clientY) || rect.height / 2) - rect.top;
        ripple.style.left = x + 'px';
        ripple.style.top = y + 'px';
        ripple.style.width = '50px';
        ripple.style.height = '50px';
        ripple.style.marginLeft = '-25px';
        ripple.style.marginTop = '-25px';
        element.appendChild(ripple);
        setTimeout(() => ripple.remove(), 400);
    }
    
    // Show seek indicator
    function showSeekIndicator(indicator) {
        indicator.classList.remove('show');
        void indicator.offsetWidth; // Trigger reflow
        indicator.classList.add('show');
        setTimeout(() => indicator.classList.remove('show'), 500);
    }
    
    // Seek backward (left zone)
    if (seekZoneLeft) {
        createDoubleTapHandler(seekZoneLeft, function(e) {
            if (player) {
                player.currentTime = Math.max(0, player.currentTime - SEEK_TIME);
            } else {
                videoElement.currentTime = Math.max(0, videoElement.currentTime - SEEK_TIME);
            }
            showRipple(seekZoneLeft, e);
            showSeekIndicator(seekIndicatorLeft);
        });
    }
    
    // Seek forward (right zone)
    if (seekZoneRight) {
        createDoubleTapHandler(seekZoneRight, function(e) {
            const duration = player ? player.duration : videoElement.duration;
            if (player) {
                player.currentTime = Math.min(duration, player.currentTime + SEEK_TIME);
            } else {
                videoElement.currentTime = Math.min(duration, videoElement.currentTime + SEEK_TIME);
            }
            showRipple(seekZoneRight, e);
            showSeekIndicator(seekIndicatorRight);
        });
    }
    
    // Initialize Plyr when modal opens
    if (videoModal) {
        videoModal.addEventListener('shown.bs.modal', function() {
            if (!player && typeof Plyr !== 'undefined') {
                player = new Plyr(videoElement, {
                    controls: [
                        'play-large',
                        'play',
                        'progress',
                        'current-time',
                        'duration',
                        'mute',
                        'volume',
                        'settings',
                        'pip',
                        'fullscreen'
                    ],
                    settings: ['quality', 'speed'],
                    speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
                    keyboard: { focused: true, global: true },
                    seekTime: SEEK_TIME
                });
            }
            if (player) {
                player.play();
            }
        });
        
        // Stop video when modal closes
        videoModal.addEventListener('hidden.bs.modal', function() {
            if (player) {
                player.pause();
                player.currentTime = 0;
            }
            videoElement.pause();
            videoElement.currentTime = 0;
        });
    }
    
    // Handle play button clicks
    document.addEventListener('click', function(e) {
        const playBtn = e.target.closest('.btn-play-video');
        if (!playBtn) return;
        
        const videoPath = playBtn.dataset.videoPath;
        const videoName = playBtn.dataset.videoName;
        
        if (!videoPath) return;
        
        // Get file extension and set appropriate MIME type
        const ext = videoName.split('.').pop().toLowerCase();
        const mimeType = mimeTypes[ext] || 'video/mp4';
        
        // Update modal title
        modalTitle.textContent = videoName;
        
        // Set video source
        const streamUrl = '/file-index/stream?path=' + encodeURIComponent(videoPath);
        videoSource.src = streamUrl;
        videoSource.type = mimeType;
        
        // Reload video element
        videoElement.load();
        
        // Show modal
        const modal = new bootstrap.Modal(videoModal);
        modal.show();
    });
})();
</script>
