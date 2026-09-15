<?php

/**
 * Canonical plain-text normalization for values stored in SmartBooks.
 *
 * Database values must be stored as plain UTF-8 text. HTML escaping belongs
 * only at the final HTML-rendering boundary. These helpers deliberately decode
 * legacy entities such as "&amp;" before data is persisted or used as an
 * identity label.
 */
function smartbooksDecodeHtmlEntities($value): string
{
    $text = (string) ($value ?? '');

    // Decode more than once so legacy double-encoded values such as &amp;amp;
    // are repaired too, while stopping as soon as the value stabilises.
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $text) {
            break;
        }
        $text = $decoded;
    }

    // Normalise non-breaking spaces that may have come from &nbsp;/&#160;.
    $text = str_replace("\xC2\xA0", ' ', $text);

    return $text;
}

function smartbooksCanonicalText($value): string
{
    return trim(smartbooksDecodeHtmlEntities($value));
}

function smartbooksCanonicalName($value): string
{
    $text = smartbooksCanonicalText($value);
    $collapsed = preg_replace('/[\p{Z}\s]+/u', ' ', $text);
    return trim($collapsed ?? $text);
}

function smartbooksDecodeHtmlEntitiesRecursive($value)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = smartbooksDecodeHtmlEntitiesRecursive($item);
        }
        return $value;
    }

    if (is_string($value)) {
        return smartbooksDecodeHtmlEntities($value);
    }

    return $value;
}
