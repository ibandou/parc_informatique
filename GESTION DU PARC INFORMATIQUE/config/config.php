<?php
// Paramètres généraux de l'application (versionnés).
// Les identifiants de la base de données sont dans config/database.php (non versionné).

define('APP_NAME', 'IT CORP - Gestion du parc informatique');
define('APP_TIMEZONE', 'Africa/Libreville');

// Nom du cookie de session (évite les collisions avec d'autres applications du même serveur)
define('SESSION_NAME', 'itcorp_parc');

// Durée d'inactivité (en secondes) au-delà de laquelle la session est fermée
define('SESSION_TIMEOUT', 2 * 60 * 60);

// Longueur minimale des mots de passe
define('PASSWORD_MIN_LENGTH', 8);

// Nombre d'éléments par page dans les listes et tableaux
define('ITEMS_PER_PAGE', 10);
