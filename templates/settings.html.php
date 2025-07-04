<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>Settings</h2>
            <a href="/" class="btn btn-outline-secondary">← Back to Home</a>
        </div>
        
        <!-- Navigation tabs -->
        <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="top-menu-tab" data-bs-toggle="tab" data-bs-target="#top-menu" type="button" role="tab" aria-controls="top-menu" aria-selected="true">
                    Top Menu Settings
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="file-index-tab" data-bs-toggle="tab" data-bs-target="#file-index" type="button" role="tab" aria-controls="file-index" aria-selected="false">
                    File Index Settings
                </button>
            </li>
        </ul>
        
        <!-- Tab content -->
        <div class="tab-content mt-3" id="settingsTabContent">
            <div class="tab-pane fade show active" id="top-menu" role="tabpanel" aria-labelledby="top-menu-tab">
                <?php if (isset($message)): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>Top Menu Entries</h4>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addMenuModal">
                        Add New Entry
                    </button>
                </div>
                
                <!-- Menu entries table -->
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>URL</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($menuItems)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No menu items found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($menuItems as $index => $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td><?= htmlspecialchars($item['url']) ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary" 
                                                    onclick="editMenuItem(<?= $index ?>, '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>')">
                                                Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="deleteMenuItem(<?= $index ?>, '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')">
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- File Index Settings Tab -->
            <div class="tab-pane fade" id="file-index" role="tabpanel" aria-labelledby="file-index-tab">
                <?php if (isset($message)): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">File Index Configuration</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/settings/file-index/update">
                            <div class="mb-3">
                                <label for="catalogPath" class="form-label">Catalog Path</label>
                                <input type="text" class="form-control" id="catalogPath" name="catalogPath" 
                                       value="<?= htmlspecialchars($catalogPath ?? '') ?>" required>
                                <div class="form-text">
                                    Enter the full path to the directory you want to index in the File Index page.
                                    The path must be readable by the web server.
                                </div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                <a href="/file-index" class="btn btn-outline-secondary">View File Index</a>
                            </div>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Current Settings</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Catalog Path:</strong></td>
                                        <td><code><?= htmlspecialchars($catalogPath ?? 'Not set') ?></code></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Path Status:</strong></td>
                                        <td>
                                            <?php 
                                            $pathStatus = 'Unknown';
                                            $pathClass = 'text-muted';
                                            if (isset($catalogPath) && !empty($catalogPath)) {
                                                if (file_exists($catalogPath)) {
                                                    if (is_dir($catalogPath)) {
                                                        if (is_readable($catalogPath)) {
                                                            $pathStatus = 'Valid';
                                                            $pathClass = 'text-success';
                                                        } else {
                                                            $pathStatus = 'Not readable';
                                                            $pathClass = 'text-warning';
                                                        }
                                                    } else {
                                                        $pathStatus = 'Not a directory';
                                                        $pathClass = 'text-danger';
                                                    }
                                                } else {
                                                    $pathStatus = 'Does not exist';
                                                    $pathClass = 'text-danger';
                                                }
                                            }
                                            ?>
                                            <span class="<?= $pathClass ?>"><?= $pathStatus ?></span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Menu Item Modal -->
<div class="modal fade" id="addMenuModal" tabindex="-1" aria-labelledby="addMenuModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/settings/top-menu/create">
                <div class="modal-header">
                    <h5 class="modal-title" id="addMenuModalLabel">Add New Menu Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="addMenuName" class="form-label">Name</label>
                        <input type="text" class="form-control" id="addMenuName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="addMenuUrl" class="form-label">URL</label>
                        <input type="url" class="form-control" id="addMenuUrl" name="url" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Menu Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Menu Item Modal -->
<div class="modal fade" id="editMenuModal" tabindex="-1" aria-labelledby="editMenuModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/settings/top-menu/edit">
                <div class="modal-header">
                    <h5 class="modal-title" id="editMenuModalLabel">Edit Menu Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editMenuIndex" name="index">
                    <div class="mb-3">
                        <label for="editMenuName" class="form-label">Name</label>
                        <input type="text" class="form-control" id="editMenuName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="editMenuUrl" class="form-label">URL</label>
                        <input type="url" class="form-control" id="editMenuUrl" name="url" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteMenuModal" tabindex="-1" aria-labelledby="deleteMenuModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/settings/top-menu/delete">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteMenuModalLabel">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="deleteMenuIndex" name="index">
                    <p>Are you sure you want to delete the menu item "<span id="deleteMenuName"></span>"?</p>
                    <p class="text-muted">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editMenuItem(index, name, url) {
    document.getElementById('editMenuIndex').value = index;
    document.getElementById('editMenuName').value = name;
    document.getElementById('editMenuUrl').value = url;
    
    var editModal = new bootstrap.Modal(document.getElementById('editMenuModal'));
    editModal.show();
}

function deleteMenuItem(index, name) {
    document.getElementById('deleteMenuIndex').value = index;
    document.getElementById('deleteMenuName').textContent = name;
    
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteMenuModal'));
    deleteModal.show();
}
</script>
