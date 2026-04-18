# SEOBetter Authority Domain Lists

> **Purpose:** Tavily `include_domains` filter for expert quote + citation sourcing.
> Only non-commercial, informational sources. No private brands.
> Used by `Content_Injector::get_authority_domains($domain, $country)`
>
> **Last updated:** 2026-04-18 (v1.5.108)
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

## Editing These Lists

1. Edit `includes/Content_Injector.php::get_authority_domains()` — the code
2. Update this file to match — the documentation
3. Both must stay in sync. The code is the source of truth.

## Categories Without Country-Specific Lists

Categories not listed for a specific country use the **global list only**. If the global list returns < 2 Tavily results, the search falls back to unrestricted (with the substantive language filter still active).

Currently missing country-specific lists for: art_design, blockchain, books, cryptocurrency, currency, ecommerce, games, music, sports, transportation, travel, weather. These are either global by nature (cryptocurrency) or too niche for country-specific sources. Add country lists as needed.
