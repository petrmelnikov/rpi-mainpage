<div class="row mt-3">
    <div class="col-12">
        <h3>YouTube Player</h3>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div id="ytPlayerPlaceholder" class="border rounded p-4 bg-light text-center text-muted" style="aspect-ratio: 16 / 9; display: flex; align-items: center; justify-content: center;">
            Выберите видео из списка справа
        </div>
        <div id="ytPlayerContainer" class="d-none" style="aspect-ratio: 16 / 9;"></div>
    </div>
    <div class="col-lg-4">
        <form id="addYoutubeForm" class="mb-3">
            <label for="youtubeUrlInput" class="form-label">Добавить ссылку на YouTube</label>
            <div class="input-group">
                <input id="youtubeUrlInput" type="url" class="form-control" placeholder="https://www.youtube.com/watch?v=..." required>
                <button class="btn btn-primary" type="submit">Добавить</button>
            </div>
            <div id="youtubeFormError" class="text-danger small mt-1 d-none"></div>
        </form>

        <div class="border rounded p-2" style="max-height: 65vh; overflow: auto;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Список видео</strong>
                <span id="youtubeListCount" class="text-muted small">0</span>
            </div>
            <div id="youtubeList" class="list-group"></div>
            <div id="youtubeEmptyState" class="text-muted small">Список пуст</div>
        </div>
    </div>
</div>

<script>
(function () {
    const listEl = document.getElementById('youtubeList');
    const emptyStateEl = document.getElementById('youtubeEmptyState');
    const listCountEl = document.getElementById('youtubeListCount');
    const formEl = document.getElementById('addYoutubeForm');
    const urlInputEl = document.getElementById('youtubeUrlInput');
    const formErrorEl = document.getElementById('youtubeFormError');
    const playerContainerEl = document.getElementById('ytPlayerContainer');
    const playerPlaceholderEl = document.getElementById('ytPlayerPlaceholder');

    let items = [];
    let currentVideoId = '';
    let player = null;
    let isYoutubeApiReady = false;
    let trackInterval = null;

    function showFormError(text) {
        formErrorEl.textContent = text;
        formErrorEl.classList.remove('d-none');
    }

    function clearFormError() {
        formErrorEl.textContent = '';
        formErrorEl.classList.add('d-none');
    }

    function extractYoutubeVideoId(raw) {
        const value = String(raw || '').trim();
        if (!value) return '';

        let parsed = null;
        try {
            parsed = new URL(value);
        } catch (e) {
            return '';
        }

        const host = parsed.hostname.replace(/^www\./, '').toLowerCase();

        if (host === 'youtu.be') {
            const id = parsed.pathname.replace(/^\//, '').split('/')[0];
            return /^[a-zA-Z0-9_-]{11}$/.test(id) ? id : '';
        }

        if (host === 'youtube.com' || host === 'm.youtube.com') {
            if (parsed.pathname === '/watch') {
                const id = parsed.searchParams.get('v') || '';
                return /^[a-zA-Z0-9_-]{11}$/.test(id) ? id : '';
            }

            const pathParts = parsed.pathname.split('/').filter(Boolean);
            if (pathParts.length >= 2 && ['shorts', 'embed', 'live'].includes(pathParts[0])) {
                const id = pathParts[1] || '';
                return /^[a-zA-Z0-9_-]{11}$/.test(id) ? id : '';
            }
        }

        return '';
    }

    function getVideoLabel(item) {
        return item.title && item.title.trim() ? item.title : (item.url || item.id);
    }

    function updateListMeta() {
        listCountEl.textContent = String(items.length);
        emptyStateEl.classList.toggle('d-none', items.length > 0);
    }

    function stopProgressTracking() {
        if (trackInterval) {
            clearInterval(trackInterval);
            trackInterval = null;
        }
    }

    async function saveProgress(videoId) {
        if (!player || !videoId || currentVideoId !== videoId) return;

        const currentTime = Number(player.getCurrentTime ? player.getCurrentTime() : 0) || 0;
        const duration = Number(player.getDuration ? player.getDuration() : 0) || 0;

        try {
            await fetch('/youtube-player/progress', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: videoId, time: currentTime, duration: duration})
            });

            items = items.map((item) => {
                if (item.id !== videoId) return item;
                const percent = duration > 0 ? Math.max(0, Math.min(100, Math.round((currentTime / duration) * 100))) : 0;
                return {
                    ...item,
                    time: currentTime,
                    duration: duration,
                    percent: percent,
                };
            });
            renderList();
        } catch (e) {
            console.error('Failed to save YouTube progress', e);
        }
    }

    function startProgressTracking(videoId) {
        stopProgressTracking();
        trackInterval = window.setInterval(() => {
            void saveProgress(videoId);
        }, 5000);
    }

    function renderList() {
        listEl.innerHTML = '';

        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'list-group-item list-group-item-action';
            if (item.id === currentVideoId) {
                row.classList.add('active');
            }

            const top = document.createElement('div');
            top.className = 'd-flex align-items-start justify-content-between gap-2';

            const clickZone = document.createElement('button');
            clickZone.type = 'button';
            clickZone.className = 'btn btn-link text-start p-0 text-decoration-none flex-grow-1';
            clickZone.innerHTML = '<div class="fw-semibold">' + getVideoLabel(item).replace(/</g, '&lt;') + '</div>'
                + '<div class="small text-muted">' + (item.url || '').replace(/</g, '&lt;') + '</div>';
            clickZone.addEventListener('click', () => {
                void selectVideo(item.id);
            });

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn btn-sm btn-outline-danger';
            deleteBtn.textContent = 'Удалить';
            deleteBtn.addEventListener('click', async () => {
                await deleteVideo(item.id);
            });

            top.appendChild(clickZone);
            top.appendChild(deleteBtn);

            const progressWrap = document.createElement('div');
            progressWrap.className = 'mt-2';
            const percent = Math.max(0, Math.min(100, Number(item.percent) || 0));
            progressWrap.innerHTML = '<div class="d-flex justify-content-between small text-muted mb-1"><span>Прогресс</span><span>' + percent + '%</span></div>'
                + '<div class="progress" style="height: 8px;"><div class="progress-bar" role="progressbar" style="width: ' + percent + '%;"></div></div>';

            row.appendChild(top);
            row.appendChild(progressWrap);
            listEl.appendChild(row);
        });

        updateListMeta();
    }

    async function loadVideos() {
        const response = await fetch('/youtube-player/videos', {cache: 'no-store'});
        if (!response.ok) {
            throw new Error('Failed to load videos');
        }
        const payload = await response.json();
        items = Array.isArray(payload.videos) ? payload.videos : [];
        renderList();
    }

    async function deleteVideo(videoId) {
        const response = await fetch('/youtube-player/videos?id=' + encodeURIComponent(videoId), {
            method: 'DELETE'
        });

        if (!response.ok) {
            return;
        }

        items = items.filter((item) => item.id !== videoId);
        if (currentVideoId === videoId) {
            currentVideoId = '';
            stopProgressTracking();
            if (player && typeof player.stopVideo === 'function') {
                player.stopVideo();
            }
            playerContainerEl.classList.add('d-none');
            playerPlaceholderEl.classList.remove('d-none');
        }
        renderList();
    }

    async function updateVideoTitle(videoId, title) {
        if (!videoId || !title) return;

        const item = items.find((entry) => entry.id === videoId);
        if (!item || (item.title && item.title.trim())) {
            return;
        }

        try {
            await fetch('/youtube-player/videos', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: item.id, url: item.url, title: title})
            });

            items = items.map((entry) => entry.id === videoId ? ({...entry, title: title}) : entry);
            renderList();
        } catch (e) {
            console.error('Failed to update title', e);
        }
    }

    async function getSavedProgress(videoId) {
        const response = await fetch('/youtube-player/progress?id=' + encodeURIComponent(videoId), {cache: 'no-store'});
        if (!response.ok) {
            return 0;
        }
        const payload = await response.json();
        return Math.max(0, Number(payload.time) || 0);
    }

    function ensureYoutubeApi() {
        if (window.YT && window.YT.Player) {
            isYoutubeApiReady = true;
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            const previousReady = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function () {
                isYoutubeApiReady = true;
                if (typeof previousReady === 'function') {
                    previousReady();
                }
                resolve();
            };

            if (!document.querySelector('script[data-yt-api="1"]')) {
                const script = document.createElement('script');
                script.src = 'https://www.youtube.com/iframe_api';
                script.async = true;
                script.dataset.ytApi = '1';
                document.head.appendChild(script);
            }
        });
    }

    async function selectVideo(videoId) {
        const item = items.find((entry) => entry.id === videoId);
        if (!item) {
            return;
        }

        await ensureYoutubeApi();
        if (!isYoutubeApiReady) {
            return;
        }

        currentVideoId = videoId;
        renderList();

        const startSeconds = await getSavedProgress(videoId);

        playerPlaceholderEl.classList.add('d-none');
        playerContainerEl.classList.remove('d-none');

        if (!player) {
            player = new YT.Player('ytPlayerContainer', {
                width: '100%',
                height: '100%',
                videoId: videoId,
                playerVars: {
                    autoplay: 1,
                    start: Math.floor(startSeconds),
                    rel: 0,
                    modestbranding: 1
                },
                events: {
                    onReady: function (event) {
                        if (startSeconds > 0) {
                            event.target.seekTo(startSeconds, true);
                        }
                        event.target.playVideo();
                        const data = event.target.getVideoData ? event.target.getVideoData() : null;
                        if (data && data.title) {
                            void updateVideoTitle(videoId, data.title);
                        }
                    },
                    onStateChange: function (event) {
                        const state = event.data;
                        if (state === YT.PlayerState.PLAYING) {
                            startProgressTracking(videoId);
                            const data = player.getVideoData ? player.getVideoData() : null;
                            if (data && data.title) {
                                void updateVideoTitle(videoId, data.title);
                            }
                            return;
                        }

                        if (state === YT.PlayerState.PAUSED || state === YT.PlayerState.ENDED) {
                            stopProgressTracking();
                            void saveProgress(videoId);
                        }
                    }
                }
            });
        } else {
            player.loadVideoById({
                videoId: videoId,
                startSeconds: Math.floor(startSeconds)
            });
        }
    }

    formEl.addEventListener('submit', async function (event) {
        event.preventDefault();
        clearFormError();

        const rawUrl = urlInputEl.value.trim();
        const videoId = extractYoutubeVideoId(rawUrl);

        if (!videoId) {
            showFormError('Введите корректную ссылку на YouTube видео.');
            return;
        }

        if (items.some((item) => item.id === videoId)) {
            showFormError('Это видео уже добавлено в список.');
            return;
        }

        const response = await fetch('/youtube-player/videos', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: videoId, url: rawUrl})
        });

        if (!response.ok) {
            showFormError('Не удалось добавить видео.');
            return;
        }

        const payload = await response.json();
        if (!payload || !payload.ok || !payload.video) {
            showFormError('Некорректный ответ от сервера.');
            return;
        }

        items.push({
            id: payload.video.id,
            url: payload.video.url,
            title: payload.video.title || '',
            time: 0,
            duration: 0,
            percent: 0,
        });

        urlInputEl.value = '';
        renderList();
    });

    window.addEventListener('beforeunload', () => {
        if (currentVideoId) {
            void saveProgress(currentVideoId);
        }
    });

    void loadVideos();
})();
</script>
