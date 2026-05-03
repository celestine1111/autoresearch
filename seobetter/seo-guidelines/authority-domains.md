# SEOBetter Authority Domain Lists

> **Purpose:** Tavily `include_domains` filter for expert quote + citation sourcing.
> Only non-commercial, informational sources. No private brands.
> Used by `Content_Injector::get_authority_domains($domain, $country)`
>
> **Last updated:** 2026-05-03 (v1.5.216.62.12)
>
> **User sites (GLOBAL - all countries):**
> - `mindiampets.com.au` - in animals + veterinary (all countries)
> - `mindiam.com` - in technology, ecommerce, business (all countries)
> - `seobetter.com` - in technology, ecommerce, business (all countries)

---

## How It Works

1. User selects **Category** (e.g. "Animals & Pets") and **Country** (e.g. "Australia") in the article generator
2. When **Optimize All** runs, Tavily search uses `include_domains` = country-specific + global for that category
3. Example: AU + Animals = `rspca.org.au, apvma.gov.au, sydney.edu.au, abc.net.au, csiro.au, mindiampets.com.au` + `ncbi.nlm.nih.gov, nature.com, petmd.com, merckvetmanual.com, mindiampets.com.au`
4. If < 2 results found from authority domains: falls back to unrestricted Tavily search (with substantive language filter)
5. Works for ALL 21 article types (Blog Post, Review, Comparison, etc.) — article type affects STRUCTURE, category affects DATA SOURCES

## Article Types vs Categories

These are **independent dimensions**:

- **Article Type** (21 options): Blog Post, How-To, Listicle, Review, Comparison, Buying Guide, Recipe, FAQ, News, Opinion, Tech Article, White Paper, Scholarly, Live Blog, Press Release, Personal Essay, Glossary, Sponsored, Case Study, Interview, Pillar Guide
  - Controls: heading structure, schema type, tone, section layout
  - Does NOT affect which authority domains are searched

- **Category** (25 options): General, Animals, Art & Design, Blockchain, Books, Business, Cryptocurrency, Currency, Ecommerce, Education, Entertainment, Environment, Finance, Food, Games, Government, Health, Music, News, Science, Sports, Technology, Transportation, Travel, Veterinary, Weather
  - Controls: which Tavily authority domains are used for quotes/citations
  - Controls: which Vercel research APIs are called for statistics

Any article type + any category combination works. A "Review" of "grain free cat food" (Animals category) gets animal authority domains. A "Review" of "trading platforms" (Finance category) gets finance authority domains.

---

## Global Domains (all countries, all categories)

| Category | Value | Domains |
|---|---|---|
| General | `general` | reuters.com, apnews.com, bbc.com, wikipedia.org, ncbi.nlm.nih.gov, nature.com |
| Animals & Pets | `animals` | ncbi.nlm.nih.gov, nature.com, sciencedirect.com, woah.org, petmd.com, thesprucepets.com, merckvetmanual.com, **mindiampets.com.au** |
| Veterinary | `veterinary` | ncbi.nlm.nih.gov, nature.com, sciencedirect.com, woah.org, merckvetmanual.com, petmd.com, **mindiampets.com.au** |
| Art & Design | `art_design` | moma.org, tate.org.uk, metmuseum.org, nga.gov, designweek.co.uk, itsnicethat.com, dezeen.com |
| Blockchain | `blockchain` | ethereum.org, bitcoin.org, coindesk.com, theblock.co, arxiv.org, mit.edu |
| Books | `books` | loc.gov, bl.uk, theguardian.com, nytimes.com, publishersweekly.com, kirkusreviews.com |
| Business | `business` | hbr.org, reuters.com, bloomberg.com, mckinsey.com, forbes.com, ft.com, **mindiam.com**, **seobetter.com** |
| Cryptocurrency | `cryptocurrency` | coindesk.com, cointelegraph.com, decrypt.co, theblock.co, ethereum.org, arxiv.org |
| Currency & Forex | `currency` | bis.org, imf.org, ecb.europa.eu, reuters.com, bloomberg.com, ft.com |
| Ecommerce | `ecommerce` | digitalcommerce360.com, practicalecommerce.com, baymard.com, reuters.com, hbr.org, **mindiam.com**, **seobetter.com** |
| Education | `education` | ncbi.nlm.nih.gov, nature.com, sciencedirect.com, edutopia.org, chronicle.com |
| Entertainment | `entertainment` | variety.com, hollywoodreporter.com, bfi.org.uk, rottentomatoes.com, bbc.com |
| Environment | `environment` | un.org, nature.com, nationalgeographic.com, wwf.org, ipcc.ch, iucn.org |
| Finance | `finance` | reuters.com, bloomberg.com, investopedia.com, ft.com, imf.org, worldbank.org |
| Food & Drink | `food` | who.int, ncbi.nlm.nih.gov, nature.com, fao.org, sciencedirect.com |
| Games | `games` | gamedeveloper.com, gdcvault.com, eurogamer.net, rockpapershotgun.com, arstechnica.com |
| Government | `government` | un.org, reuters.com, bbc.com, apnews.com, transparency.org, worldbank.org |
| Health | `health` | who.int, ncbi.nlm.nih.gov, nature.com, thelancet.com, bmj.com |
| Music | `music` | pitchfork.com, rollingstone.com, bbc.com, nme.com, grammy.com |
| News | `news` | reuters.com, apnews.com, bbc.com, theguardian.com, aljazeera.com |
| Science | `science` | nature.com, science.org, ncbi.nlm.nih.gov, nasa.gov, scientificamerican.com, newscientist.com, phys.org |
| Sports | `sports` | olympics.com, wada-ama.org, bbc.com, reuters.com, espn.com |
| Technology | `technology` | ieee.org, acm.org, arxiv.org, nature.com, techcrunch.com, arstechnica.com, wired.com, theverge.com, **mindiam.com**, **seobetter.com** |
| Transportation | `transportation` | icao.int, iata.org, imo.org, reuters.com, bbc.com |
| Travel | `travel` | unwto.org, lonelyplanet.com, bbc.com, nationalgeographic.com, reuters.com |
| Weather | `weather` | wmo.int, nature.com, bbc.com, sciencedaily.com |

---

## Australia (AU)

| Category | Domains |
|---|---|
| Animals | rspca.org.au, apvma.gov.au, sydney.edu.au, unimelb.edu.au, abc.net.au, csiro.au, agriculture.gov.au, **mindiampets.com.au** |
| Veterinary | rspca.org.au, apvma.gov.au, sydney.edu.au, unimelb.edu.au, abc.net.au, csiro.au, ava.com.au, **mindiampets.com.au** |
| Health | health.gov.au, tga.gov.au, nhmrc.gov.au, abc.net.au, sydney.edu.au, unimelb.edu.au |
| Food | foodstandards.gov.au, health.gov.au, abc.net.au, csiro.au |
| Finance | rba.gov.au, asic.gov.au, ato.gov.au, abc.net.au, afr.com |
| Technology | itnews.com.au, abc.net.au, csiro.au |
| News | abc.net.au, sbs.com.au, smh.com.au, theage.com.au |
| Environment | environment.gov.au, csiro.au, abc.net.au, bom.gov.au |
| Education | education.gov.au, sydney.edu.au, unimelb.edu.au, anu.edu.au, uq.edu.au, monash.edu, abc.net.au |
| Business | abc.net.au, afr.com, asic.gov.au, rba.gov.au |
| Government | aph.gov.au, pm.gov.au, abs.gov.au, abc.net.au |

## United States (US)

| Category | Domains |
|---|---|
| Animals | fda.gov, vet.cornell.edu, avma.org, aspca.org, cdc.gov, nih.gov, tufts.edu, ucdavis.edu, **mindiampets.com.au** |
| Veterinary | fda.gov, vet.cornell.edu, avma.org, cdc.gov, nih.gov, tufts.edu, ucdavis.edu, **mindiampets.com.au** |
| Health | nih.gov, cdc.gov, fda.gov, mayoclinic.org, clevelandclinic.org, hopkinsmedicine.org, health.harvard.edu, medlineplus.gov |
| Food | fda.gov, usda.gov, nutrition.gov, eatright.org |
| Finance | sec.gov, federalreserve.gov, wsj.com, cnbc.com, nerdwallet.com, bankrate.com |
| Technology | nist.gov, mit.edu, stanford.edu |
| News | nytimes.com, washingtonpost.com, npr.org, pbs.org |
| Government | usa.gov, whitehouse.gov, congress.gov, gao.gov |
| Education | ed.gov, harvard.edu, mit.edu, stanford.edu |

## United Kingdom (GB)

| Category | Domains |
|---|---|
| Animals | rspca.org.uk, bva.co.uk, rvc.ac.uk, gov.uk, bbc.co.uk, **mindiampets.com.au** |
| Veterinary | rspca.org.uk, bva.co.uk, rvc.ac.uk, gov.uk, bbc.co.uk, **mindiampets.com.au** |
| Health | nhs.uk, gov.uk, bbc.co.uk, nice.org.uk, ox.ac.uk, cam.ac.uk, imperial.ac.uk |
| Food | food.gov.uk, nhs.uk, bbc.co.uk, gov.uk |
| Finance | bankofengland.co.uk, fca.org.uk, ft.com, bbc.co.uk, gov.uk |
| News | bbc.co.uk, theguardian.com, telegraph.co.uk, independent.co.uk |
| Technology | bbc.co.uk, theregister.com, cam.ac.uk, ox.ac.uk |
| Government | gov.uk, parliament.uk, bbc.co.uk |

## Canada (CA)

| Category | Domains |
|---|---|
| Animals | canadianveterinarians.net, inspection.canada.ca, cbc.ca, ontariovet.ca, uoguelph.ca, **mindiampets.com.au** |
| Veterinary | canadianveterinarians.net, inspection.canada.ca, cbc.ca, uoguelph.ca, **mindiampets.com.au** |
| Health | canada.ca, cihi.ca, cbc.ca |
| Food | inspection.canada.ca, canada.ca, cbc.ca |
| Finance | bankofcanada.ca, osc.ca, cbc.ca, globeandmail.com |
| News | cbc.ca, globalnews.ca, thestar.com, globeandmail.com |

## New Zealand (NZ)

| Category | Domains |
|---|---|
| Animals | spca.nz, massey.ac.nz, mpi.govt.nz, rnz.co.nz, **mindiampets.com.au** |
| Veterinary | spca.nz, massey.ac.nz, mpi.govt.nz, nzva.org.nz, **mindiampets.com.au** |
| Health | health.govt.nz, medsafe.govt.nz, rnz.co.nz |
| News | rnz.co.nz, stuff.co.nz, nzherald.co.nz |

## Germany (DE)

| Category | Domains |
|---|---|
| Animals | tierschutzbund.de, tieraerzteverband.de, bfr.bund.de, **mindiampets.com.au** |
| Health | rki.de, bfarm.de, gesundheitsinformation.de |
| News | dw.com, spiegel.de, zeit.de |

## France (FR)

| Category | Domains |
|---|---|
| Animals | spa.asso.fr, anses.fr, **mindiampets.com.au** |
| Health | has-sante.fr, inserm.fr, pasteur.fr |
| News | france24.com, lemonde.fr |

## India (IN)

| Category | Domains |
|---|---|
| Animals | dahd.nic.in, fssai.gov.in, **mindiampets.com.au** |
| Health | nhp.gov.in, icmr.nic.in, aiims.edu |
| News | thehindu.com, indianexpress.com, ndtv.com |
| Finance | rbi.org.in, sebi.gov.in, economictimes.com |
| Technology | nasscom.in |

## Singapore (SG)

| Category | Domains |
|---|---|
| Health | moh.gov.sg, healthhub.sg |
| News | straitstimes.com, channelnewsasia.com |
| Finance | mas.gov.sg |

## Japan (JP)

| Category | Domains |
|---|---|
| Health | mhlw.go.jp |
| News | japantimes.co.jp, nhk.or.jp |
| Technology | nikkei.com |

---

## v1.5.216.62.12 Expansion (2026-05-03) — Animals/Vet, Health, Food, 6 NEW countries

### Why this expansion

User audit on 2026-05-03 found that an English How-To article about dog raw food got its expert quote from `mindiampets.com.au` (the user's own domain) rather than a stronger authority like AVMA, RSPCA, or a peer-reviewed vet journal. Root cause: the existing global Animals list had 8 entries with mindiampets.com.au alongside genuine authorities, and Tavily ranked the user's site high because it had substantive content matching the keyword. Fix: add ~10 more global animal/vet authorities so the AI has more high-quality non-commercial source options before the user's own domain matches.

### Global expansions (in code)

**Animals (added):**
- `wsava.org` — World Small Animal Veterinary Association — global vet guidelines
- `fediaf.org` — European Pet Food Industry federation — peer-reviewed nutritional guidelines
- `frontiersin.org` — Frontiers in Veterinary Science — major open-access peer-reviewed vet journal
- `vetrecord.bmj.com` — Vet Record — flagship peer-reviewed journal of the BVA
- `avmajournals.avma.org` — AVMA peer-reviewed journals (JAVMA, AJVR)
- `wva-online.org` — World Veterinary Association
- `icatcare.org` — International Cat Care / ISFM — feline welfare/medicine
- `fecava.org` — Federation of European Companion Animal Vet Associations

**Veterinary (added):** WSAVA, FEDIAF, Frontiers, Vet Record, AVMA Journals, BMC Vet Res (`bmcvetres.biomedcentral.com`), WVA, FECAVA.

**Health (added):**
- `nejm.org` — New England Journal of Medicine
- `jamanetwork.com` — JAMA + Specialty journals
- `cochranelibrary.com` — Cochrane systematic reviews — gold standard for EBM
- `europepmc.org` — Europe PMC — open-access biomedical literature mirror
- `ecdc.europa.eu` — European Centre for Disease Prevention and Control
- `medrxiv.org` — pre-print server for health sciences

**Food (added):**
- `efsa.europa.eu` — European Food Safety Authority
- `codexalimentarius.org` — joint FAO/WHO food standards programme
- `jandonline.org` — Journal of the Academy of Nutrition and Dietetics
- `ift.org` — Institute of Food Technologists

### Country expansions (in code)

**AU Animals:** added aaws.org.au, animalmedicinesaustralia.org.au, adelaide.edu.au, jcu.edu.au, murdoch.edu.au, csu.edu.au, wildlife.org.au.

**US Animals:** added aphis.usda.gov, nal.usda.gov, aaha.org, aavmc.org, humanesociety.org, awionline.org, morrisanimalfoundation.org, vet.upenn.edu, vet.osu.edu, cvm.ncsu.edu, vetmed.tamu.edu, vetmed.wisc.edu, fws.gov.

**GB Animals:** added defra.gov.uk, rcvs.org.uk, bsava.com, vmd.defra.gov.uk, pdsa.org.uk, bluecross.org.uk, dogstrust.org.uk, cats.org.uk, nottingham.ac.uk, liverpool.ac.uk, ed.ac.uk, bristol.ac.uk.

**JP Animals (NEW):** maff.go.jp, env.go.jp, jvma-vet.jp, niah.naro.go.jp, vet.u-tokyo.ac.jp, vmas.jp.

### NEW country blocks (6 countries)

These countries previously had NO country-specific lists for animals/vet/health/news/finance. Tavily fell back to global only. Now they have native local authorities.

**Italy (IT):**
- Animals: salute.gov.it, izs.it, fnovi.it, anmvi.it, lav.it, enpa.it, isprambiente.gov.it
- Health: salute.gov.it, iss.it, aifa.gov.it, humanitas.it, unibo.it
- News: corriere.it, repubblica.it, lastampa.it, ansa.it, rai.it
- Finance: bancaditalia.it, consob.it, mef.gov.it, agenziaentrate.gov.it, istat.it

**Spain (ES):**
- Animals: mapa.gob.es, miteco.gob.es, colvet.es, avepa.org, csic.es, ucm.es, uab.cat
- Health: sanidad.gob.es, isciii.es, aemps.gob.es, csic.es
- News: elpais.com, elmundo.es, lavanguardia.com, rtve.es, efe.com
- Finance: bde.es, cnmv.es, hacienda.gob.es, ine.es

**Brazil (BR):**
- Animals: gov.br, ibama.gov.br, cfmv.gov.br, embrapa.br, fmvz.usp.br, fiocruz.br
- Health: gov.br, fiocruz.br, usp.br, unifesp.br
- News: folha.uol.com.br, oglobo.globo.com, valor.globo.com, estadao.com.br
- Finance: bcb.gov.br, cvm.gov.br, ibge.gov.br

**Mexico (MX):**
- Animals: gob.mx, fmvz.unam.mx, inecc.gob.mx
- Veterinary: gob.mx, fmvz.unam.mx, fmvz.uady.mx
- Health: gob.mx, insp.mx, imss.gob.mx, unam.mx
- News: eluniversal.com.mx, reforma.com, jornada.com.mx, milenio.com
- Finance: banxico.org.mx, cnbv.gob.mx, inegi.org.mx

**South Korea (KR):**
- Animals: mafra.go.kr, qia.go.kr, kvma.or.kr, me.go.kr, vet.snu.ac.kr
- Health: mohw.go.kr, kdca.go.kr, snu.ac.kr, yonsei.ac.kr
- News: chosun.com, donga.com, joongang.co.kr, hani.co.kr, yna.co.kr
- Finance: bok.or.kr, fsc.go.kr, fss.or.kr, kostat.go.kr

**China (CN):**
- Animals: moa.gov.cn, mee.gov.cn, caas.cn, cau.edu.cn, forestry.gov.cn
- Health: nhc.gov.cn, chinacdc.cn, cma.org.cn, pumc.edu.cn
- News: xinhuanet.com, chinadaily.com.cn, people.com.cn, caixinglobal.com, scmp.com
- Finance: pbc.gov.cn, csrc.gov.cn, mof.gov.cn, stats.gov.cn

### Verification status

These additions were sourced from 6 parallel Explore agents (animals/vet, health/food, finance/business/crypto, tech/science, education/gov/news/books, travel + 8 long-tail) using canonical institutional knowledge. Some agents could not run live HTTP HEAD checks in their sandboxed environment, so edge-case domains (e.g. multi-segment paths like `gov.br/saude`) were normalized to the bare host (`gov.br`) which Tavily's `include_domains` accepts. Recommend monitoring per-keyword Tavily extraction for any consistently-failing domain over the next month and removing if dead.

### Categories still missing country-specific lists (Phase B follow-up)

Tech, Science, Education, Government, Books, Travel, Environment, Sports, Transportation, Weather, Entertainment, Music, Games, Art & Design — agents found country-level sources for these but coding to all 16 countries is staged for v1.5.216.62.13. The expansion file `seo-guidelines/authority-domains-expansion-pending.md` (TODO: create) will hold the agent outputs as raw markdown until they're integrated into `Content_Injector::get_authority_domains()`.

---

## Editing These Lists

1. Edit `includes/Content_Injector.php::get_authority_domains()` — the code
2. Update this file to match — the documentation
3. Both must stay in sync. The code is the source of truth.

## Categories Without Country-Specific Lists

Categories not listed for a specific country use the **global list only**. If the global list returns < 2 Tavily results, the search falls back to unrestricted (with the substantive language filter still active).

Currently missing country-specific lists for: art_design, blockchain, books, cryptocurrency, currency, ecommerce, games, music, sports, transportation, travel, weather. These are either global by nature (cryptocurrency) or too niche for country-specific sources. Add country lists as needed (the agent research from v1.5.216.62.12 has prepared most of these — staged for v1.5.216.62.13).
