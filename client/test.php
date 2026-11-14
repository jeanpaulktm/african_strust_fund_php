<?php
$file = __DIR__ . '/retrait.php';
$content = file_get_contents($file);
$first = substr($content, 0, 20);

echo "<pre>";
echo "Premiers 20 octets (hex) : " . bin2hex($first) . "\n\n";

if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
    echo "❌ BOM détecté — réenregistrez en UTF-8 sans BOM\n";
} else {
    echo "✅ Pas de BOM\n";
}

if (substr($content, 0, 5) === "<?php") {
    echo "✅ Commence par <?php\n";
} else {
    echo "❌ Ne commence PAS par <?php\n";
    echo "Début : '" . substr($first, 0, 15) . "'\n";
}

echo "</pre>";