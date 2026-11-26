<?php
/** Preserve literal article text through the application's later template pass. */
function PT_EncodeArticleTemplateBraces($text)
{
    return str_replace(array('{', '}'), array('&#123;', '&#125;'), (string) $text);
}

/** Server-side HTML policy for article bodies, including pre-existing rows. */
function PT_SanitizeArticleHtml($html)
{
    static $purifier = null;

    if (!is_string($html)) {
        return '';
    }

    if ($purifier === null) {
        require_once __DIR__ . '/../libs/security-html/vendor/autoload.php';
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // Build definitions in memory; do not trust a cache writable by the web user.
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', implode(',', array(
            'p[style|class]', 'br', 'hr', 'div[style|class]', 'span[style|class]',
            'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]', 'h5[style]', 'h6[style]',
            'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup',
            'blockquote[cite|style]', 'pre[class]', 'code[class]',
            'ul[style]', 'ol[start|type|style]', 'li[style]', 'dl', 'dt', 'dd',
            'a[href|title|target|rel]', 'img[src|alt|title|width|height|style]',
            'table[summary|width|border|cellpadding|cellspacing|style]',
            'caption[style]', 'thead', 'tbody', 'tfoot', 'tr[style]',
            'th[colspan|rowspan|scope|abbr|style]', 'td[colspan|rowspan|style]'
        )));
        $config->set('Attr.EnableID', false);
        $config->set('Attr.AllowedFrameTargets', array('_blank', '_self'));
        $config->set('HTML.SafeIframe', false);
        $config->set('URI.AllowedSchemes', array('http' => true, 'https' => true, 'mailto' => true));
        $config->set('CSS.AllowedProperties', array(
            'color', 'background-color', 'font-family', 'font-size', 'font-style',
            'font-weight', 'text-align', 'text-decoration', 'text-indent',
            'line-height', 'vertical-align', 'white-space', 'width', 'height',
            'max-width', 'border', 'border-width', 'border-style', 'border-color',
            'border-collapse', 'padding', 'padding-left', 'padding-right',
            'padding-top', 'padding-bottom', 'margin-left', 'margin-right',
            'margin-top', 'margin-bottom', 'list-style-type'
        ));
        $purifier = new HTMLPurifier($config);
    }

    // PT_LoadPage expands template directives after inserting TEXT. Keep article
    // braces literal in the browser rather than interpreting them as directives.
    // Do not HTML-decode this output again before rendering.
    return PT_EncodeArticleTemplateBraces($purifier->purify($html));
}
