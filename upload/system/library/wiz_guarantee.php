<?php
class WizGuarantee {
    const TITLE = 'Zakonsko jamstvo – najmanje 2 godine';
    const EU = 'https://europa.eu/youreurope/jamstva_hr';
    const IMAGE = 'catalog/view/theme/basel/image/legal-guarantee/legal-guarantee-notice-hr.png';

    public static function html($storeUrl) {
        $url = rtrim($storeUrl, '/') . '/index.php?route=information/legal_guarantee';
        $withdrawal = rtrim($storeUrl, '/') . '/index.php?route=information/withdrawal';
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $returnLink = htmlspecialchars($withdrawal, ENT_QUOTES, 'UTF-8');
        return '<div style="margin:20px 0;font:15px/1.5 Arial,sans-serif"><p><strong><a href="' . $safe . '">' . self::TITLE . '</a></strong><br><a href="' . $safe . '"><img src="' . htmlspecialchars(rtrim($storeUrl, '/') . '/' . self::IMAGE, ENT_QUOTES, 'UTF-8') . '" width="300" style="max-width:100%;height:auto" alt="Službena hrvatska obavijest o zakonskom jamstvu"></a><br> <a href="' . self::EU . '">Informacije EU-a o jamstvu</a></p><p><a href="' . $returnLink . '">Jednostrani raskid ugovora u roku od 14 dana i povrat artikala</a></p></div>';
    }

    public static function append($storeUrl, &$html, &$text) {
        $url = rtrim($storeUrl, '/') . '/index.php?route=information/legal_guarantee';
        if ($html && strpos($html, 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"') === false) {
            $section = self::html($storeUrl);
            $end = strripos($html, '</body>');
            $html = $end === false ? $html . $section : substr_replace($html, $section, $end, 0);
        }
        if (!preg_match('~^' . preg_quote($url, '~') . '\r?$~m', (string)$text)) {
            $text = trim((string)$text . "\n\n" . self::text($storeUrl));
        }
    }

    public static function text($storeUrl) {
        $base = rtrim($storeUrl, '/') . '/index.php?route=information/';
        return self::TITLE . "\n" . $base . "legal_guarantee\n" . self::EU .
            "\n\nJednostrani raskid ugovora i povrat artikala\n" . $base . 'withdrawal';
    }

    public static function restoreOrderMail($mail, $html, $storeUrl) {
        require_once DIR_SYSTEM . 'library/emailtemplate.php';
        $text = EmailTemplateHtml2Text::convert('<?xml encoding="UTF-8">' . $html);
        self::append($storeUrl, $html, $text);
        $mail->setHtml($html);
        $mail->setText($text);
        $mail->addAttachment(dirname(DIR_SYSTEM) . '/' . self::IMAGE);
    }
}
