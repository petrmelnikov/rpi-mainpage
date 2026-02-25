<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Download Queue</h2>
            <div class="d-flex gap-2">
                <a href="/file-index" class="btn btn-outline-secondary btn-sm">⬅ Back to file index</a>
                <button type="button" class="btn btn-outline-primary btn-sm" id="refreshJobsBtn">🔄 Refresh</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover" id="downloadJobsTable">
                <thead class="table-dark">
                    <tr>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Target</th>
                        <th>URL</th>
                        <th>Tool</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody id="downloadJobsBody">
                <?php if (!empty($jobs)): ?>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td>
                                <?php $status = (string)($job['status'] ?? 'unknown'); ?>
                                <?php
                                    $badgeClass = match ($status) {
                                        'queued' => 'bg-secondary',
                                        'running' => 'bg-primary',
                                        'done' => 'bg-success',
                                        'failed' => 'bg-danger',
                                        default => 'bg-dark',
                                    };
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                            </td>
                            <td><?= date('Y-m-d H:i:s', (int)($job['createdAt'] ?? 0)) ?></td>
                            <td><code><?= htmlspecialchars((string)($job['targetPath'] ?? '')) ?></code></td>
                            <td style="max-width: 520px; word-break: break-all;"><?= htmlspecialchars((string)($job['url'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($job['tool'] ?? '')) ?> <?= htmlspecialchars((string)($job['mode'] ?? '')) ?></td>
                            <td style="max-width: 420px; word-break: break-word;"><?= htmlspecialchars((string)($job['error'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('downloadJobsBody');
    const refreshBtn = document.getElementById('refreshJobsBtn');

    function escapeHtml(str) {
        return String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function statusBadge(status) {
        const map = {
            queued: 'bg-secondary',
            running: 'bg-primary',
            done: 'bg-success',
            failed: 'bg-danger'
        };
        const cls = map[status] || 'bg-dark';
        return `<span class="badge ${cls}">${escapeHtml(status || 'unknown')}</span>`;
    }

    function toDate(ts) {
        const n = Number(ts || 0);
        if (!n) return '';
        const d = new Date(n * 1000);
        const p = (v) => String(v).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
    }

    function renderJobs(jobs) {
        tbody.innerHTML = '';
        for (const job of jobs) {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${statusBadge(job.status)}</td>
                <td>${escapeHtml(toDate(job.createdAt))}</td>
                <td><code>${escapeHtml(job.targetPath || '')}</code></td>
                <td style="max-width: 520px; word-break: break-all;">${escapeHtml(job.url || '')}</td>
                <td>${escapeHtml((job.tool || '') + ' ' + (job.mode || ''))}</td>
                <td style="max-width: 420px; word-break: break-word;">${escapeHtml(job.error || '')}</td>
            `;
            tbody.appendChild(tr);
        }
    }

    async function refreshJobs() {
        const res = await fetch('/file-index/download-jobs/status', { headers: { 'Accept': 'application/json' } });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok || !Array.isArray(data.jobs)) {
            return;
        }
        renderJobs(data.jobs);
    }

    refreshBtn?.addEventListener('click', function() {
        void refreshJobs();
    });

    setInterval(() => {
        void refreshJobs();
    }, 3000);
});
</script>
