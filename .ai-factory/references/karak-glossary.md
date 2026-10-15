# Karak glossary: en / ru / pl

The only source of names for `fe/src/i18n/locales/{ru,pl}.js`. Researched on 2026-10-15.

Sources:
- **PL base** (official, Albi Polska): https://albipolska.pl/data/files/products/38500/1615211831-karak_instrukcja_pl.pdf
- **PL Regent** (official, Albi Polska): https://albipolska.pl/data/files/products/38288/1645803575-k-regent_pravidla-pl-web.pdf
- **RU base** (official, «Звезда», title «Приключения в замке Карак»): https://www.tesera.ru/images/items/2163359/8873_Karak_Pravila.pdf
- **RU Regent**: no Russian edition was found (tesera hosts the same English scan as `Karak Regent.pdf` in the repo). Russian Regent terms are **ours**.

Marks: **off** = official edition term; **ours** = our translation (no official term found, or the official one does not fit the UI).
Forms for monsters and items: `nom` / `acc` (direct object: "defeats X") / `ins` (ru «бой с X», pl «walka z X»).

## Game and terms

| key | en | ru | pl |
|---|---|---|---|
| game | Karak | Карак (off; edition title «Приключения в замке Карак») | Karak (off) |
| HP | HP | ОЗ, «очки здоровья» (ours; the edition says «жетон здоровья») | PW, «punkty wytrzymałości» (off) |
| victory points | victory points | очки победы, ОП (ours) | PZ, «punkty zwycięstwa» (off) |
| healing fountain | healing fountain | целебный фонтан (off) | uzdrowicielska fontanna (off) |
| teleport gate | teleport gate | портал (off) | brama teleportacji (off) |
| chest | chest | сундук (off) | skrzynia (off) |
| treasure | treasure | сокровище (off) | skarb (off) |
| Curse of Karak | Curse of Karak | проклятие Карака (off) | klątwa Karak (off) |
| Battle Arena | Battle Arena | арена (ours) | arena walki (off) |
| Cursed Room | Cursed Room | проклятая комната (ours) | przeklęta komnata (off) |
| Lord Karak (variant/hero) | Lord Karak | Владыка Карака (ours) | Władca Karaku (off) |
| move | move | шаг (off) | ruch (off) |
| turn | turn | ход (off) | tura (off) |
| dragon's ruby | dragon's ruby | рубин дракона (off) | smoczy rubin (off) |

## Heroes

| key | en name / short / role | ru | pl |
|---|---|---|---|
| warrior | Horan, the Warrior / Horan / the Warrior | Хоран, воин / Хоран / воин (off: «Воин Хоран») | Horan, wojownik / Horan / wojownik (off) |
| thief | Aderyn, the Thief | Адерин, разбойница (off: «Разбойница Адерин») | Aderyn, złodziejka (off) |
| swordsman | Victorius, the Swordsman | Викториус, мечник (off) | Victorious, mistrz miecza (off; the PL edition spells "Victorious") |
| warlock | Lord Xanros, the Warlock | Лорд Ксанрос, чернокнижник (off) | Lord Xanros, czarnoksiężnik (off) |
| wizard | Argentus, the Wizard | Аргентус, волшебник (off) | Argentus, czarodziej (off) |
| oracle | Taia, the Oracle | Тайя, оракул (off) | Taia, wyrocznia (off) |
| barbarian | Valduk, the Barbarian | Валдук, варвар (ours) | Valduk, barbarzyńca (off) |
| battle_mage | Baron Markul, the Battle Mage | Барон Маркул, боевой маг (ours) | Baron Markul, waleczny mag (off) |
| acrobat | Hanna, the Acrobat | Ханна, акробатка (ours) | Hannah, akrobatka (off; PL spells "Hannah") |
| ranger | Lady Lorraine, the Ranger | Леди Лоррейн, следопыт (ours) | Lady Lorraine, pogranicznik (off) |
| lord_karak | Lord Karak | Владыка Карака (ours) | Władca Karaku (off) |
| none | Adventurer | Искатель приключений (ours) | Poszukiwacz przygód (ours) |

## Skills (key = backend `HeroType::skills()[].key`)

| key | en | ru | pl |
|---|---|---|---|
| double_attack | Double Attack | Двойная атака (off) | Podwójny atak (off) |
| reincarnation | Reincarnation | Воскрешение (off) | Reinkarnacja (off) |
| backstab | Backstab | Удар в спину (off) | Zdradziecki cios (off) |
| stealth | Stealth | Тихие шаги (off) | Maskowanie (off) |
| combat_training | Combat Training | Боевая подготовка (off) | Szkolenie bojowe (off) |
| unstoppable | Unstoppable | Неудержимый (off) | Niepowstrzymany (off) |
| sacrifice | Sacrifice | Жертва (off) | Ofiara (off) |
| magic_swap | Magic Swap | Волшебный обмен (off) | Magiczna zamiana (off) |
| magical_affinity | Magical Affinity | Волшебный дар (off) | Magia we krwi (off) |
| astral_walking | Astral Walking | Астральная прогулка (off) | Spacer astralny (off) |
| farseeing | Farseeing | Дальновидность (off) | Przewidywanie (off) |
| fateweaver | Fateweaver | Ткач судьбы (off) | Tkaczka losu (off) |
| berserk | Berserk | Берсерк (ours) | Berserk (off) |
| tireless | Tireless | Неутомимый (ours) | Wytrwałość (off) |
| sword_master | Sword Master | Мастер меча (ours) | Mistrz miecza (off) |
| lightning_attack | Lightning Attack | Молниеносная атака (ours) | Błyskawiczny atak (off) |
| dagger_throwing | Dagger Throwing | Метание кинжалов (ours) | Noże do rzucania (off) |
| sprint | Sprint | Спринт (ours) | Sprint (off) |
| bear_attack | Bear Attack | Медвежья атака (ours) | Atak niedźwiedzia (off) |
| listening | Listening | Подслушивание (ours) | Nasłuch (off) |

## Monsters (nom / acc / ins)

| key | en | ru | pl |
|---|---|---|---|
| giant_rat | Giant Rat | гигантская крыса / гигантскую крысу / гигантской крысой (off) | ogromny szczur / ogromnego szczura / ogromnym szczurem (off) |
| giant_spider | Giant Spider | огромный паук / огромного паука / огромным пауком (off) | ogromny pająk / ogromnego pająka / ogromnym pająkiem (off) |
| mummy | Mummy | мумия / мумию / мумией (off) | mumia / mumię / mumią (off) |
| skeleton_turnkey | Skeleton Turnkey | скелет-ключник / скелета-ключника / скелетом-ключником (off) | szkieletor klucznik / szkieletora klucznika / szkieletorem klucznikiem (off) |
| skeleton_warrior | Skeleton Warrior | скелет-воин / скелета-воина / скелетом-воином (off) | szkieletor wojownik / szkieletora wojownika / szkieletorem wojownikiem (off) |
| skeleton_king | Skeleton King | король-скелет / короля-скелета / королём-скелетом (off) | szkieletor król / szkieletora króla / szkieletorem królem (off) |
| fallen | Fallen | падший / падшего / падшим (off) | Upadły / Upadłego / Upadłym (off) |
| dragon | Dragon | дракон / дракона / драконом (off) | smok / smoka / smokiem (off) |
| giant_bat | Giant Bat | гигантская летучая мышь / гигантскую летучую мышь / гигантской летучей мышью (ours) | ogromny nietoperz / ogromnego nietoperza / ogromnym nietoperzem (off) |
| skeleton_mage | Skeleton Mage | скелет-маг / скелета-мага / скелетом-магом (ours; the token is the "ice skeleton") | lodowy szkieletor / lodowego szkieletora / lodowym szkieletorem (off) |

Display names start with a capital letter in titles and lists ("Гигантская крыса", "Ogromny szczur"); sentence forms stay lowercase in ru/pl except "Upadły".

## Items and spells (nom / acc)

| key | en | ru | pl |
|---|---|---|---|
| dagger | Dagger | кинжал / кинжал (off; the edition says «кинжалы») | sztylet / sztylet (off; «sztylety») |
| sword | Sword | меч / меч (off) | miecz / miecz (off) |
| axe | Axe | топор / топор (off) | topór / topór (off) |
| fireball | Fireball | магический заряд / магический заряд (off) | magiczny pocisk / magiczny pocisk (off) |
| teleport | Portal of Healing | целебный портал / целебный портал (off) | portal uzdrowienia / portal uzdrowienia (off) |
| key | Key | ключ / ключ (off) | klucz / klucz (off) |
| chest | Chest | сундук / сундук (off) | skrzynia / skrzynię (off) |
| treasure | Treasure | сокровище / сокровище (off) | skarb / skarb (off) |
| ruby_chest | Ruby | рубин дракона / рубин дракона (off) | smoczy rubin / smoczy rubin (off) |
| dark_thorn | Dark Thorn | тёмный шип / тёмный шип (ours) | kolec ciemności / kolec ciemności (off) |
| frost_fist | Frost Fist | ледяной кулак / ледяной кулак (ours) | mrożąca pięść / mrożącą pięść (off) |
