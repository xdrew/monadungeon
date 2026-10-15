/**
 * English — the source locale. Texts the e2e matches stay exactly as they are
 * (Turn: N, End Turn, Pick Up, Leave It, Processing..., …): see README "Localization".
 * Plurals: "one | other". Names: .ai-factory/references/karak-glossary.md.
 */
export default {
  common: {
    appName: 'Karak',
    hp: 'HP',
    you: 'You',
    ai: 'AI',
    player: 'Player',
    cancel: 'Cancel',
    close: 'Close',
    confirm: 'Confirm',
    skip: 'Skip',
    unknown: 'Unknown',
    language: 'Language',
    languages: { en: 'English', ru: 'Русский', pl: 'Polski' },
    moves: '{n} move | {n} moves',
    hpCount: '{n} HP | {n} HP'
  },
  routes: {
    home: 'Home',
    game: 'Game',
    leaderboard: 'Leaderboard',
    rules: 'Rules',
    notFound: '404 Not Found'
  },
  monsters: {
    giant_rat: { name: 'Giant Rat', nom: 'Giant Rat', acc: 'Giant Rat', ins: 'Giant Rat' },
    giant_spider: { name: 'Giant Spider', nom: 'Giant Spider', acc: 'Giant Spider', ins: 'Giant Spider' },
    mummy: { name: 'Mummy', nom: 'Mummy', acc: 'Mummy', ins: 'Mummy' },
    skeleton_turnkey: { name: 'Skeleton Turnkey', nom: 'Skeleton Turnkey', acc: 'Skeleton Turnkey', ins: 'Skeleton Turnkey' },
    skeleton_warrior: { name: 'Skeleton Warrior', nom: 'Skeleton Warrior', acc: 'Skeleton Warrior', ins: 'Skeleton Warrior' },
    skeleton_king: { name: 'Skeleton King', nom: 'Skeleton King', acc: 'Skeleton King', ins: 'Skeleton King' },
    fallen: { name: 'Fallen', nom: 'Fallen', acc: 'Fallen', ins: 'Fallen' },
    dragon: { name: 'Dragon', nom: 'Dragon', acc: 'Dragon', ins: 'Dragon' },
    giant_bat: { name: 'Giant Bat', nom: 'Giant Bat', acc: 'Giant Bat', ins: 'Giant Bat' },
    skeleton_mage: { name: 'Skeleton Mage', nom: 'Skeleton Mage', acc: 'Skeleton Mage', ins: 'Skeleton Mage' }
  },
  heroes: {
    none: { name: 'Adventurer', short: 'Adventurer', role: 'adventurer' },
    warrior: { name: 'Horan, the Warrior', short: 'Horan', role: 'the warrior' },
    thief: { name: 'Aderyn, the Thief', short: 'Aderyn', role: 'the thief' },
    swordsman: { name: 'Victorius, the Swordsman', short: 'Victorius', role: 'the swordsman' },
    warlock: { name: 'Lord Xanros, the Warlock', short: 'Xanros', role: 'the warlock' },
    wizard: { name: 'Argentus, the Wizard', short: 'Argentus', role: 'the wizard' },
    oracle: { name: 'Taia, the Oracle', short: 'Taia', role: 'the oracle' },
    barbarian: { name: 'Valduk, the Barbarian', short: 'Valduk', role: 'the barbarian' },
    battle_mage: { name: 'Baron Markul, the Battle Mage', short: 'Markul', role: 'the battle mage' },
    acrobat: { name: 'Hanna, the Acrobat', short: 'Hanna', role: 'the acrobat' },
    ranger: { name: 'Lady Lorraine, the Ranger', short: 'Lorraine', role: 'the ranger' },
    lord_karak: { name: 'Lord Karak', short: 'Lord Karak', role: 'Lord Karak' }
  },
  items: {
    dagger: { nom: 'Dagger', acc: 'a dagger' },
    sword: { nom: 'Sword', acc: 'a sword' },
    axe: { nom: 'Axe', acc: 'an axe' },
    fireball: { nom: 'Fireball', acc: 'a fireball' },
    teleport: { nom: 'Portal of Healing', acc: 'a Portal of Healing' },
    key: { nom: 'Key', acc: 'a key' },
    chest: { nom: 'Chest', acc: 'a chest' },
    treasure: { nom: 'Treasure', acc: 'a treasure' },
    ruby_chest: { nom: 'Ruby', acc: 'the dragon\'s ruby' },
    dark_thorn: { nom: 'Dark Thorn', acc: 'a Dark Thorn' },
    frost_fist: { nom: 'Frost Fist', acc: 'a Frost Fist' },
    unknown: { nom: 'Item', acc: 'an item' }
  },
  skills: {
    double_attack: { name: 'Double Attack', description: 'In any combat, the Warrior can reroll the dice if the first roll is not to his liking. He does not lose a HP in the first loss, but must accept the second roll\'s result.' },
    reincarnation: { name: 'Reincarnation', description: 'If the Warrior loses his last HP, he does not fall unconscious. Instead he teleports to any tile with a healing fountain, where all his HP are replenished. His turn ends anyway.' },
    backstab: { name: 'Backstab', description: 'The Thief attacks from the shadows: she wins any combat that would normally result in a tie.' },
    stealth: { name: 'Stealth', description: 'The Thief moves in the shadows: when entering a tile with a monster, she can decide to fight it or to sneak past and continue moving.' },
    combat_training: { name: 'Combat Training', description: 'The Swordsman is an experienced fighter and his strikes are always precise: any die that shows a 1 is rerolled as many times as necessary.' },
    unstoppable: { name: 'Unstoppable', description: 'If at least one die shows a 6 during combat, the Swordsman continues his turn afterwards. He may even fight the same monster again after a tie or a loss.' },
    sacrifice: { name: 'Sacrifice', description: 'The Warlock can sacrifice one HP to gain a bonus of +1 to his strength. He decides after rolling the dice and gains the bonus only once per combat. He can even sacrifice his last HP, but then falls unconscious for his next turn.' },
    magic_swap: { name: 'Magic Swap', description: 'The Warlock can swap his position with any other hero. This costs 4 moves, so it is only possible at the beginning of his turn; he can still use the ability of the tile where he ended up (unlocking a chest, healing, etc.).' },
    magical_affinity: { name: 'Magical Affinity', description: 'The Wizard knows how to use the magic bolts more effectively: his fireball scrolls are not consumed when used in combat. This does not apply to the portal of healing.' },
    astral_walking: { name: 'Astral Walking', description: 'The Wizard can move through walls, so he does not have to respect the continuity of the dungeon. This can be used only while moving over the discovered tiles.' },
    farseeing: { name: 'Farseeing', description: 'The Oracle has the best knowledge of all her enemies: if she fights in the first move of her turn, she gains a bonus of +1 to her roll.' },
    fateweaver: { name: 'Fateweaver', description: 'The Oracle can influence her future: whenever she is supposed to draw a token from the bag, she draws two instead, places one on the tile and puts the other one back in the bag.' },
    berserk: { name: 'Berserk', description: 'The weaker the Barbarian gets, the harder he hits: with exactly 3 or 4 HP he gains +1 to his strength, with 1 or 2 HP +2. When healing he may choose how many HP to heal.' },
    tireless: { name: 'Tireless', description: 'If the Barbarian defeats a monster and does not take its loot, he may continue his remaining moves.' },
    sword_master: { name: 'Sword Master', description: 'A sword has strength 3 in the hands of the Battle Mage.' },
    lightning_attack: { name: 'Lightning Attack', description: 'For all 4 moves the Battle Mage teleports to any tile with a monster and fights it. If he does not win, he chooses where to retreat, only onto an already opened tile.' },
    dagger_throwing: { name: 'Dagger Throwing', description: 'The Acrobat may keep daggers (strength 1) in her spell slots. Every dagger she carries adds +1 to her strength and is never consumed.' },
    sprint: { name: 'Sprint', description: 'Instead of normal movement the Acrobat may declare a sprint at the start of her turn: up to 8 moves, but she may not explore undiscovered tiles.' },
    bear_attack: { name: 'Bear Attack', description: 'The Ranger fights alongside her bear: a die showing 1 counts as 6.' },
    listening: { name: 'Listening', description: 'For one move the Ranger may explore an adjacent tile (place it and draw its token) without entering it. Entering later costs another move. She may listen several times per turn.' }
  }
};
