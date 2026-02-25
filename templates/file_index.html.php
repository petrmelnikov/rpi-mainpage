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

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-start">
                    <div class="col-12 col-lg-6">
                        <form action="/file-index/dir/create" method="POST" class="row g-2">
                            <div class="col-12">
                                <label class="form-label mb-1">Create directory</label>
                            </div>
                            <div class="col-8">
                                <input type="text" class="form-control" name="dirName" placeholder="New folder name" autocomplete="off" required>
                                <input type="hidden" name="parentPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                <input type="hidden" name="returnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                            </div>
                            <div class="col-4">
                                <button type="submit" class="btn btn-primary w-100">📁 Create</button>
                            </div>
                        </form>
                    </div>

                    <div class="col-12 col-lg-6">
                        <form action="/file-index/upload" method="POST" enctype="multipart/form-data" class="row g-2">
                            <div class="col-12">
                                <label class="form-label mb-1">Upload file</label>
                            </div>
                            <div class="col-8">
                                <input type="file" class="form-control" name="file" id="chunkUploadFile" multiple required>
                                <input type="hidden" name="targetPath" id="chunkUploadTargetPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                <input type="hidden" name="returnPath" id="chunkUploadReturnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                            </div>
                            <div class="col-4">
                                <button type="submit" class="btn btn-success w-100" id="chunkUploadBtn">⬆️ Upload</button>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-danger d-none mb-2" id="chunkUploadError"></div>
                                <div class="small text-muted d-none" id="chunkUploadStatus"></div>
                                <div class="progress d-none" style="height: 10px;" id="chunkUploadProgressWrap">
                                    <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="chunkUploadProgressBar"></div>
                                </div>
                                <div class="small text-muted d-none" id="chunkUploadHint">Chunked upload with resume is enabled.</div>
                            </div>
                        </form>
                    </div>

                    <div class="col-12">
                        <form action="/file-index/download-url" method="POST" class="row g-2">
                            <div class="col-12">
                                <label class="form-label mb-1">Download from URL (aria2c, fallback wget)</label>
                            </div>
                            <div class="col-12 col-lg-10">
                                <input type="url" class="form-control" name="url" placeholder="https://example.com/file.zip" autocomplete="off" required>
                                <input type="hidden" name="targetPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                <input type="hidden" name="returnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                            </div>
                            <div class="col-12 col-lg-2">
                                <button type="submit" class="btn btn-outline-success w-100">⬇️ Download</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
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
                                    <?php if (!$file['isDir']): ?>
                                        <?php
                                        $nameExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                        $isVideoFileForProgress = in_array($nameExt, ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi', 'm4v']);
                                        ?>
                                        <?php if ($isVideoFileForProgress): ?>
                                            <div class="small text-muted file-video-progress d-none" data-video-progress-path="<?= htmlspecialchars($file['path']) ?>">▶ Progress: <span class="file-video-progress-percent">0%</span></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
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
                                    <div class="d-inline-flex align-items-center flex-wrap gap-1 file-actions">
                                    <?php if ($file['isDir']): ?>
                                        <?php $isPinned = isset($pinnedPaths[$file['path']]); ?>
                                        <?php if ($file['isNavigable']): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary btn-icon btn-override-media-info"
                                                    data-media-path="<?= htmlspecialchars($file['path']) ?>"
                                                    data-media-name="<?= htmlspecialchars($file['name']) ?>"
                                                    data-media-is-dir="1"
                                                    aria-label="Override media info"
                                                    title="Override media info">
                                                📝
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($isPinned): ?>
                                            <form action="/file-index/unpin" method="POST" class="d-inline">
                                                <input type="hidden" name="path" value="<?= htmlspecialchars($file['path']) ?>">
                                                <input type="hidden" name="returnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                                <button type="submit" class="btn btn-sm btn-warning btn-icon" aria-label="Unpin directory" title="Unpin directory">
                                                    📌
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form action="/file-index/pin" method="POST" class="d-inline">
                                                <input type="hidden" name="path" value="<?= htmlspecialchars($file['path']) ?>">
                                                <input type="hidden" name="name" value="<?= htmlspecialchars($file['name']) ?>">
                                                <input type="hidden" name="returnPath" value="<?= htmlspecialchars($currentPath ?? '') ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning btn-icon" aria-label="Pin directory" title="Pin directory">
                                                    📌
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="/file-index/download?path=<?= urlencode($file['path']) ?>" 
                                           class="btn btn-sm btn-outline-primary btn-icon download-btn" 
                                           aria-label="Download directory as .tar.gz archive"
                                           title="Download directory as .tar.gz archive"
                                           onclick="showDownloadProgress(this)">
                                            📦
                                        </a>

                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary btn-icon btn-rename-dir"
                                                data-dir-path="<?= htmlspecialchars($file['path']) ?>"
                                                data-dir-name="<?= htmlspecialchars($file['name']) ?>"
                                                data-return-path="<?= htmlspecialchars($currentPath ?? '') ?>"
                                                aria-label="Rename directory"
                                                title="Rename directory">
                                            ✏️
                                        </button>

                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger btn-icon btn-delete-dir"
                                                data-dir-path="<?= htmlspecialchars($file['path']) ?>"
                                                data-dir-name="<?= htmlspecialchars($file['name']) ?>"
                                                data-return-path="<?= htmlspecialchars($currentPath ?? '') ?>"
                                                aria-label="Delete directory (must be empty)"
                                                title="Delete directory (must be empty)">
                                            🗑
                                        </button>
                                    <?php else: ?>
                                        <?php
                                        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                        $isPlayableVideo = in_array($fileExt, ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi', 'm4v']);
                                        $isNfoFile = ($fileExt === 'nfo');
                                        ?>
                                        <?php if (!$isNfoFile && $isPlayableVideo): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary btn-icon btn-override-media-info"
                                                    data-media-path="<?= htmlspecialchars($file['path']) ?>"
                                                    data-media-name="<?= htmlspecialchars($file['name']) ?>"
                                                    data-media-is-dir="0"
                                                    aria-label="Override media info"
                                                    title="Override media info">
                                                📝
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($isPlayableVideo): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-success btn-icon btn-play-video"
                                                    data-video-path="<?= htmlspecialchars($file['path']) ?>"
                                                    data-video-name="<?= htmlspecialchars($file['name']) ?>"
                                                    aria-label="Play video"
                                                    title="Play video">
                                                ▶️
                                            </button>
                                        <?php endif; ?>
                                        <a href="/file-index/download/file?path=<?= urlencode($file['path']) ?>" 
                                           class="btn btn-sm btn-outline-success btn-icon download-btn" 
                                           aria-label="Download file"
                                           title="Download file"
                                           download>
                                            💾
                                        </a>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger btn-icon btn-delete-file"
                                                data-file-path="<?= htmlspecialchars($file['path']) ?>"
                                                data-file-name="<?= htmlspecialchars($file['name']) ?>"
                                                data-return-path="<?= htmlspecialchars($currentPath ?? '') ?>"
                                                aria-label="Delete file"
                                                title="Delete file">
                                            🗑
                                        </button>
                                    <?php endif; ?>
                                    </div>
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
    button.innerHTML = '⏳';
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

<!-- Delete File Confirmation Modal -->
<div class="modal fade" id="deleteFileModal" tabindex="-1" aria-labelledby="deleteFileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteFileModalLabel">Confirm deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/file-index/delete" method="POST">
                <div class="modal-body">
                    <p class="mb-2">Delete file:</p>
                    <p class="mb-0"><strong id="deleteFileName"></strong></p>
                    <p class="text-muted small mb-0">This action cannot be undone.</p>

                    <input type="hidden" name="path" id="deleteFilePath" value="">
                    <input type="hidden" name="returnPath" id="deleteReturnPath" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Directory Confirmation Modal -->
<div class="modal fade" id="deleteDirModal" tabindex="-1" aria-labelledby="deleteDirModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteDirModalLabel">Confirm directory deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/file-index/dir/delete" method="POST">
                <div class="modal-body">
                    <p class="mb-2">Delete directory (must be empty):</p>
                    <p class="mb-0"><strong id="deleteDirName"></strong></p>
                    <p class="text-muted small mb-0">This action cannot be undone.</p>

                    <input type="hidden" name="path" id="deleteDirPath" value="">
                    <input type="hidden" name="returnPath" id="deleteDirReturnPath" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Rename Directory Modal -->
<div class="modal fade" id="renameDirModal" tabindex="-1" aria-labelledby="renameDirModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="renameDirModalLabel">Rename directory</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/file-index/dir/rename" method="POST">
                <div class="modal-body">
                    <div class="mb-2">
                        <div class="small text-muted">Current name:</div>
                        <div class="fw-semibold" id="renameDirCurrentName"></div>
                    </div>

                    <label class="form-label" for="renameDirNewName">New name</label>
                    <input type="text" class="form-control" name="newName" id="renameDirNewName" autocomplete="off" required>

                    <input type="hidden" name="path" id="renameDirPath" value="">
                    <input type="hidden" name="returnPath" id="renameDirReturnPath" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Rename</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Override Media Info Modal -->
<div class="modal fade" id="overrideMediaInfoModal" tabindex="-1" aria-labelledby="overrideMediaInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="overrideMediaInfoModalLabel">Override Media Info</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <div class="small text-muted">Target:</div>
                    <div class="fw-semibold" id="overrideMediaInfoTargetName"></div>
                </div>

                <div class="alert alert-danger d-none" id="overrideMediaInfoError"></div>
                <div class="alert alert-success d-none" id="overrideMediaInfoSuccess"></div>

                <input type="hidden" id="overrideMediaInfoPath" value="">

                <div class="mb-3">
                    <label for="overrideMediaInfoTitle" class="form-label">Title</label>
                    <input type="text" class="form-control" id="overrideMediaInfoTitle" autocomplete="off">
                </div>
                <div class="mb-0">
                    <label for="overrideMediaInfoYear" class="form-label">Year</label>
                    <input type="number" class="form-control" id="overrideMediaInfoYear" inputmode="numeric" min="1000" max="9999" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="overrideMediaInfoSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>

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
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let player = null;
    let seekZonesInitialized = false;
    let currentVideoPath = null;
    let timeUpdateTimeout = null;
    let isVideoReadyForSave = false; // Flag to prevent saving 0.00s on initial load
    let currentVideoProgressRequest = null;
    let holdSpeedRestoreValue = 1;
    let holdSpeedTimeout = null;
    let holdToSpeedActive = false;

    const videoModalElement = document.getElementById('videoPlayerModal');
    const videoElement = document.getElementById('videoPlayer');
    const modalTitle = document.getElementById('videoPlayerModalLabel');
    
    // Initialize Bootstrap modal only after DOM is ready and Bootstrap is loaded
    let videoModal = null;
    if (typeof bootstrap !== 'undefined') {
        videoModal = new bootstrap.Modal(videoModalElement);
    } else {
        console.error('Bootstrap is not loaded');
        return;
    }
    
    const SEEK_TIME = 10; // seconds
    const SAVE_INTERVAL = 5000; // 5 seconds
    const HOLD_TO_SPEED_RATE = 2;
    const HOLD_TO_SPEED_DELAY = 200;

    // Video MIME types mapping
    const mimeTypes = {
        'mp4': 'video/mp4', 'm4v': 'video/mp4', 'webm': 'video/webm', 'ogg': 'video/ogg',
        'mov': 'video/quicktime', 'mkv': 'video/x-matroska', 'avi': 'video/x-msvideo'
    };

    // --- Backend video progress helpers ---
    async function saveVideoTime(path, time, duration) {
        if (!path) return;
        // Prevent overwriting valid saved time with 0 if video hasn't started/restored properly yet
        if (!isVideoReadyForSave && time < 1) {
            return;
        }

        try {
            await fetch('/file-index/video-progress', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    path: path,
                    time: Number(time) || 0,
                    duration: Number(duration) || 0,
                }),
            });
        } catch (e) {
            console.error("Failed to save video time to backend", e);
        }
    }

    async function getSavedVideoTime(path) {
        if (!path) return 0;

        try {
            const url = '/file-index/video-progress?path=' + encodeURIComponent(path);
            const response = await fetch(url, { method: 'GET' });
            if (!response.ok) return 0;

            const payload = await response.json();
            if (!payload || !payload.ok) return 0;

            return parseFloat(payload.time || 0);
        } catch (e) {
            console.error("Failed to get video time from backend", e);
            return 0;
        }
    }

    function restoreSavedVideoTimeAsync(pathForRequest) {
        if (!pathForRequest) {
            return;
        }

        const progressRequest = currentVideoProgressRequest || getSavedVideoTime(pathForRequest);

        void progressRequest.then((savedTime) => {
            if (!player || currentVideoPath !== pathForRequest) {
                return;
            }
            if (!Number.isFinite(savedTime) || savedTime <= 0) {
                return;
            }

            const currentTime = Number(player.currentTime) || 0;
            if (currentTime > 0) {
                return;
            }

            const duration = Number(player.duration) || 0;
            const boundedTime = duration > 0
                ? Math.max(0, Math.min(duration, savedTime))
                : Math.max(0, savedTime);
            player.currentTime = boundedTime;
        });
    }

    async function loadVideoProgressForFileList() {
        const progressRows = Array.from(document.querySelectorAll('[data-video-progress-path]'));
        if (!progressRows.length) return;

        const paths = progressRows
            .map((row) => row.dataset.videoProgressPath || '')
            .filter((value) => value);

        if (!paths.length) return;

        try {
            const params = new URLSearchParams();
            for (const path of paths) {
                params.append('paths[]', path);
            }

            const response = await fetch('/file-index/video-progress/list?' + params.toString(), {
                method: 'GET',
            });
            if (!response.ok) return;

            const payload = await response.json();
            if (!payload || !payload.ok || !payload.progress) return;

            for (const row of progressRows) {
                const path = row.dataset.videoProgressPath || '';
                const progress = payload.progress[path];
                if (!progress) continue;

                const percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
                if (percent <= 0) continue;

                const percentEl = row.querySelector('.file-video-progress-percent');
                if (percentEl) {
                    percentEl.textContent = percent.toFixed(0) + '%';
                }
                row.classList.remove('d-none');
            }
        } catch (e) {
            console.error('Failed to load file list video progress', e);
        }
    }

    // Create seek zone HTML
    function createSeekZonesHTML() {
        return `
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
        `;
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

    // Seek video helper
    function seekVideo(seconds) {
        if (player) {
            const newTime = player.currentTime + seconds;
            player.currentTime = Math.max(0, Math.min(player.duration || Infinity, newTime));
        }
    }

    function startHoldToSpeed() {
        if (!player || holdToSpeedActive) return;
        holdSpeedRestoreValue = player.speed || 1;
        player.speed = HOLD_TO_SPEED_RATE;
        holdToSpeedActive = true;
    }

    function stopHoldToSpeed() {
        if (!player || !holdToSpeedActive) return;
        player.speed = holdSpeedRestoreValue;
        holdToSpeedActive = false;
    }

    function clearHoldToSpeedTimeout() {
        if (holdSpeedTimeout) {
            clearTimeout(holdSpeedTimeout);
            holdSpeedTimeout = null;
        }
    }

    function setupHoldToSpeed() {
        if (!player || !player.elements || !player.elements.container) return;

        const plyrContainer = player.elements.container;
        const videoWrapper = plyrContainer.querySelector('.plyr__video-wrapper');
        if (!videoWrapper) return;

        videoWrapper.addEventListener('touchstart', function(e) {
            if (e.target.closest('.plyr__controls') || e.target.closest('.seek-zone')) return;
            clearHoldToSpeedTimeout();
            holdSpeedTimeout = setTimeout(() => {
                startHoldToSpeed();
                holdSpeedTimeout = null;
            }, HOLD_TO_SPEED_DELAY);
        }, { passive: true });

        const finishHold = function() {
            clearHoldToSpeedTimeout();
            stopHoldToSpeed();
        };

        videoWrapper.addEventListener('touchend', finishHold, { passive: true });
        videoWrapper.addEventListener('touchcancel', finishHold, { passive: true });
        videoWrapper.addEventListener('touchmove', function(e) {
            if (e.touches && e.touches.length > 1) {
                finishHold();
            }
        }, { passive: true });
    }

    // Setup seek zones logic
    function setupSeekZones() {
        const plyrContainer = player && player.elements && player.elements.container 
            ? player.elements.container 
            : document.querySelector('.plyr');
            
        if (!plyrContainer || seekZonesInitialized) return;

        plyrContainer.insertAdjacentHTML('beforeend', createSeekZonesHTML());
        seekZonesInitialized = true;
        
        const seekZoneLeft = document.getElementById('seekZoneLeft');
        const seekZoneRight = document.getElementById('seekZoneRight');
        const seekIndicatorLeft = document.getElementById('seekIndicatorLeft');
        const seekIndicatorRight = document.getElementById('seekIndicatorRight');
        
        function setupDoubleTap(element, seekSeconds, indicator) {
            let lastTap = 0;
            const DOUBLE_TAP_DELAY = 300;
            
            function handleTap(e) {
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                const now = Date.now();
                if (now - lastTap < DOUBLE_TAP_DELAY) {
                    seekVideo(seekSeconds);
                    showRipple(element, e);
                    showSeekIndicator(indicator);
                    lastTap = 0;
                } else {
                    lastTap = now;
                }
            }
            element.addEventListener('click', handleTap, true);
            element.addEventListener('touchend', handleTap, true);
            element.addEventListener('dblclick', (e) => {
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
            }, true);
        }
        
        if (seekZoneLeft) setupDoubleTap(seekZoneLeft, -SEEK_TIME, seekIndicatorLeft);
        if (seekZoneRight) setupDoubleTap(seekZoneRight, SEEK_TIME, seekIndicatorRight);
        
        plyrContainer.addEventListener('dblclick', (e) => {
            if (e.target.closest('.seek-zone')) {
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
            }
        }, true);
    }
    
    // Initialize Plyr ONCE
    function initPlyr() {
        if (player || typeof Plyr === 'undefined') return;
        
        player = new Plyr(videoElement, {
            controls: [
                'play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 
                'volume', 'settings', 'pip', 'fullscreen'
            ],
            settings: ['quality', 'speed'],
            speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
            keyboard: { focused: true, global: true },
            seekTime: SEEK_TIME,
            clickToPlay: true,
            disableContextMenu: false
        });
        
        player.on('ready', function() {
            setupSeekZones();
            setupHoldToSpeed();
        });

        player.on('loadedmetadata', function() {
            isVideoReadyForSave = true;
            restoreSavedVideoTimeAsync(currentVideoPath);
        });

        player.on('timeupdate', function() {
            if (isVideoReadyForSave && !timeUpdateTimeout) {
                timeUpdateTimeout = setTimeout(() => {
                    void saveVideoTime(currentVideoPath, player.currentTime, player.duration);
                    timeUpdateTimeout = null;
                }, SAVE_INTERVAL);
            }
        });
    }
    
    // Initialize on load
    initPlyr();
    void loadVideoProgressForFileList();
        
    // Stop video and save final time when modal closes
    videoModalElement.addEventListener('hidden.bs.modal', function() {
        if (player) {
            clearHoldToSpeedTimeout();
            stopHoldToSpeed();
            clearTimeout(timeUpdateTimeout);
            timeUpdateTimeout = null;
            if (isVideoReadyForSave) {
                void saveVideoTime(currentVideoPath, player.currentTime, player.duration);
            }
            player.pause();
            isVideoReadyForSave = false;
            currentVideoPath = null;
            currentVideoProgressRequest = null;
        }
    });
    
    // Handle play button clicks
    document.addEventListener('click', function(e) {
        const playBtn = e.target.closest('.btn-play-video');
        if (!playBtn) return;
        
        currentVideoPath = playBtn.dataset.videoPath; // Store current video path
        const videoName = playBtn.dataset.videoName;

        if (!currentVideoPath) return;

        currentVideoProgressRequest = getSavedVideoTime(currentVideoPath);

        initPlyr();
        
        isVideoReadyForSave = false;

        const ext = videoName.split('.').pop().toLowerCase();
        const mimeType = mimeTypes[ext] || 'video/mp4';
        
        modalTitle.textContent = videoName;
        
        const streamUrl = '/file-index/stream?path=' + encodeURIComponent(currentVideoPath);
        
        player.source = {
            type: 'video',
            title: videoName,
            sources: [
                {
                    src: streamUrl,
                    type: mimeType,
                },
            ],
        };
        
        if (videoModal) {
            videoModal.show();
        }
    });

    // --- Delete file modal ---
    const deleteModalElement = document.getElementById('deleteFileModal');
    const deleteFileNameEl = document.getElementById('deleteFileName');
    const deleteFilePathInput = document.getElementById('deleteFilePath');
    const deleteReturnPathInput = document.getElementById('deleteReturnPath');

    let deleteModal = null;
    if (deleteModalElement && typeof bootstrap !== 'undefined') {
        deleteModal = new bootstrap.Modal(deleteModalElement);
    }

    document.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('.btn-delete-file');
        if (!deleteBtn || !deleteModal) return;

        const filePath = deleteBtn.dataset.filePath || '';
        const fileName = deleteBtn.dataset.fileName || '';
        const returnPath = deleteBtn.dataset.returnPath || '';

        deleteFileNameEl.textContent = fileName;
        deleteFilePathInput.value = filePath;
        deleteReturnPathInput.value = returnPath;

        deleteModal.show();
    });

    // --- Delete directory modal ---
    const deleteDirModalElement = document.getElementById('deleteDirModal');
    const deleteDirNameEl = document.getElementById('deleteDirName');
    const deleteDirPathInput = document.getElementById('deleteDirPath');
    const deleteDirReturnPathInput = document.getElementById('deleteDirReturnPath');

    let deleteDirModal = null;
    if (deleteDirModalElement && typeof bootstrap !== 'undefined') {
        deleteDirModal = new bootstrap.Modal(deleteDirModalElement);
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-delete-dir');
        if (!btn || !deleteDirModal) return;

        const dirPath = btn.dataset.dirPath || '';
        const dirName = btn.dataset.dirName || '';
        const returnPath = btn.dataset.returnPath || '';

        deleteDirNameEl.textContent = dirName;
        deleteDirPathInput.value = dirPath;
        deleteDirReturnPathInput.value = returnPath;

        deleteDirModal.show();
    });

    // --- Rename directory modal ---
    const renameDirModalElement = document.getElementById('renameDirModal');
    const renameDirCurrentNameEl = document.getElementById('renameDirCurrentName');
    const renameDirNewNameInput = document.getElementById('renameDirNewName');
    const renameDirPathInput = document.getElementById('renameDirPath');
    const renameDirReturnPathInput = document.getElementById('renameDirReturnPath');

    let renameDirModal = null;
    if (renameDirModalElement && typeof bootstrap !== 'undefined') {
        renameDirModal = new bootstrap.Modal(renameDirModalElement);
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-rename-dir');
        if (!btn || !renameDirModal) return;

        const dirPath = btn.dataset.dirPath || '';
        const dirName = btn.dataset.dirName || '';
        const returnPath = btn.dataset.returnPath || '';

        renameDirCurrentNameEl.textContent = dirName;
        renameDirNewNameInput.value = dirName;
        renameDirPathInput.value = dirPath;
        renameDirReturnPathInput.value = returnPath;

        renameDirModal.show();
        setTimeout(() => renameDirNewNameInput && renameDirNewNameInput.focus(), 50);
    });

    // --- Override media info modal ---
    const overrideModalElement = document.getElementById('overrideMediaInfoModal');
    const overrideTargetNameEl = document.getElementById('overrideMediaInfoTargetName');
    const overridePathInput = document.getElementById('overrideMediaInfoPath');
    const overrideTitleInput = document.getElementById('overrideMediaInfoTitle');
    const overrideYearInput = document.getElementById('overrideMediaInfoYear');
    const overrideSaveBtn = document.getElementById('overrideMediaInfoSaveBtn');
    const overrideErrorEl = document.getElementById('overrideMediaInfoError');
    const overrideSuccessEl = document.getElementById('overrideMediaInfoSuccess');

    let overrideModal = null;
    if (overrideModalElement && typeof bootstrap !== 'undefined') {
        overrideModal = new bootstrap.Modal(overrideModalElement);
    }

    function setOverrideError(msg) {
        if (!overrideErrorEl) return;
        if (!msg) {
            overrideErrorEl.classList.add('d-none');
            overrideErrorEl.textContent = '';
            return;
        }
        overrideErrorEl.textContent = msg;
        overrideErrorEl.classList.remove('d-none');
    }

    function setOverrideSuccess(msg) {
        if (!overrideSuccessEl) return;
        if (!msg) {
            overrideSuccessEl.classList.add('d-none');
            overrideSuccessEl.textContent = '';
            return;
        }
        overrideSuccessEl.textContent = msg;
        overrideSuccessEl.classList.remove('d-none');
    }

    async function loadExistingNfo(path) {
        const url = '/file-index/nfo?path=' + encodeURIComponent(path);
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) {
            let err = (data && data.error) ? data.error : '';
            if (!err) {
                const text = await res.text().catch(() => '');
                err = text ? text.slice(0, 300) : 'Failed to load existing NFO';
            }
            throw new Error(err);
        }
        return data;
    }

    async function saveNfo(path, title, year) {
        const body = new URLSearchParams();
        body.set('path', path);
        body.set('title', title);
        body.set('year', year);

        const res = await fetch('/file-index/nfo', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) {
            let err = (data && data.error) ? data.error : '';
            if (!err) {
                const text = await res.text().catch(() => '');
                err = text ? text.slice(0, 300) : 'Failed to save NFO';
            }
            throw new Error(err);
        }
        return data;
    }

    document.addEventListener('click', async function(e) {
        const btn = e.target.closest('.btn-override-media-info');
        if (!btn || !overrideModal) return;

        const mediaPath = btn.dataset.mediaPath || '';
        const mediaName = btn.dataset.mediaName || '';
        if (!mediaPath) return;

        setOverrideError('');
        setOverrideSuccess('');
        overrideTargetNameEl.textContent = mediaName;
        overridePathInput.value = mediaPath;
        overrideTitleInput.value = '';
        overrideYearInput.value = '';
        overrideSaveBtn.disabled = true;

        overrideModal.show();

        try {
            const data = await loadExistingNfo(mediaPath);
            overrideTitleInput.value = data.title || '';
            overrideYearInput.value = data.year || '';
            overrideSaveBtn.disabled = false;
        } catch (err) {
            setOverrideError(err && err.message ? err.message : 'Failed to load existing NFO');
            overrideSaveBtn.disabled = false;
        }
    });

    if (overrideSaveBtn) {
        overrideSaveBtn.addEventListener('click', async function() {
            const path = overridePathInput.value || '';
            const title = (overrideTitleInput.value || '').trim();
            const year = (overrideYearInput.value || '').trim();

            setOverrideError('');
            setOverrideSuccess('');

            if (!path) {
                setOverrideError('Missing target path');
                return;
            }
            if (!title) {
                setOverrideError('Title is required');
                return;
            }

            overrideSaveBtn.disabled = true;
            try {
                await saveNfo(path, title, year);
                setOverrideSuccess('Saved');
                // Refresh page so the created .nfo shows up in listing
                window.location.reload();
            } catch (err) {
                setOverrideError(err && err.message ? err.message : 'Failed to save NFO');
                overrideSaveBtn.disabled = false;
            }
        });
    }

    // --- Chunked upload ---
    const uploadFileInput = document.getElementById('chunkUploadFile');
    const uploadTargetPathInput = document.getElementById('chunkUploadTargetPath');
    const uploadReturnPathInput = document.getElementById('chunkUploadReturnPath');
    const uploadBtn = document.getElementById('chunkUploadBtn');
    const uploadErrorEl = document.getElementById('chunkUploadError');
    const uploadStatusEl = document.getElementById('chunkUploadStatus');
    const uploadProgressWrap = document.getElementById('chunkUploadProgressWrap');
    const uploadProgressBar = document.getElementById('chunkUploadProgressBar');
    const uploadHintEl = document.getElementById('chunkUploadHint');

    function setUploadError(msg) {
        if (!uploadErrorEl) return;
        if (!msg) {
            uploadErrorEl.classList.add('d-none');
            uploadErrorEl.textContent = '';
            return;
        }
        uploadErrorEl.textContent = msg;
        uploadErrorEl.classList.remove('d-none');
    }

    function setUploadStatus(msg) {
        if (!uploadStatusEl) return;
        if (!msg) {
            uploadStatusEl.classList.add('d-none');
            uploadStatusEl.textContent = '';
            return;
        }
        uploadStatusEl.textContent = msg;
        uploadStatusEl.classList.remove('d-none');
    }

    function setUploadProgress(pct) {
        if (!uploadProgressWrap || !uploadProgressBar) return;
        uploadProgressWrap.classList.remove('d-none');
        const v = Math.max(0, Math.min(100, pct));
        uploadProgressBar.style.width = v.toFixed(1) + '%';
        uploadProgressBar.setAttribute('aria-valuenow', String(v));
        uploadProgressBar.textContent = v >= 12 ? (v.toFixed(0) + '%') : '';
    }

    async function postForm(url, params) {
        const body = new URLSearchParams();
        for (const [k, v] of Object.entries(params)) {
            body.set(k, String(v));
        }
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) {
            const err = (data && data.error) ? String(data.error) : ('Request failed: ' + res.status);
            throw new Error(err);
        }
        return data;
    }

    async function uploadChunk(uploadId, chunkIndex, blob, fileName, attempt = 1) {
        const fd = new FormData();
        fd.set('uploadId', uploadId);
        fd.set('chunkIndex', String(chunkIndex));
        fd.set('chunk', blob, fileName);

        const res = await fetch('/file-index/upload/chunk', {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) {
            const err = (data && data.error) ? String(data.error) : ('Chunk upload failed: ' + res.status);
            if (attempt < 3) {
                await new Promise(r => setTimeout(r, 300 * attempt));
                return uploadChunk(uploadId, chunkIndex, blob, fileName, attempt + 1);
            }
            throw new Error(err);
        }
        return data;
    }

    async function runChunkedUpload(file, targetPath, returnPath, shouldRedirect = true) {
        const CHUNK_SIZE = 16 * 1024 * 1024; // 16MB
        const MAX_PARALLEL_CHUNKS = 3;

        setUploadError('');
        if (uploadHintEl) uploadHintEl.classList.remove('d-none');
        setUploadStatus('Initializing upload…');
        setUploadProgress(0);

        const init = await postForm('/file-index/upload/init', {
            targetPath,
            fileName: file.name,
            fileSize: file.size,
            lastModified: file.lastModified || 0,
            chunkSize: CHUNK_SIZE,
        });

        const uploadId = init.uploadId;
        const chunkSize = init.chunkSize;
        const totalChunks = init.totalChunks;
        const received = Array.isArray(init.received) ? init.received : [];
        const receivedSet = new Set(received.map(n => Number(n)));

        let done = receivedSet.size;
        setUploadStatus(`Uploading ${file.name} (${done}/${totalChunks} chunks ready)…`);
        setUploadProgress((done / totalChunks) * 100);

        const pendingChunks = [];
        for (let i = 0; i < totalChunks; i++) {
            if (!receivedSet.has(i)) {
                pendingChunks.push(i);
            }
        }

        let queuePos = 0;
        const workerCount = Math.min(MAX_PARALLEL_CHUNKS, pendingChunks.length || 1);

        const uploadWorker = async () => {
            while (true) {
                const idx = queuePos;
                queuePos++;
                if (idx >= pendingChunks.length) {
                    return;
                }

                const i = pendingChunks[idx];
                const start = i * chunkSize;
                const end = Math.min(file.size, start + chunkSize);
                const blob = file.slice(start, end);

                setUploadStatus(`Uploading ${file.name} (${done}/${totalChunks})…`);
                await uploadChunk(uploadId, i, blob, file.name);

                receivedSet.add(i);
                done++;
                setUploadProgress((done / totalChunks) * 100);
            }
        };

        await Promise.all(Array.from({ length: workerCount }, () => uploadWorker()));

        setUploadStatus('Finalizing…');
        await postForm('/file-index/upload/finish', { uploadId });

        if (shouldRedirect) {
            // Refresh directory view
            const url = '/file-index' + (returnPath ? ('?path=' + encodeURIComponent(returnPath)) : '');
            window.location.href = url;
        }
    }

    // Intercept upload form submit for resumable chunked upload.
    if (uploadFileInput && uploadBtn && uploadTargetPathInput && uploadReturnPathInput) {
        const uploadForm = uploadBtn.closest('form');
        if (uploadForm) {
            uploadForm.addEventListener('submit', async function(e) {
                // If browser doesn't support required APIs, fall back to normal upload.
                if (!window.fetch || !window.FormData || !uploadFileInput.files || uploadFileInput.files.length === 0) {
                    return;
                }
                e.preventDefault();

                const files = Array.from(uploadFileInput.files || []);
                const targetPath = uploadTargetPathInput.value || '';
                const returnPath = uploadReturnPathInput.value || '';

                uploadBtn.disabled = true;
                uploadFileInput.disabled = true;

                try {
                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];
                        setUploadStatus(`File ${i + 1}/${files.length}: ${file.name}`);
                        await runChunkedUpload(file, targetPath, returnPath, false);
                    }

                    const url = '/file-index' + (returnPath ? ('?path=' + encodeURIComponent(returnPath)) : '');
                    window.location.href = url;
                } catch (err) {
                    setUploadError(err && err.message ? err.message : 'Upload failed');
                    setUploadStatus('');
                    // Keep progress visible for debugging
                    uploadBtn.disabled = false;
                    uploadFileInput.disabled = false;
                }
            });
        }
    }
});
</script>
