</main>
<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner">
        <div class="site-footer__col">
            <h2 class="site-footer__title"><?= e(siteName()) ?></h2>
            <p><?= e(siteTagline()) ?></p>
        </div>
        <div class="site-footer__col">
            <h3>Kategorie</h3>
            <ul>
                <?php foreach (getCategories() as $cat): ?>
                    <li><a href="<?= e(categoryUrl($cat['category'])) ?>"><?= e($cat['category']) ?> (<?= (int)$cat['count'] ?>)</a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="site-footer__col">
            <h3>Linki</h3>
            <ul>
                <?php if (setting('contact_enabled', '1') === '1'): ?>
                    <li><a href="<?= e(BASE_URL) ?>/kontakt">Kontakt</a></li>
                <?php endif; ?>
                <li><a href="<?= e(BASE_URL) ?>/sitemap.xml">Mapa strony</a></li>
                <li><a href="<?= e(BASE_URL) ?>/feed.php">RSS</a></li>
                <li><a href="<?= e(BASE_URL) ?>/admin/login.php">Panel redakcji</a></li>
            </ul>
        </div>
    </div>
    <p class="site-footer__copy">&copy; <?= date('Y') ?> <?= e(siteName()) ?>. Wszystkie prawa zastrzeżone.</p>
</footer>
</body>
</html>
