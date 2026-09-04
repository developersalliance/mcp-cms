<?php
// Consistency check: every tool has a one-liner, a schema and a handler.
require __DIR__ . '/../mcp/tools-definition.php';
$a = array_keys(getMCPTools());
$b = array_keys(getMCPToolsWithSchema());
$src = file_get_contents(__DIR__ . '/../mcp/handlers.php');
$missingHandlers = [];
foreach ($a as $t) {
    if (!preg_match("/'" . preg_quote($t, '/') . "'\s*=>\s*function/", $src)) $missingHandlers[] = $t;
}
echo count($a) . ' tools, ' . count($b) . " schemas\n";
echo 'one-liner without schema: ' . json_encode(array_values(array_diff($a, $b))) . "\n";
echo 'schema without one-liner: ' . json_encode(array_values(array_diff($b, $a))) . "\n";
echo 'without handler: ' . json_encode($missingHandlers) . "\n";
exit((array_diff($a, $b) || array_diff($b, $a) || $missingHandlers) ? 1 : 0);
