<?php
defined('ACCESS') or define('ACCESS', true);
require_once __DIR__ . '/../../../src/config/app.php';
requireLogin();
requirePermission('assistant.consulter');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AssistantRepository.php';
$assistantRepository = new AssistantRepository();
$assistantRepository->purgeExpiredConversations();
$conversationPage = $assistantRepository->listConversations((int) $_SESSION['utilisateur_id']);
$conversations = $conversationPage['items'];

$pageTitle = 'Assistant scolaire';
$pageIcon = 'fa-wand-magic-sparkles';
$pageDescription = 'Assistant sécurisé pour explorer les informations de votre école.';
$extraCSS = ['assistant/assistant.css'];
$extraJS = ['assistant/assistant.js?v=' . filemtime(PUBLIC_PATH . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'assistant' . DIRECTORY_SEPARATOR . 'assistant.js')];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<section class="assistant-shell" aria-label="Assistant scolaire">
    <script>
        window.ASSISTANT_CAN_DELETE = <?= can('assistant.supprimer') ? 'true' : 'false' ?>;
    </script>
    <aside class="assistant-sidebar" id="assistantSidebar">
        <div class="assistant-sidebar-header">
            <div>
                <span class="assistant-eyebrow">Espace intelligent</span>
                <h1>Vos conversations</h1>
            </div>
            <button type="button" class="assistant-icon-button assistant-sidebar-close" id="assistantSidebarClose" aria-label="Fermer l’historique">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <button type="button" class="assistant-new-chat" id="newConversation">
            <i class="fa-solid fa-plus"></i>
            <span>Nouvelle conversation</span>
        </button>

        <div class="assistant-conversation-list" id="conversationList">
            <?php if ($conversations === []): ?>
                <div class="assistant-history-empty">
                    <i class="fa-regular fa-comments"></i>
                    <p>Votre historique apparaîtra ici.</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conversation): ?>
                    <div class="assistant-conversation-row">
                        <button type="button" class="assistant-conversation-item" data-conversation-id="<?= (int) $conversation['id'] ?>">
                            <i class="fa-regular fa-message"></i>
                            <span>
                                <strong><?= e($conversation['titre']) ?></strong>
                                <small><?= e(formatDate($conversation['last_message_at'] ?: $conversation['created_at'], 'd/m/Y H:i')) ?></small>
                            </span>
                        </button>
                        <?php if (can('assistant.supprimer')): ?>
                            <button type="button" class="assistant-conversation-delete" data-conversation-id="<?= (int) $conversation['id'] ?>" aria-label="Supprimer la conversation">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="assistant-history-more" id="assistantHistoryMore" <?= !$conversationPage['has_more'] ? 'hidden' : '' ?>>
            <i class="fa-solid fa-chevron-down"></i> Charger plus
        </button>

        <div class="assistant-sidebar-footer">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Vos échanges sont protégés par vos permissions.</span>
        </div>
    </aside>

    <div class="assistant-main">
        <div class="assistant-mobile-toolbar">
            <button type="button" class="assistant-icon-button" id="assistantSidebarOpen" aria-label="Afficher l’historique">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </button>
            <span>Assistant scolaire</span>
            <button type="button" class="assistant-icon-button" id="assistantMobileNew" aria-label="Nouvelle conversation">
                <i class="fa-solid fa-plus"></i>
            </button>
        </div>

        <div class="assistant-welcome" id="assistantWelcome">
            <div class="assistant-orb">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
            <span class="assistant-eyebrow">Assistant scolaire</span>
            <h2>Comment puis-je vous aider ?</h2>
            <p>Posez une question sur les élèves, les classes, les paiements, les absences ou les résultats.</p>

            <div class="assistant-suggestion-grid">
                <button type="button" class="assistant-suggestion" data-prompt="Combien d’élèves avons-nous dans l’école ?">
                    <i class="fa-solid fa-users"></i>
                    <span>Voir l’effectif total</span>
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </button>
                <button type="button" class="assistant-suggestion" data-prompt="Donne-moi la liste des élèves d’une classe.">
                    <i class="fa-solid fa-list-check"></i>
                    <span>Rechercher des élèves</span>
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </button>
                <button type="button" class="assistant-suggestion" data-prompt="Quelle classe rencontre des difficultés de paiement ?">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Analyser les paiements</span>
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </button>
            </div>
        </div>

        <div class="assistant-messages" id="assistantMessages" aria-live="polite"></div>

        <?php if (can('assistant.importer')): ?>
        <input type="file" id="assistantFileInput" accept=".csv,image/jpeg,image/png,image/webp" hidden>
        <section class="assistant-upload-panel" id="assistantUploadPanel" hidden aria-live="polite">
            <div class="assistant-upload-preview" id="assistantUploadPreview"></div>
            <div class="assistant-upload-actions">
                <button type="button" class="assistant-secondary-button" id="assistantUploadCancel">
                    <i class="fa-solid fa-xmark"></i> Annuler
                </button>
                <button type="button" class="assistant-danger-button" id="assistantUploadDelete">
                    <i class="fa-solid fa-trash"></i> Supprimer
                </button>
                <button type="button" class="assistant-primary-button" id="assistantUploadConfirm" hidden>
                    <i class="fa-solid fa-check"></i> Confirmer l’import
                </button>
            </div>
        </section>
        <?php endif; ?>

        <form class="assistant-composer" id="assistantComposer">
            <div class="assistant-composer-inner">
                <?php if (can('assistant.importer')): ?>
                <button type="button" class="assistant-attach-button" id="assistantAttach" title="Joindre un fichier" aria-label="Joindre un fichier">
                    <i class="fa-solid fa-paperclip"></i>
                </button>
                <?php endif; ?>
                <textarea id="assistantInput" name="message" rows="1" maxlength="<?= (int) ASSISTANT_MAX_MESSAGE_LENGTH ?>" placeholder="Écrivez votre question..." aria-label="Votre question"></textarea>
                <button type="submit" class="assistant-send-button" id="assistantSend" aria-label="Envoyer la question">
                    <i class="fa-solid fa-arrow-up"></i>
                </button>
            </div>
            <p class="assistant-composer-hint"><i class="fa-solid fa-circle-info"></i> L’assistant répond uniquement selon vos droits d’accès.</p>
        </form>
    </div>
</section>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
