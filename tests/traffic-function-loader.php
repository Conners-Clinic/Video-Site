<?php
/** Load selected, trusted repository function definitions without app_start side effects. */
function trafficLoadFunctions($file, $names) {
    $tokens = token_get_all(file_get_contents($file));
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $start = $i; $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || !in_array($tokens[$j][1], $names, true)) { continue; }
        $name = $tokens[$j][1]; $depth = 0; $opened = false; $code = '';
        for (; $i < $count; $i++) {
            $token = $tokens[$i]; $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { $depth++; $opened = true; }
            if ($token === '}') { $depth--; if ($opened && $depth === 0) { break; } }
        }
        if (!function_exists($name)) { eval($code); }
    }
    foreach ($names as $name) { if (!function_exists($name)) { throw new RuntimeException('Missing test function: ' . $name); } }
}
