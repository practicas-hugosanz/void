<?php
// Borrar este archivo en producción una vez verificado
echo "<h2>PHP OK — " . phpversion() . "</h2>";

echo "<h3>Extensiones PDO:</h3><pre>";
$drivers = PDO::getAvailableDrivers();
echo implode(', ', $drivers) ?: 'ninguno';
echo "</pre>";

$url = getenv('DATABASE_URL');
echo "<h3>DATABASE_URL:</h3><pre>" . ($url ? '✅ definida' : '❌ NO definida') . "</pre>";

if ($url) {
    try {
        $parts = parse_url($url);
        $dsn = "pgsql:host={$parts['host']};port=" . ($parts['port'] ?? 5432) . ";dbname=" . ltrim($parts['path'], '/') . ";sslmode=require";
        $pdo = new PDO($dsn, $parts['user'], $parts['pass']);
        echo "<h3>Conexión PostgreSQL:</h3><pre>✅ OK</pre>";
    } catch (Exception $e) {
        echo "<h3>Conexión PostgreSQL:</h3><pre>❌ " . $e->getMessage() . "</pre>";
    }
}
