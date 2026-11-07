// Pack colour and brand line for each product, taken from the approved design.
// The API contract has no field for these yet. Suggested contract addition for the backend team:
// `accent_color` and `brand_line` on Product. Until then the look is matched by slug here,
// and any new product gets a colour from the fallback list.
const LOOKS = {
  'oont-chap-three-mango-mirchi-powder': { c: '#D9261C', f: '#FBF4E6', line: 'Oont Chap · Three Mango' },
  'guntur-gold-premium-chilli-powder': { c: '#C8141C', f: '#FBF4E6', line: 'Spice Mama' },
  'chilli-powder-guntur-special': { c: '#F2D31B', f: '#2B1510', line: 'Spice Mama · Guntur Special' },
  'coriander-powder': { c: '#0A7A39', f: '#FBF4E6', line: 'Spice Mama · Aroma Special' },
  'oont-chap-chilli-powder': { c: '#B5121B', f: '#FBF4E6', line: 'Oont Chap' },
  'turmeric-powder': { c: '#F3C316', f: '#2B1510', line: 'Spice Mama · Aroma Special' },
  'sambar-powder': { c: '#74358A', f: '#FBF4E6', line: 'Spice Mama' },
  'rasam-powder': { c: '#C8161D', f: '#FBF4E6', line: 'Spice Mama' },
  'kasuri-methi': { c: '#0B6A2C', f: '#FBF4E6', line: 'Spice Mama' },
  'garam-masala': { c: '#6B0E0A', f: '#FBF4E6', line: 'Spice Mama' },
  'chicken-masala': { c: '#953709', f: '#FBF4E6', line: 'Spice Mama' },
  'ragi-flour': { c: '#1C7A72', f: '#FBF4E6', line: 'Spice Mama' },
};

const FALLBACK = [
  { c: '#C8141C', f: '#FBF4E6' }, { c: '#0E5A30', f: '#FBF4E6' }, { c: '#6B0E0A', f: '#FBF4E6' },
  { c: '#C79A3B', f: '#2B1510' }, { c: '#74358A', f: '#FBF4E6' }, { c: '#1C7A72', f: '#FBF4E6' },
];

// Spice Mama family illustration shown on a product's page ("From the Spice Mama kitchen").
const CHILLI = { src: '/assets/images/art/chilli-pounding.webp', w: 670, h: 1000, alt: 'Illustration: the Spice Mama family pounding dried red chillies' };
const ART = {
  'oont-chap-three-mango-mirchi-powder': CHILLI,
  'guntur-gold-premium-chilli-powder': CHILLI,
  'chilli-powder-guntur-special': CHILLI,
  'oont-chap-chilli-powder': CHILLI,
  'chicken-masala': { src: '/assets/images/art/chicken-masala.webp', w: 1000, h: 869, alt: 'Illustration: the Spice Mama couple cooking chicken curry' },
  'garam-masala': { src: '/assets/images/art/garam-masala.webp', w: 1000, h: 864, alt: 'Illustration: the Spice Mama family grinding spices at home' },
  'ragi-flour': { src: '/assets/images/art/ragi-mudde.webp', w: 857, h: 1000, alt: 'Illustration: making ragi mudde in the Spice Mama kitchen' },
  'rasam-powder': { src: '/assets/images/art/rasam.webp', w: 1000, h: 740, alt: 'Illustration: the Spice Mama couple cooking rasam' },
  'sambar-powder': { src: '/assets/images/art/sambar.webp', w: 811, h: 1000, alt: 'Illustration: the Spice Mama couple tasting sambar' },
  'kasuri-methi': { src: '/assets/images/art/kasuri-methi.webp', w: 848, h: 1000, alt: 'Illustration: the Spice Mama family cooking with kasuri methi' },
};

export function artFor(product) {
  return ART[product.slug] || null;
}

export function lookFor(product) {
  const known = LOOKS[product.slug];
  if (known) return known;
  const n = Number(product.id) || [...String(product.slug)].reduce((a, ch) => a + ch.charCodeAt(0), 0);
  return { ...FALLBACK[n % FALLBACK.length], line: '' };
}

export function primaryImage(product) {
  const imgs = Array.isArray(product.images) ? [...product.images] : [];
  imgs.sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
  return imgs[0] || null;
}
