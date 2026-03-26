#!/usr/bin/env python3
"""
Generate placeholder PNG sprites for Moonbase game.
Run once to create all needed sprite files.
Each sprite is a colored shape with a text label – ready to be replaced with artwork.
"""
from PIL import Image, ImageDraw, ImageFont
import os, math

BASE = os.path.join(os.path.dirname(__file__), '..', 'assets', 'sprites')

def mkdir(path):
    os.makedirs(path, exist_ok=True)

def save(img, *parts):
    path = os.path.join(BASE, *parts)
    mkdir(os.path.dirname(path))
    img.save(path)
    print(f"  Created: {path}")

def rgba(hex_color, alpha=255):
    h = hex_color.lstrip('#')
    r,g,b = (int(h[i:i+2],16) for i in (0,2,4))
    return (r,g,b,alpha)

def label(draw, text, w, h, color=(255,255,255,200), size=12):
    # Simple centered text
    try:
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', size)
    except Exception:
        font = ImageFont.load_default()
    bb = draw.textbbox((0,0), text, font=font)
    tw = bb[2]-bb[0]; th = bb[3]-bb[1]
    draw.text(((w-tw)//2, (h-th)//2), text, fill=color, font=font)

# ── Tile sprites (64x64) ─────────────────────────────────────────────────────
def make_tile(filename, bg_color, detail_color=None, detail=''):
    img  = Image.new('RGBA', (64,64), rgba(bg_color))
    draw = ImageDraw.Draw(img)
    # Subtle grid border
    draw.rectangle([0,0,63,63], outline=rgba('#00000040'))
    if detail_color:
        draw.rectangle([8,8,55,55], fill=rgba(detail_color, 60))
    if detail:
        label(draw, detail, 64, 64, size=9)
    save(img, 'tiles', filename)

print("Generating tile sprites...")
make_tile('moon_surface.png',   '#3a3a4a', '#5a5a6a')
make_tile('crater.png',         '#2a2a3a', '#1a1a2a', '●')
make_tile('rock.png',           '#4a4a4e', '#6a6a70', '▲')
make_tile('buildable.png',      '#2a4a2a', '#3a6a3a')
make_tile('unbuildable.png',    '#4a2a2a', '#6a3a3a')
make_tile('highlight.png',      '#2a6a9a', '#4a8aba')
make_tile('grid_empty.png',     '#252535', '#353545')

# ── Building sprites (128x128 each, different sizes drawn) ────────────────────
BUILDINGS = {
    'command_center': {'color': '#1a6aaf', 'accent': '#4a9adf', 'label': 'CMD', 'shape': 'dome'},
    'fuel_plant':     {'color': '#af6a1a', 'accent': '#df9a4a', 'label': 'FUEL', 'shape': 'factory'},
    'storage':        {'color': '#6a6a1a', 'accent': '#9a9a4a', 'label': 'STORE', 'shape': 'box'},
    'mining_station': {'color': '#6a3a1a', 'accent': '#9a5a3a', 'label': 'MINE', 'shape': 'drill'},
    'smelter':        {'color': '#8a2a1a', 'accent': '#ca4a3a', 'label': 'SMELT', 'shape': 'furnace'},
    'market':         {'color': '#1a6a3a', 'accent': '#3a9a5a', 'label': 'MARKET', 'shape': 'arch'},
    'research_lab':   {'color': '#4a1a8a', 'accent': '#7a4aba', 'label': 'LAB', 'shape': 'antenna'},
    'defense_tower':  {'color': '#6a1a1a', 'accent': '#9a3a3a', 'label': 'DEF', 'shape': 'tower'},
}

def draw_dome(draw, cx, cy, r, color, accent):
    draw.ellipse([cx-r, cy-r//2, cx+r, cy+r//2], fill=rgba(color), outline=rgba(accent))
    draw.rectangle([cx-r//2, cy, cx+r//2, cy+r//2], fill=rgba(color))

def draw_factory(draw, x0, y0, x1, y1, color, accent):
    draw.rectangle([x0+10, y0+20, x1-10, y1], fill=rgba(color), outline=rgba(accent))
    for i in range(3):
        cx = x0+20 + i*((x1-x0-40)//2)
        draw.rectangle([cx, y0, cx+12, y0+25], fill=rgba(accent))
    draw.rectangle([x0+20, y0+35, x1-20, y1-5], fill=rgba(accent, 120))

def draw_box(draw, x0, y0, x1, y1, color, accent):
    draw.rectangle([x0+5, y0+20, x1-5, y1-5], fill=rgba(color), outline=rgba(accent))
    draw.line([x0+5, y0+20, (x0+x1)//2, y0+8], fill=rgba(accent), width=2)
    draw.line([x1-5, y0+20, (x0+x1)//2, y0+8], fill=rgba(accent), width=2)

def draw_tower(draw, x0, y0, x1, y1, color, accent):
    cx = (x0+x1)//2
    draw.polygon([(cx, y0+5), (x0+25, y1-5), (x1-25, y1-5)], fill=rgba(color), outline=rgba(accent))
    draw.rectangle([cx-6, y0+5, cx+6, y0+25], fill=rgba(accent))

def make_building(name, cfg, w, h):
    img  = Image.new('RGBA', (w, h), (0,0,0,0))
    draw = ImageDraw.Draw(img)
    color  = cfg['color']
    accent = cfg['accent']
    shape  = cfg['shape']

    # Shadow
    draw.ellipse([10, h-16, w-10, h-4], fill=(0,0,0,80))

    if shape == 'dome':
        draw_dome(draw, w//2, h//2, w//2-8, color, accent)
    elif shape == 'factory':
        draw_factory(draw, 4, 4, w-4, h-4, color, accent)
    elif shape == 'box':
        draw_box(draw, 4, 4, w-4, h-4, color, accent)
    elif shape == 'tower':
        draw_tower(draw, 4, 4, w-4, h-4, color, accent)
    elif shape == 'drill':
        draw.rectangle([w//2-15, 20, w//2+15, h-15], fill=rgba(color), outline=rgba(accent))
        draw.polygon([(w//2, h-8),(w//2-18, h-22),(w//2+18,h-22)], fill=rgba(accent))
        draw.rectangle([w//2-5, 5, w//2+5, 25], fill=rgba(accent))
    elif shape == 'furnace':
        draw.rectangle([10, 15, w-10, h-10], fill=rgba(color), outline=rgba(accent))
        draw.ellipse([20, h//2-10, w-20, h//2+20], fill=rgba(accent, 180))
        # flames
        for i in range(3):
            ox = 30 + i * ((w-60)//2)
            draw.ellipse([ox, h//2-5, ox+12, h//2+15], fill=(255, 140, 0, 200))
    elif shape == 'arch':
        draw.arc([10, 5, w-10, h-20], 180, 360, fill=rgba(accent), width=8)
        draw.rectangle([10, (5+h-20)//2, w-10, h-10], fill=rgba(color), outline=rgba(accent))
    elif shape == 'antenna':
        draw.rectangle([w//2-20, 30, w//2+20, h-10], fill=rgba(color), outline=rgba(accent))
        draw.line([(w//2, 5), (w//2, 30)], fill=rgba(accent), width=3)
        draw.ellipse([w//2-10, 0, w//2+10, 20], outline=rgba(accent), width=2)
    else:
        draw.rectangle([10, 10, w-10, h-10], fill=rgba(color), outline=rgba(accent))

    label(draw, cfg['label'], w, h, size=13)
    save(img, 'buildings', f'{name}.png')

print("Generating building sprites...")
sizes = {
    'command_center': (192, 192),
    'fuel_plant':     (128, 128),
    'storage':        (128, 128),
    'mining_station': (128, 128),
    'smelter':        (128, 128),
    'market':         (192, 192),
    'research_lab':   (128, 128),
    'defense_tower':  (64,  80),
}
for name, cfg in BUILDINGS.items():
    w, h = sizes.get(name, (128, 128))
    make_building(name, cfg, w, h)

# ── UI sprites ────────────────────────────────────────────────────────────────
print("Generating UI sprites...")

def panel(filename, w, h, bg='#0a1a2a', border='#1a4a7a', alpha=200):
    img  = Image.new('RGBA', (w, h), (0,0,0,0))
    draw = ImageDraw.Draw(img)
    draw.rounded_rectangle([0,0,w-1,h-1], radius=8, fill=rgba(bg,alpha), outline=rgba(border))
    save(img, 'ui', filename)

def button(filename, w, h, color='#1a6aaf', hover=False):
    img  = Image.new('RGBA', (w,h), (0,0,0,0))
    draw = ImageDraw.Draw(img)
    c    = '#2a8adf' if hover else color
    draw.rounded_rectangle([0,0,w-1,h-1], radius=6, fill=rgba(c), outline=rgba('#4aabef'))
    save(img, 'ui', filename)

panel('hud_bg.png',      800, 60)
panel('panel_bg.png',    320, 480)
panel('tooltip_bg.png',  200, 80)
panel('modal_bg.png',    480, 360)
button('btn_normal.png', 160, 40)
button('btn_hover.png',  160, 40, hover=True)
button('btn_danger.png', 160, 40, '#8a1a1a')

# Fuel bar
img  = Image.new('RGBA', (200, 20), (0,0,0,0))
draw = ImageDraw.Draw(img)
draw.rounded_rectangle([0,0,199,19], radius=5, fill=rgba('#0a1a0a',200), outline=rgba('#1a4a1a'))
draw.rounded_rectangle([2,2,120,17], radius=4, fill=rgba('#2aaf2a'))
save(img, 'ui', 'fuel_bar.png')

# Minimap bg
img  = Image.new('RGBA', (160, 120), rgba('#050510', 220))
draw = ImageDraw.Draw(img)
draw.rectangle([0,0,159,119], outline=rgba('#1a4a7a'))
save(img, 'ui', 'minimap_bg.png')

# Resource icons (32x32)
ICONS = {
    'fuel':     ('#f0a030', '⛽'),
    'minerals': ('#30a0f0', '💎'),
    'metal':    ('#a0a0b0', '⚙'),
    'mooncoin': ('#f0d020', '🪙'),
}
for name, (color, sym) in ICONS.items():
    img  = Image.new('RGBA', (32, 32), (0,0,0,0))
    draw = ImageDraw.Draw(img)
    draw.ellipse([1,1,30,30], fill=rgba(color), outline=rgba('#ffffff60'))
    save(img, 'ui', f'icon_{name}.png')

# ── Player / effects sprites ──────────────────────────────────────────────────
print("Generating effect sprites...")

# Particle: fuel glow (16x16)
for name, color in [('particle_fuel','#f0a030'), ('particle_mineral','#30a0f0'), ('particle_metal','#c0c0d0')]:
    img  = Image.new('RGBA', (16,16), (0,0,0,0))
    draw = ImageDraw.Draw(img)
    draw.ellipse([2,2,13,13], fill=rgba(color,220))
    draw.ellipse([5,5,10,10], fill=rgba('#ffffff', 180))
    save(img, 'effects', f'{name}.png')

# Selection ring (64x64)
img  = Image.new('RGBA', (64,64), (0,0,0,0))
draw = ImageDraw.Draw(img)
draw.ellipse([4,4,59,59], outline=rgba('#4adfff',200), width=2)
save(img, 'effects', 'selection_ring.png')

# Build preview overlay
img  = Image.new('RGBA', (64,64), rgba('#4adfff',40))
draw = ImageDraw.Draw(img)
draw.rectangle([0,0,63,63], outline=rgba('#4adfff',180))
save(img, 'effects', 'build_preview_ok.png')

img  = Image.new('RGBA', (64,64), rgba('#ff4444',40))
draw = ImageDraw.Draw(img)
draw.rectangle([0,0,63,63], outline=rgba('#ff4444',180))
save(img, 'effects', 'build_preview_err.png')

# ── Animated sprite sheets ────────────────────────────────────────────────────
print("Generating sprite sheets...")

# Fuel plant animation (4 frames, each 128x128)
FRAMES = 4
sheet  = Image.new('RGBA', (128*FRAMES, 128), (0,0,0,0))
for f in range(FRAMES):
    frame = Image.new('RGBA', (128,128), (0,0,0,0))
    draw  = ImageDraw.Draw(frame)
    draw.rectangle([20, 30, 108, 110], fill=rgba('#af6a1a'), outline=rgba('#df9a4a'))
    for i in range(3):
        cx = 35 + i * 20
        flicker = 10 + f*5
        draw.ellipse([cx, 20-flicker, cx+14, 35], fill=(255, 140+f*20, 0, 220))
    sheet.paste(frame, (f*128, 0))
save(sheet, 'buildings', 'fuel_plant_anim.png')

# Idle smoke particle sheet (8 frames 16x16)
FRAMES = 8
sheet  = Image.new('RGBA', (16*FRAMES, 16), (0,0,0,0))
for f in range(FRAMES):
    frame = Image.new('RGBA', (16,16), (0,0,0,0))
    draw  = ImageDraw.Draw(frame)
    alpha = 220 - f*25
    size  = 3 + f
    draw.ellipse([8-size, 8-size, 8+size, 8+size], fill=(200,200,200,max(0,alpha)))
    sheet.paste(frame, (f*16, 0))
save(sheet, 'effects', 'smoke_sheet.png')

print("\nAll sprites generated successfully!")
print(f"Sprite directory: {os.path.abspath(BASE)}")
