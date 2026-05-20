</main>
<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner">
        <div class="site-footer__col site-footer__col--brand">
            <h2 class="site-footer__title"><?= e(siteName()) ?></h2>
            <p><?= e(siteTagline()) ?></p>
            <ul class="site-footer__links">
                <?php if (setting('contact_enabled', '1') === '1'): ?>
                    <li><a href="<?= e(BASE_URL) ?>/kontakt">Kontakt</a></li>
                <?php endif; ?>
                <li><a href="<?= e(BASE_URL) ?>/sitemap.xml">Mapa strony</a></li>
                <li><a href="<?= e(BASE_URL) ?>/feed.php">RSS</a></li>
                <li><a href="<?= e(BASE_URL) ?>/admin/login.php">Panel redakcji</a></li>
            </ul>
        </div>

        <div class="site-footer__col">
            <h3>Kategorie</h3>
            <?php $topCats = topCategories(max(1, (int)setting('footer_categories_count', '8'))); ?>
            <ul class="site-footer__categories">
                <?php foreach ($topCats as $cat): ?>
                    <li>
                        <a href="<?= e(categoryUrl($cat['category'])) ?>">
                            <span class="site-footer__cat-name"><?= e($cat['category']) ?></span>
                            <span class="site-footer__cat-count"><?= (int)$cat['count'] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php
        $tagLimit = max(1, (int)setting('footer_tags_count', '20'));
        $tags = topTags($tagLimit);
        ?>
        <?php if ($tags): ?>
            <div class="site-footer__col site-footer__col--tags">
                <h3><?= e(tagLabel()) ?></h3>
                <?php $maxUsage = max(array_map(fn($t) => (int)$t['usage_count'], $tags)); ?>
                <ul class="footer-tag-cloud">
                    <?php foreach ($tags as $t):
                        $bucket = tagSizeBucket((int)$t['usage_count'], $maxUsage); ?>
                        <li>
                            <a href="<?= e(tagUrl($t['slug'])) ?>" class="footer-tag-chip footer-tag-chip--s<?= $bucket ?>" title="<?= e($t['name']) ?> · <?= (int)$t['usage_count'] ?> art.">
                                <?= e($t['name']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    <p class="site-footer__copy">&copy; <?= date('Y') ?> <?= e(siteName()) ?>. Wszystkie prawa zastrzeżone.</p>
</footer>
<?= renderCustomCode('body_end') ?>
</body>
</html>
