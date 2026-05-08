<?php

namespace SEOBetter;

/**
 * AI Content Generator.
 *
 * Generates GEO-optimized articles from a keyword using the user's own AI API key.
 * Implements the article creation protocol (v2026.4):
 * - BLUF header (Key Takeaways, 3 bullets)
 * - 40-60 word citation rule per section
 * - Island Test (no pronoun starts)
 * - Factual density (3+ stats per 1000 words)
 * - Expert quotes (2+ per article)
 * - Inline citations (5+ per article)
 * - Tables for comparisons
 * - JSON-LD schema at the end
 * - Markdown output for token efficiency
 *
 * Pro feature only.
 */
class AI_Content_Generator {

    /**
     * Generate a full GEO-optimized article from a keyword.
     *
     * Works for both free and pro users:
     * - With BYOK (own API key): unlimited, uses their provider
     * - Without key (Cloud): free = 5/month, pro = unlimited
     */
    public function generate( string $keyword, array $options = [] ): array {
        // Check generation allowance
        $gen_check = License_Manager::can_generate();
        if ( ! $gen_check['allowed'] ) {
            return [ 'success' => false, 'error' => $gen_check['message'] ];
        }

        $word_count = $options['word_count'] ?? 2000;
        $tone = $options['tone'] ?? 'authoritative';
        $audience = $options['audience'] ?? 'general';
        $domain = $options['domain'] ?? 'general';
        $primary_keyword = $keyword;
        $secondary_keywords = $options['secondary_keywords'] ?? [];
        $lsi_keywords = $options['lsi_keywords'] ?? [];

        $system_prompt = $this->build_system_prompt();

        // Fetch recent trends to inject fresh data
        $recent_trends = $this->fetch_recent_trends( $primary_keyword );

        // For long articles (1500+), chain multiple requests:
        // 1. Generate outline
        // 2. Generate each section separately
        // 3. Combine into full article
        if ( $word_count >= 1500 ) {
            $content = $this->generate_chained( $primary_keyword, $word_count, $tone, $audience, $domain, $secondary_keywords, $lsi_keywords, $system_prompt, $recent_trends );
        } else {
            $content = $this->generate_single( $primary_keyword, $word_count, $tone, $audience, $domain, $secondary_keywords, $lsi_keywords, $system_prompt, $recent_trends );
        }

        if ( is_array( $content ) && isset( $content['success'] ) && ! $content['success'] ) {
            return $content; // Error from generation
        }

        // Auto-insert stock images
        $image_inserter = new Stock_Image_Inserter();
        $content = $image_inserter->insert_images( $content, $primary_keyword );

        // Post-process: convert Markdown to visually formatted WordPress content
        $editor_mode = $options['editor_mode'] ?? 'auto';
        $formatter = new Content_Formatter();
        $html = $formatter->format( $content, $editor_mode, [
            'accent_color' => $options['accent_color'] ?? '#764ba2',
            // v1.5.216.62.114 — pass content_type so format_hybrid can apply
            // per-type design (faq_page accordion, etc.). Bulk_Generator path
            // already passes this; single-article rest_save_draft uses this
            // class.
            'content_type' => $options['content_type'] ?? 'blog_post',
            'language'     => $options['language'] ?? 'en',
        ] );

        // Run GEO analysis on the generated content
        $analyzer = new GEO_Analyzer();
        $score = $analyzer->analyze( $html, $keyword );

        // Generate 5 headline variations
        $headlines = $this->generate_headlines( $keyword, wp_strip_all_tags( $html ) );

        // Generate meta tags
        $meta = $this->generate_meta_tags( $keyword, wp_strip_all_tags( $html ) );

        return [
            'success'    => true,
            'content'    => $html,
            'markdown'   => $content,
            'keyword'    => $keyword,
            'geo_score'  => $score['geo_score'],
            'grade'      => $score['grade'],
            'word_count' => str_word_count( wp_strip_all_tags( $html ) ),
            'model_used' => 'chained',
            'suggestions' => $score['suggestions'],
            'headlines'  => $headlines,
            'meta'       => $meta,
        ];
    }

    /**
     * Generate 5 headline variations for the article.
     * Based on copywriting skill: power words, numbers, emotional triggers, curiosity gaps.
     *
     * v1.5.206d-fix7 — Accepts $language so non-English articles get headlines
     * written in the article's language, not English templates wrapping a
     * foreign-language keyword. Fixes the "How to Find 서울 최고의 카페 2026:
     * The Ultimate Insider Guide" bug where the Korean keyword was embedded
     * in an English headline.
     *
     * v1.5.206d-fix7.1 — Every language in Localized_Strings::get_language_name()
     * is supported (46 languages, single source of truth shared with
     * Async_Generator::get_system_prompt).
     */
    public function generate_headlines( string $keyword, string $article_text = '', string $language = 'en', string $content_type = 'blog_post' ): array {
        // v1.5.206d-fix7.1 — single source of truth for BCP-47 → human name.
        // Guarantees AI_Content_Generator and Async_Generator see the same
        // language-name table, so all 46 supported languages produce matching
        // prompt text. Previously had a 35-entry table that missed 11 languages
        // (Swahili, Urdu, Sinhala, Nepali, Mongolian, Kazakh, Uzbek, Icelandic,
        // Estonian, Latvian, Lithuanian) — those got "English" as the lang
        // name in the headline prompt.
        $lang_name  = \SEOBetter\Localized_Strings::get_language_name( $language );
        $is_english = $language === 'en';

        // v1.5.212.3 — Translate the user's keyword into the target language
        // before threading it through the prompt + filter + fallbacks. Without
        // this, the rule "every headline MUST contain the exact phrase
        // {$keyword}" forces the AI to embed the English keyword verbatim
        // inside a Japanese/Korean/etc headline, producing slugs like
        // "best-slow-cooker-recipes-for-winter-2026を使って…". Translation
        // happens server-side via Cloud_API::translate_strings_batch (single
        // OpenRouter call). Fail-open: if translation errors, fall back to
        // the original English keyword (pre-fix behaviour, no regression).
        $keyword_for_prompt = $keyword;
        if ( ! $is_english && trim( $keyword ) !== '' ) {
            $translated = \SEOBetter\Cloud_API::translate_strings_batch( [ $keyword ], $language );
            if ( is_array( $translated ) && ! empty( $translated[0] ) && $translated[0] !== $keyword ) {
                $keyword_for_prompt = $translated[0];
            }
        }

        // v1.5.216.62.49 — display-format the keyword (Title Case for
        // English, Sentence Case for other Latin-script, unchanged for
        // non-Latin scripts). See display_keyword() docblock for the
        // language-family handling. User-reported on the RBA news article:
        // an all-lowercase user-typed keyword propagated into the H1
        // post_title ("australian rba interest rate decision may 2026:
        // RBA Held Rates at 4.35%") and image alt text. Title-casing here
        // fixes both since the AI is asked to include the keyword
        // verbatim in headlines, and Stock_Image_Inserter calls the same
        // display_keyword() helper. Mixed-case user input is preserved.
        $keyword_for_prompt = self::display_keyword( $keyword_for_prompt, $language );

        $context = $article_text ? "\n\nArticle summary: " . substr( $article_text, 0, 300 ) : '';

        // v1.5.206d-fix7 — universal language clause. For English articles the
        // clause is empty (byte-identical to pre-fix7). For non-English, it
        // overrides the English example formulas and forbids mixing English
        // with the target language. The AI still gets five varied formulas
        // (number, how-to, question, power-words, current-year) but renders
        // them IN the target language using the canonical translations the
        // system prompt already injected (via canonical_translation_block).
        $lang_clause = '';
        if ( ! $is_english ) {
            $lang_clause = "\n\nLANGUAGE: Write all 5 headlines ENTIRELY in {$lang_name}. Every word except the exact keyword phrase must be in {$lang_name}. Do NOT wrap a {$lang_name} keyword in English connector phrases like \"How to Find X: The Ultimate Guide\" — a {$lang_name} headline uses {$lang_name} connector phrases (e.g. Korean would use '{$lang_name}-appropriate wording' rather than 'How to Find X'). Use the five formulas below but express each formula in {$lang_name}.";
        }

        // v1.5.216.62.48 — REVERT v62.47 per-genre formula table.
        //
        // v62.47 introduced per-genre formulas like
        //   "{$kw} reported (subject + active verb past tense + 5 Ws)"
        // The AI took the parenthetical "(subject + active verb past tense
        // + 5 Ws)" as TEXT to emit, not as a pattern description, and
        // generated the headline:
        //   "Keyword: australian rba interest rate decision may 2026
        //    reported—Who, What, When, Where & Why"
        // (User-reported regression on the RBA-rate retest.)
        //
        // v62.48 fix: keep the proven original 5 formulas (Number / How-to
        // / Question / Power-words / Current-year) which never had the
        // literal-emit problem — they use clear "+" delimiters with token
        // labels (Number/Benefit/etc) the AI consistently reads as a
        // pattern. Add a CONTENT-TYPE GUARDRAIL clause AFTER the formulas
        // that steers genre-mismatched framings away (e.g. for
        // news_article, replace How-to framing with event-report framing).
        // Adds an explicit anti-leak rule banning parenthetical pattern
        // descriptions in the output.
        $genre_guardrail = self::headline_genre_guardrail( $content_type, $keyword_for_prompt );

        $prompt = "Generate exactly 5 headline variations for an article about: \"{$keyword_for_prompt}\"{$context}{$lang_clause}

CRITICAL RULE: Every single headline MUST contain the exact phrase \"{$keyword_for_prompt}\" — no exceptions. If the keyword is multiple words, include ALL words.

Rules:
1. Each headline must be 50-60 characters (for full SERP display)
2. The keyword \"{$keyword_for_prompt}\" must appear in ALL 5 headlines
3. Front-load the keyword (put it in the first half of the headline) in at least 3 of 5
4. Use different headline formulas:
   - #1: Number + \"{$keyword_for_prompt}\" + Benefit
   - #2: How-to + \"{$keyword_for_prompt}\"
   - #3: Question + \"{$keyword_for_prompt}\"
   - #4: \"{$keyword_for_prompt}\" + Power words
   - #5: \"{$keyword_for_prompt}\" + Current year
5. ANTI-LEAK RULE — the actual published headline must NOT contain pattern descriptions, parenthetical hints, or formula labels. Words like \"(subject + active verb)\", \"(5 Ws)\", \"(Who, What, When, Where, Why)\", \"Keyword:\", or any text resembling a template description must NEVER appear in the output. The output is just the headline itself.{$genre_guardrail}

Return ONLY the 5 headlines, numbered 1-5, one per line. No explanations.";

        $system_hint = $is_english
            ? 'You are an expert copywriter who writes headlines that get clicks. Return only the numbered list.'
            : "You are an expert copywriter who writes headlines that get clicks. Write every headline in {$lang_name}, never in English. Return only the numbered list.";

        $result = $this->send_ai_request( $prompt, $system_hint, [ 'max_tokens' => 400 ] );

        if ( ! $result['success'] ) {
            return [];
        }

        $lines = explode( "\n", trim( $result['content'] ) );
        $headlines = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^\d+[\.\)]\s*(.+)$/', $line, $m ) ) {
                $hl = trim( $m[1], '"\'*' );
                // v1.5.212.3 — filter against the post-translation keyword.
                // Pre-fix used the original English keyword which forced a
                // verbatim English match in non-English headlines.
                if ( stripos( $hl, $keyword_for_prompt ) !== false ) {
                    $headlines[] = $hl;
                }
            }
        }

        // If filtering removed too many, add fallback headlines.
        // v1.5.206d-fix7 — for non-English articles the fallbacks are keyword-only
        // (or keyword + current year), since there's no safe way to synthesize a
        // full native-language "Complete Guide" / "Expert Review" phrase without
        // a per-language template table. Keyword-only titles are common in
        // Korean/Japanese/Chinese editorial style anyway.
        // v1.5.212.3 — fallbacks use the translated keyword too.
        if ( count( $headlines ) < 3 ) {
            // v1.5.216.62.72 — per-content-type fallback lists. Pre-fix every
            // type fell to the generic 3 fallbacks including "How to Choose
            // [kw]: Buyer's Guide" — that's a buying-guide framing the AI
            // would emit as Review H1 when its own headlines didn't pass
            // the keyword filter. Each genre now gets fallbacks that match
            // its own framing. English-only; non-English path below
            // unchanged (keyword-only fallbacks stay safe across languages).
            if ( $is_english ) {
                $kw_uc = ucwords( $keyword_for_prompt );
                $year  = wp_date( 'Y' );
                $fallbacks_by_type = [
                    'review'         => [ "{$kw_uc} Review", "Is {$kw_uc} Worth It in {$year}?", "{$kw_uc}: Honest Verdict" ],
                    'opinion'        => [ "Why {$kw_uc} Is Broken", "The Case Against {$kw_uc}", "{$kw_uc}: My Take" ],
                    'news_article'   => [ "{$kw_uc}: What Happened in {$year}", "{$kw_uc}: The Latest", "{$kw_uc} Update" ],
                    'press_release'  => [ "Company Announces {$kw_uc} for {$year}", "{$kw_uc}: New Release", "{$kw_uc} Now Available" ],
                    'personal_essay' => [ "What {$kw_uc} Taught Me", "The Year of {$kw_uc}", "Why {$kw_uc} Still Matters" ],
                    'recipe'         => [ "Easy {$kw_uc} Recipe ({$year})", "How to Make {$kw_uc}", "{$kw_uc}: Quick & Simple" ],
                    'live_blog'      => [ "{$kw_uc}: Live Updates", "{$kw_uc} As It Happened", "{$kw_uc}: Live Blog" ],
                    'interview'      => [ "On {$kw_uc}: A Conversation", "{$kw_uc}: Insights", "{$kw_uc} Explained" ],
                    'comparison'     => [ "{$kw_uc}: Compared", "{$kw_uc}: Which Wins?", "{$kw_uc}: Side-by-Side" ],
                    'buying_guide'   => [ "Best {$kw_uc} ({$year})", "How to Choose {$kw_uc}", "{$kw_uc}: Buyer's Guide" ],
                    'listicle'       => [ "Best {$kw_uc} ({$year})", "Top {$kw_uc} List", "{$kw_uc}: Our Picks" ],
                ];
                $fallbacks = $fallbacks_by_type[ $content_type ] ?? [
                    "{$kw_uc}: Complete Guide for {$year}",
                    "Best {$kw_uc} — Expert Review {$year}",
                    "{$kw_uc} Explained",
                ];
            } else {
                $fallbacks = [
                    $keyword_for_prompt . ' ' . wp_date( 'Y' ),
                    $keyword_for_prompt,
                ];
            }
            foreach ( $fallbacks as $fb ) {
                if ( count( $headlines ) >= 5 ) break;
                $headlines[] = $fb;
            }
        }

        return array_slice( $headlines, 0, 5 );
    }

    /**
     * v1.5.216.62.49 — Format a user-typed keyword for display in
     * headlines, post_title, image alt text, meta tags, and any other
     * surface where the keyword is rendered as VISIBLE text.
     *
     * Rules by language family:
     *   - English (en) → mb_convert_case Title Case
     *     "australian rba interest rate decision may 2026"
     *     → "Australian Rba Interest Rate Decision May 2026"
     *     (Acronym restoration like "Rba" → "RBA" is left to the AI's
     *     body generator which handles it correctly in body content;
     *     headline keyword stays Title Case for grammatical neutrality.)
     *   - Other Latin-script languages (es / fr / de / it / pt / nl /
     *     pl / cs / da / fi / sv / no / hu / tr / ro / id / ms / vi /
     *     etc.) → ucfirst-equivalent Sentence Case (capitalize first
     *     letter only, rest unchanged) — matches title casing
     *     conventions of those languages where most words stay
     *     lowercase except the first word of a sentence and proper
     *     nouns.
     *   - Non-Latin scripts (ja / zh / ko / th / lo / km / hi / bn /
     *     ta / te / kn / ml / gu / pa / si / ar / he / fa / ur / el /
     *     hy / ka / ru / uk / bg / sr / mk / be / kk / mn) →
     *     unchanged. These scripts don't carry case information so
     *     forcing a transformation would either no-op or corrupt
     *     character mapping.
     *
     * Mixed-case user input is preserved — if any letter is already
     * uppercase, the user has signalled deliberate casing (e.g. "RBA
     * interest rate decision") and we don't overwrite it.
     *
     * Empty / whitespace-only input returns unchanged.
     *
     * @param string $kw       Keyword as-typed by the user (or post-
     *                         translation for non-English articles).
     * @param string $language BCP-47 code (en, es, fr, ja, etc.).
     * @return string          Display-formatted keyword.
     */
    public static function display_keyword( string $kw, string $language = 'en' ): string {
        $kw_trimmed = trim( $kw );
        if ( $kw_trimmed === '' ) return $kw;

        // Preserve user-supplied mixed-case input.
        if ( $kw_trimmed !== mb_strtolower( $kw_trimmed, 'UTF-8' ) ) {
            return $kw;
        }

        $base = strtolower( substr( $language ?: 'en', 0, 2 ) );

        $non_latin_scripts = [
            'ja', 'zh', 'ko', 'th', 'lo', 'km', 'my',
            'hi', 'bn', 'ta', 'te', 'kn', 'ml', 'gu', 'pa', 'si',
            'ar', 'he', 'fa', 'ur',
            'el', 'hy', 'ka',
            'ru', 'uk', 'bg', 'sr', 'mk', 'be', 'kk', 'mn',
        ];
        if ( in_array( $base, $non_latin_scripts, true ) ) {
            return $kw;
        }

        if ( $base === 'en' || $base === '' ) {
            return mb_convert_case( $kw, MB_CASE_TITLE, 'UTF-8' );
        }

        // Other Latin-script languages — sentence case.
        $first  = mb_strtoupper( mb_substr( $kw_trimmed, 0, 1, 'UTF-8' ), 'UTF-8' );
        $rest   = mb_substr( $kw_trimmed, 1, null, 'UTF-8' );
        // Preserve any leading/trailing whitespace on the original input.
        $lead   = (string) preg_replace( '/[^\s].*$/su', '', $kw );
        $trail  = (string) preg_replace( '/^.*[^\s]/su', '', $kw );
        return $lead . $first . $rest . $trail;
    }

    /**
     * v1.5.216.62.48 — Per-content-type headline genre guardrail.
     *
     * Appended AFTER the standard 5-formula block in the headline prompt.
     * For genre-override content types (news_article, press_release,
     * opinion, personal_essay, recipe, live_blog, interview) the guardrail
     * tells the AI which of the 5 formulas to AVOID and gives 1-2 concrete
     * example headlines (real strings, not pattern descriptions). For
     * §3.1-default content types the guardrail is empty — the standard
     * formulas already work.
     *
     * Why guardrail-after-formula instead of replacing-the-formula:
     * v1.5.216.62.47 tried per-genre formula tables that contained
     * parenthetical pattern descriptions like "(subject + active verb +
     * 5 Ws)". The AI emitted the descriptions verbatim as headline text,
     * producing headlines like "Keyword: australian rba interest rate
     * decision may 2026 reported—Who, What, When, Where & Why". The
     * guardrail-after approach keeps the proven formulas (Number /
     * How-to / Question / Power-words / Year) which the AI handles
     * cleanly, then overrides genre-mismatched formulas via plain English
     * instruction with concrete example headlines.
     */
    private static function headline_genre_guardrail( string $content_type, string $kw ): string {
        $year = wp_date( 'Y' );
        $guardrails = [
            'news_article' => "\n\nGENRE GUARDRAIL (news_article — this article reports an EVENT, not how-to advice):\n"
                . "  • Skip formula #2 (How-to). Replace with an event-report framing instead — \"{$kw}: [Active verb past tense] [Number/Result]\".\n"
                . "  • Skip formula #3 (Question). Replace with a quote-led news lede — e.g. \"{$kw}: [Source] [verb] [claim]\".\n"
                . "  • Use ACTIVE VERBS in past tense (held, cut, hiked, paused, announced, ruled, said).\n"
                . "  • Concrete example for keyword \"{$kw}\": \"{$kw}: Rate Held at 4.10% in {$year}\". Note: this is a REAL HEADLINE, not a description — emit text like this, not pattern labels.",
            'press_release' => "\n\nGENRE GUARDRAIL (press_release — corporate announcement aiming for media pickup):\n"
                . "  • Use active announce verbs: announces, launches, reports, introduces, reveals.\n"
                . "  • BAN words anywhere in the headline: groundbreaking, disruptor, revolutionary, game-changing, industry-leading, unique, innovative, breaking, exclusive, best-in-class, cutting-edge, world-class, unleash, unveil.\n"
                . "  • Skip formula #3 (Question — journalists ignore Q-headlines).\n"
                . "  • Concrete example for keyword \"{$kw}\": \"Company X Announces {$kw} for {$year}\".",
            'opinion' => "\n\nGENRE GUARDRAIL (opinion / op-ed — provocative thesis, never neutral reporting):\n"
                . "  • Use first-person or claim-led framings: \"Why\", \"The case for/against\", \"Stop doing X\", \"X is broken\".\n"
                . "  • Skip formula #1 (Number) — too neutral; opinion needs a stance.\n"
                . "  • Concrete example for keyword \"{$kw}\": \"Why {$kw} Is Broken\" or \"The Case Against {$kw}\".",
            'personal_essay' => "\n\nGENRE GUARDRAIL (personal_essay — first-person literary, concrete moments):\n"
                . "  • Use concrete-moment framings: \"The day I…\", \"What X taught me…\", \"Why X still matters\".\n"
                . "  • Skip formulas #1 (Number) and #4 (Power words) — both feel listicle, not literary.\n"
                . "  • Concrete example for keyword \"{$kw}\": \"What {$kw} Taught Me\" or \"The Year of {$kw}\".",
            'recipe' => "\n\nGENRE GUARDRAIL (recipe — action verb + dish + qualifier):\n"
                . "  • Use cooking framings: \"How to make…\", \"Easy X recipe\", \"Quick X in N minutes\", \"Best X for [season/diet]\".\n"
                . "  • Skip formula #3 (Question — recipes answer \"how\", not \"why\").\n"
                . "  • Concrete example for keyword \"{$kw}\": \"Easy {$kw} Recipe ({$year})\" or \"How to Make {$kw} in 30 Minutes\".",
            'live_blog' => "\n\nGENRE GUARDRAIL (live_blog — real-time coverage):\n"
                . "  • Use coverage framings: \"X live updates\", \"X: live blog — Month DD\", \"X as it happened\".\n"
                . "  • Skip formulas #2 (How-to) and #3 (Question) — neither fits real-time event coverage.\n"
                . "  • Concrete example for keyword \"{$kw}\": \"{$kw}: Live Updates\" or \"{$kw} As It Happened\".",
            'interview' => "\n\nGENRE GUARDRAIL (interview — name + role + topic):\n"
                . "  • Use subject-led framings: \"[Name] on X\", \"How [Name] thinks about X\", \"[Name] explains X\".\n"
                . "  • Skip formulas #1 (Number), #2 (How-to), #4 (Power words) — interview headlines lead with the subject.\n"
                . "  • Concrete example for keyword \"{$kw}\": \"[Name] on {$kw}\" or \"A Conversation with [Name] about {$kw}\".",
            // v1.5.216.62.72 — review guardrail. Pre-fix Review type had no
            // entry, so it defaulted to the generic 5-formula table including
            // formula #2 "How-to + keyword" → AI emitted "How to Choose:
            // Dyson v15 detect cordless vacuum review Guide" as the H1 on
            // T3 #6 (the "How to Choose" prefix is buying-guide framing,
            // not review framing). Plus the English fallback list at line
            // ~243 included "How to Choose [keyword]: Buyer's Guide" as
            // fallback #3 which the AI happily picked up. New review
            // guardrail bans "How to Choose" + "Buyer's Guide" framings
            // explicitly and steers toward verdict-style headlines.
            'review' => "\n\nGENRE GUARDRAIL (review — verdict on a single product, hands-on tested):\n"
                . "  • Use review framings: \"{$kw} Review\", \"Is {$kw} Worth It?\", \"{$kw}: Honest Review\", \"{$kw} Tested for {$year}\", \"{$kw}: My Verdict\".\n"
                . "  • Skip formula #2 (How-to) — Review is a verdict on a product, not how-to advice.\n"
                . "  • NEVER prefix with \"How to Choose\" or \"How to Pick\" — those are buying-guide framings, not review framings.\n"
                . "  • NEVER suffix with \"Buyer's Guide\" or \"Complete Guide\" — those are buying-guide / pillar framings.\n"
                . "  • Concrete example for keyword \"{$kw}\": \"{$kw} Review\" or \"Is {$kw} Worth It in {$year}?\" or \"{$kw}: Honest Verdict\".",
        ];

        return $guardrails[ $content_type ] ?? '';
    }

    /**
     * Generate SEO meta title + description with CTR scoring.
     * Based on meta-tags-optimizer skill.
     *
     * v1.5.212.3 — Accept $language so non-English meta tags don't ship as
     * pure-English copy. Pre-fix: AIOSEO meta title for a Japanese article
     * came back `Best Slow Cooker Recipes For Winter 2026` because the LLM
     * was given an English keyword and an English-only system prompt with
     * no language clause. Now mirrors generate_headlines: translates the
     * keyword first, threads $lang_name into the prompt + system_hint,
     * and passes meta tags through the same language-guard pipeline as
     * body headings.
     */
    public function generate_meta_tags( string $keyword, string $article_text = '', string $language = 'en' ): array {
        $is_english = ( $language === 'en' || $language === '' );
        $lang_name  = $is_english ? 'English' : \SEOBetter\Localized_Strings::get_language_name( $language );

        // v1.5.212.3 — translate keyword for non-English path so the "exact
        // phrase MUST appear" rule doesn't force English into the meta title.
        $keyword_for_prompt = $keyword;
        if ( ! $is_english && trim( $keyword ) !== '' ) {
            $translated = \SEOBetter\Cloud_API::translate_strings_batch( [ $keyword ], $language );
            if ( is_array( $translated ) && ! empty( $translated[0] ) && $translated[0] !== $keyword ) {
                $keyword_for_prompt = $translated[0];
            }
        }

        // v1.5.216.62.49 — display-format keyword (matches generate_headlines).
        $keyword_for_prompt = self::display_keyword( $keyword_for_prompt, $language );

        $summary = $article_text ? substr( $article_text, 0, 500 ) : '';
        $lang_clause = $is_english ? '' : "\n\nLANGUAGE: Write TITLE, DESCRIPTION, and OG_TITLE entirely in {$lang_name}. Every word except proper nouns must be in {$lang_name}. Do not ship English copy.";

        $prompt = "Generate SEO meta tags for an article about: \"{$keyword_for_prompt}\"{$lang_clause}

Article summary: {$summary}

Return in this exact format:
TITLE: [50-60 chars, keyword front-loaded, power word included]
DESCRIPTION: [150-160 chars, MUST include the exact phrase \"{$keyword_for_prompt}\", has a call-to-action, reads like an ad]
OG_TITLE: [60-90 chars, slightly more compelling than TITLE, can be longer]

Rules:
- Title MUST be 50-60 characters and contain \"{$keyword_for_prompt}\"
- Description MUST be 150-160 characters and MUST contain the exact phrase \"{$keyword_for_prompt}\"
- Front-load the keyword \"{$keyword_for_prompt}\" in the title (first half)
- Include a number or year if relevant
- Description should create urgency or curiosity
- No clickbait, must be accurate to content
- CRITICAL: The exact phrase \"{$keyword_for_prompt}\" must appear in both TITLE and DESCRIPTION";

        $system_hint = $is_english
            ? 'You are an SEO meta tag specialist. Return only the requested format.'
            : "You are an SEO meta tag specialist. Write all output (TITLE / DESCRIPTION / OG_TITLE) in {$lang_name}, never in English. Return only the requested format.";

        $result = $this->send_ai_request( $prompt, $system_hint, [ 'max_tokens' => 300 ] );

        if ( ! $result['success'] ) {
            return [ 'title' => '', 'description' => '', 'og_title' => '' ];
        }

        $meta = [ 'title' => '', 'description' => '', 'og_title' => '' ];
        $lines = explode( "\n", $result['content'] );
        foreach ( $lines as $line ) {
            if ( preg_match( '/^TITLE:\s*(.+)$/i', trim( $line ), $m ) ) {
                $meta['title'] = trim( $m[1] );
            } elseif ( preg_match( '/^DESCRIPTION:\s*(.+)$/i', trim( $line ), $m ) ) {
                $meta['description'] = trim( $m[1] );
            } elseif ( preg_match( '/^OG_TITLE:\s*(.+)$/i', trim( $line ), $m ) ) {
                $meta['og_title'] = trim( $m[1] );
            }
        }

        // v1.5.216.62.68 — defensive meta-title sanitization. The AI was
        // told "50-60 chars" but routinely overshoots to 63-70 chars, and
        // sometimes emits an unencoded `&` (ampersand) inside the title.
        // Google's SERP truncates titles >60 chars at the boundary, and when
        // the truncation lands mid-character on an `&`, the displayed title
        // ends with literal "&…" — user-reported on T3 #3 Opinion as
        // "Why AI content moderation is broken: 2026 insights &…". Fix:
        // replace `& ` → ` and `, decode any entities, trim to last
        // word boundary if still over limit.
        $meta['title']    = self::sanitize_meta_title( $meta['title'], 60 );
        $meta['og_title'] = self::sanitize_meta_title( $meta['og_title'], 90 );

        // CTR scoring
        $meta['title_length'] = mb_strlen( $meta['title'] );
        $meta['desc_length'] = mb_strlen( $meta['description'] );
        $meta['title_score'] = $this->score_meta_title( $meta['title'], $keyword_for_prompt );
        $meta['desc_score'] = $this->score_meta_description( $meta['description'], $keyword_for_prompt );

        return $meta;
    }

    /**
     * v1.5.216.62.68 — sanitize a meta title to avoid mid-character SERP
     * truncation. Replaces standalone `&` with " and " (so neither side
     * leaves a dangling ampersand), decodes any HTML entities the AI may
     * have emitted, collapses whitespace, then trims to the last word
     * boundary if the result still exceeds $max_chars. Returns "" for
     * empty input. Default max for TITLE is 60 (Google SERP cutoff);
     * pass 90 for OG_TITLE per AI prompt §10 of generate_meta_tags.
     */
    private static function sanitize_meta_title( string $title, int $max_chars = 60 ): string {
        if ( trim( $title ) === '' ) return $title;
        // Decode entities first so we're working with literal characters
        $title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
        // Replace standalone & (with surrounding whitespace) with "and"
        $title = preg_replace( '/\s+&\s+/', ' and ', $title );
        // Collapse any remaining whitespace runs
        $title = preg_replace( '/\s+/', ' ', trim( $title ) );
        if ( mb_strlen( $title ) <= $max_chars ) {
            return $title;
        }
        // Over budget — trim at the last word boundary within $max_chars
        $cut = mb_substr( $title, 0, $max_chars );
        if ( preg_match( '/^(.+)\s+\S+$/u', $cut, $m ) ) {
            $cut = $m[1];
        }
        // Strip trailing punctuation that would look dangling after truncation
        return rtrim( $cut, " ,;:-—–" );
    }

    /**
     * Generate topic suggestions for a niche.
     * Based on content-strategy + content-gap-analysis skills.
     */
    public function suggest_topics( string $niche, string $audience = '', int $count = 10 ): array {
        $audience_ctx = $audience ? "\nTarget audience: {$audience}" : '';
        $prompt = "Suggest {$count} high-value article topics for the niche: \"{$niche}\"{$audience_ctx}

For each topic provide:
- TOPIC: [article title/keyword]
- INTENT: [informational/commercial/transactional]
- DIFFICULTY: [low/medium/high]
- WHY: [1 sentence on why this topic will drive traffic]

Mix of:
- 4 informational (how-to, guides, explanations)
- 3 commercial (comparisons, reviews, best-of lists)
- 2 transactional (buying guides, product roundups)
- 1 trending/timely topic

Prioritize topics where:
- Search volume is likely high but competition may be low
- AI models (ChatGPT, Perplexity, Google AI Overviews) would cite a well-written article
- The topic can include comparison tables, statistics, and expert quotes (GEO signals)

Return exactly {$count} topics in the format above.";

        $result = $this->send_ai_request( $prompt, 'You are an SEO content strategist specializing in topic research and GEO optimization.', [ 'max_tokens' => 2000 ] );

        if ( ! $result['success'] ) {
            return $result;
        }

        // Parse topics
        $topics = [];
        $current = [];
        foreach ( explode( "\n", $result['content'] ) as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^TOPIC:\s*(.+)$/i', $line, $m ) ) {
                if ( ! empty( $current ) ) $topics[] = $current;
                $current = [ 'topic' => trim( $m[1], '"*' ) ];
            } elseif ( preg_match( '/^INTENT:\s*(.+)$/i', $line, $m ) ) {
                $current['intent'] = trim( $m[1] );
            } elseif ( preg_match( '/^DIFFICULTY:\s*(.+)$/i', $line, $m ) ) {
                $current['difficulty'] = trim( $m[1] );
            } elseif ( preg_match( '/^WHY:\s*(.+)$/i', $line, $m ) ) {
                $current['why'] = trim( $m[1] );
            }
        }
        if ( ! empty( $current ) ) $topics[] = $current;

        return [ 'success' => true, 'topics' => $topics, 'raw' => $result['content'] ];
    }

    /**
     * Generate social media content from an article.
     * Based on social-content skill.
     */
    public function generate_social_content( string $article_text, string $keyword, string $url = '' ): array {
        $summary = substr( wp_strip_all_tags( $article_text ), 0, 1500 );
        $url_line = $url ? "\nArticle URL: {$url}" : '';

        $prompt = "Create social media content from this article about \"{$keyword}\".{$url_line}

Article content:
{$summary}

Generate ALL THREE formats:

=== TWITTER THREAD ===
Write a 5-tweet thread. Tweet 1 is the hook (must stop the scroll). Tweets 2-4 are key insights with stats/quotes from the article. Tweet 5 is the CTA with link.
- Each tweet max 280 chars
- Use line breaks for readability
- Include 1-2 relevant hashtags on tweet 1 and 5 only

=== LINKEDIN POST ===
Write a LinkedIn post (150-300 words).
- Hook line (first line visible before \"see more\")
- 3-4 key insights as short paragraphs
- End with a question to drive comments
- Add 3-5 relevant hashtags at the end

=== INSTAGRAM CAPTION ===
Write an Instagram caption.
- Hook line
- 3 key takeaways with emoji bullets
- CTA (save this post, share with someone who needs this)
- 20-30 relevant hashtags on a separate line

Use the article's statistics, expert quotes, and key facts. Make each piece standalone — someone should get value without clicking the link.";

        $result = $this->send_ai_request( $prompt, 'You are a social media content expert who creates viral, engagement-driving posts from articles. Write in a punchy, direct style.', [ 'max_tokens' => 3000 ] );

        if ( ! $result['success'] ) {
            return $result;
        }

        // Parse the three formats
        $content = $result['content'];
        $social = [ 'twitter' => '', 'linkedin' => '', 'instagram' => '' ];

        if ( preg_match( '/TWITTER\s*THREAD\s*===?\s*\n(.*?)(?====\s*LINKEDIN|$)/is', $content, $m ) ) {
            $social['twitter'] = trim( $m[1] );
        }
        if ( preg_match( '/LINKEDIN\s*POST\s*===?\s*\n(.*?)(?====\s*INSTAGRAM|$)/is', $content, $m ) ) {
            $social['linkedin'] = trim( $m[1] );
        }
        if ( preg_match( '/INSTAGRAM\s*CAPTION\s*===?\s*\n(.*?)$/is', $content, $m ) ) {
            $social['instagram'] = trim( $m[1] );
        }

        // Fallback if parsing failed
        if ( ! $social['twitter'] && ! $social['linkedin'] ) {
            $social['raw'] = $content;
        }

        return [ 'success' => true, 'social' => $social ];
    }

    /**
     * Fetch recent trends for a keyword to inject fresh data into articles.
     * Based on last30days skill.
     */
    /**
     * Fetch recent trends for a keyword.
     * Uses Last30Days real web research if available, otherwise AI fallback.
     */
    public function fetch_recent_trends( string $keyword ): string {
        $research = Trend_Researcher::research( $keyword );
        return $research['for_prompt'] ?? '';
    }

    private function score_meta_title( string $title, string $keyword ): int {
        $score = 0;
        $len = mb_strlen( $title );
        if ( $len >= 50 && $len <= 60 ) $score += 30; elseif ( $len >= 40 && $len <= 70 ) $score += 15;
        if ( stripos( $title, $keyword ) !== false ) $score += 30;
        if ( stripos( $title, $keyword ) === 0 || stripos( $title, $keyword ) <= 5 ) $score += 10; // front-loaded
        if ( preg_match( '/\d/', $title ) ) $score += 10; // has number
        if ( preg_match( '/20[2-3]\d/', $title ) ) $score += 10; // has year
        $power_words = [ 'best', 'ultimate', 'complete', 'essential', 'proven', 'expert', 'top', 'guide', 'review' ];
        foreach ( $power_words as $pw ) { if ( stripos( $title, $pw ) !== false ) { $score += 10; break; } }
        return min( 100, $score );
    }

    private function score_meta_description( string $desc, string $keyword ): int {
        $score = 0;
        $len = mb_strlen( $desc );
        if ( $len >= 150 && $len <= 160 ) $score += 30; elseif ( $len >= 120 && $len <= 170 ) $score += 15;
        if ( stripos( $desc, $keyword ) !== false ) $score += 25;
        if ( preg_match( '/\d/', $desc ) ) $score += 10;
        $cta_words = [ 'learn', 'discover', 'find out', 'get', 'check', 'see', 'read', 'explore', 'compare' ];
        foreach ( $cta_words as $cta ) { if ( stripos( $desc, $cta ) !== false ) { $score += 15; break; } }
        if ( preg_match( '/[.!?]$/', trim( $desc ) ) ) $score += 10; // proper ending
        if ( preg_match( '/\b(free|save|best|top|expert|proven)\b/i', $desc ) ) $score += 10;
        return min( 100, $score );
    }

    /**
     * Single-request generation for short articles.
     */
    private function generate_single( string $keyword, int $word_count, string $tone, string $audience, string $domain, array $secondary, array $lsi, string $system, string $trends = '' ): string|array {
        $prompt = $this->build_user_prompt( $keyword, $word_count, $tone, $audience, $domain, $secondary, $lsi );
        if ( $trends ) {
            $prompt .= "\n\nRECENT DATA TO INCLUDE (integrate naturally as statistics/citations):\n{$trends}";
        }
        $result = $this->send_ai_request( $prompt, $system, [ 'max_tokens' => 4096 ] );

        if ( ! $result['success'] ) {
            return $result;
        }
        return $result['content'];
    }

    /**
     * Chained multi-request generation for long articles (1500+ words).
     * Step 1: Generate a detailed outline with section headings
     * Step 2: Generate each section individually (~400-600 words each)
     * Step 3: Combine into a full article
     */
    private function generate_chained( string $keyword, int $word_count, string $tone, string $audience, string $domain, array $secondary, array $lsi, string $system, string $trends = '' ): string|array {
        $date = wp_date( 'F Y' );
        $kw_context = '';
        if ( ! empty( $secondary ) ) {
            $kw_context .= "\nSecondary keywords: " . implode( ', ', $secondary );
        }
        if ( ! empty( $lsi ) ) {
            $kw_context .= "\nLSI keywords: " . implode( ', ', $lsi );
        }

        $num_sections = max( 4, round( $word_count / 400 ) );
        $words_per_section = round( $word_count / $num_sections );

        // Step 1: Generate outline
        $outline_prompt = "Create an article outline for: \"{$keyword}\"\n{$kw_context}\n\nRequirements:\n- {$num_sections} H2 sections with question-format headings where possible\n- Include a Key Takeaways section at the start\n- Include a FAQ section (3-5 questions) near the end\n- Include a References section at the end\n- Target audience: {$audience}\n- Domain: {$domain}\n- Tone: {$tone}\n\nReturn ONLY the outline as a numbered list of H2 headings, one per line. Example:\n1. Key Takeaways\n2. What Are the Best [Topic]?\n3. How Does [Topic] Compare?\n...\nN. Frequently Asked Questions\nN+1. References";

        $outline_result = $this->send_ai_request( $outline_prompt, 'You are an SEO content strategist. Return only the numbered list of headings.', [ 'max_tokens' => 500 ] );

        if ( ! $outline_result['success'] ) {
            return $outline_result;
        }

        // Parse outline into section headings
        $headings = [];
        $lines = explode( "\n", trim( $outline_result['content'] ) );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^\d+[\.\)]\s*(.+)$/', $line, $m ) ) {
                $headings[] = trim( $m[1] );
            }
        }

        if ( count( $headings ) < 3 ) {
            // Fallback: generate as single request
            return $this->generate_single( $keyword, $word_count, $tone, $audience, $domain, $secondary, $lsi, $system );
        }

        // Step 2: Generate each section
        $full_article = "Last Updated: {$date}\n\n# {$keyword}\n\n";

        foreach ( $headings as $i => $heading ) {
            $is_first = ( $i === 0 );
            $is_takeaways = preg_match( '/key\s*takeaway/i', $heading );
            $is_faq = preg_match( '/faq|frequently\s*asked/i', $heading );
            $is_references = preg_match( '/reference/i', $heading );

            if ( $is_takeaways ) {
                $section_prompt = "Write the Key Takeaways section for an article about \"{$keyword}\".\n\nReturn exactly:\n## Key Takeaways\n- [Takeaway 1]\n- [Takeaway 2]\n- [Takeaway 3]\n\nEach takeaway should be 15-25 words summarizing a core insight. Be specific with numbers/facts.";
                $max = 300;
            } elseif ( $is_faq ) {
                $section_prompt = "Write an FAQ section for an article about \"{$keyword}\".\n{$kw_context}\n\nReturn 3-5 question-answer pairs. Each answer should be 40-60 words (optimized for featured snippets and People Also Ask). Format:\n\n## Frequently Asked Questions\n\n### [Question]?\n[Answer paragraph]\n\nNever start answers with pronouns (Island Test).";
                $max = 1500;
            } elseif ( $is_references ) {
                $section_prompt = "Write a References section for an article about \"{$keyword}\". Include 5-8 realistic references with source names and years. Format as a numbered Markdown list.";
                $max = 500;
            } else {
                $trends_inject = ( $trends && $i <= 3 ) ? "\n\nRECENT DATA TO INCLUDE (use 1-2 of these naturally):\n{$trends}" : '';
                $section_prompt = "Write section {$i} of an article about \"{$keyword}\".\n{$kw_context}\n\nSection heading: \"{$heading}\"\nTarget: {$words_per_section} words\nTone: {$tone}\nAudience: {$audience}{$trends_inject}\n\nRULES:\n- Start with ## {$heading}\n- First paragraph MUST be 40-60 words and directly answer the heading\n- Include 1-2 statistics with (Source, Year) attribution\n- Include 1 expert quote if relevant\n- Include inline citations in [Source, Year] format\n- Include a comparison table if this section involves comparing items\n- NEVER start paragraphs with pronouns (It, This, They)\n- Use **Bold** for key entities\n- Use bullet/numbered lists where appropriate\n\nOutput pure Markdown for this section only.";
                $max = 2000;
            }

            $section_result = $this->send_ai_request( $section_prompt, $system, [ 'max_tokens' => $max, 'temperature' => 0.7 ] );

            if ( $section_result['success'] ) {
                $full_article .= trim( $section_result['content'] ) . "\n\n";
            }
        }

        return $full_article;
    }

    /**
     * Send a request to either BYOK provider or Cloud.
     */
    private function send_ai_request( string $prompt, string $system, array $options = [] ): array {
        $provider = AI_Provider_Manager::get_active_provider();
        if ( $provider ) {
            return AI_Provider_Manager::send_request( $provider['provider_id'], $prompt, $system, $options );
        }
        return Cloud_API::generate( $prompt, $system, $options );
    }

    /**
     * Generate an article outline before full generation.
     */
    public function generate_outline( string $keyword, array $options = [] ): array {
        $gen_check = License_Manager::can_generate();
        if ( ! $gen_check['allowed'] ) {
            return [ 'success' => false, 'error' => $gen_check['message'] ];
        }

        $provider = AI_Provider_Manager::get_active_provider();

        $prompt = "Create a detailed SEO article outline for the keyword: \"{$keyword}\"

Return a structured outline with:
1. A compelling title (50-60 chars, keyword front-loaded)
2. Key Takeaways section (3 bullet points)
3. 5-8 H2 sections, each with:
   - Question-format heading where possible (for featured snippets + PAA)
   - 2-3 sub-points to cover
   - Suggested data/statistics to include
4. Suggest 1 comparison table topic
5. Suggest 2 expert quotes to seek
6. Suggest FAQ questions (3-5)

Format as clean Markdown.";

        $system = 'You are an expert SEO content strategist specializing in GEO (Generative Engine Optimization).';
        $request_options = [ 'max_tokens' => 2048, 'temperature' => 0.7 ];

        if ( $provider ) {
            $result = AI_Provider_Manager::send_request( $provider['provider_id'], $prompt, $system, $request_options );
        } else {
            $result = Cloud_API::generate( $prompt, $system, $request_options );
        }

        return $result;
    }

    /**
     * Enhance existing content with GEO optimization.
     */
    public function enhance_content( string $content, array $methods = [] ): array {
        if ( ! License_Manager::can_use( 'geo_optimizer' ) ) {
            return [ 'success' => false, 'error' => 'Content enhancement requires SEOBetter Pro.' ];
        }

        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        if ( empty( $methods ) ) {
            $optimizer = new GEO_Optimizer();
            $domain = $optimizer->detect_domain( $content );
            $methods = $optimizer->get_domain_strategy( $domain );
        }

        $method_instructions = $this->build_enhancement_instructions( $methods );

        $prompt = "Enhance this article content using these specific GEO optimization methods:\n\n{$method_instructions}\n\nOriginal content:\n\n{$content}\n\nReturn the enhanced content in Markdown format. Preserve the original structure but apply the requested enhancements.";

        $system = "You are an expert content optimizer specializing in Generative Engine Optimization (GEO). Your changes should make content more likely to be cited by AI models (Google AI Overviews, Perplexity, ChatGPT, Gemini, Claude). Never use keyword stuffing — research proves it HURTS visibility by 8%.";

        $result = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            $system,
            [ 'max_tokens' => 8192, 'temperature' => 0.5 ]
        );

        if ( ! $result['success'] ) {
            return $result;
        }

        $html = $this->markdown_to_html( $result['content'] );

        return [
            'success'   => true,
            'content'   => $html,
            'markdown'  => $result['content'],
            'methods'   => $methods,
        ];
    }

    /**
     * Build the system prompt implementing the article protocol v2026.4.
     */
    private function build_system_prompt(): string {
        return <<<'PROMPT'
You are an expert SEO content writer. Follow these rules exactly:

## READABILITY (MOST IMPORTANT)
- Write at a 6th-8th GRADE reading level
- Use SHORT sentences (under 20 words each)
- Use SIMPLE, everyday words (use "buy" not "purchase", "help" not "facilitate")
- A smart 12-year-old should understand every sentence
- No academic jargon, no complex vocabulary

## WORD COUNT
- Always write the FULL number of words requested
- If asked for 400 words per section, write at least 400
- Being too short is a failure — write more, not less

## STRUCTURE
- Start with ## Key Takeaways (3 bullet points)
- Every H2/H3 section starts with a 40-60 word paragraph answering the heading
- NEVER start paragraphs with pronouns (It, This, They, These, Those)

## EVIDENCE
- 3+ statistics per 1,000 words with (Source Name, Year)
- 2+ expert quotes: "Quote" — Dr. Name, Title (Source, Year)
- 5+ inline citations in [Source, Year] format
- At least 1 comparison table in Markdown

## FORMAT
- GitHub Flavored Markdown
- **Bold** for key terms
- Tables for comparisons
- Bullet/numbered lists
- "Last Updated: [Month Year]" at top
- FAQ section with 3-5 Q&A pairs
- References section at end

## BLOCKED (hurts visibility)
- Keyword stuffing (-8%)
- Starting paragraphs with pronouns
- Complex academic language
PROMPT;
    }

    /**
     * Build the user prompt for article generation.
     */
    private function build_user_prompt( string $keyword, int $word_count, string $tone, string $audience, string $domain, array $secondary_keywords = [], array $lsi_keywords = [] ): string {
        $date = wp_date( 'F Y' );

        $prompt = "Write a comprehensive, GEO-optimized article for the primary keyword: \"{$keyword}\"";

        if ( ! empty( $secondary_keywords ) ) {
            $prompt .= "\n\nSecondary keywords to naturally incorporate throughout the article:\n- " . implode( "\n- ", $secondary_keywords );
        }

        if ( ! empty( $lsi_keywords ) ) {
            $prompt .= "\n\nLSI/semantic keywords to weave in naturally (do NOT stuff):\n- " . implode( "\n- ", $lsi_keywords );
        }

        $prompt .= "

Requirements:
- Target length: {$word_count} words
- Tone: {$tone}
- Target audience: {$audience}
- Content domain: {$domain}
- Current date for freshness signal: {$date}

Follow ALL rules from the Article Protocol v2026.4 in your system instructions. The article must score 80+ on a GEO analysis that checks:
- BLUF header presence (Key Takeaways with 3 bullets at the top)
- 40-60 word section openings after every H2/H3
- Island Test (never start paragraphs with It, This, They, etc.)
- 3+ verifiable statistics per 1000 words with (Source, Year) attribution
- 2+ direct expert quotes with credentials
- 5+ inline citations in [Source, Year] format
- At least 1 comparison table
- \"Last Updated: {$date}\" freshness signal at the top
- Question-format H2/H3 headings (for featured snippets + People Also Ask)
- FAQ section with 3-5 Q&A pairs near the end
- References section at the bottom with linked sources

Output pure GitHub Flavored Markdown.";

        return $prompt;
    }

    /**
     * Build enhancement instructions for specific GEO methods.
     */
    private function build_enhancement_instructions( array $methods ): string {
        $instructions = [];

        $method_map = [
            'statistics'  => 'STATISTICS ADDITION (+30% visibility): Add verifiable statistics with source attribution wherever claims are made. Use format: (Source Name, Year). Target: 3+ per 1000 words.',
            'quotations'  => 'QUOTATION ADDITION (+41% visibility): Add 2+ direct quotes from credentialed experts. Include their title and organization. This provides the HIGHEST visibility boost.',
            'citations'   => 'CITE SOURCES (+28% visibility): Add inline citations in [Source, Year] format throughout. Target: 5+ per article. Add a References section at the end.',
            'fluency'     => 'FLUENCY OPTIMIZATION (+27% visibility): Improve sentence flow, reduce awkward phrasing, ensure smooth transitions between ideas.',
            'authoritative' => 'AUTHORITATIVE TONE (+10% visibility): Make the writing more persuasive and confident. Use active voice, decisive language.',
            'technical_terms' => 'TECHNICAL TERMS (+18% visibility): Add precise industry terminology where appropriate.',
            'easy_to_understand' => 'SIMPLIFY LANGUAGE (+14% visibility): Reduce reading level to grade 6-8. Use shorter sentences and simpler words.',
        ];

        foreach ( $methods as $method ) {
            if ( isset( $method_map[ $method ] ) ) {
                $instructions[] = $method_map[ $method ];
            }
        }

        return implode( "\n\n", $instructions );
    }

    /**
     * Convert Markdown to HTML (basic conversion for WordPress).
     */
    private function markdown_to_html( string $markdown ): string {
        // Headers
        $html = preg_replace( '/^######\s+(.+)$/m', '<h6>$1</h6>', $markdown );
        $html = preg_replace( '/^#####\s+(.+)$/m', '<h5>$1</h5>', $html );
        $html = preg_replace( '/^####\s+(.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', $html );
        $html = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', $html );
        $html = preg_replace( '/^#\s+(.+)$/m', '<h1>$1</h1>', $html );

        // Bold and italic
        $html = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $html );
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        $html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

        // Links — negative lookbehind for `!` so `![alt](url)` image markdown is not rewritten
        $html = preg_replace( '/(?<!!)\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html );

        // Unordered lists
        $html = preg_replace_callback( '/(?:^- .+\n?)+/m', function ( $matches ) {
            $items = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $matches[0] );
            return '<ul>' . $items . '</ul>';
        }, $html );

        // Ordered lists
        $html = preg_replace_callback( '/(?:^\d+\. .+\n?)+/m', function ( $matches ) {
            $items = preg_replace( '/^\d+\. (.+)$/m', '<li>$1</li>', $matches[0] );
            return '<ol>' . $items . '</ol>';
        }, $html );

        // Blockquotes
        $html = preg_replace( '/^>\s*(.+)$/m', '<blockquote>$1</blockquote>', $html );

        // Simple table conversion
        $html = preg_replace_callback( '/\|(.+)\|\n\|[-| ]+\|\n((?:\|.+\|\n?)+)/m', function ( $matches ) {
            $headers = explode( '|', trim( $matches[1], '| ' ) );
            $rows = explode( "\n", trim( $matches[2] ) );

            $table = '<table><thead><tr>';
            foreach ( $headers as $h ) {
                $table .= '<th>' . trim( $h ) . '</th>';
            }
            $table .= '</tr></thead><tbody>';

            foreach ( $rows as $row ) {
                if ( empty( trim( $row ) ) ) continue;
                $cells = explode( '|', trim( $row, '| ' ) );
                $table .= '<tr>';
                foreach ( $cells as $cell ) {
                    $table .= '<td>' . trim( $cell ) . '</td>';
                }
                $table .= '</tr>';
            }
            $table .= '</tbody></table>';
            return $table;
        }, $html );

        // Paragraphs: wrap non-tagged lines
        $lines = explode( "\n", $html );
        $result = [];
        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( empty( $trimmed ) ) {
                $result[] = '';
                continue;
            }
            if ( preg_match( '/^<(h[1-6]|ul|ol|li|table|thead|tbody|tr|th|td|blockquote|hr|p)/', $trimmed ) ) {
                $result[] = $trimmed;
            } else {
                $result[] = '<p>' . $trimmed . '</p>';
            }
        }

        return implode( "\n", array_filter( $result ) );
    }
}
