# doliBIT – Kiegészítő Melléklet PDF Generátor

## Teljes technikai dokumentáció (v3)

### OpenCart 4.0.2.3 – dolibit pénzügyi modul

---

## 1. RENDSZER ÁTTEKINTÉS

### 1.1 Technológiai stack
- **Platform:** OpenCart 4.0.2.3
- **PHP:** 8.x
- **MySQL:** 5.7+
- **PDF library:** `setasign\Fpdi\Fpdi` (FPDF + FPDI)
- **Font:** DejaVu Sans, CP1250 kódolás (`iconv('UTF-8','CP1250//TRANSLIT', $text)`)
- **Tábla prefix:** `oc4023_doliBIT_fin_` (pénzügyi modulhoz), `oc4023_doliBIT_` (törzsadathoz)

### 1.2 Fájlstruktúra

```text
extension/dolibit/
├── admin/
│   └── controller/
│       └── finance/
│           └── annualtest.php      ← PHP trait (1688 sor, 18 metódus)
└── model/
    └── extension/
        └── dolibit/
            └── finance/
                └── annual.php      ← Model osztály (362 sor, 13 metódus)
```

### 1.3 SQL fájlok (futtatási sorrend)
1. `doliBIT_update_schema_names.sql` – BS/IS sorok teljes törvényi megnevezése
2. `doliBIT_phase3_asset_movement.sql` – asset_movement tábla + létszám mezők + tesztadatok
3. `doliBIT_phase4_line_items.sql` – line_item tábla (egyéb köv/köt) + tesztadatok
4. `doliBIT_fix_testdata.sql` – GeoLayer tesztadat javítások
5. `doliBIT_phase5_headcount_tax.sql` – létszám részletezés + KIVA/TAO adólevezetés táblák
6. `doliBIT_phase5b_headcount_prev.sql` – létszám tábla előző évi oszlopok + adatfrissítés

---

## 2. ADATBÁZIS SÉMA

### 2.1 Törzsadat táblák

#### `oc4023_doliBIT_company`
A vállalkozás alapadatai.

| Mező | Típus | Leírás |
|------|-------|--------|
| company_id | INT PK | Egyedi azonosító |
| company_name | VARCHAR(255) | Teljes cégnév |
| company_name_short | VARCHAR(100) | Rövid cégnév (PDF-en ez jelenik meg) |
| company_taxhu | VARCHAR(20) | Adószám (pl. 32027107-2-43) |
| company_regnum | VARCHAR(30) | Cégjegyzékszám (pl. 01 09 403351) |
| company_statnum | VARCHAR(25) | Statisztikai szám (pl. 32027107-7112-113-01) |
| company_legal_form | VARCHAR(100) | Társasági forma (pl. Korlátolt felelősségű társaság) |
| company_founded | DATE | Alakulás időpontja |
| company_founded_type | VARCHAR(100) | Alakulás típusa (pl. Jogelőd nélküli alakulás) |
| company_activity_code | VARCHAR(20) | Fő tevékenység TEÁOR kódja (pl. 7022'08) |
| company_activity_name | VARCHAR(200) | Fő tevékenység megnevezése |
| company_share_capital | DECIMAL(15,3) | Törzstőke nagysága eFt-ban |
| company_decision_body | VARCHAR(100) | Döntéshozó szerv (PHP felülírja: Kft→Taggyűlés, Zrt/Nyrt→Közgyűlés) |
| company_city | VARCHAR(100) | Település (backup, ha cím tábla üres) |

**GFO kód:** Nincs külön mező – a PHP kinyeri a statisztikai számból: `preg_match('/\d{8}-\d{4}-(\d{3})-\d{2}/', $statnum, $m)` → 113 = Kft.

#### `oc4023_doliBIT_address`
Cím komponensek.

| Mező | Típus | Leírás |
|------|-------|--------|
| address_id | INT PK | |
| postcode | VARCHAR(10) | Irányítószám (pl. 1182) |
| city | VARCHAR(100) | Település (pl. Budapest) |
| streetname | VARCHAR(200) | Utcanév (DB-ben UPPERCASE → PHP `mb_strtolower`) |
| publicplacecategory | VARCHAR(50) | Közterület típus (utca/út/tér stb. → PHP kisbetűsíti) |
| number | VARCHAR(20) | Házszám |
| staircase | VARCHAR(10) | Lépcsőház |
| building | VARCHAR(10) | Épület |
| floor | VARCHAR(10) | Emelet |
| door | VARCHAR(10) | Ajtó |

**Cím összeállítás a PHP-ben:**

```text
$postcode $city, $streetname $publicplacecategory $number[. $staircase][. $building][. $floor/$door]
```

Az `addrFix()` metódus a végső cím stringre biztonsági hálóként fut.

#### `oc4023_doliBIT_company_address`
Összekapcsolás: company ↔ address.

| Mező | Típus | Leírás |
|------|-------|--------|
| company_id | INT FK | |
| address_id | INT FK | |
| addresstype_id | INT | 1=székhely, 2=telephely, 3=fióktelep |

#### `oc4023_doliBIT_fin_company_contact`
Kapcsolattartók/tisztségviselők.

| Mező | Típus | Leírás |
|------|-------|--------|
| contact_id | INT PK | |
| company_id | INT FK | |
| role_type | ENUM | officer / member / related / accountant / auditor |
| contact_name | VARCHAR(200) | Személy neve |
| contact_title | VARCHAR(100) | Tisztség (pl. ügyvezető) |
| contact_address | VARCHAR(300) | Cím (DB-ből VÁLTOZTATÁS NÉLKÜL jelenik meg) |
| contact_reg_number | VARCHAR(30) | PM regisztrációs szám (könyvelőnél) |
| can_represent | VARCHAR(30) | Képviseleti jog (pl. önálló) |
| voting_share_pct | DECIMAL(5,2) | Szavazati arány % |
| firm_name | VARCHAR(200) | Cég neve (könyvelő/könyvvizsgáló esetén) |
| is_related_party | TINYINT | Kapcsolt vállalkozás-e |
| related_party_note | TEXT | Jogállás megjegyzés (pl. „bizalmi vagyonkezelő") |
| sort_order | INT | Sorrend |
| status | TINYINT | 1=aktív |

### 2.2 Beszámoló táblák

#### `oc4023_doliBIT_fin_report`
Egy beszámoló = egy sor.

| Mező | Típus | Leírás |
|------|-------|--------|
| report_id | INT PK | (tesztadat: 1) |
| company_id | INT FK | (tesztadat: 13) |
| report_type_id | INT FK | 1=egyszerűsített éves |
| fiscal_year | INT | 2025 |
| date_from | DATE | 2025-01-01 |
| date_to | DATE | 2025-12-31 |

#### `oc4023_doliBIT_fin_report_config`
Beszámoló konfigurációs beállítások.

| Mező | Típus | Leírás | Forrás |
|------|-------|--------|--------|
| balance_date | VARCHAR(20) | Mérlegkészítés időpontja (pl. 2026.03.17.) | Űrlap: kézi |
| balance_location | VARCHAR(100) | Hely (pl. Budapest) | Űrlap: kézi |
| balance_sheet_type | VARCHAR(5) | Mérleg típus: A / B | Űrlap: választó |
| income_stmt_method | VARCHAR(20) | ER módszer: total_cost / sales_cost | Űrlap: választó |
| low_value_threshold | INT | Kisértékű eszköz határ eFt (alapértelmezés: 200) | Űrlap: kézi |
| significant_error_pct | DECIMAL(5,2) | Jelentős hiba % (alapértelmezés: 2) | Űrlap: kézi |
| significant_error_min_amt | DECIMAL(15,3) | Jelentős hiba min összeg eFt (alapértelmezés: 1000) | Űrlap: kézi |
| inventory_method | VARCHAR(10) | Készletértékelés: FIFO/LIFO/AVG/none | Űrlap: választó |
| inventory_continuous | TINYINT(1) | Van-e folyamatos nyilvántartás (0/1) | Űrlap: checkbox |
| depreciation_method | VARCHAR(50) | ÉCS módszer (pl. lineáris leírási módszerrel) | Űrlap: kézi |
| cost_class | VARCHAR(5) | Költség számlaosztály (5/6/7) | Űrlap: választó |
| fx_evaluation_method | VARCHAR(200) | Deviza átszámítás szöveg | Űrlap: kézi (ritkán módosul) |
| tax_type | VARCHAR(10) | Adónem: KIVA / TAO | Űrlap: választó |
| text_market | TEXT | Tevékenység szöveges bemutatása | Űrlap: szabad szöveg |
| text_accounting_policy | TEXT | Számviteli politika kiegészítő szöveg (NULL ha duplikáció) | Űrlap: szabad szöveg |
| text_valuation_change | TEXT | Értékelési változás szöveg | Űrlap: szabad szöveg |
| text_consolidation | TEXT | Konszolidáció szöveg (NINCS HASZNÁLVA – szekció törölve) | – |
| avg_headcount | DECIMAL(8,1) | Átlagos stat. létszám tárgyév (fallback) | Űrlap: kézi |
| avg_headcount_prev | DECIMAL(8,1) | Átlagos stat. létszám előző év (fallback) | Űrlap: kézi |

#### `oc4023_doliBIT_fin_statement_schema`
Mérleg/ER sor sablonok.

| Mező | Típus | Leírás |
|------|-------|--------|
| schema_row_id | INT PK | |
| code | VARCHAR(20) | BS_001..BS_109 (mérleg), IS_001..IS_047 (ER) |
| sort_order | INT | Megjelenési sorrend |
| level | INT | Mélység (0=fősor, 1=al-sor) |
| row_type | VARCHAR(20) | total / subtotal / item |
| statement_type_id | INT | 1=mérleg, 2=eredménykimutatás |

#### `oc4023_doliBIT_fin_statement_schema_description`
Sor megnevezések (nyelvi).

| Mező | Típus | Leírás |
|------|-------|--------|
| schema_row_id | INT FK | |
| language_id | INT | 1=magyar |
| name | VARCHAR(300) | Teljes törvényi megnevezés |
| name_short | VARCHAR(100) | Rövid megnevezés (összesítő táblákhoz) |

#### `oc4023_doliBIT_fin_statement_value`
Mérleg/ER értékek.

| Mező | Típus | Leírás |
|------|-------|--------|
| report_id | INT FK | |
| schema_row_id | INT FK | |
| value_previous_year | DECIMAL(15,3) | Előző év eFt |
| value_current_year | DECIMAL(15,3) | Tárgyév eFt |
| value_correction | DECIMAL(15,3) | Korrekció |

**Kulcs mérleg kódok (26 összesítő sor):**
BS_001 (Befektetett eszközök), BS_002 (Immat.javak), BS_010 (Tárgyi eszk.), BS_018 (Bef.pü.eszk.),
BS_029 (Forgóeszközök), BS_030 (Készletek), BS_037 (Követelések), BS_038 (Vevők), BS_039 (Kapcs.váll.köv.),
BS_043 (Egyéb köv.), BS_046 (Értékpapírok), BS_053 (Pénzeszközök), BS_054 (Pénztár), BS_055 (Bankbetétek),
BS_056 (AIE), BS_060 (Eszközök összesen), BS_061 (Saját tőke), BS_062..BS_071 (ST részletezés), BS_072 (Céltartalékok),
BS_076 (Kötelezettségek), BS_077 (Hátrasorolt), BS_082 (Hosszú lej.), BS_091 (Egyéb hosszú), BS_092 (Rövid lej.),
BS_097 (Szállítók), BS_099 (Kapcs.váll.köt.), BS_102 (Egyéb rövid), BS_105 (PIE), BS_109 (Források összesen)

**Kulcs ER kódok (14 összesítő + 3 bér):**
IS_003 (Árbevétel), IS_006 (Aktiv.saját), IS_007 (Egyéb bev.), IS_014 (Anyagj.ráf.), IS_015 (Bérköltség),
IS_016 (Szem.egyéb), IS_017 (Bérjárulékok), IS_018 (Személyi ráf.), IS_019 (ÉCS), IS_020 (Egyéb ráf.),
IS_022 (Üzemi eredm.), IS_033 (Pü.bev.), IS_043 (Pü.ráf.), IS_044 (Pü.eredm.), IS_045 (AEE), IS_046 (Adó), IS_047 (Adózott eredm.)

#### `oc4023_doliBIT_fin_asset_movement`
Befektetési tükör – bruttó érték és ÉCS mozgások.

| Mező | Típus | Leírás |
|------|-------|--------|
| report_id | INT PK/FK | |
| schema_row_id | INT PK/FK | BS_002-BS_017 sorok |
| gross_opening | DECIMAL(15,3) | Bruttó nyitó |
| gross_addition | DECIMAL(15,3) | Növekedés |
| gross_disposal | DECIMAL(15,3) | Csökkenés |
| gross_reclassification | DECIMAL(15,3) | Átsorolás |
| gross_closing | DECIMAL(15,3) | Záró |
| depr_opening | DECIMAL(15,3) | ÉCS nyitó |
| depr_current_year | DECIMAL(15,3) | Terv szerinti |
| depr_impairment | DECIMAL(15,3) | Terven felüli |
| depr_reversal | DECIMAL(15,3) | Kisértékű |
| depr_disposal | DECIMAL(15,3) | ÉCS csökkenés |
| depr_closing | DECIMAL(15,3) | ÉCS záró |

**Nettó érték:** PHP-ben számított: `gross_closing - depr_closing`
**Mindösszesen sor:** PHP-ben összegzett: `BS_002 + BS_010`

#### `oc4023_doliBIT_fin_line_item`
Egyéb követelések/kötelezettségek jogcímenkénti részletezése.

| Mező | Típus | Leírás |
|------|-------|--------|
| line_item_id | INT PK AUTO | |
| report_id | INT FK | |
| category | VARCHAR(30) | `egyeb_koveteles` vagy `egyeb_rovid_kot` |
| description | VARCHAR(300) | Jogcím megnevezése |
| amount | DECIMAL(15,3) | Összeg eFt |
| sort_order | INT | Sorrend |

**FONTOS:** Az amount összegnek egyeznie kell a megfelelő mérleg sorral:
- `egyeb_koveteles` összeg = BS_043 tárgyév
- `egyeb_rovid_kot` összeg = BS_102 tárgyév

#### `oc4023_doliBIT_fin_headcount_detail`
Létszám és béradatok állománycsoportonkénti bontása.

| Mező | Típus | Leírás |
|------|-------|--------|
| headcount_id | INT PK AUTO | |
| report_id | INT FK | |
| category | VARCHAR(30) | total / intellectual / physical / executive / supervisory |
| category_label | VARCHAR(100) | Megjelenítési név (pl. „Szellemi foglalkoztatottak") |
| avg_headcount | DECIMAL(8,1) | Tárgyévi létszám (fő) |
| avg_headcount_prev | DECIMAL(8,1) | Előző évi létszám (fő) |
| wage_cost | DECIMAL(15,3) | TÉ bérköltség eFt |
| wage_cost_prev | DECIMAL(15,3) | EÉ bérköltség eFt |
| personal_other | DECIMAL(15,3) | TÉ szem.jell. egyéb eFt |
| personal_other_prev | DECIMAL(15,3) | EÉ szem.jell. egyéb eFt |
| wage_contributions | DECIMAL(15,3) | TÉ bérjárulékok eFt |
| wage_contributions_prev | DECIMAL(15,3) | EÉ bérjárulékok eFt |
| personal_total | DECIMAL(15,3) | TÉ személyi ráfordítások összesen eFt |
| personal_total_prev | DECIMAL(15,3) | EÉ személyi ráfordítások összesen eFt |
| sort_order | INT | Sorrend |

**PDF megjelenítés:** CompTable stílusú 6 oszlopos tábla: Megnevezés | EÉ Fő | EÉ Szem.ráf. | TÉ Fő | TÉ Szem.ráf. | Vált.%

#### `oc4023_doliBIT_fin_tax_calculation`
KIVA/TAO adólevezetés tételei.

| Mező | Típus | Leírás |
|------|-------|--------|
| tax_calc_id | INT PK AUTO | |
| report_id | INT FK | |
| tax_type | VARCHAR(10) | KIVA / TAO |
| line_code | VARCHAR(20) | Bevallás sorszám (1-21 jelenik meg a táblában) |
| description | VARCHAR(300) | Tétel megnevezése |
| amount | DECIMAL(15,3) | Összeg eFt |
| line_type | VARCHAR(20) | item / subtotal / total / info |
| sort_order | INT | Sorrend |

**Megjelenítési szabály:** 1-21. sor → tábla; 22+ → nem jelenik meg.

---

## 3. PDF STRUKTÚRA (oldalankénti)

### 3.1 Borítólap (1. oldal)
- Cégnév, székhely, cégjegyzékszám, adószám, statisztikai szám
- „KIEGÉSZÍTŐ MELLÉKLET" cím
- „2025. évi egyszerűsített éves beszámolóhoz"
- Hely, dátum (`balance_location`, `balance_date`)
- „Ügyvezető aláírása"
- **Adatforrás:** company, address, report_config

### 3.2 I. ÁLTALÁNOS RÉSZ (2-4. oldal)

#### 1. A vállalkozás bemutatása
**a) Alapadatok** – InfoTable (3:9 arány, bold label)

| PDF mező | DB forrás | Megjegyzés |
|----------|-----------|------------|
| Vállalkozás neve | company.company_name_short | |
| Székhely | address.postcode + city + streetname + publicplacecategory + number... | addrFix() |
| Cégjegyzékszám | company.company_regnum | |
| Adószám | company.company_taxhu | |
| Statisztikai szám | company.company_statnum | |
| Alakulás időpontja | company.company_founded | `date('Y.m.d.')` formátum |
| Alakulás típusa | company.company_founded_type | |
| Társasági forma | company.company_legal_form + (GFO: XXX) | GFO kinyerve statnum-ból |
| Törzstőke nagysága | company.company_share_capital | `number_format` + „eFt" |
| Döntéshozó szerv | company.company_decision_body | PHP felülírja: Kft→Taggyűlés |
| Fő tevékenység | company.company_activity_code + activity_name | |

**Vállalkozás folytatásának elve** – statikus szöveg (kódban hardkódolva)

**b) Tulajdonosi szerkezet** – `company_contact WHERE role_type='member'`

| PDF mező | DB forrás |
|----------|-----------|
| Tulajdonos neve | contact_name |
| Cím | contact_address (VÁLTOZTATÁS NÉLKÜL) |
| Jogállás | related_party_note (pl. „bizalmi vagyonkezelő") |
| Szavazati arány | voting_share_pct + „%" |

**c) Tevékenység bemutatása** – `report_config.text_market`

**d) Képviseletre jogosultak** – `company_contact WHERE role_type='officer'`

| PDF mező | DB forrás |
|----------|-----------|
| Név és cím | contact_name + contact_address |
| Tisztség | contact_title |
| Képviseleti jog | can_represent |

**Hivatkozás:** Sztv. 154.§

#### 2. Kapcsolt vállalkozások
`company_contact WHERE role_type IN ('member','related')`
- Név + cím megjelenítés
- Member: „A kapcsolt felek ügyleteik során a szokásos piaci árat alkalmazzák."
- Related: `related_party_note` szöveg

#### 3. Általános számviteli információk

**a) Számviteli politika** – InfoTable + szöveges bekezdések

| PDF mező | DB forrás | Megjegyzés |
|----------|-----------|------------|
| Beszámoló formája | Hardkódolt | „Egyszerűsített éves beszámoló" |
| Mérleg típusa | report_config.balance_sheet_type | A→'"A" változat', B→'"B" változat' |
| Eredménykimutatás | report_config.income_stmt_method | total_cost→'Összköltség eljárással' |
| Beszámoló időszaka | report.date_from + date_to | |
| Mérleg fordulónapja | report.date_to | |
| Mérlegkészítés időpontja | report_config.balance_date | `rtrim(., '.')` dupla pont ellen |

**Szöveges bekezdések (kódban hardkódolva, DB konfigból paraméterezve):**
- Könyvvezetés nyelve, pénzneme
- Készletek nyilvántartása (`inventory_continuous` → leltár / FIFO)
- Analitikus nyilvántartások
- Külföldi pénzérték átszámítás
- Jelentős hiba definíció (`significant_error_pct`, `significant_error_min_amt`)
- Lényeges hiba definíció

**b) Könyvviteli szolgáltatók** – `company_contact WHERE role_type='accountant'`

**c) Könyvvizsgálók** – `company_contact WHERE role_type='auditor'`

**d) Értékcsökkenés** – szöveges (kódban hardkódolva, `low_value_threshold` paraméterből)

### 3.3 II. SPECIÁLIS RÉSZ (5-12. oldal)

#### 1. Mérleghez kapcsolódó kiegészítések

**Mérlegadatok változása** – SummaryTable (26 összesítő sor)
- **Adatforrás:** `getStatementRowsByCodes(report_id, $bs_summary_codes)`
- Oszlopok: Megnevezés | Előző év | Tárgyév | Változás | Vált.%

**a) Eszközadatok**
- Eszközök összetétele – CompTable (`BS_001`, `BS_029`, `BS_056` / `BS_060`)
- Befektetett eszközök összetétele – CompTable (`BS_002`, `BS_010`, `BS_018` / `BS_001`)
  - **Szöveges alatta:** ÉCS változás, maradványérték, immat.javak, bef.pü.eszk.
- **Befektetési tükör** – Landscape, 14 oszlop, `getAssetMovement()`
- Forgóeszközök összetétele – CompTable (`BS_030`, `BS_037`, `BS_046`, `BS_053` / `BS_029`)
  - **Szöveges alatta:** értékvesztés, értékhelyesbítés, valós értékelés, vevő köv., kapcs.váll.köv., értékpapír, pénzeszközök bontás, környezetvédelem
- Követelések részletezése – CompTable (`BS_038-BS_045` / `BS_037`)
- Egyéb követelések – InfoTable (8:4 arány, NEM bold, jobbra igazított, eFt nélkül)
  - **Adatforrás:** `getLineItems(report_id, 'egyeb_koveteles')`
- AIE összetétele – CompTable (`BS_057-BS_059` / `BS_056`)
  - **Szöveges:** „Aktív időbeli elhatárolás elszámolására [összeg] eFt összegben került sor."

**b) Forrásadatok**
- Források összetétele – CompTable (`BS_061`, `BS_072`, `BS_076`, `BS_105` / `BS_109`)
  - **Szöveges:** céltartalék
- Saját tőke összetétele – CompTable (`BS_062-BS_071` / `BS_061`)
  - **Szöveges:** tőkeváltozás összeg + indoklás
- Kötelezettségek összetétele – CompTable (`BS_077`, `BS_082`, `BS_092` / `BS_076`)
  - **Szöveges:** hátrasorolt köt.
- Hosszú lej. köt. részletezése – CompTable (`BS_083-BS_091` / `BS_082`)
  - **Szöveges:** lízing, törlesztés átsorolás
- Rövid lej. köt. részletezése – CompTable (`BS_093-BS_104` / `BS_092`)
  - **Szöveges:** szállítói köt., kapcs.váll.köt.
- Egyéb rövid lej. köt. – InfoTable (8:4 arány, NEM bold, jobbra igazított)
  - **Adatforrás:** `getLineItems(report_id, 'egyeb_rovid_kot')`
- PIE összetétele – CompTable (`BS_106-BS_108` / `BS_105`)
  - **Szöveges:** PIE + **Sztv. 90.§ (7) mérlegen kívüli tételek**

#### 2. Eredménykimutatáshoz kapcsolódó kiegészítések
- ER változás – SummaryTable (14 sor)
  - **Szöveges:** exporttámogatás, dotáció, K+F, **Sztv. 88.§ (4a) kivételes tételek**
- Költségszerkezet – CompTable (`IS_014`, `IS_018`, `IS_019`, `IS_020`)
- Eredménykategóriák arányai – ErCategoryTable (üzemi eredmény bázisú)

#### 3. Vagyoni, pénzügyi, jövedelmi elemzés
Minden alszekció: bevezető szöveg + AnalysisTable (6 oszlop: Mutató+képlet | EÉ eFt | EÉ % | TÉ eFt | TÉ % | Vált.%)

**a) Vagyoni helyzet** – 5 mutató (bef.eszk.arány, forgóeszk.arány, tőkeerősség, eladósodottság, fedezettség)
**b) Pénzügyi helyzet** – 2 mutató (ST/IT, nettó forgótőke)
**c) Likviditás** – 3 mutató (likv.ráta, gyorsráta, pénzhányad)
**d) Jövedelmezőség** – 4 mutató (árbev.arányos üzemi, árbev.arányos adózott, ROE, ROA)

### 3.4 Tájékoztató adatok (13-15. oldal)

#### 4. Tájékoztató adatok

**a) Munkavállalók létszám- és béradatai**
- CompTable stílusú 6 oszlop: Megnevezés | EÉ Fő | EÉ Szem.ráf. | TÉ Fő | TÉ Szem.ráf. | Vált.%
- **Adatforrás:** `getHeadcountDetail(report_id)`
- Fallback: `getHeadcount()` ha nincs részletezés

**b) Adófizetési kötelezettség – KIVA/TAO levezetés**
- 3 oszlopos tábla: Sor | Megnevezés | Összeg
- **Adatforrás:** `getTaxCalculation(report_id, 'KIVA')`
- Sorok 1-21 jelennek meg, 22+ kihagyva

---

## 4. HELPER METÓDUSOK (`annualtest.php` trait)

| Metódus | Leírás |
|---------|--------|
| `u($text)` | UTF-8 → CP1250 konverzió (iconv) |
| `addrFix($addr)` | Utcanév title case + közterület típus kisbetű |
| `pdfFooter($pdf,$pw,$C,$companyName,$footerSub)` | 2 soros lábléc: cégnév + oldalszám / beszámoló alcím |
| `pdfFooterLandscape(...)` | Ugyanaz landscape-hoz |
| `pdfChapterTitle(...)` | „I. ÁLTALÁNOS RÉSZ" stílusú fejezet cím |
| `pdfSection(...)` | „1. A vállalkozás bemutatása" stílusú szekció cím |
| `pdfSubsection(...)` | „a) Alapadatok" stílusú alszekció cím (italic) |
| `pdfText(...)` | Szöveg bekezdés (8.5pt, normál) |
| `pdfEftLabel(...)` | „adatok eFt-ban" jobb felső sarok |
| `pdfInfoTable(...,$labelRatio,$valueAlign,$labelBold)` | Kulcs-érték párok (3:9/5:7/8:4 arány) |
| `pdfSummaryTable(...)` | Mérleg/ER összesítő (5:1.75:1.75:1.75:maradék) |
| `pdfCompTable(...)` | Összetétel tábla 2 soros fejléccel (5:1.4:1.15:1.4:1.15:maradék) |
| `pdfAnalysisTable(...)` | Mutatók tábla 2 soros + képlet (azonos CompTable arány) |
| `pdfErCategoryTable(...)` | Eredménykategóriák (5:1.75:1.75:1.75:1.75) |
| `pdfRatioTable(...)` | Régi mutató tábla (3.5:1.5:1.5:5.5) – megtartva kompatibilitásra |
| `pdfLineItemTable(...)` | Régi jogcím tábla (9:3) – nem használt, InfoTable váltotta |
| `pdfAssetMovementTable(...)` | Befektetési tükör (landscape, 14 oszlop, 3 soros fejléc) |

### 4.1 Szín paletta (`$C` tömb)

```php
$C = [
    'pri'   => [41,65,94],      // Fejezet/fejléc szín (sötétkék)
    'acc'   => [70,130,180],    // Kiemelés
    'txt'   => [50,50,50],      // Normál szöveg
    'mut'   => [120,120,120],   // Halvány szöveg (lábléc, képlet)
    'ln'    => [200,200,200],   // Vonalak
    'hdr'   => [235,241,247],   // Tábla fejléc háttér
    'zebra' => [248,248,248],   // Zebra csík
    'total' => [228,236,244],   // Összesítő sor háttér
];
```

---

## 5. ÚJ VÁLLALKOZÁS FELVÉTELE – SZÜKSÉGES ADATOK

### 5.1 Törzsadat (egyszeri beállítás)

| Adat | Tábla | Kötelező | Megjegyzés |
|------|-------|----------|------------|
| Cégnév (rövid + teljes) | company | ✅ | |
| Adószám | company | ✅ | |
| Cégjegyzékszám | company | ✅ | |
| Statisztikai szám | company | ✅ | GFO kód innen számolódik |
| Társasági forma | company | ✅ | |
| Alakulás dátuma + típusa | company | ✅ | |
| Törzstőke | company | ✅ | eFt-ban |
| Fő tevékenység kód + név | company | ✅ | |
| Székhely cím | address + company_address | ✅ | Postcode, city, streetname, publicplacecategory, number stb. |
| Tulajdonos(ok) | company_contact (member) | ✅ | Név, cím, szavazati arány, bizalmi vagyonkezelő flag |
| Ügyvezető(k) | company_contact (officer) | ✅ | Név, cím, tisztség, képviseleti jog |
| Kapcsolt vállalkozások | company_contact (related) | Opcionális | Név, cím, piaci ár szöveg |
| Könyvelő | company_contact (accountant) | ✅ | Név, cég, PM szám, cím |
| Könyvvizsgáló | company_contact (auditor) | Opcionális | Csak ha kötelezett |

### 5.2 Beszámoló konfiguráció (évente)

| Adat | Tábla.mező | Kötelező | Megjegyzés |
|------|------------|----------|------------|
| Mérleg típus (A/B) | report_config.balance_sheet_type | ✅ | |
| ER módszer | report_config.income_stmt_method | ✅ | total_cost / sales_cost |
| Mérlegkészítés dátuma | report_config.balance_date | ✅ | pl. „2026.03.17." |
| Hely | report_config.balance_location | ✅ | pl. „Budapest" |
| Kisértékű határ | report_config.low_value_threshold | ✅ | Alapértelmezés: 200 eFt |
| Jelentős hiba % | report_config.significant_error_pct | ✅ | Alapértelmezés: 2% |
| Adónem | report_config.tax_type | ✅ | KIVA vagy TAO |
| Készlet nyilvántartás | report_config.inventory_continuous | ✅ | 0/1 |
| Tevékenység szöveg | report_config.text_market | Opcionális | Szöveges leírás |

### 5.3 Beszámoló adatok (a könyvelésből)

| Adat | Tábla | Forrás |
|------|-------|--------|
| Mérleg sorok (`BS_001-BS_109`) | statement_value | Főkönyvi kivonatból |
| ER sorok (`IS_001-IS_047`) | statement_value | Főkönyvi kivonatból |
| Befektetési tükör mozgások | asset_movement | Eszköznyilvántartásból |
| Egyéb követelések jogcímei | line_item (`egyeb_koveteles`) | Analitikából |
| Egyéb rövid köt. jogcímei | line_item (`egyeb_rovid_kot`) | Analitikából |
| Létszám + béradatok | headcount_detail | Bérszámfejtésből / KIVA bevallás |
| KIVA/TAO sorok | tax_calculation | Adóbevallásból |

---

## 6. ISMERT KORLÁTOZÁSOK ÉS DÖNTÉSEK

1. **GFO kód:** Nincs külön DB mező – statisztikai számból van kinyerve regex-szel.
2. **Döntéshozó szerv:** PHP felülírja DB értéket Kft→Taggyűlés, Zrt/Nyrt→Közgyűlés alapján.
3. **Kontakt címek:** NEM `addrFix()`-en mennek át – a DB-ből változtatás nélkül jelennek meg.
4. **Székhely cím:** `addrFix()` alkalmazva (utca/út/tér kisbetű, title case).
5. **Konszolidáció szekció:** Törölve – egyszerűsített éves beszámolónál nem releváns.
6. **f) Általános mutatók:** Törölve – nem szükséges.
7. **text_accounting_policy:** Szűrve – ha tartalmazza az „Analitikus" vagy „készletek" szót, nem íródik ki (duplikáció megelőzés).
8. **0/0 arány:** CompTable-ben ha nevező=0 ÉS érték=0 → üres cella (nem „0,00").
9. **Összesítő sor Arány%:** „100,00" csak ha a total > 0.
10. **KIVA tábla:** 1-21 sor jelenik meg, 22+ (előleg, fizetendő) kihagyva.
11. **PDF lábléc:** 2 soros – 1: cégnév / oldalszám; 2: „Kiegészítő melléklet a XXXX. évi egyszerűsített éves beszámolóhoz"
12. **Fedlap:** Dátum és aláírás itt van – a PDF végén NINCS záró blokk.

---

## 7. SZTV. MEGFELELÉS (egyszerűsített éves beszámoló)

### Sztv. 96.§ (4) – kötelező tartalom

| § hivatkozás | Tartalom | PDF szekció | Státusz |
|---|---|---|---|
| 88.§ (4) | Számviteli politika fő vonásai | I/3/a | ✅ |
| 88.§ (4a) | Kivételes nagyságú tételek nyilatkozat | II/2 szöveg | ✅ |
| 88.§ (5) | Értékelési szabályrendszer | I/3/d | ✅ |
| 89.§ (4) b) | Vezető tisztségviselők előleg/kölcsön/garancia | 4/a szöveg | ✅ |
| 89.§ (6) | Saját tőke alakulása | II/1/b | ✅ |
| 90.§ (2) | Befektetési tükör (bruttó/ÉCS/nettó) | II/1/a landscape | ✅ |
| 90.§ (3) a-c) | Követelések/kötelezettségek részletezés | II/1/a-b | ✅ |
| 90.§ (7) | Mérlegen kívüli tételek | II/1/b PIE után | ✅ |
| 90.§ (9) a-e),g) | Pénzügyi helyzet bemutatása | II/1 szöveges + II/3 | ✅ |
| 91.§ a) | Átlagos statisztikai létszám | 4/a tábla | ✅ |

**Eredmény: 10/10 kötelező elem teljesítve.**
