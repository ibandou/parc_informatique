<?php
// Connexion unique à la base donnée MySQL

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $configFile = __DIR__ . '/../config/database.php';
        if (!is_file($configFile)) {
            exit('Configuration manquante : copiez config/database.example.php en config/database.php.');
        }
        $c = require $configFile;

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['dbname'], $c['charset']);

        try {
            $pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Le message technique (hôte, utilisateur...) n'est jamais affiché au visiteur
            error_log('Connexion MySQL impossible : ' . $e->getMessage());
            http_response_code(500);
            exit('Connexion à la base de données impossible. Vérifiez config/database.php et que MySQL est démarré.');
        }

        // Aligne le fuseau de MySQL (NOW(), CURRENT_TIMESTAMP) sur celui de PHP
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }

    return $pdo;
}

// Raccourcis pour les requêtes préparées
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_fetch_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = [])
{
    return db_query($sql, $params)->fetchColumn();
}
