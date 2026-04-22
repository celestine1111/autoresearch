<?php

namespace SEOBetter;

/**
 * Regional Context Injector (v1.5.206c — Layer 6 piece 3 of 4).
 *
 * Builds a compact per-country context block appended to the system prompt
 * in Async_Generator::get_system_prompt() when the user selects a target
 * country. The block tells the AI which regional authority sources to
 * prefer, what date/measurement/currency conventions to use, and any
 * relevant editorial register conventions.
 *
 * Country-gated: returns an empty string for empty / US / GB / AU / CA /
 * NZ / IE so Western-default articles produce a byte-identical prompt to
 * pre-v1.5.206c. Non-Western countries not in the priority list also return
 * empty (strict approach — we don't guess guidance we haven't researched).
 *
 * Priority countries with custom blocks (v1.5.206c):
 *   CN / JP / KR / RU / DE / FR / ES / IT / BR / PT / IN / SA / AE / MX / AR
 *
 * Adding a new country:
 *   1. Add an ISO 2-letter key to self::get_blocks().
 *   2. Keep each block under ~8 short lines (prompt-size-conscious).
 *   3. Only list authority domains from the corresponding region's entry
 *      in external-links-policy.md §10 (so the AI's cited URLs survive
 *      validate_outbound_links).
 *   4. Update seo-guidelines/international-optimization.md §2 if the
 *      country now has a custom prompt block.
 */
class Regional_Context {

    /** Countries whose AI output is already well-tuned by the default prompt. */
    private const WESTERN_DEFAULT_COUNTRIES = [
        '', 'US', 'GB', 'AU', 'CA', 'NZ', 'IE',
    ];

    /**
     * Return the per-country context string to append to the system prompt.
     * Empty string = no-op (byte-identical prompt for Western-default articles).
     *
     * @param string $country_code ISO 2-letter code (e.g. 'JP', 'CN'). Empty allowed.
     */
    public static function get_block( string $country_code ): string {
        $code = strtoupper( trim( $country_code ) );
        if ( in_array( $code, self::WESTERN_DEFAULT_COUNTRIES, true ) ) {
            return '';
        }
        $blocks = self::get_blocks();
        if ( isset( $blocks[ $code ] ) ) {
            return "\n\n" . $blocks[ $code ];
        }
        return '';
    }

    /**
     * Return the map of ISO code → prompt-ready context block.
     *
     * Each block is wrapped with 'REGIONAL CONTEXT' header so the AI can
     * recognise it as a tight operational rule (not content material).
     */
    private static function get_blocks(): array {
        return [

            'CN' => "REGIONAL CONTEXT (target country: China / CN — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Chinese audiences: baike.baidu.com (Baidu Baike), zhihu.com, people.com.cn, xinhuanet.com, chinadaily.com.cn, 36kr.com, zh.wikipedia.org. Use metric units (kg, km, °C) and CNY (¥) for prices. Date format: YYYY年MM月DD日 or YYYY/MM/DD. Write proper nouns in Simplified Chinese when the article language is Chinese. Chinese place names may include original characters on first mention when the article is in English (e.g. 'Shanghai (上海)').",

            'JP' => "REGIONAL CONTEXT (target country: Japan / JP — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Japanese audiences: nhk.or.jp (NHK), asahi.com, mainichi.jp, nikkei.com, yomiuri.co.jp, ja.wikipedia.org, kotobank.jp, chiebukuro.yahoo.co.jp (Yahoo! Chiebukuro). Use metric units (kg, km, °C) and JPY (¥) for prices — note Japanese yen has no decimals. Date format: YYYY/MM/DD or YYYY年MM月DD日. Japanese place names may include original characters in parentheses on first mention (e.g. 'Shinjuku (新宿)') when the article is in English. When the article language is Japanese, use 敬語 (keigo) register for reader-facing text.",

            'KR' => "REGIONAL CONTEXT (target country: South Korea / KR — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Korean audiences: ko.wikipedia.org, terms.naver.com (Naver Encyclopedia), kin.naver.com (Naver Knowledge-iN), academic.naver.com, yna.co.kr (Yonhap), chosun.com, donga.com, hani.co.kr, joongang.co.kr. Use metric units and KRW (₩) for prices. Date format: YYYY.MM.DD or YYYY년 MM월 DD일. Korean place names in both Hangul and romanisation on first mention. When the article language is Korean, use 존댓말 (formal register) for reader-facing text.",

            'RU' => "REGIONAL CONTEXT (target country: Russia / RU — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Russian audiences: ru.wikipedia.org, ria.ru (RIA Novosti), tass.ru (TASS), rbc.ru, lenta.ru, yandex.ru, habr.com (tech). Use metric units and RUB (₽) for prices. Date format: DD.MM.YYYY. Russian proper nouns may appear in Cyrillic on first mention (e.g. 'Moscow (Москва)') when the article is in English. When the article language is Russian, use full Cyrillic — do not transliterate.",

            'DE' => "REGIONAL CONTEXT (target country: Germany / DE — Layer 6):\n"
                . "When citing authority sources, prefer domains read by German-speaking audiences: de.wikipedia.org, spiegel.de, faz.net, zeit.de, sueddeutsche.de, welt.de, tagesschau.de. Use metric units and EUR (€) for prices. Date format: DD.MM.YYYY. German uses a decimal comma (e.g. 'EUR 1.234,56') and thousand separator period. When the article language is German, use the formal 'Sie' register for reader-facing text unless the topic genuinely calls for informal 'du' (hobby, youth, lifestyle).",

            'FR' => "REGIONAL CONTEXT (target country: France / FR — Layer 6):\n"
                . "When citing authority sources, prefer domains read by French audiences: fr.wikipedia.org, lemonde.fr, lefigaro.fr, liberation.fr, leparisien.fr. Use metric units and EUR (€) for prices. Date format: DD/MM/YYYY. French uses a decimal comma and thin space thousand separator (e.g. 'EUR 1 234,56'). When the article language is French, use the formal 'vous' register for reader-facing text.",

            'ES' => "REGIONAL CONTEXT (target country: Spain / ES — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Spanish audiences: es.wikipedia.org, elpais.com, elmundo.es. Use metric units and EUR (€) for prices. Date format: DD/MM/YYYY. Spanish uses decimal comma and period thousand separator. Distinguish European Spanish (es-ES) from Latin American Spanish — for Spain-targeted articles use 'vosotros' in informal plural contexts.",

            'IT' => "REGIONAL CONTEXT (target country: Italy / IT — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Italian audiences: it.wikipedia.org, corriere.it (Corriere della Sera), repubblica.it, lastampa.it. Use metric units and EUR (€) for prices. Date format: DD/MM/YYYY. Italian uses decimal comma and period thousand separator. Italian place names stay in Italian (e.g. 'Firenze', not 'Florence') when the article is in Italian.",

            'BR' => "REGIONAL CONTEXT (target country: Brazil / BR — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Brazilian audiences: pt.wikipedia.org, globo.com (G1), folha.uol.com.br, uol.com.br, estadao.com.br. Use metric units and BRL (R\\$) for prices. Date format: DD/MM/YYYY. Portuguese uses decimal comma and period thousand separator. When the article language is Portuguese, use Brazilian Portuguese (pt-BR) spelling and vocabulary — distinct from European Portuguese.",

            'PT' => "REGIONAL CONTEXT (target country: Portugal / PT — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Portuguese audiences: pt.wikipedia.org, publico.pt, expresso.pt. Use metric units and EUR (€) for prices. Date format: DD/MM/YYYY. European Portuguese (pt-PT) spelling differs from Brazilian Portuguese — do not mix. Formal register for reader-facing text.",

            'IN' => "REGIONAL CONTEXT (target country: India / IN — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Indian audiences: en.wikipedia.org (India is a majority-English-reading market online), hi.wikipedia.org for Hindi articles, thehindu.com, indianexpress.com, timesofindia.indiatimes.com, ndtv.com. Use metric units and INR (₹) for prices. Date format: DD/MM/YYYY. Indian numbering uses lakh (100,000) and crore (10,000,000) — feel free to use these alongside full numbers for familiarity.",

            'SA' => "REGIONAL CONTEXT (target country: Saudi Arabia / SA — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Arabic-speaking audiences: ar.wikipedia.org, aljazeera.net, alarabiya.net, bbc.com/arabic. Use metric units and SAR (﷼) for prices. Date format: DD/MM/YYYY (Gregorian) — include Hijri date alongside when culturally relevant. When the article language is Arabic, write right-to-left content in standard Arabic without transliteration.",

            'AE' => "REGIONAL CONTEXT (target country: UAE / AE — Layer 6):\n"
                . "When citing authority sources, prefer domains read by UAE audiences: ar.wikipedia.org, aljazeera.net, alarabiya.net, gulfnews.com, thenationalnews.com. Use metric units and AED (د.إ) for prices. Date format: DD/MM/YYYY. UAE is bilingual Arabic/English — match the article language. When writing about UAE local context, avoid cultural assumptions that apply only to Saudi Arabia or Egypt.",

            'MX' => "REGIONAL CONTEXT (target country: Mexico / MX — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Mexican audiences: es.wikipedia.org, reforma.com, eluniversal.com.mx. Use metric units and MXN (\\$) for prices — note the peso and US dollar share the \\$ symbol, use 'MXN' or '\\$MX' in tables for clarity. Date format: DD/MM/YYYY. Latin American Spanish — use 'ustedes' for plural (not 'vosotros').",

            'AR' => "REGIONAL CONTEXT (target country: Argentina / AR — Layer 6):\n"
                . "When citing authority sources, prefer domains read by Argentine audiences: es.wikipedia.org, clarin.com, lanacion.com.ar, infobae.com. Use metric units and ARS (\\$) for prices — clarify with 'ARS' in tables. Date format: DD/MM/YYYY. Argentine Spanish — 'vos' replaces 'tú' in informal register; verb conjugation changes accordingly (e.g. 'vos tenés' not 'tú tienes'). Use 'ustedes' for plural.",

        ];
    }
}
