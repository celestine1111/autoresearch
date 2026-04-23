/**
 * SEOBetter — BM25 + multilingual tokenizer utility (v1.5.208)
 *
 * Shared helper used by content-brief.js (and any future endpoint) to:
 *   1. tokenize text across the 29 plugin-supported languages
 *   2. compute Okapi BM25 scores over a small corpus (top-10 SERP bodies)
 *   3. rank terms by "distinctiveness across the corpus"
 *
 * Why BM25 over plain TF-IDF? BM25 adds saturation (10 mentions isn't 10× 1)
 * and document-length normalization — it's what modern search + hybrid RAG
 * pipelines actually use. Parameters k1=1.5, b=0.75 are industry defaults.
 *
 * This file is pure JS — no npm deps. Stopword lists are inlined (per-language
 * minimal sets; ~30-80 terms each for the 29 plugin-supported languages).
 * A larger list could come from `stopwords-iso` later, but this keeps cold-
 * start latency on Vercel near zero.
 *
 * Cross-reference:
 *   - SEO-GEO-AI-GUIDELINES.md §28.1 — implements "Topic Selection via
 *     Competitor Analysis" which was documented-but-unimplemented until now.
 *   - SEO-GEO-AI-GUIDELINES.md §1 — the -9% keyword-stuffing rule is
 *     respected: we return CONCEPT coverage (top terms by BM25) not a
 *     stuffing density target.
 */

// ---------------------------------------------------------------------------
// 1. Stopwords — minimal per-language lists for the 29 supported locales
// ---------------------------------------------------------------------------
// Latin / Greek / Cyrillic / Arabic / Hebrew / Hindi / Thai / CJK.
// Source: distilled from stopwords-iso + plugin's Layer 6 particle lists
// (matches what extractCoreTopic() uses in topic-research.js fix16).
const STOPWORDS = {
  en: ['the','a','an','and','or','but','is','are','was','were','be','been','being','have','has','had','do','does','did','will','would','could','should','may','might','must','can','of','in','on','at','to','for','with','by','from','up','about','into','through','during','before','after','above','below','between','under','over','again','further','then','once','here','there','when','where','why','how','all','each','every','both','few','more','most','some','such','no','nor','not','only','own','same','so','than','too','very','just','also','as','if','because','while','this','that','these','those','i','you','he','she','it','we','they','them','their','our','your','my','me','us','him','her','its','what','which','who','whom'],
  es: ['el','la','los','las','un','una','unos','unas','y','o','pero','es','son','era','eran','ser','estar','está','están','de','del','al','a','en','por','para','con','sin','sobre','entre','desde','hasta','hacia','contra','durante','antes','después','más','menos','muy','ya','aún','todavía','también','pero','sino','que','si','cuando','donde','como','porque','este','esta','esto','estos','estas','ese','esa','eso','esos','esas','yo','tú','él','ella','nosotros','vosotros','ellos','ellas','me','te','se','nos','os','le','les','su','sus','mi','tu','lo','no','ni','sí'],
  fr: ['le','la','les','un','une','des','et','ou','mais','est','sont','était','étaient','être','avoir','a','ont','avait','avaient','de','du','des','au','aux','à','en','dans','par','pour','avec','sans','sur','sous','entre','depuis','jusqu','vers','contre','pendant','avant','après','plus','moins','très','déjà','encore','aussi','que','si','quand','où','comment','parce','ce','cette','ces','cet','je','tu','il','elle','nous','vous','ils','elles','me','te','se','lui','leur','mon','ma','mes','ton','ta','tes','son','sa','ses','notre','votre','leur','ne','pas','non'],
  de: ['der','die','das','den','dem','des','ein','eine','eines','einem','einer','und','oder','aber','ist','sind','war','waren','sein','haben','hat','hatte','hatten','werden','wird','wurde','wurden','von','mit','bei','zu','zur','zum','für','auf','in','im','aus','nach','vor','über','unter','zwischen','durch','gegen','ohne','um','während','wegen','mehr','weniger','sehr','schon','noch','auch','aber','sondern','dass','wenn','wo','wie','warum','weil','dieser','diese','dieses','jener','jene','jenes','ich','du','er','sie','es','wir','ihr','mich','dich','sich','uns','euch','ihn','ihm','ihr','sein','seine','mein','meine','nicht'],
  it: ['il','lo','la','i','gli','le','un','uno','una','e','o','ma','è','sono','era','erano','essere','avere','ha','ho','hanno','aveva','di','del','dello','della','dei','degli','delle','a','al','allo','alla','ai','agli','alle','in','nel','nella','nei','nelle','da','dal','dalla','dai','dalle','con','su','sul','sulla','sui','sulle','per','tra','fra','più','meno','molto','già','ancora','anche','che','se','quando','dove','come','perché','questo','questa','questi','queste','quel','quella','quei','quelle','io','tu','lui','lei','noi','voi','loro','mi','ti','si','ci','vi','lo','la','li','le','mio','tuo','suo','non','né'],
  pt: ['o','a','os','as','um','uma','uns','umas','e','ou','mas','é','são','era','eram','ser','estar','está','estão','ter','tem','têm','tinha','tinham','de','do','da','dos','das','em','no','na','nos','nas','por','para','com','sem','sobre','entre','desde','até','contra','durante','mais','menos','muito','já','ainda','também','mas','que','se','quando','onde','como','porque','este','esta','isto','estes','estas','esse','essa','isso','esses','essas','aquele','aquela','aquilo','eu','tu','ele','ela','nós','vós','eles','elas','me','te','se','nos','vos','lhe','lhes','seu','sua','seus','suas','meu','minha','teu','tua','não','nem'],
  nl: ['de','het','een','en','of','maar','is','zijn','was','waren','heeft','hebben','had','hadden','worden','wordt','werd','werden','van','met','bij','naar','uit','voor','in','op','aan','onder','over','tussen','door','tegen','zonder','om','tijdens','meer','minder','zeer','al','nog','ook','maar','dat','als','wanneer','waar','hoe','waarom','omdat','deze','dit','die','dat','ik','jij','hij','zij','het','wij','jullie','zij','me','je','zich','ons','hem','haar','zijn','haar','mijn','jouw','niet','geen'],
  sv: ['en','ett','den','det','de','och','eller','men','är','var','vara','varit','har','hade','ha','av','på','i','till','för','med','från','genom','mellan','under','över','efter','före','mer','mindre','mycket','redan','också','men','att','som','när','var','hur','varför','denna','detta','dessa','jag','du','han','hon','vi','ni','de','mig','dig','sig','oss','er','dem','honom','henne','min','din','sin','inte','ej'],
  no: ['en','et','den','det','de','og','eller','men','er','var','være','vært','har','hadde','ha','av','på','i','til','for','med','fra','gjennom','mellom','under','over','etter','før','mer','mindre','svært','allerede','også','men','at','som','når','hvor','hvordan','hvorfor','denne','dette','disse','jeg','du','han','hun','vi','dere','de','meg','deg','seg','oss','dem','ham','henne','min','din','sin','ikke'],
  da: ['en','et','den','det','de','og','eller','men','er','var','være','været','har','havde','have','af','på','i','til','for','med','fra','gennem','mellem','under','over','efter','før','mere','mindre','meget','allerede','også','men','at','som','når','hvor','hvordan','hvorfor','denne','dette','disse','jeg','du','han','hun','vi','i','de','mig','dig','sig','os','jer','dem','ham','hende','min','din','sin','ikke'],
  fi: ['ja','tai','mutta','ei','on','oli','olla','ollut','ovat','olivat','olen','olemme','olette','hän','he','me','te','minä','sinä','se','tämä','tuo','nämä','nuo','että','kun','jos','koska','missä','miten','miksi','mikä','kenen','joka','mikä','jonka','ne','niitä','ja','tai','mutta','vaikka','vaan','sekä','paitsi','kuin','noin','paljon','vähän','hyvin','jo','vielä','myös','ei','en','et','emme','ette','eivät'],
  pl: ['i','a','o','w','na','z','do','od','ze','za','po','przy','dla','przez','bez','o','lub','albo','ale','czy','że','jeśli','gdy','kiedy','gdzie','jak','dlaczego','ponieważ','dlatego','ten','ta','to','ci','te','tamten','tamta','tamto','tamci','tamte','ja','ty','on','ona','ono','my','wy','oni','one','mnie','ciebie','siebie','nas','was','go','ją','je','mu','jej','mu','moje','twoje','jego','jej','nasze','wasze','ich','nie','jest','są','był','była','było','byli','być','mieć','ma','mają','miał','miała'],
  cs: ['a','i','ale','nebo','v','na','za','s','se','k','o','do','po','před','pod','mezi','je','jsou','byl','byla','bylo','byli','být','mám','máme','mají','má','měl','měli','že','jestli','když','kde','jak','proč','protože','ten','ta','to','ti','ty','tato','toto','já','ty','on','ona','ono','my','vy','oni','ony','mě','tě','ho','ji','nás','vás','jich','ne','ano'],
  hu: ['a','az','egy','és','vagy','de','van','volt','voltak','lesz','lenni','lett','lettek','és','de','hogy','ha','amikor','ahol','hogyan','miért','mert','ez','az','ezek','azok','én','te','ő','mi','ti','ők','engem','téged','őt','minket','titeket','őket','nem','sem','igen','már','még','is','csak','nagyon','kell','kellene'],
  ro: ['și','sau','dar','este','sunt','era','erau','fi','fost','are','au','aveau','în','la','pe','cu','de','din','pentru','prin','fără','după','înainte','peste','sub','între','mai','mult','puțin','foarte','deja','încă','și','dar','că','dacă','când','unde','cum','de','ce','pentru','că','acest','această','aceste','acești','acel','acea','aceia','acelea','eu','tu','el','ea','noi','voi','ei','ele','mă','te','se','ne','vă','îi','îl','o','le','nu'],
  el: ['ο','η','το','οι','τα','ένας','μία','μια','ένα','και','ή','αλλά','είναι','είναι','ήταν','να','έχω','έχεις','έχει','έχουμε','έχετε','έχουν','είχε','είχα','είχαμε','από','σε','για','με','χωρίς','σε','στον','στην','στο','στους','στις','στα','πριν','μετά','κατά','πάνω','κάτω','μεταξύ','μέσω','χωρίς','αυτός','αυτή','αυτό','αυτοί','αυτές','αυτά','εγώ','εσύ','αυτός','αυτή','αυτό','εμείς','εσείς','αυτοί','αυτές','αυτά','με','σε','τον','την','το','τους','τις','τα','δεν','μη','όχι','ναι','αν','όταν','πού','πώς','γιατί','επειδή','ότι'],
  tr: ['ve','veya','ama','fakat','ancak','ile','için','gibi','kadar','sadece','da','de','mi','mı','mu','mü','bir','bu','şu','o','bunlar','şunlar','onlar','ben','sen','biz','siz','onlar','beni','seni','bizi','sizi','onu','onları','değil','yok','var','olmak','olmuş','olacak','olan','idi','imiş','ise','evet','hayır','nasıl','nerede','ne','neden','niçin','kim','hangi'],
  ru: ['и','или','но','а','в','на','с','о','от','до','по','за','под','над','между','через','для','без','из','к','у','про','при','около','среди','вокруг','это','этот','эта','эти','тот','та','то','те','я','ты','он','она','оно','мы','вы','они','меня','тебя','его','её','нас','вас','их','мой','твой','его','её','наш','ваш','их','не','нет','да','если','когда','где','как','почему','потому','что','чтобы','ли','уже','ещё','тоже','также','только','очень','более','менее','есть','был','была','было','были','будет','быть','может','можно','нельзя'],
  uk: ['і','й','та','або','але','а','в','на','з','о','від','до','по','за','під','над','між','через','для','без','із','к','у','про','при','біля','серед','навколо','це','цей','ця','ці','той','та','те','ті','я','ти','він','вона','воно','ми','ви','вони','мене','тебе','його','її','нас','вас','їх','мій','твій','його','її','наш','ваш','їх','не','ні','так','якщо','коли','де','як','чому','тому','що','щоб','чи','вже','ще','теж','також','тільки','дуже','більше','менше','є','був','була','було','були','буде','бути','може','можна','не можна'],
  ja: ['の','は','が','を','に','で','と','も','や','な','から','まで','より','へ','や','か','な','ね','よ','さ','って','って','これ','それ','あれ','この','その','あの','ここ','そこ','あそこ','私','あなた','彼','彼女','我々','あなたたち','彼ら','です','である','だ','ます','した','する','した','いる','ある','ない','なかった','でも','しかし','だから','そして','そして','また','さらに','特に','非常に','とても','最も','最高','最良','最新','良い','悪い','大きい','小さい','多い','少ない','新しい','古い'],
  ko: ['의','은','는','이','가','을','를','에','에서','으로','와','과','도','만','까지','부터','이다','있다','없다','하다','되다','이','그','저','이것','그것','저것','여기','거기','저기','나','너','그','그녀','우리','너희','그들','저','입니다','습니다','있습니다','아닙니다','있다','없다','하다','되다','이다','최고','최고의','가장','베스트','추천','좋은','나쁜','큰','작은','많은','적은','새로운','오래된','그러나','하지만','그리고','또한','특히','매우','아주','가장'],
  zh: ['的','了','和','与','或','但','是','在','有','被','为','对','从','到','把','给','让','使','由','因','所','以','与','及','或','也','都','就','还','才','又','再','很','非','不','没','无','一','二','三','四','五','这','那','哪','什么','怎么','为什么','如何','因为','所以','虽然','但是','然而','而且','并且','最','最好','最佳','最新','推荐','好','坏','大','小','多','少','新','旧','我','你','他','她','它','我们','你们','他们','她们','它们','的','了','着','过','吗','呢','吧','啊'],
  ar: ['ال','في','من','على','إلى','عن','مع','أو','و','ب','ل','ك','هذا','هذه','ذلك','تلك','الذي','التي','اللذين','اللاتي','ما','لا','لم','لن','قد','كان','كانت','يكون','تكون','أن','إن','هل','كل','بعض','غير','أي','كما','أيضا','جدا','كثيرا','قليلا','أحسن','أفضل','الأفضل','جيد','سيء','كبير','صغير','أنا','أنت','هو','هي','نحن','أنتم','هم','هن','لي','لك','له','لها','لنا','لكم','لهم','لهن'],
  he: ['ה','של','את','על','אל','מן','מ','ל','ב','ו','כ','ש','זה','זאת','אלה','אלו','ההוא','ההיא','ההם','ההן','אני','אתה','את','הוא','היא','אנחנו','אתם','אתן','הם','הן','לי','לך','לו','לה','לנו','לכם','להם','להן','לא','לא','אל','אם','כאשר','איפה','איך','למה','בגלל','אבל','אך','רק','מאוד','כבר','עוד','גם','רק','הכי','הטוב','הטובים','ביותר','טוב','רע','גדול','קטן'],
  hi: ['का','के','की','को','में','से','पर','और','या','लेकिन','है','हैं','था','थे','थी','थीं','होना','होता','होती','हुआ','हुई','हुए','नहीं','न','मत','अगर','अगर','जब','कहाँ','कैसे','क्यों','क्योंकि','इस','इसे','इसका','इसकी','इसके','उस','उसे','उसका','उसकी','उसके','ये','वे','यह','वह','मैं','तुम','आप','हम','वह','वे','सर्वश्रेष्ठ','सबसे','अच्छा','बेस्ट','अच्छा','बुरा','बड़ा','छोटा','नया','पुराना','बहुत','ज्यादा','कम'],
  th: ['และ','หรือ','แต่','ใน','ของ','ที่','จาก','ไป','มา','ให้','ได้','ถูก','จะ','คือ','เป็น','อยู่','มี','ไม่','ไม่','ไม่ได้','ไม่ใช่','ที่','ซึ่ง','เพราะ','ถ้า','เมื่อ','ที่','อย่างไร','ทำไม','ฉัน','คุณ','เขา','เธอ','เรา','พวกเขา','พวกเรา','นี่','นั่น','โน่น','นี้','นั้น','โน้น','ดี','แย่','ใหญ่','เล็ก','เยอะ','น้อย','ใหม่','เก่า','มาก','ที่สุด','ยอดนิยม','ดีที่สุด','แนะนำ'],
  vi: ['và','hoặc','nhưng','trong','của','ở','tại','từ','đến','với','cho','về','như','là','có','không','được','bị','đã','đang','sẽ','mà','rằng','khi','ở đâu','thế nào','tại sao','bởi vì','tôi','bạn','anh','chị','nó','chúng tôi','các bạn','họ','này','đó','kia','tốt','xấu','lớn','nhỏ','nhiều','ít','mới','cũ','rất','quá','nhất','tốt nhất','khuyên dùng','đề xuất'],
  id: ['dan','atau','tapi','di','dari','ke','untuk','dengan','tentang','sebagai','adalah','ada','tidak','bukan','akan','telah','sudah','yang','bahwa','jika','ketika','dimana','bagaimana','mengapa','karena','saya','anda','dia','kita','kami','mereka','ini','itu','baik','buruk','besar','kecil','banyak','sedikit','baru','lama','sangat','paling','terbaik','direkomendasikan'],
  ms: ['dan','atau','tetapi','di','dari','ke','untuk','dengan','tentang','sebagai','adalah','ada','tidak','bukan','akan','telah','sudah','yang','bahawa','jika','apabila','di mana','bagaimana','mengapa','kerana','saya','anda','dia','kita','kami','mereka','ini','itu','baik','buruk','besar','kecil','banyak','sedikit','baru','lama','sangat','paling','terbaik','disyorkan']
};

// ---------------------------------------------------------------------------
// 2. Tokenizer — language-aware
// ---------------------------------------------------------------------------
// Latin / Cyrillic / Greek / Arabic / Hebrew / Hindi / Thai / CJK.
// - Latin / Cyrillic / Greek: Unicode word boundaries via /\p{L}\p{N}/
// - CJK (ja/ko/zh): 2-char and 3-char sliding window n-grams (no word
//   boundaries exist — ES regex \p{L} matches each char individually, which
//   is useless for meaning. Window n-grams capture compounds.)
// - Thai: same n-gram approach as CJK
// - Arabic/Hebrew: Unicode word boundaries (they have spaces)

const CJK_LANGS = new Set(['ja', 'ko', 'zh', 'zh-CN', 'zh-TW', 'th']);

function isCJKLang(lang) {
  if (!lang) return false;
  const base = String(lang).toLowerCase().split('-')[0];
  return CJK_LANGS.has(base);
}

/**
 * Tokenize text into a flat array of tokens (lowercased, stopwords stripped).
 * @param {string} text
 * @param {string} lang — BCP-47 language code (e.g. "en", "ja", "zh-CN")
 * @returns {string[]}
 */
export function tokenize(text, lang = 'en') {
  if (!text || typeof text !== 'string') return [];
  const base = String(lang || 'en').toLowerCase().split('-')[0];
  const stopwords = new Set(STOPWORDS[base] || STOPWORDS.en);

  let tokens = [];

  if (isCJKLang(base)) {
    // CJK + Thai: strip whitespace + punctuation, then 2-char and 3-char
    // sliding window n-grams. Captures typical word lengths without a
    // morphological analyzer.
    const cleaned = text
      .replace(/[\s\p{P}\p{S}]+/gu, '') // drop whitespace + punctuation + symbols
      .replace(/[0-9]+/g, ' ')          // drop numbers (noise for BM25)
      .trim();
    // 2-char windows (captures most Japanese/Chinese common terms)
    for (let i = 0; i < cleaned.length - 1; i++) {
      tokens.push(cleaned.slice(i, i + 2));
    }
    // 3-char windows (captures compound terms like ミームコイン)
    for (let i = 0; i < cleaned.length - 2; i++) {
      tokens.push(cleaned.slice(i, i + 3));
    }
  } else {
    // Latin / Cyrillic / Greek / Arabic / Hebrew / Hindi / Vietnamese /
    // Indonesian / Malay: Unicode word boundaries.
    const matches = text.toLowerCase().match(/[\p{L}\p{N}]+/gu) || [];
    tokens = matches;
  }

  // Filter: length 2-30, not pure numeric, not a stopword, not all-same-char
  return tokens.filter(t => {
    if (t.length < 2 || t.length > 30) return false;
    if (/^\d+$/.test(t)) return false;
    if (stopwords.has(t)) return false;
    if (/^(.)\1+$/.test(t)) return false; // "aaa", "111"
    return true;
  });
}

// ---------------------------------------------------------------------------
// 3. BM25 — the actual algorithm
// ---------------------------------------------------------------------------

/**
 * Compute BM25 scores for every unique term across a small corpus.
 *
 * @param {string[]} documents — raw text, one per document (top-N SERP bodies)
 * @param {string} lang — BCP-47 language code
 * @param {object} opts — { k1: 1.5, b: 0.75, maxTerms: 50 }
 * @returns {{ terms: Array<{term, score, df, tf_total}>, avgDocLength: number, stats: {...} }}
 */
export function bm25Corpus(documents, lang = 'en', opts = {}) {
  const k1 = opts.k1 ?? 1.5;
  const b = opts.b ?? 0.75;
  const maxTerms = opts.maxTerms ?? 50;

  if (!Array.isArray(documents) || documents.length === 0) {
    return { terms: [], avgDocLength: 0, stats: { docs: 0, uniqueTerms: 0 } };
  }

  // 1. Tokenize every document
  const tokenizedDocs = documents.map(d => tokenize(d, lang));
  const N = tokenizedDocs.length;
  const docLengths = tokenizedDocs.map(toks => toks.length);
  const avgDocLength = docLengths.reduce((a, b) => a + b, 0) / Math.max(1, N);

  // 2. Compute term frequencies per document + document frequencies
  const tfPerDoc = tokenizedDocs.map(toks => {
    const tf = {};
    for (const t of toks) tf[t] = (tf[t] || 0) + 1;
    return tf;
  });

  const df = {};
  for (const tf of tfPerDoc) {
    for (const t in tf) df[t] = (df[t] || 0) + 1;
  }

  // 3. BM25 per term per doc, summed across corpus to get "distinctiveness"
  const scores = {};
  const tfTotals = {};
  for (const term in df) {
    const docFreq = df[term];
    // Standard Okapi BM25 IDF with +1 smoothing
    const idf = Math.log((N - docFreq + 0.5) / (docFreq + 0.5) + 1);
    let sumScore = 0;
    let sumTf = 0;
    for (let i = 0; i < N; i++) {
      const tf = tfPerDoc[i][term] || 0;
      if (tf === 0) continue;
      sumTf += tf;
      const lenNorm = 1 - b + b * (docLengths[i] / Math.max(1, avgDocLength));
      const score = idf * ((tf * (k1 + 1)) / (tf + k1 * lenNorm));
      sumScore += score;
    }
    scores[term] = sumScore;
    tfTotals[term] = sumTf;
  }

  // 4. Rank by BM25 score. Filter: must appear in ≥2 docs (removes noise)
  // to prefer terms competitors agree on.
  const ranked = Object.entries(scores)
    .filter(([term, _]) => df[term] >= 2)
    .map(([term, score]) => ({
      term,
      score: Math.round(score * 100) / 100,
      df: df[term],
      tf_total: tfTotals[term],
    }))
    .sort((a, b) => b.score - a.score)
    .slice(0, maxTerms);

  return {
    terms: ranked,
    avgDocLength: Math.round(avgDocLength),
    stats: {
      docs: N,
      uniqueTerms: Object.keys(df).length,
      k1, b,
    },
  };
}

// ---------------------------------------------------------------------------
// 4. Heading + PAA extraction (shared helpers for content-brief.js)
// ---------------------------------------------------------------------------

/**
 * Extract H2 headings from raw HTML. Strips attribute clutter; returns an
 * array of {text, count} where count is frequency across the corpus (how
 * many docs used this H2).
 */
export function commonH2Patterns(htmls) {
  const counts = {};
  for (const html of htmls) {
    if (!html) continue;
    const matches = String(html).match(/<h2[^>]*>([^<]{2,120})<\/h2>/gi) || [];
    const seen = new Set();
    for (const m of matches) {
      const text = m.replace(/<[^>]+>/g, '').trim().toLowerCase()
        .replace(/\s+/g, ' ')
        .replace(/^[\d\.\)\s]+/, ''); // strip leading numbers "1. "
      if (text.length < 3 || text.length > 120) continue;
      if (seen.has(text)) continue; // don't count same H2 twice within one doc
      seen.add(text);
      counts[text] = (counts[text] || 0) + 1;
    }
  }
  return Object.entries(counts)
    .filter(([_, c]) => c >= 2) // only patterns used by ≥2 competitors
    .map(([text, count]) => ({ text, count }))
    .sort((a, b) => b.count - a.count)
    .slice(0, 15);
}

/**
 * Word count utility with CJK-aware heuristic (matches
 * GEO_Analyzer::count_words_lang() behavior from v1.5.206d).
 */
export function wordCount(text, lang = 'en') {
  if (!text) return 0;
  const stripped = String(text).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  if (isCJKLang(String(lang).toLowerCase().split('-')[0])) {
    // CJK has no spaces — char-count ÷ 2 heuristic
    const cjkChars = (stripped.match(/\p{Script=Han}|\p{Script=Hiragana}|\p{Script=Katakana}|\p{Script=Hangul}|\p{Script=Thai}/gu) || []).length;
    const latinWords = (stripped.match(/[\p{L}\p{N}]+/gu) || []).filter(w => !/\p{Script=Han}|\p{Script=Hiragana}|\p{Script=Katakana}|\p{Script=Hangul}|\p{Script=Thai}/u.test(w)).length;
    return Math.round(cjkChars / 2) + latinWords;
  }
  return (stripped.match(/[\p{L}\p{N}]+/gu) || []).length;
}
