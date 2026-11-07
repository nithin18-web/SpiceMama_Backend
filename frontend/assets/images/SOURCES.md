# Image sources

Derived web images and the repository original they come from (originals in `Images/` are never edited).

| Web image | Source original | How |
|---|---|---|
| `campaign/pounding-scene.webp` | `Images/WhatsApp Image 2026-10-27 at 06.26.37.jpeg` (Guntur Gold pack front) | crop x628 y418 327×545 (inside the pack's red panel), WebP q86; used on `maintenance.html` |
| `packs/<slug>.webp`, `packs/<slug>-sm.webp` (10 products + `red-chilli-pickle`) | `~/Downloads/Spicemama_Mockup/*.png` (owner-supplied pack mockups, 2026-11-07) | transparent margins trimmed, 1100 px tall (`-sm`: 400 px wide), WebP q86 with alpha. Not yet replaced: `oont-chap-chilli-powder` (no mockup supplied) |
| `recipes/tomato-rasam.webp` | `~/Downloads/Spice mama art/Rasam.jpg` | near-white made transparent, square crop (45% across), 1000×1000, WebP q84 |
| `recipes/vegetable-sambar.webp` | `~/Downloads/Spice mama art/Sambar.jpg` | near-white made transparent, square crop (bottom), 1000×1000 |
| `recipes/aloo-methi-fry.webp` | `~/Downloads/Spice mama art/kasuri methi copy.png` | transparent background kept, square crop (84% down), 1000×1000 |
| `packs/oont-chap-three-mango-mirchi-powder.webp` | `~/Downloads/Spicemama_Mockup/Corrected Mango Pickle Package Mockup.png` (owner-supplied, 1086×1448) | transparent margins trimmed, 664×912, WebP q88. Note: Telugu line 2 reads "వంటల కొనది" (real pack: "వంటల కోసం") and "& Daily Uses" (pack: "& Daily Use") — designer to correct |
| `art/*.webp` (chicken-masala, garam-masala, chilli-pounding, ragi-mudde, rasam, sambar, kasuri-methi, pappad, vermicelli, gulab-jamun) | `~/Downloads/Spice mama art/*` (owner-supplied family artwork) | near-white made transparent, trimmed, ≤1000 px (table scenes ≤900 px), WebP q84. Used on product pages (`product-look.js` → `artFor`) and the About page |
