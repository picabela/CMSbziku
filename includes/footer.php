</main>
<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner">
        <div class="site-footer__col site-footer__col--brand">
            <h2 class="site-footer__title"><?= e(siteName()) ?></h2>
            <p><?= e(siteTagline()) ?></p>

            <form class="footer-search" method="get" action="<?= e(BASE_URL) ?>/szukaj" role="search">
                <label class="footer-search__label" for="footer-search-input">Szukaj na stronie</label>
                <div class="footer-search__field">
                    <svg class="footer-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" id="footer-search-input" name="q" placeholder="Wpisz frazę…" minlength="2" required autocomplete="off">
                    <button type="submit" aria-label="Szukaj">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </form>

            <ul class="site-footer__links">
                <?php $footerMenu = getMenuItems('footer'); ?>
                <?php if ($footerMenu): ?>
                    <?php foreach (renderMenu('footer', false) as $mi): ?>
                        <li><a href="<?= e($mi['url']) ?>"><?= e($mi['label']) ?></a></li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (setting('contact_enabled', '1') === '1'): ?>
                        <li><a href="<?= e(BASE_URL) ?>/kontakt">Kontakt</a></li>
                    <?php endif; ?>
                    <li><a href="<?= e(BASE_URL) ?>/sitemap.xml">Mapa strony</a></li>
                    <li><a href="<?= e(BASE_URL) ?>/feed.php">RSS</a></li>
                    <li><a href="<?= e(BASE_URL) ?>/admin/login.php">Panel redakcji</a></li>
                <?php endif; ?>
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
<style>.card__cats{display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.5rem}.card__cats .kicker{margin-bottom:0}</style>
<?php if (!empty($_SESSION['admin_logged_in'])): ?>
    <style>
    .admin-fab{position:fixed;right:18px;bottom:18px;z-index:9999;display:inline-flex;align-items:center;gap:.5rem;
        padding:.6rem .9rem;border-radius:999px;background:#111;color:#fff;text-decoration:none;font-size:.85rem;
        font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.25);opacity:.55;transition:opacity .15s ease,transform .15s ease}
    .admin-fab:hover{opacity:1;transform:translateY(-2px);color:#fff}
    .admin-fab__label{display:none}
    .admin-fab:hover .admin-fab__label{display:inline}
    @media (max-width:640px){.admin-fab{right:12px;bottom:12px;padding:.55rem}}
    </style>
    <a href="<?= e(BASE_URL) ?>/admin/index.php" class="admin-fab" title="Przejdź do panelu CMS" aria-label="Przejdź do panelu CMS">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        <span class="admin-fab__label">Panel CMS</span>
    </a>
<?php endif; ?>
<?= renderCustomCode('body_end') ?>
</body>
</html>
