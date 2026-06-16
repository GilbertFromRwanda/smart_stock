<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

$user_id = (int)$_SESSION['user_id'];

function cidSql() {
    return isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
}

// ── Toggle pin (AJAX) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_pin'])) {
    $id = (int)$_POST['note_id'];
    mysqli_query($conn, "UPDATE notes SET is_pinned = 1 - is_pinned WHERE id=$id AND user_id=$user_id");
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_pinned FROM notes WHERE id=$id AND user_id=$user_id"));
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'is_pinned' => (int)$row['is_pinned']]);
    exit;
}

// ── Add note (AJAX) ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $title = mysqli_real_escape_string($conn, trim($_POST['title']));
    $note  = mysqli_real_escape_string($conn, trim($_POST['note']));
    if ($title === '' || $note === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Title and note are required.']);
        exit;
    }
    $ins = mysqli_query($conn, "INSERT INTO notes (company_id, user_id, title, note) VALUES (" . cidSql() . ", $user_id, '$title', '$note')");
    header('Content-Type: application/json');
    echo json_encode($ins ? ['success' => true] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── Edit note (AJAX) ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_note'])) {
    $id    = (int)$_POST['note_id'];
    $title = mysqli_real_escape_string($conn, trim($_POST['title']));
    $note  = mysqli_real_escape_string($conn, trim($_POST['note']));
    if ($title === '' || $note === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Title and note are required.']);
        exit;
    }
    $upd = mysqli_query($conn, "UPDATE notes SET title='$title', note='$note' WHERE id=$id AND user_id=$user_id");
    header('Content-Type: application/json');
    echo json_encode($upd ? ['success' => true] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    mysqli_query($conn, "DELETE FROM notes WHERE id=" . (int)$_GET['delete'] . " AND user_id=$user_id");
    header('Location: notes.php'); exit;
}

// ── Fetch all notes — pinned first ────────────────────────────────────────────
$notes_res = mysqli_query($conn, "SELECT * FROM notes WHERE user_id=$user_id ORDER BY is_pinned DESC, updated_at DESC");
$all_notes = [];
while ($r = mysqli_fetch_assoc($notes_res)) $all_notes[] = $r;

$pinned_count = count(array_filter($all_notes, fn($n) => $n['is_pinned']));
$total_count  = count($all_notes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .notes-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
        }
        .notes-header h1 { margin: 0; }

        .notes-meta {
            display: flex; gap: 12px; margin-bottom: 22px; flex-wrap: wrap;
        }
        .notes-badge {
            background: var(--white); border: 1px solid var(--gray-200);
            border-radius: 20px; padding: 5px 14px;
            font-size: 12px; font-weight: 600; color: var(--secondary);
            box-shadow: var(--shadow-sm);
        }
        .notes-badge span { color: var(--dark); }

        /* ── Section labels ── */
        .notes-section-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.7px; color: var(--secondary);
            margin: 0 0 12px;
        }
        .notes-section-label.pinned { color: #b45309; }

        /* ── Cards grid ── */
        .notes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .note-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 14px;
            padding: 18px 18px 14px;
            box-shadow: var(--shadow-sm);
            border-top: 4px solid var(--primary);
            position: relative;
            display: flex; flex-direction: column; gap: 10px;
            transition: box-shadow .15s, transform .15s;
        }
        .note-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.1); transform: translateY(-1px); }
        .note-card.pinned { border-top-color: #f59e0b; }

        .note-card-top {
            display: flex; align-items: flex-start; gap: 8px;
        }
        .note-card-title {
            font-size: 14px; font-weight: 700; color: var(--dark);
            flex: 1; line-height: 1.35; word-break: break-word;
        }
        .note-pin-btn {
            background: none; border: none; cursor: pointer;
            font-size: 16px; line-height: 1; padding: 2px;
            color: #d1d5db; transition: color .15s; flex-shrink: 0;
        }
        .note-pin-btn.pinned { color: #f59e0b; }
        .note-pin-btn:hover { color: #f59e0b; }

        .note-card-body {
            font-size: 13px; color: var(--secondary);
            line-height: 1.55; word-break: break-word;
            white-space: pre-wrap;
            max-height: 130px; overflow: hidden;
            mask-image: linear-gradient(to bottom, black 70%, transparent 100%);
            -webkit-mask-image: linear-gradient(to bottom, black 70%, transparent 100%);
            cursor: pointer;
            user-select: none;
        }
        .note-card-body.expanded {
            max-height: none;
            mask-image: none; -webkit-mask-image: none;
            cursor: default;
            user-select: text;
        }

        .note-card-footer {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: auto;
        }
        .note-date {
            font-size: 11px; color: #94a3b8;
        }
        .note-actions {
            display: flex; gap: 6px;
        }
        .note-action-btn {
            background: none; border: 1px solid var(--gray-200); border-radius: 6px;
            padding: 3px 9px; font-size: 11px; font-weight: 600; cursor: pointer;
            color: var(--secondary); transition: all .15s;
        }
        .note-action-btn:hover { background: var(--gray-100); }
        .note-action-btn.danger:hover { background: #fef2f2; color: var(--danger); border-color: #fca5a5; }

        .pin-label {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10px; font-weight: 700; color: #92400e;
            background: #fef3c7; border-radius: 10px; padding: 2px 8px;
            letter-spacing: 0.4px;
        }

        .notes-empty {
            text-align: center; padding: 60px 20px;
            color: var(--secondary); font-size: 14px;
        }
        .notes-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: .4; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div class="notes-header">
            <h1>Notes</h1>
            <button onclick="openModal('addNoteModal')" class="btn btn-primary">+ New Note</button>
        </div>

        <div class="notes-meta">
            <div class="notes-badge">Total: <span><?php echo $total_count; ?></span></div>
            <div class="notes-badge">Pinned: <span><?php echo $pinned_count; ?></span></div>
        </div>

        <div id="pageAlert" class="alert" style="display:none;"></div>

        <?php if ($total_count === 0): ?>
        <div class="notes-empty">
            <div class="notes-empty-icon">✎</div>
            <p>No notes yet. Click <strong>+ New Note</strong> to get started.</p>
        </div>
        <?php else: ?>

        <?php
        $pinned = array_filter($all_notes, fn($n) => $n['is_pinned']);
        $others = array_filter($all_notes, fn($n) => !$n['is_pinned']);
        ?>

        <?php if ($pinned): ?>
        <p class="notes-section-label pinned">📌 Pinned</p>
        <div class="notes-grid">
            <?php foreach ($pinned as $note): ?>
            <?php echo renderNoteCard($note); ?>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($others): ?>
        <?php if ($pinned): ?><p class="notes-section-label">Other Notes</p><?php endif; ?>
        <div class="notes-grid">
            <?php foreach ($others as $note): ?>
            <?php echo renderNoteCard($note); ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addNoteModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addNoteModal')">&times;</span>
        <h2>New Note</h2>
        <div id="addNoteAlert" class="alert" style="display:none;"></div>
        <form id="addNoteForm">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" id="add_title" required placeholder="Note title...">
            </div>
            <div class="form-group">
                <label>Note *</label>
                <textarea name="note" id="add_note" rows="6" required placeholder="Write your note here..." style="resize:vertical;"></textarea>
            </div>
            <button type="submit" name="add_note" class="btn btn-primary">Save Note</button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editNoteModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editNoteModal')">&times;</span>
        <h2>Edit Note</h2>
        <div id="editNoteAlert" class="alert" style="display:none;"></div>
        <form id="editNoteForm">
            <input type="hidden" name="note_id" id="edit_note_id">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" id="edit_title" required>
            </div>
            <div class="form-group">
                <label>Note *</label>
                <textarea name="note" id="edit_note_body" rows="6" required style="resize:vertical;"></textarea>
            </div>
            <button type="submit" name="edit_note" class="btn btn-primary">Update Note</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
function openEdit(btn) {
    var d = btn.dataset;
    document.getElementById('edit_note_id').value   = d.id;
    document.getElementById('edit_title').value     = d.title;
    document.getElementById('edit_note_body').value = d.note;
    document.getElementById('editNoteAlert').style.display = 'none';
    openModal('editNoteModal');
}

function togglePin(id, btn) {
    var data = new FormData();
    data.append('toggle_pin', '1');
    data.append('note_id', id);
    fetch('notes.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) { location.reload(); }
        });
}

function toggleExpand(el) {
    el.classList.toggle('expanded');
}

function ajaxNoteForm(formId, alertId, actionKey, onSuccess) {
    document.getElementById(formId).addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var btn = form.querySelector('button[type="submit"]');
        var alertBox = document.getElementById(alertId);
        var orig = btn.textContent;
        btn.disabled = true; btn.textContent = 'Saving...';
        alertBox.style.display = 'none';

        var data = new FormData(form);
        data.append(actionKey, '1');

        fetch('notes.php', { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { onSuccess(); }
                else {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = res.message || 'Error.';
                    alertBox.style.display = 'block';
                    btn.disabled = false; btn.textContent = orig;
                }
            })
            .catch(function() {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = 'Network error. Try again.';
                alertBox.style.display = 'block';
                btn.disabled = false; btn.textContent = orig;
            });
    });
}

ajaxNoteForm('addNoteForm', 'addNoteAlert', 'add_note', function() {
    closeModal('addNoteModal');
    document.getElementById('addNoteForm').reset();
    location.reload();
});

ajaxNoteForm('editNoteForm', 'editNoteAlert', 'edit_note', function() {
    closeModal('editNoteModal');
    location.reload();
});
</script>
</body>
</html>
<?php
function renderNoteCard(array $note): string {
    $id       = (int)$note['id'];
    $title    = htmlspecialchars($note['title']);
    $body     = htmlspecialchars($note['note']);
    $pinned   = (bool)$note['is_pinned'];
    $date     = date('M d, Y', strtotime($note['updated_at']));
    $long     = mb_strlen($note['note']) > 200;

    $card_cls  = $pinned ? 'note-card pinned' : 'note-card';
    $pin_cls   = $pinned ? 'note-pin-btn pinned' : 'note-pin-btn';
    $pin_title = $pinned ? 'Unpin' : 'Pin';

    $body_onclick = $long ? ' onclick="toggleExpand(this)"' : '';
    $body_attr = 'id="note-body-'.$id.'" class="note-card-body"'.$body_onclick;

    $pin_label = $pinned ? '<span class="pin-label">📌 Pinned</span>' : '';

    $title_attr = htmlspecialchars($note['title'], ENT_QUOTES);
    $body_attr2 = htmlspecialchars($note['note'],  ENT_QUOTES);

    return <<<HTML
<div class="{$card_cls}">
    <div class="note-card-top">
        <div class="note-card-title">{$title}</div>
        <button class="{$pin_cls}" title="{$pin_title}" onclick="togglePin({$id}, this)">📌</button>
    </div>
    {$pin_label}
    <div {$body_attr}>{$body}</div>
    <div class="note-card-footer">
        <span class="note-date">{$date}</span>
        <div class="note-actions">
            <button class="note-action-btn"
                data-id="{$id}"
                data-title="{$title_attr}"
                data-note="{$body_attr2}"
                onclick="openEdit(this)">Edit</button>
            <a class="note-action-btn danger"
                href="?delete={$id}"
                onclick="return confirm('Delete this note?')">Delete</a>
        </div>
    </div>
</div>
HTML;
}
?>
