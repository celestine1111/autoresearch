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
     * v1.5.206d-fix6 — Build a regex alternation matching the English form OR
     * the article's localized form for a given key.
     *
     * Used by Content_Formatter detection (Tip/Note/Warning callouts, Key
     * Takeaways block, References block) so the same code path works for
     * every language: English articles match the English pattern, Korean
     * articles ALSO match `팁`/`핵심 요약`/`참고 자료`, etc.
     *
     * @param string $key       Localized-Strings key (tip, note, warning, key_takeaways, references, last_updated, etc.).
     * @param string $lang      Article language (en, ja, ko, ru, de, fr, etc.).
     * @param string $english_pattern Optional English-side regex fragment (no delimiters).
     *                                Defaults to preg_quote(Localized_Strings::get($key, 'en')).
     * @return string Regex fragment — use inside your own delimiters.
     */
    public static function get_detection_pattern( string $key, string $lang = 'en', string $english_pattern = '' ): string {
        $english = $english_pattern !== '' ? $english_pattern : preg_quote( self::get( $key, 'en' ), '/' );
        $lang = strtolower( str_replace( '_', '-', trim( $lang ) ) );
        if ( $lang === 'en' || strpos( $lang, 'en-' ) === 0 ) {
            return $english;
        }
        $localized = self::get( $key, $lang );
        $localized_en = self::get( $key, 'en' );
        if ( $localized === $localized_en || $localized === '' ) {
            return $english; // no localized variant (fell through to English)
        }
        // OR the English pattern with the localized literal
        return '(?:' . $english . '|' . preg_quote( $localized, '/' ) . ')';
    }

    /**
     * v1.5.206d-fix6 — Return a compact prompt-ready block that tells the AI
     * to use EXACT canonical translations for the structural anchors the
     * plugin detects (Key Takeaways, References, Last Updated, FAQ,
     * Introduction, Conclusion, Tip, Note, Warning, Pros, Cons).
     *
     * Without this block, a non-English article may pick its own synonyms
     * (e.g. Korean AI rendered `중요 포인트` instead of `핵심 요약` for Key
     * Takeaways). Content_Formatter detects sections by exact localized
     * label, so a synonym breaks the styled-block render AND the BLUF score.
     *
     * Returns empty string for English articles (byte-identical prompt).
     */
    public static function canonical_translation_block( string $lang = 'en' ): string {
        $lang = strtolower( str_replace( '_', '-', trim( $lang ) ) );
        if ( $lang === 'en' || strpos( $lang, 'en-' ) === 0 ) {
            return '';
        }
        $keys = [
            'last_updated'  => 'Last Updated',
            'key_takeaways' => 'Key Takeaways',
            'references'    => 'References',
            'faq'           => 'Frequently Asked Questions',
            'introduction'  => 'Introduction',
            'conclusion'    => 'Conclusion',
            'tip'           => 'Tip',
            'note'          => 'Note',
            'warning'       => 'Warning',
            'pros'          => 'Pros',
            'cons'          => 'Cons',
        ];
        $lines = [];
        foreach ( $keys as $key => $english ) {
            $translated = self::get( $key, $lang );
            if ( $translated !== '' && $translated !== $english ) {
                $lines[] = '- ' . $english . ' → ' . $translated;
            }
        }
        if ( empty( $lines ) ) {
            return '';
        }
        return "\n\nCANONICAL TRANSLATIONS (v1.5.206d-fix6 — USE THESE EXACT TERMS, NOT YOUR OWN VARIANTS): The plugin auto-detects these structural anchors via exact string matching. If you output a different synonym, the styled callout boxes will not render and the scoring rubric will miss the signal. Use the left-hand English term's right-hand translation VERBATIM:\n"
            . implode( "\n", $lines )
            . "\n\nThis rule applies to H2/H3 section headings (Key Takeaways, Introduction, Conclusion, Frequently Asked Questions, References), callout-box prefixes (\"Tip:\", \"Note:\", \"Warning:\"), the Last Updated freshness line at the top of the article, and pros/cons list headings. Use the translated term IN the heading or the prefix — do not invent synonyms.";
    }

    /**
     * v1.5.206d-fix7.1 — Single source of truth for BCP-47 → human-readable
     * language name. Used by `Async_Generator::get_system_prompt()`,
     * `AI_Content_Generator::generate_headlines()`, and anywhere else the
     * plugin needs to render a language name to the AI or the user.
     *
     * Covers 46 languages — the union of what Regional_Context supports,
     * what Localized_Strings already translates UI labels for, and what
     * Async_Generator ships in its per-language writing rule.
     *
     * Fallback: returns 'English' for unknown codes (safe default — AI still
     * writes in the target language because the LANGUAGE rule has other
     * enforcement mechanisms beyond the name).
     */
    public static function get_language_name( string $lang ): string {
        $lang = strtolower( substr( trim( $lang ), 0, 2 ) );
        $names = [
            'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
            'pt' => 'Portuguese', 'it' => 'Italian', 'nl' => 'Dutch', 'sv' => 'Swedish',
            'no' => 'Norwegian', 'da' => 'Danish', 'fi' => 'Finnish', 'pl' => 'Polish',
            'cs' => 'Czech', 'sk' => 'Slovak', 'hu' => 'Hungarian', 'ro' => 'Romanian',
            'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sr' => 'Serbian', 'sl' => 'Slovenian',
            'uk' => 'Ukrainian', 'ru' => 'Russian', 'tr' => 'Turkish', 'el' => 'Greek',
            'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese (Simplified)',
            'ar' => 'Arabic', 'he' => 'Hebrew', 'hi' => 'Hindi', 'bn' => 'Bengali',
            'th' => 'Thai', 'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay',
            'sw' => 'Swahili', 'ur' => 'Urdu', 'si' => 'Sinhala', 'ne' => 'Nepali',
            'mn' => 'Mongolian', 'kk' => 'Kazakh', 'uz' => 'Uzbek', 'is' => 'Icelandic',
            'et' => 'Estonian', 'lv' => 'Latvian', 'lt' => 'Lithuanian',
        ];
        return $names[ $lang ] ?? 'English';
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

            // v1.5.206d-fix6 — Callout-box labels (Content_Formatter renders these
            // as the bold prefix inside Tip / Note / Warning blocks). Detection
            // regex also matches these so AI-written non-English callouts are
            // correctly recognised and styled.

            'tip' => [
                'en'    => 'Tip',
                'ja'    => 'ヒント',
                'zh'    => '提示',
                'zh-cn' => '提示',
                'zh-tw' => '提示',
                'ko'    => '팁',
                'ru'    => 'Совет',
                'de'    => 'Tipp',
                'fr'    => 'Astuce',
                'es'    => 'Consejo',
                'it'    => 'Suggerimento',
                'pt'    => 'Dica',
                'pt-br' => 'Dica',
                'hi'    => 'सुझाव',
                'ar'    => 'نصيحة',
                'nl'    => 'Tip',
                'pl'    => 'Wskazówka',
                'tr'    => 'İpucu',
                'sv'    => 'Tips',
                'da'    => 'Tip',
                'no'    => 'Tips',
                'fi'    => 'Vinkki',
                'cs'    => 'Tip',
                'hu'    => 'Tipp',
                'ro'    => 'Sfat',
                'el'    => 'Συμβουλή',
                'uk'    => 'Порада',
                'vi'    => 'Mẹo',
                'th'    => 'เคล็ดลับ',
                'id'    => 'Tips',
                'ms'    => 'Tip',
                'he'    => 'טיפ',
            ],

            'note' => [
                'en'    => 'Note',
                'ja'    => '注意',
                'zh'    => '注意',
                'zh-cn' => '注意',
                'zh-tw' => '注意',
                'ko'    => '참고',
                'ru'    => 'Примечание',
                'de'    => 'Hinweis',
                'fr'    => 'Remarque',
                'es'    => 'Nota',
                'it'    => 'Nota',
                'pt'    => 'Nota',
                'pt-br' => 'Nota',
                'hi'    => 'ध्यान दें',
                'ar'    => 'ملاحظة',
                'nl'    => 'Opmerking',
                'pl'    => 'Uwaga',
                'tr'    => 'Not',
                'sv'    => 'Obs',
                'da'    => 'Bemærk',
                'no'    => 'Merk',
                'fi'    => 'Huomio',
                'cs'    => 'Poznámka',
                'hu'    => 'Megjegyzés',
                'ro'    => 'Notă',
                'el'    => 'Σημείωση',
                'uk'    => 'Примітка',
                'vi'    => 'Lưu ý',
                'th'    => 'หมายเหตุ',
                'id'    => 'Catatan',
                'ms'    => 'Nota',
                'he'    => 'הערה',
            ],

            'warning' => [
                'en'    => 'Warning',
                'ja'    => '警告',
                'zh'    => '警告',
                'zh-cn' => '警告',
                'zh-tw' => '警告',
                'ko'    => '경고',
                'ru'    => 'Внимание',
                'de'    => 'Warnung',
                'fr'    => 'Attention',
                'es'    => 'Advertencia',
                'it'    => 'Attenzione',
                'pt'    => 'Aviso',
                'pt-br' => 'Aviso',
                'hi'    => 'चेतावनी',
                'ar'    => 'تحذير',
                'nl'    => 'Waarschuwing',
                'pl'    => 'Ostrzeżenie',
                'tr'    => 'Uyarı',
                'sv'    => 'Varning',
                'da'    => 'Advarsel',
                'no'    => 'Advarsel',
                'fi'    => 'Varoitus',
                'cs'    => 'Upozornění',
                'hu'    => 'Figyelem',
                'ro'    => 'Avertisment',
                'el'    => 'Προσοχή',
                'uk'    => 'Увага',
                'vi'    => 'Cảnh báo',
                'th'    => 'คำเตือน',
                'id'    => 'Peringatan',
                'ms'    => 'Amaran',
                'he'    => 'אזהרה',
            ],

            // Common H2 section anchors — the AI translates these in non-English
            // articles and Content_Formatter/GEO_Analyzer detection uses these
            // canonical forms. Without a canonical table, AI variants (e.g. Korean
            // "중요 포인트" for Key Takeaways instead of "핵심 요약") break detection.

            'faq' => [
                'en'    => 'Frequently Asked Questions',
                'ja'    => 'よくある質問',
                'zh'    => '常见问题',
                'zh-cn' => '常见问题',
                'zh-tw' => '常見問題',
                'ko'    => '자주 묻는 질문',
                'ru'    => 'Часто задаваемые вопросы',
                'de'    => 'Häufig gestellte Fragen',
                'fr'    => 'Questions fréquemment posées',
                'es'    => 'Preguntas frecuentes',
                'it'    => 'Domande frequenti',
                'pt'    => 'Perguntas frequentes',
                'pt-br' => 'Perguntas frequentes',
                'hi'    => 'अक्सर पूछे जाने वाले प्रश्न',
                'ar'    => 'الأسئلة الشائعة',
                'nl'    => 'Veelgestelde vragen',
                'pl'    => 'Często zadawane pytania',
                'tr'    => 'Sıkça Sorulan Sorular',
                'sv'    => 'Vanliga frågor',
                'da'    => 'Ofte stillede spørgsmål',
                'no'    => 'Ofte stilte spørsmål',
                'fi'    => 'Usein kysytyt kysymykset',
                'cs'    => 'Často kladené otázky',
                'hu'    => 'Gyakran ismételt kérdések',
                'ro'    => 'Întrebări frecvente',
                'el'    => 'Συχνές ερωτήσεις',
                'uk'    => 'Часті запитання',
                'vi'    => 'Câu hỏi thường gặp',
                'th'    => 'คำถามที่พบบ่อย',
                'id'    => 'Pertanyaan yang sering diajukan',
                'ms'    => 'Soalan lazim',
                'he'    => 'שאלות נפוצות',
            ],

            'introduction' => [
                'en'    => 'Introduction',
                'ja'    => '序論',
                'zh'    => '简介',
                'zh-cn' => '简介',
                'zh-tw' => '簡介',
                'ko'    => '서론',
                'ru'    => 'Введение',
                'de'    => 'Einleitung',
                'fr'    => 'Introduction',
                'es'    => 'Introducción',
                'it'    => 'Introduzione',
                'pt'    => 'Introdução',
                'pt-br' => 'Introdução',
                'hi'    => 'परिचय',
                'ar'    => 'مقدمة',
                'nl'    => 'Inleiding',
                'pl'    => 'Wstęp',
                'tr'    => 'Giriş',
                'sv'    => 'Introduktion',
                'da'    => 'Introduktion',
                'no'    => 'Introduksjon',
                'fi'    => 'Johdanto',
                'cs'    => 'Úvod',
                'hu'    => 'Bevezetés',
                'ro'    => 'Introducere',
                'el'    => 'Εισαγωγή',
                'uk'    => 'Вступ',
                'vi'    => 'Giới thiệu',
                'th'    => 'บทนำ',
                'id'    => 'Pendahuluan',
                'ms'    => 'Pengenalan',
                'he'    => 'מבוא',
            ],

            'conclusion' => [
                'en'    => 'Conclusion',
                'ja'    => '結論',
                'zh'    => '结论',
                'zh-cn' => '结论',
                'zh-tw' => '結論',
                'ko'    => '결론',
                'ru'    => 'Заключение',
                'de'    => 'Fazit',
                'fr'    => 'Conclusion',
                'es'    => 'Conclusión',
                'it'    => 'Conclusione',
                'pt'    => 'Conclusão',
                'pt-br' => 'Conclusão',
                'hi'    => 'निष्कर्ष',
                'ar'    => 'خلاصة',
                'nl'    => 'Conclusie',
                'pl'    => 'Wniosek',
                'tr'    => 'Sonuç',
                'sv'    => 'Slutsats',
                'da'    => 'Konklusion',
                'no'    => 'Konklusjon',
                'fi'    => 'Johtopäätös',
                'cs'    => 'Závěr',
                'hu'    => 'Következtetés',
                'ro'    => 'Concluzie',
                'el'    => 'Συμπέρασμα',
                'uk'    => 'Висновок',
                'vi'    => 'Kết luận',
                'th'    => 'สรุป',
                'id'    => 'Kesimpulan',
                'ms'    => 'Kesimpulan',
                'he'    => 'סיכום',
            ],

            'pros' => [
                'en'    => 'Pros',
                'ja'    => 'メリット',
                'zh'    => '优点',
                'zh-cn' => '优点',
                'zh-tw' => '優點',
                'ko'    => '장점',
                'ru'    => 'Плюсы',
                'de'    => 'Vorteile',
                'fr'    => 'Avantages',
                'es'    => 'Ventajas',
                'it'    => 'Vantaggi',
                'pt'    => 'Vantagens',
                'pt-br' => 'Vantagens',
                'hi'    => 'फायदे',
                'ar'    => 'المزايا',
                'nl'    => 'Voordelen',
                'pl'    => 'Zalety',
                'tr'    => 'Artılar',
                'sv'    => 'Fördelar',
                'da'    => 'Fordele',
                'no'    => 'Fordeler',
                'fi'    => 'Edut',
                'cs'    => 'Klady',
                'hu'    => 'Előnyök',
                'ro'    => 'Avantaje',
                'el'    => 'Πλεονεκτήματα',
                'uk'    => 'Плюси',
                'vi'    => 'Ưu điểm',
                'th'    => 'ข้อดี',
                'id'    => 'Kelebihan',
                'ms'    => 'Kelebihan',
                'he'    => 'יתרונות',
            ],

            'cons' => [
                'en'    => 'Cons',
                'ja'    => 'デメリット',
                'zh'    => '缺点',
                'zh-cn' => '缺点',
                'zh-tw' => '缺點',
                'ko'    => '단점',
                'ru'    => 'Минусы',
                'de'    => 'Nachteile',
                'fr'    => 'Inconvénients',
                'es'    => 'Desventajas',
                'it'    => 'Svantaggi',
                'pt'    => 'Desvantagens',
                'pt-br' => 'Desvantagens',
                'hi'    => 'नुकसान',
                'ar'    => 'العيوب',
                'nl'    => 'Nadelen',
                'pl'    => 'Wady',
                'tr'    => 'Eksiler',
                'sv'    => 'Nackdelar',
                'da'    => 'Ulemper',
                'no'    => 'Ulemper',
                'fi'    => 'Haitat',
                'cs'    => 'Zápory',
                'hu'    => 'Hátrányok',
                'ro'    => 'Dezavantaje',
                'el'    => 'Μειονεκτήματα',
                'uk'    => 'Мінуси',
                'vi'    => 'Nhược điểm',
                'th'    => 'ข้อเสีย',
                'id'    => 'Kekurangan',
                'ms'    => 'Kekurangan',
                'he'    => 'חסרונות',
            ],
        ];
    }
}
