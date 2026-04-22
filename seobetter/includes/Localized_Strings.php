<?php

namespace SEOBetter;

/**
 * Localized UI Strings (v1.5.206d — Layer 6 piece 4 of 4).
 *
 * Returns translated versions of the short UI labels SEOBetter emits directly
 * into article markup (not AI-generated content). Used when article language
 * is non-English to prevent English strings leaking through into Japanese,
 * Korean, Chinese, Russian, etc. articles.
 *
 * Scope: labels the plugin renders — NOT AI-generated H2 section headings.
 * Those are translated by the AI itself via the LANGUAGE rule in
 * Async_Generator::get_system_prompt(); the prose templates list section
 * names in English as structural anchors and the AI translates them for
 * reader-facing output.
 *
 * Covered labels:
 *   - last_updated — "Last Updated" freshness-signal prefix
 *   - key_takeaways — block header above the auto-styled Key Takeaways box
 *   - references — block header above the References box
 *
 * Fallback chain: $lang exact match → language family match
 * (e.g. 'pt-BR' → 'pt') → 'en'. Case-insensitive on the lang code.
 *
 * Translations sourced from: Wikipedia equivalents + major newspaper style
 * guides for each language + native-speaker review where available. Short
 * single-word/two-word labels so they fit existing UI chrome.
 */
class Localized_Strings {

    /**
     * Get a translated UI label.
     *
     * @param string $key  One of: last_updated, key_takeaways, references.
     * @param string $lang Language code (BCP-47 or ISO 639-1; 'en', 'ja', 'zh-CN', etc.).
     * @return string Translated label. Falls back to English on unknown key/lang.
     */
    public static function get( string $key, string $lang = 'en' ): string {
        $lang = strtolower( str_replace( '_', '-', trim( $lang ) ) );

        $t = self::get_translations();

        if ( ! isset( $t[ $key ] ) ) {
            return $key; // unknown key → return as-is for debugging
        }

        $strings = $t[ $key ];

        // Exact match (e.g. 'pt-br')
        if ( isset( $strings[ $lang ] ) ) {
            return $strings[ $lang ];
        }

        // Language family fallback (e.g. 'pt-br' → 'pt')
        if ( strpos( $lang, '-' ) !== false ) {
            $base = substr( $lang, 0, strpos( $lang, '-' ) );
            if ( isset( $strings[ $base ] ) ) {
                return $strings[ $base ];
            }
        }

        // Default to English
        return $strings['en'] ?? $key;
    }

    /**
     * v1.5.206d — Locale-aware "Month Year" string used in freshness signal.
     *
     * Uses wp_date() with locale hints so Japanese produces "2026年4月",
     * Chinese "2026年4月", Korean "2026년 4월", etc. Falls back to English
     * "April 2026" format for languages without a custom rule.
     */
    public static function month_year( string $lang = 'en', ?int $timestamp = null ): string {
        $ts = $timestamp ?: current_time( 'timestamp' );
        $lang = strtolower( str_replace( '_', '-', trim( $lang ) ) );
        $base = strpos( $lang, '-' ) !== false ? substr( $lang, 0, strpos( $lang, '-' ) ) : $lang;

        $month_num = (int) wp_date( 'n', $ts );
        $year      = wp_date( 'Y', $ts );

        // CJK: YYYY年MM月 pattern (Chinese/Japanese) / YYYY년 MM월 (Korean)
        if ( $base === 'ja' || $base === 'zh' ) {
            return "{$year}年{$month_num}月";
        }
        if ( $base === 'ko' ) {
            return "{$year}년 {$month_num}월";
        }

        // Localised month names for the priority languages
        $month_names = [
            'en' => [ '', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ],
            'ru' => [ '', 'январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь' ],
            'de' => [ '', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' ],
            'fr' => [ '', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' ],
            'es' => [ '', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ],
            'it' => [ '', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' ],
            'pt' => [ '', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro' ],
            'hi' => [ '', 'जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर' ],
            'ar' => [ '', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر' ],
        ];

        $names = $month_names[ $base ] ?? $month_names['en'];
        return "{$names[$month_num]} {$year}";
    }

    /**
     * Translation table for the short UI labels.
     * Keys: last_updated, key_takeaways, references.
     * Language codes follow ISO 639-1 (or BCP-47 for region variants).
     */
    private static function get_translations(): array {
        return [
            'last_updated' => [
                'en'    => 'Last Updated',
                'ja'    => '最終更新日',
                'zh'    => '最后更新',
                'zh-cn' => '最后更新',
                'zh-tw' => '最後更新',
                'ko'    => '최종 수정일',
                'ru'    => 'Последнее обновление',
                'de'    => 'Zuletzt aktualisiert',
                'fr'    => 'Dernière mise à jour',
                'es'    => 'Última actualización',
                'it'    => 'Ultimo aggiornamento',
                'pt'    => 'Última atualização',
                'pt-br' => 'Última atualização',
                'hi'    => 'अंतिम अद्यतन',
                'ar'    => 'آخر تحديث',
                'nl'    => 'Laatst bijgewerkt',
                'pl'    => 'Ostatnia aktualizacja',
                'tr'    => 'Son güncelleme',
                'sv'    => 'Senast uppdaterad',
                'da'    => 'Senest opdateret',
                'no'    => 'Sist oppdatert',
                'fi'    => 'Viimeksi päivitetty',
                'cs'    => 'Naposledy aktualizováno',
                'hu'    => 'Utoljára frissítve',
                'ro'    => 'Ultima actualizare',
                'el'    => 'Τελευταία ενημέρωση',
                'uk'    => 'Останнє оновлення',
                'vi'    => 'Cập nhật lần cuối',
                'th'    => 'อัปเดตล่าสุด',
                'id'    => 'Terakhir diperbarui',
                'ms'    => 'Kemas kini terakhir',
                'he'    => 'עודכן לאחרונה',
            ],
            'key_takeaways' => [
                'en'    => 'Key Takeaways',
                'ja'    => '重要なポイント',
                'zh'    => '要点',
                'zh-cn' => '要点',
                'zh-tw' => '重點整理',
                'ko'    => '핵심 요약',
                'ru'    => 'Ключевые выводы',
                'de'    => 'Die wichtigsten Erkenntnisse',
                'fr'    => 'Points clés',
                'es'    => 'Puntos clave',
                'it'    => 'Punti chiave',
                'pt'    => 'Principais pontos',
                'pt-br' => 'Principais pontos',
                'hi'    => 'मुख्य बिंदु',
                'ar'    => 'النقاط الرئيسية',
                'nl'    => 'Belangrijkste punten',
                'pl'    => 'Kluczowe wnioski',
                'tr'    => 'Önemli noktalar',
                'sv'    => 'Viktiga punkter',
                'da'    => 'Vigtige pointer',
                'no'    => 'Viktige punkter',
                'fi'    => 'Keskeiset havainnot',
                'cs'    => 'Klíčové poznatky',
                'hu'    => 'Legfontosabb megállapítások',
                'ro'    => 'Puncte cheie',
                'el'    => 'Βασικά σημεία',
                'uk'    => 'Ключові висновки',
                'vi'    => 'Điểm chính',
                'th'    => 'ประเด็นสำคัญ',
                'id'    => 'Poin utama',
                'ms'    => 'Perkara utama',
                'he'    => 'נקודות עיקריות',
            ],
            'references' => [
                'en'    => 'References',
                'ja'    => '参考文献',
                'zh'    => '参考资料',
                'zh-cn' => '参考资料',
                'zh-tw' => '參考資料',
                'ko'    => '참고 자료',
                'ru'    => 'Источники',
                'de'    => 'Quellen',
                'fr'    => 'Références',
                'es'    => 'Referencias',
                'it'    => 'Riferimenti',
                'pt'    => 'Referências',
                'pt-br' => 'Referências',
                'hi'    => 'संदर्भ',
                'ar'    => 'المراجع',
                'nl'    => 'Referenties',
                'pl'    => 'Źródła',
                'tr'    => 'Kaynaklar',
                'sv'    => 'Referenser',
                'da'    => 'Kilder',
                'no'    => 'Referanser',
                'fi'    => 'Lähteet',
                'cs'    => 'Zdroje',
                'hu'    => 'Hivatkozások',
                'ro'    => 'Referințe',
                'el'    => 'Αναφορές',
                'uk'    => 'Джерела',
                'vi'    => 'Nguồn tham khảo',
                'th'    => 'แหล่งอ้างอิง',
                'id'    => 'Referensi',
                'ms'    => 'Rujukan',
                'he'    => 'הפניות',
            ],
        ];
    }
}
