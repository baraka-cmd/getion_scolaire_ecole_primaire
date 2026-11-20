<?php
/**
 * Fichier : public/views/templates/footer.php
 * Rôle     : Pied de page commun à toutes les pages
 */

defined('ACCESS') or die('Accès interdit');

// Année actuelle pour le copyright
$currentYear = date('Y');

// Version de l'application
$appVersion = APP_VERSION;

// Auteur
$appAuthor = APP_AUTHOR;

// URLs dynamiques (S'adaptent automatiquement en local et en ligne)
$baseUrl   = $baseUrl ?? (defined('BASE_URL') ? rtrim(BASE_URL, '/') : (defined('APP_URL') ? rtrim(APP_URL, '/') : ''));
$assetsUrl = $assetsUrl ?? (defined('ASSETS_URL') ? rtrim(ASSETS_URL, '/') . '/' : ($baseUrl !== '' ? $baseUrl . '/assets/' : '/assets/'));
$sidebarJsVersion = filemtime(ASSETS_PATH . 'js' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'sidebar.js');

?>

    </main>
    <!-- Fin .page-content -->

    <!-- ============================================================
         FOOTER
         ============================================================ -->
    <footer class="footer" id="main-footer">
        <!-- Partie gauche : Copyright -->
        <div class="footer-left">
            <span class="footer-copyright">
                <i class="fa-regular fa-copyright"></i>
                <span class="footer-year"><?= $currentYear ?></span>
                <strong><?= e(ECOLE_NOM) ?></strong>
                <span>— Tous droits réservés</span>
            </span>

            <span class="footer-version" title="Version de l'application">
                <i class="fa-solid fa-code-branch"></i>
                v<?= e($appVersion) ?>
            </span>
        </div>

        <!-- Partie centrale : Liens -->
        <div class="footer-center">
            <a href="<?= $baseUrl ?>/aide" class="footer-link">
                <i class="fa-solid fa-circle-question"></i> Aide
            </a>
            <span class="footer-separator"></span>
            <a href="<?= $baseUrl ?>/confidentialite" class="footer-link">
                <i class="fa-solid fa-shield-halved"></i> Confidentialité
            </a>
            <span class="footer-separator"></span>
            <a href="<?= $baseUrl ?>/conditions" class="footer-link">
                <i class="fa-solid fa-file-contract"></i> Conditions
            </a>
            <span class="footer-separator"></span>
            <a href="<?= $baseUrl ?>/contact" class="footer-link">
                <i class="fa-solid fa-envelope"></i> Contact
            </a>
        </div>

        <!-- Partie droite : Réseaux sociaux -->
        <div class="footer-right">
            <div class="footer-social">
                <a href="https://facebook.com/" class="footer-social-link" target="_blank" rel="noopener noreferrer" title="Facebook" aria-label="Facebook">
                    <i class="fa-brands fa-facebook-f"></i>
                </a>
                <a href="https://twitter.com/" class="footer-social-link" target="_blank" rel="noopener noreferrer" title="Twitter" aria-label="Twitter">
                    <i class="fa-brands fa-x-twitter"></i>
                </a>
                <a href="https://linkedin.com/" class="footer-social-link" target="_blank" rel="noopener noreferrer" title="LinkedIn" aria-label="LinkedIn">
                    <i class="fa-brands fa-linkedin-in"></i>
                </a>
                <a href="https://youtube.com/" class="footer-social-link" target="_blank" rel="noopener noreferrer" title="YouTube" aria-label="YouTube">
                    <i class="fa-brands fa-youtube"></i>
                </a>
                <a href="mailto:<?= e(ECOLE_NOM) ?>" class="footer-social-link" title="Email" aria-label="Email">
                    <i class="fa-solid fa-envelope"></i>
                </a>
            </div>
        </div>
    </footer>

</div>
<!-- Fin .main-wrapper -->

<!-- ============================================================
     SCRIPTS JAVASCRIPT
     ============================================================ -->

<!-- Chart.js pour les graphiques -->
<script src="<?= $assetsUrl ?>js/common/chart.umd.min.js"></script>

<!-- Scripts communs -->
<script src="<?= $assetsUrl ?>js/common/main.js"></script>

<!-- Scripts des templates -->
<script src="<?= $assetsUrl ?>js/templates/sidebar.js?v=<?= $sidebarJsVersion ?>"></script>
<script src="<?= $assetsUrl ?>js/templates/header.js"></script>
<script src="<?= $assetsUrl ?>js/templates/footer.js"></script>

<!-- Scripts additionnels par page -->
<?php if (!empty($extraJS)): ?>
    <?php foreach ((array)$extraJS as $js): ?>
        <script src="<?= $assetsUrl ?>js/<?= $js ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Script inline optionnel -->
<?php if (!empty($inlineJS)): ?>
    <script>
        <?= $inlineJS ?>
    </script>
<?php endif; ?>

</body>
</html>