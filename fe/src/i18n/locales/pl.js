/**
 * Polski. Nazwy z .ai-factory/references/karak-glossary.md (oficjalna instrukcja Albi Polska).
 * Liczba mnoga: „one | few | many” (1 ruch · 2 ruchy · 5 ruchów).
 */
export default {
  common: {
    appName: 'Karak',
    hp: 'PW',
    you: 'Ty',
    ai: 'SI',
    player: 'Gracz',
    cancel: 'Anuluj',
    close: 'Zamknij',
    confirm: 'Potwierdź',
    skip: 'Pomiń',
    unknown: 'Nieznane',
    language: 'Język',
    languages: { en: 'English', ru: 'Русский', pl: 'Polski' },
    moves: '{n} ruch | {n} ruchy | {n} ruchów',
    hpCount: '{n} PW | {n} PW | {n} PW'
  },
  routes: {
    home: 'Start',
    game: 'Gra',
    leaderboard: 'Ranking',
    rules: 'Zasady',
    notFound: '404 — nie znaleziono'
  },
  monsters: {
    giant_rat: { name: 'Ogromny szczur', nom: 'ogromny szczur', acc: 'ogromnego szczura', ins: 'ogromnym szczurem' },
    giant_spider: { name: 'Ogromny pająk', nom: 'ogromny pająk', acc: 'ogromnego pająka', ins: 'ogromnym pająkiem' },
    mummy: { name: 'Mumia', nom: 'mumia', acc: 'mumię', ins: 'mumią' },
    skeleton_turnkey: { name: 'Szkieletor klucznik', nom: 'szkieletor klucznik', acc: 'szkieletora klucznika', ins: 'szkieletorem klucznikiem' },
    skeleton_warrior: { name: 'Szkieletor wojownik', nom: 'szkieletor wojownik', acc: 'szkieletora wojownika', ins: 'szkieletorem wojownikiem' },
    skeleton_king: { name: 'Szkieletor król', nom: 'szkieletor król', acc: 'szkieletora króla', ins: 'szkieletorem królem' },
    fallen: { name: 'Upadły', nom: 'Upadły', acc: 'Upadłego', ins: 'Upadłym' },
    dragon: { name: 'Smok', nom: 'smok', acc: 'smoka', ins: 'smokiem' },
    giant_bat: { name: 'Ogromny nietoperz', nom: 'ogromny nietoperz', acc: 'ogromnego nietoperza', ins: 'ogromnym nietoperzem' },
    skeleton_mage: { name: 'Lodowy szkieletor', nom: 'lodowy szkieletor', acc: 'lodowego szkieletora', ins: 'lodowym szkieletorem' }
  },
  heroes: {
    none: { name: 'Poszukiwacz przygód', short: 'Poszukiwacz', role: 'poszukiwacz przygód' },
    warrior: { name: 'Horan, wojownik', short: 'Horan', role: 'wojownik' },
    thief: { name: 'Aderyn, złodziejka', short: 'Aderyn', role: 'złodziejka' },
    swordsman: { name: 'Victorious, mistrz miecza', short: 'Victorious', role: 'mistrz miecza' },
    warlock: { name: 'Lord Xanros, czarnoksiężnik', short: 'Xanros', role: 'czarnoksiężnik' },
    wizard: { name: 'Argentus, czarodziej', short: 'Argentus', role: 'czarodziej' },
    oracle: { name: 'Taia, wyrocznia', short: 'Taia', role: 'wyrocznia' },
    barbarian: { name: 'Valduk, barbarzyńca', short: 'Valduk', role: 'barbarzyńca' },
    battle_mage: { name: 'Baron Markul, waleczny mag', short: 'Markul', role: 'waleczny mag' },
    acrobat: { name: 'Hannah, akrobatka', short: 'Hannah', role: 'akrobatka' },
    ranger: { name: 'Lady Lorraine, pogranicznik', short: 'Lorraine', role: 'pogranicznik' },
    lord_karak: { name: 'Władca Karaku', short: 'Władca Karaku', role: 'Władca Karaku' }
  },
  items: {
    dagger: { nom: 'Sztylet', acc: 'sztylet' },
    sword: { nom: 'Miecz', acc: 'miecz' },
    axe: { nom: 'Topór', acc: 'topór' },
    fireball: { nom: 'Magiczny pocisk', acc: 'magiczny pocisk' },
    teleport: { nom: 'Portal uzdrowienia', acc: 'portal uzdrowienia' },
    key: { nom: 'Klucz', acc: 'klucz' },
    chest: { nom: 'Skrzynia', acc: 'skrzynię' },
    treasure: { nom: 'Skarb', acc: 'skarb' },
    ruby_chest: { nom: 'Smoczy rubin', acc: 'smoczy rubin' },
    dark_thorn: { nom: 'Kolec ciemności', acc: 'kolec ciemności' },
    frost_fist: { nom: 'Mrożąca pięść', acc: 'mrożącą pięść' },
    unknown: { nom: 'Przedmiot', acc: 'przedmiot' }
  },
  skills: {
    double_attack: { name: 'Podwójny atak', description: 'W dowolnej walce wojownik może ponownie rzucić kośćmi, jeśli pierwszy rzut mu nie odpowiada. Po pierwszej porażce nie traci PW, ale musi przyjąć wynik drugiego rzutu.' },
    reincarnation: { name: 'Reinkarnacja', description: 'Jeśli wojownik straci ostatni PW, nie traci przytomności, lecz przenosi się na dowolną płytkę z uzdrowicielską fontanną i odzyskuje wszystkie PW. Jego tura i tak się kończy.' },
    backstab: { name: 'Zdradziecki cios', description: 'Złodziejka atakuje z cienia: wygrywa każdą walkę, która normalnie zakończyłaby się remisem.' },
    stealth: { name: 'Maskowanie', description: 'Złodziejka porusza się w cieniu: wchodząc na płytkę z potworem, może z nim walczyć albo prześlizgnąć się obok i iść dalej.' },
    combat_training: { name: 'Szkolenie bojowe', description: 'Mistrz miecza to doświadczony wojownik, którego uderzenia zawsze trafiają w cel: kość, na której wypadnie „1”, jest przerzucana tyle razy, ile trzeba.' },
    unstoppable: { name: 'Niepowstrzymany', description: 'Jeśli podczas walki na co najmniej jednej kości wypadnie „6”, mistrz miecza po walce kontynuuje turę. Może nawet ponownie walczyć z tym samym potworem po remisie lub porażce.' },
    sacrifice: { name: 'Ofiara', description: 'Czarnoksiężnik może poświęcić 1 PW, aby otrzymać premię +1 do siły. Decyduje po rzucie kośćmi, premię otrzymuje raz na walkę. Może poświęcić nawet ostatni PW, ale wtedy traci przytomność na swoją kolejną turę.' },
    magic_swap: { name: 'Magiczna zamiana', description: 'Czarnoksiężnik może zamienić się miejscami z dowolnym innym bohaterem. Zużywa to 4 ruchy, więc jest możliwe tylko na początku jego tury; nadal może użyć płytki, na której się znalazł (otworzyć skrzynię, wyleczyć się itp.).' },
    magical_affinity: { name: 'Magia we krwi', description: 'Czarodziej skuteczniej używa magicznych pocisków: jego zaklęcia magiczny pocisk nie zużywają się w walce. Nie dotyczy to portalu uzdrowienia.' },
    astral_walking: { name: 'Spacer astralny', description: 'Czarodziej może przechodzić przez ściany, więc nie musi trzymać się korytarzy podziemi. Działa tylko przy ruchu po odkrytych płytkach.' },
    farseeing: { name: 'Przewidywanie', description: 'Wyrocznia doskonale zna swoich przeciwników: jeśli walczy w pierwszym ruchu swojej tury, otrzymuje premię +1 do rzutu.' },
    fateweaver: { name: 'Tkaczka losu', description: 'Wyrocznia może wpływać na przyszłość: ilekroć ma wylosować żeton z woreczka, losuje dwa, jeden kładzie na płytce, a drugi odkłada do woreczka.' },
    berserk: { name: 'Berserk', description: 'Im słabszy barbarzyńca, tym mocniej uderza: przy dokładnie 3–4 PW ma +1 do siły, przy 1–2 PW +2. Lecząc się, sam wybiera, ile PW odzyskać.' },
    tireless: { name: 'Wytrwałość', description: 'Jeśli barbarzyńca pokona potwora i nie weźmie łupu, może wykorzystać pozostałe ruchy.' },
    sword_master: { name: 'Mistrz miecza', description: 'W rękach walecznego maga miecz ma siłę 3.' },
    lightning_attack: { name: 'Błyskawiczny atak', description: 'Za wszystkie 4 ruchy waleczny mag teleportuje się na dowolną płytkę z potworem i walczy z nim. Jeśli nie wygra, sam wybiera, dokąd się wycofać — tylko na już odkrytą płytkę.' },
    dagger_throwing: { name: 'Noże do rzucania', description: 'Akrobatka może trzymać sztylety (siła 1) w miejscach na zaklęcia. Każdy sztylet daje +1 do siły i nigdy się nie zużywa.' },
    sprint: { name: 'Sprint', description: 'Zamiast zwykłego ruchu akrobatka może na początku tury ogłosić sprint: do 8 ruchów, ale bez wchodzenia na nieodkryte płytki.' },
    bear_attack: { name: 'Atak niedźwiedzia', description: 'Pogranicznik walczy razem ze swoim niedźwiedziem: „1” na kości liczy się jak „6”.' },
    listening: { name: 'Nasłuch', description: 'Za jeden ruch pogranicznik może odkryć sąsiednią płytkę (położyć ją i wylosować żeton), nie wchodząc na nią. Wejście później kosztuje kolejny ruch. W jednej turze może nasłuchiwać wielokrotnie.' }
  }
};
