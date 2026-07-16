<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * All of the regular expressions in use within readability.
 *
 * Mirrors the REGEXPS object of Readability.js v0.6.0. Constant names are the
 * UPPER_SNAKE form of the JS keys; patterns are direct PCRE translations.
 */
final class RegExps
{
    /**
     * Character class matching what JavaScript considers whitespace (\s and
     * String.prototype.trim): it includes NBSP and other Unicode space
     * separators, which PCRE's \s does not.
     */
    public const string JS_WS = '\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Equivalent of JavaScript's String.prototype.trim, for use with preg_replace. */
    public const string TRIM = '/^[' . self::JS_WS . ']+|[' . self::JS_WS . ']+$/u';

    /**
     * NOTE: In Readability.js these two regular expressions are duplicated in
     * Readability-readerable.js. Here both Readability and Readerable read them
     * from this class, so there is a single copy to keep in sync with upstream.
     */
    public const string UNLIKELY_CANDIDATES = '/-ad-|ai2html|banner|breadcrumbs|combx|comment|community|cover-wrap|disqus|extra|footer|gdpr|header|legends|menu|related|remark|replies|rss|shoutbox|sidebar|skyscraper|social|sponsor|supplemental|ad-break|agegate|pagination|pager|popup|yom-remote/i';
    public const string OK_MAYBE_ITS_A_CANDIDATE = '/and|article|body|column|content|main|mathjax|shadow/i';

    public const string POSITIVE = '/article|body|content|entry|hentry|h-entry|main|page|pagination|post|text|blog|story/i';
    public const string NEGATIVE = '/-ad-|hidden|^hid$| hid$| hid |^hid |banner|combx|comment|com-|contact|footer|gdpr|masthead|media|meta|outbrain|promo|related|scroll|share|shoutbox|sidebar|skyscraper|sponsor|shopping|tags|widget/i';
    public const string EXTRANEOUS = '/print|archive|comment|discuss|e[\-]?mail|share|reply|all|login|sign|single|utility/i';
    public const string BYLINE = '/byline|author|dateline|writtenby|p-author/i';
    public const string REPLACE_FONTS = '/<(\/?)font[^>]*>/i';
    public const string NORMALIZE = '/[' . self::JS_WS . ']{2,}/u';
    public const string VIDEOS = '/\/\/(www\.)?((dailymotion|youtube|youtube-nocookie|player\.vimeo|v\.qq|bilibili|live.bilibili)\.com|(archive|upload\.wikimedia)\.org|player\.twitch\.tv)/i';
    public const string SHARE_ELEMENTS = '/(\b|_)(share|sharedaddy)(\b|_)/i';
    public const string NEXT_LINK = '/(next|weiter|continue|>([^\|]|$)|»([^\|]|$))/iu';
    public const string PREV_LINK = '/(prev|earl|old|new|<|«)/iu';
    public const string TOKENIZE = '/\W+/';
    public const string WHITESPACE = '/^[' . self::JS_WS . ']*$/u';
    public const string HAS_CONTENT = '/[^' . self::JS_WS . ']$/u';
    public const string HASH_URL = '/^#.+/';
    public const string SRCSET_URL = '/(\S+)(\s+[\d.]+[xw])?(\s*(?:,|$))/';
    public const string B64_DATA_URL = '/^data:\s*([^\s;,]+)\s*;\s*base64\s*,/i';

    /**
     * Commas as used in Latin, Sindhi, Chinese and various other scripts.
     * see: https://en.wikipedia.org/wiki/Comma#Comma_variants
     */
    public const string COMMAS = '/\x{002C}|\x{060C}|\x{FE50}|\x{FE10}|\x{FE11}|\x{2E41}|\x{2E34}|\x{2E32}|\x{FF0C}/u';

    /** See: https://schema.org/Article */
    public const string JSON_LD_ARTICLE_TYPES = '/^Article|AdvertiserContentArticle|NewsArticle|AnalysisNewsArticle|AskPublicNewsArticle|BackgroundNewsArticle|OpinionNewsArticle|ReportageNewsArticle|ReviewNewsArticle|Report|SatiricalArticle|ScholarlyArticle|MedicalScholarlyArticle|SocialMediaPosting|BlogPosting|LiveBlogPosting|DiscussionForumPosting|TechArticle|APIReference$/';

    /** Used to see if a node's content matches words commonly used for ad blocks or loading indicators. */
    public const string AD_WORDS = '/^(ad(vertising|vertisement)?|pub(licité)?|werb(ung)?|广告|Реклама|Anuncio)$/iu';
    public const string LOADING_WORDS = '/^((loading|正在加载|Загрузка|chargement|cargando)(…|\.\.\.)?)$/iu';
}
