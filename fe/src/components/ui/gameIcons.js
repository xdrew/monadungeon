/**
 * Torchlight line icons for GameIcon.vue: each icon is a list of SVG shapes on a
 * 24×24 grid, stroked in currentColor (1.8 stroke, round caps). The action icons
 * (explore, spells, journal, hourglass) follow the "C · Torchlight" canvas board.
 */
const path = (d) => ({ tag: 'path', attrs: { d } });
const dot = (cx, cy, r = 1.3) => ({ tag: 'circle', attrs: { cx, cy, r, fill: 'currentColor', stroke: 'none' } });
const circle = (cx, cy, r) => ({ tag: 'circle', attrs: { cx, cy, r } });

export const ICONS = {
  heart: [path('M12 20.5s-7.5-4.8-9.8-9.4A5.4 5.4 0 0 1 12 4.8a5.4 5.4 0 0 1 9.8 6.3C19.5 15.7 12 20.5 12 20.5z')],
  treasure: [
    path('M3 10h18v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z'),
    path('M3 10a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4'),
    path('M3 13.5h18'),
    path('M10.5 12h3v3h-3z')
  ],
  dice: [
    path('M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z'),
    dot(8, 8), dot(16, 8), dot(12, 12), dot(8, 16), dot(16, 16)
  ],
  crown: [path('M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5z'), path('M5 19h14')],
  skull: [
    path('M12 3a8 8 0 0 0-8 8c0 2.6 1.2 4.4 3 5.5V20h10v-3.5c1.8-1.1 3-2.9 3-5.5a8 8 0 0 0-8-8z'),
    circle(9, 11.5, 1.8), circle(15, 11.5, 1.8),
    path('M10.5 20v-2.5M13.5 20v-2.5')
  ],
  pin: [path('M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z'), circle(12, 10, 2.4)],
  sword: [path('M14.5 17.5L3 6V3h3l11.5 11.5'), path('M13 19l6-6'), path('M16 16l4 4'), path('M19 21l2-2')],
  explore: [path('M4 20V8l8-5 8 5v12M9 20v-6h6v6')],
  compass: [circle(12, 12, 9), path('M15.5 8.5l-2 5-5 2 2-5z'), dot(12, 12, 0.9), path('M12 3v1.6M12 19.4V21M3 12h1.6M19.4 12H21')],
  spells: [path('M12 3c2 3 5 5 5 9a5 5 0 0 1-10 0c0-2 1-3 2-4 0 2 1 3 2 3 0-3-1-5 1-8z')],
  journal: [path('M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3zM5 17a3 3 0 0 1 3-3h11')],
  hourglass: [path('M7 3h10M7 21h10M8 3c0 5 8 6 8 9s-8 4-8 9M16 3c0 5-8 6-8 9')],
  'sound-on': [path('M4 9h4l5-4v14l-5-4H4z'), path('M16.5 8.5a5 5 0 0 1 0 7'), path('M19 6a8.5 8.5 0 0 1 0 12')],
  'sound-off': [path('M4 9h4l5-4v14l-5-4H4z'), path('M17 9.5l5 5M22 9.5l-5 5')],
  center: [circle(12, 12, 3.5), path('M12 2v4M12 18v4M2 12h4M18 12h4')],
  'zoom-in': [circle(10.5, 10.5, 6.5), path('M15.5 15.5L21 21'), path('M10.5 7.5v6M7.5 10.5h6')],
  'zoom-out': [circle(10.5, 10.5, 6.5), path('M15.5 15.5L21 21'), path('M7.5 10.5h6')],
  rotate: [path('M20 12a8 8 0 1 1-2.4-5.7'), path('M20 4v4.5h-4.5')],
  swap: [path('M4 8h14l-3.5-3.5'), path('M20 16H6l3.5 3.5')],
  key: [circle(7.5, 15.5, 4), path('M10.5 12.5L20 3'), path('M16.5 6.5l2.5 2.5'), path('M14 9l2 2')],
  close: [path('M6 6l12 12M18 6L6 18')],
  check: [path('M4.5 12.5l5 5 10-11')],
  warning: [path('M12 3.5l9.5 16.5h-19z'), path('M12 10v4.5'), dot(12, 17.2, 1.1)],
  trophy: [
    path('M7 4h10v5a5 5 0 0 1-10 0z'),
    path('M7 6H4a3 3 0 0 0 3 4M17 6h3a3 3 0 0 1-3 4'),
    path('M12 14v3M8 20h8M9.5 17h5')
  ],
  home: [path('M3.5 11L12 4l8.5 7'), path('M5.5 9.5V20h13V9.5'), path('M10 20v-5.5h4V20')],
  lightning: [path('M13 2.5L5 13.5h6l-1 8 8-11h-6z')],
  sprint: [path('M14.5 4.5a1.8 1.8 0 1 0 0 .1'), path('M8 21l3-6 3 2v4'), path('M11 15l1.5-5.5L9 11l-2 3'), path('M12.5 9.5l3 2.5 3-1'), path('M3 9h4M2 13h3')],
  listen: [path('M7 9a5 5 0 0 1 10 0c0 3-3 4-3 7a3 3 0 0 1-5.5 1.5'), path('M10 9.5a2 2 0 0 1 4 0c0 1.2-1 1.6-1.5 2.5')],
  eye: [path('M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z'), circle(12, 12, 3)],
  menu: [path('M4 7h16'), path('M4 12h16'), path('M4 17h16')],
  pen: [path('M4 20l4-1L18.5 8.5l-3-3L5 16l-1 4z'), path('M13.5 7.5l3 3')],
  // a table seen from above with two seats: online tables
  table: [circle(12, 12, 5), circle(12, 3.5, 1.6), circle(12, 20.5, 1.6), circle(3.5, 12, 1.6), circle(20.5, 12, 1.6)],
  // two chain links: the invite link
  link: [path('M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.2 1.2'), path('M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.2-1.2')],
  // a figure: a seat and who sits on it
  person: [circle(12, 8, 3.6), path('M4.5 20.5a7.5 7.5 0 0 1 15 0')]
};
