<?php
$adminTitle = 'Wszystkie artykuły';
require __DIR__ . '/_layout.php';

$posts = getAllPostsAdmin();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<div class="admin-page">
    <div class="admin-page__head">
        <h1>Artykuły</h1>
        <a href="edit.php" class="btn btn--primary">+ Nowy artykuł</a>
    </div>
    <?php if ($flash): ?><div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

    <?php if (empty($posts)): ?>
        <p class="empty">Brak artykułów. <a href="edit.php">Stwórz pierwszy</a>.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tytuł</th>
                    <th>Kategoria</th>
                    <th>Status</th>
                    <th>Data publikacji</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td>
                            <a href="edit.php?id=<?= (int)$p['id'] ?>" class="admin-table__title"><?= e($p['title']) ?></a>
                            <div class="admin-table__slug">/<?= e($p['slug']) ?></div>
                        </td>
                        <td><?= e($p['category']) ?></td>
                        <td><span class="pill pill--<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
                        <td><?= e(formatDate($p['published_at'])) ?></td>
                        <td class="admin-table__actions">
                            <a href="<?= e(postUrl($p)) ?>" target="_blank" rel="noopener">Zobacz</a>
                            <a href="edit.php?id=<?= (int)$p['id'] ?>">Edytuj</a>
                            <form method="post" action="delete.php" onsubmit="return confirm('Na pewno usunąć?');" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="link-btn link-btn--danger">Usuń</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php';
