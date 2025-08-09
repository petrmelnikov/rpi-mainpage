<div class="row">
    <div class="col-sm">
        <?php
        /** @var array|string $shellCommandRawContent */
        $raw = is_array($shellCommandRawContent)
            ? implode("\n", $shellCommandRawContent)
            : (string)$shellCommandRawContent;

        // Simple ANSI to HTML converter with HTML escaping
        if (!function_exists('ansi_to_html')) {
            function ansi_to_html(string $text): string {
                $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                $fg = [
                    30 => '#000000', 31 => '#AA0000', 32 => '#00AA00', 33 => '#AA5500',
                    34 => '#0000AA', 35 => '#AA00AA', 36 => '#00AAAA', 37 => '#AAAAAA',
                    90 => '#555555', 91 => '#FF5555', 92 => '#55FF55', 93 => '#FFFF55',
                    94 => '#5555FF', 95 => '#FF55FF', 96 => '#55FFFF', 97 => '#FFFFFF',
                ];
                $bg = [
                    40 => '#000000', 41 => '#AA0000', 42 => '#00AA00', 43 => '#AA5500',
                    44 => '#0000AA', 45 => '#AA00AA', 46 => '#00AAAA', 47 => '#AAAAAA',
                    100 => '#555555', 101 => '#FF5555', 102 => '#55FF55', 103 => '#FFFF55',
                    104 => '#5555FF', 105 => '#FF55FF', 106 => '#55FFFF', 107 => '#FFFFFF',
                ];

                $result = '';
                $offset = 0;
                $current = [
                    'color' => null,
                    'background' => null,
                    'bold' => false,
                    'italic' => false,
                    'underline' => false,
                ];
                $open = false;

                $pattern = "/\x1b\[((?:\d{1,3};?)+)m/"; // ESC[ ... m
                if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                    return $text;
                }

                foreach ($matches[0] as $i => $match) {
                    [$full, $pos] = $match;
                    $codesStr = $matches[1][$i][0];

                    // Append text before this escape
                    $chunk = substr($text, $offset, $pos - $offset);
                    $result .= $chunk;
                    $offset = $pos + strlen($full);

                    // Parse codes
                    $codes = array_filter(
                        array_map('intval', explode(';', $codesStr)),
                        function ($c) use ($codesStr) { return $c !== 0 || $codesStr === '0'; }
                    );
                    if ($codes === []) {
                        $codes = [0];
                    }

                    foreach ($codes as $code) {
                        switch ($code) {
                            case 0: // reset
                                $current = [
                                    'color' => null,
                                    'background' => null,
                                    'bold' => false,
                                    'italic' => false,
                                    'underline' => false,
                                ];
                                if ($open) { $result .= '</span>'; $open = false; }
                                break;
                            case 1: $current['bold'] = true; break;
                            case 3: $current['italic'] = true; break;
                            case 4: $current['underline'] = true; break;
                            case 22: $current['bold'] = false; break;
                            case 23: $current['italic'] = false; break;
                            case 24: $current['underline'] = false; break;
                            case 39: $current['color'] = null; break;
                            case 49: $current['background'] = null; break;
                            default:
                                if (isset($fg[$code])) { $current['color'] = $fg[$code]; }
                                if (isset($bg[$code])) { $current['background'] = $bg[$code]; }
                                break;
                        }
                    }

                    // Open/update span with current styles
                    $styleParts = [];
                    if ($current['color']) { $styleParts[] = 'color:' . $current['color']; }
                    if ($current['background']) { $styleParts[] = 'background-color:' . $current['background']; }
                    if ($current['bold']) { $styleParts[] = 'font-weight:bold'; }
                    if ($current['italic']) { $styleParts[] = 'font-style:italic'; }
                    if ($current['underline']) { $styleParts[] = 'text-decoration:underline'; }

                    $style = implode(';', $styleParts);
                    if ($open) { $result .= '</span>'; $open = false; }
                    if ($style !== '') { $result .= '<span style="' . $style . '">'; $open = true; }
                }

                // Append the rest
                $result .= substr($text, $offset);
                if ($open) { $result .= '</span>'; }
                return $result;
            }
        }

        $html = ansi_to_html($raw);
        ?>

        <?php if (!isset($GLOBALS['__console_styles_included'])): $GLOBALS['__console_styles_included'] = true; ?>
            <style>
                .console-box { background: #111; color: #eee; border-radius: 6px; padding: 12px; }
                .console-pre { margin: 0; white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 0.9rem; line-height: 1.4; }
            </style>
        <?php endif; ?>

        <div class="console-box">
            <pre class="console-pre"><?php echo $html; ?></pre>
        </div>
    </div>
</div>