<?php
/**
 * Vue : erreur HTTP 404
 * Rend uniquement la présentation de la page introuvable.
 */
defined('ACCESS') or die('Accès interdit');
?>

        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>404 — Page introuvable</title>
            <link rel="stylesheet" href="/gestion_scolaire/public/assets/fontawesome/css/all.min.css">
            <style>
                *{margin:0;padding:0;box-sizing:border-box}
                body{
                    font-family:'Poppins',sans-serif;
                    background:linear-gradient(135deg,#2c3e90,#1a274f);
                    min-height:100vh;display:flex;align-items:center;justify-content:center;
                    color:#fff;text-align:center;padding:20px;
                }
                .error-code{font-size:8rem;font-weight:800;opacity:0.2;line-height:1}
                .error-title{font-size:1.5rem;font-weight:600;margin:16px 0}
                .error-message{opacity:0.7;margin-bottom:30px}
                .btn-home{
                    display:inline-flex;align-items:center;gap:8px;
                    padding:12px 28px;background:#fff;color:#2c3e90;
                    border-radius:50px;text-decoration:none;font-weight:600;transition:all 0.3s;
                }
                .btn-home:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,0.3)}
            </style>
        </head>
        <body>
            <div>
                <div class="error-code">404</div>
                <h1 class="error-title"><i class="fa-solid fa-map-signs"></i> Page introuvable</h1>
                <p class="error-message">La page que vous recherchez n'existe pas.</p>
                <a href="/gestion_scolaire/dashboard" class="btn-home">
                    <i class="fa-solid fa-house"></i> Retour au tableau de bord
                </a>
            </div>
        </body>
        </html>
