"""
Génère uml/schema_relationnel.drawio et uml/schema_relationnel.png (modèle logique)
à partir de sql/01_schema.sql. Les tables reprennent la disposition du diagramme
entité-association ; les tables de jointure sont placées à la place de leur association.

    python uml/generer_schema_relationnel.py      (nécessite Pillow)
"""
import os
import re, sys, json, math, html, xml.etree.ElementTree as ET
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'uml', 'schema_relationnel')
SX, SY = 1.0, 1.0
# décalages (x, y) par rapport à la position de l'entité / association, pour qu'aucune flèche ne traverse une table
over = {'journal_audit_archive': [-300, 0], 'passage_etape': [200, 80], 'avis_expedition': [-170, 0]}

sql = open(ROOT + '/sql/01_schema.sql', encoding='utf-8').read()
sql = re.sub(r'--[^\n]*', '', sql)
tables, pk, fk, uq = {}, {}, {}, {}
for m in re.finditer(r'CREATE TABLE (\w+) \((.*?)\n\)\s*ENGINE', sql, re.S):
    t, body = m.group(1), m.group(2)
    cols = []; pk[t] = set(); fk[t] = {}; uq[t] = set()
    parts, depth, cur = [], 0, ''
    for ch in body:
        if ch == '(': depth += 1
        if ch == ')': depth -= 1
        if ch == ',' and depth == 0:
            parts.append(cur); cur = ''
        else:
            cur += ch
    parts.append(cur)
    for p in parts:
        p = ' '.join(p.split())
        if not p: continue
        k = p.split()[0].upper()
        if k in ('CONSTRAINT', 'PRIMARY', 'UNIQUE', 'INDEX', 'KEY', 'CHECK', 'FOREIGN', 'FULLTEXT'):
            mm = re.search(r'PRIMARY KEY \(([^)]*)\)', p)
            if mm: pk[t] |= {c.strip() for c in mm.group(1).split(',')}
            mm = re.search(r'FOREIGN KEY \((\w+)\)\s+REFERENCES (\w+)', p)
            if mm: fk[t][mm.group(1)] = mm.group(2)
            mm = re.match(r'(?:CONSTRAINT \w+ )?UNIQUE(?: KEY| INDEX)?(?: \w+)? \(([^)]*)\)', p)
            if mm and ',' not in mm.group(1): uq[t].add(mm.group(1).strip())
            continue
        name = p.split()[0]
        rest = p[len(name):].strip()
        typ = re.split(r' (?:NOT NULL|NULL|DEFAULT|AUTO_INCREMENT|UNIQUE|PRIMARY|GENERATED|COMMENT|CHECK)\b', rest)[0]
        typ = re.sub(r'ENUM\(.*\)', 'ENUM', typ)
        if 'PRIMARY KEY' in rest: pk[t].add(name)
        if re.search(r'\bUNIQUE\b', rest): uq[t].add(name)
        cols.append((name, typ))
    tables[t] = cols
for m in re.finditer(r'ALTER TABLE (\w+)\s+ADD CONSTRAINT \w+ FOREIGN KEY \((\w+)\) REFERENCES (\w+)', sql):
    fk[m.group(1)][m.group(2)] = m.group(3)
tables = {k: v for k, v in tables.items() if not k.startswith('seq_')}
tables['journal_audit_archive'] = tables['journal_audit']
pk['journal_audit_archive'] = pk['journal_audit']; fk['journal_audit_archive'] = {}; uq['journal_audit_archive'] = uq['journal_audit']

er = ET.parse(ROOT + '/uml/diagramme_entite_association.drawio').getroot()
pos = {}
for c in er.iter('mxCell'):
    g = c.find('mxGeometry')
    if g is not None and c.get('vertex') == '1':
        pos[c.get('id')] = (float(g.get('x', 0)) + float(g.get('width', 0)) / 2,
                            float(g.get('y', 0)) + float(g.get('height', 0)) / 2)
place = {
 'utilisateur': 'e_UTILISATEUR', 'profil': 'e_PROFIL', 'transporteur': 'e_TRANSPORTEUR', 'contact': 'e_CONTACT', 'contrat': 'e_CONTRAT',
 'chauffeur': 'e_CHAUFFEUR', 'habilitation': 'e_HABILITATION', 'vehicule': 'e_VEHICULE', 'type_vehicule': 'e_TYPE_VEHICULE', 'quai': 'e_QUAI',
 'equipement': 'e_EQUIPEMENT', 'rendez_vous': 'e_RENDEZ_VOUS', 'passage': 'e_PASSAGE', 'type_etape': 'e_TYPE_ETAPE', 'fournisseur': 'e_FOURNISSEUR',
 'avis_expedition': 'e_AVIS_EXPEDITION', 'reception': 'e_RECEPTION', 'ecart': 'e_ECART', 'reserve': 'e_RESERVE', 'lot': 'e_LOT', 'article': 'e_ARTICLE',
 'client': 'e_CLIENT', 'condition_stockage': 'e_CONDITION_STOCKAGE', 'zone': 'e_ZONE', 'allee': 'e_ALLEE', 'rack': 'e_RACK', 'emplacement': 'e_EMPLACEMENT',
 'mouvement_stock': 'e_MOUVEMENT_STOCK', 'commande': 'e_COMMANDE', 'liste_prelevement': 'e_LISTE_PRELEVEMENT', 'unite_expedition': 'e_UNITE_EXPEDITION',
 'expedition': 'e_EXPEDITION', 'document_transport': 'e_DOCUMENT_TRANSPORT', 'journal_audit': 'e_JOURNAL',
 'chauffeur_habilitation': 'a_DETENIR', 'quai_equipement': 'a_EQUIPER', 'quai_type_vehicule': 'a_ACCEPTER', 'passage_etape': 'a_FRANCHIR',
 'ligne_avis': 'a_ANNONCER', 'reception_lot': 'a_RECEVOIR', 'zone_condition': 'a_AUTORISER', 'stock': 'a_STOCKER', 'ligne_commande': 'a_COMPRENDRE',
 'ligne_prelevement': 'a_PRELEVER', 'contenu_unite': 'a_REGROUPER', 'journal_audit_archive': 'e_JOURNAL',
}
missing = set(tables) - set(place)
assert not missing, missing

f = ImageFont.truetype('C:/Windows/Fonts/arial.ttf', 12)
fi = ImageFont.truetype('C:/Windows/Fonts/ariali.ttf', 12)
fb = ImageFont.truetype('C:/Windows/Fonts/arialbd.ttf', 13)
HEAD, ROW, PADX = 26, 18, 8
def label(t, c): return ('#' if c in fk[t] else '') + c

boxes = {}
for t, cols in tables.items():
    w = max([fb.getlength(t) + 2 * PADX] + [f.getlength(label(t, c) + '  ' + ty) + 2 * PADX + 10 for c, ty in cols])
    w = max(190, int(w + 9) // 10 * 10)
    h = HEAD + ROW * len(cols) + 6
    cx, cy = pos[place[t]]; cx *= SX; cy *= SY
    if t in over: cx += over[t][0]; cy += over[t][1]
    boxes[t] = [round(cx - w / 2), round(cy - h / 2), w, h]
minx = min(b[0] for b in boxes.values()) - 40; miny = min(b[1] for b in boxes.values()) - 40
for b in boxes.values(): b[0] -= minx; b[1] -= miny
W = max(b[0] + b[2] for b in boxes.values()) + 40
H = max(b[1] + b[3] for b in boxes.values()) + 40

names = list(boxes)
for i in range(len(names)):
    for j in range(i + 1, len(names)):
        a, b = boxes[names[i]], boxes[names[j]]
        if a[0] < b[0] + b[2] + 30 and b[0] < a[0] + a[2] + 30 and a[1] < b[1] + b[3] + 30 and b[1] < a[1] + a[3] + 30:
            print('OVERLAP', names[i], names[j])

def center(b): return (b[0] + b[2] / 2, b[1] + b[3] / 2)
def clip(b, p, q):
    x, y, w, h = b; cx, cy = p; dx, dy = q[0] - cx, q[1] - cy
    ts = []
    if dx: ts.append(((x + w) - cx) / dx if dx > 0 else (x - cx) / dx)
    if dy: ts.append(((y + h) - cy) / dy if dy > 0 else (y - cy) / dy)
    tt = min(ts); return (cx + dx * tt, cy + dy * tt)
def seg_hits(b, p, q):
    x, y, w, h = b[0] - 6, b[1] - 6, b[2] + 12, b[3] + 12
    for k in range(1, 200):
        s = k / 200; px = p[0] + (q[0] - p[0]) * s; py = p[1] + (q[1] - p[1]) * s
        if x < px < x + w and y < py < y + h: return True
    return False

segs = []
for t in tables:
    refs = list(fk[t].items())
    for c, r in refs:
        same = [cc for cc, rr in refs if rr == r]
        k = same.index(c) - (len(same) - 1) / 2
        p0, p1 = center(boxes[t]), center(boxes[r])
        if k:
            dx, dy = p1[0] - p0[0], p1[1] - p0[1]; n = math.hypot(dx, dy)
            ox, oy = -dy / n * 14 * k, dx / n * 14 * k
            p0, p1 = (p0[0] + ox, p0[1] + oy), (p1[0] + ox, p1[1] + oy)
        a, b = clip(boxes[t], p0, p1), clip(boxes[r], p1, p0)
        segs.append((t, c, r, a, b))
        for o in boxes:
            if o not in (t, r) and seg_hits(boxes[o], a, b): print('CROSS', t, '->', r, 'over', o)
print('tables', len(tables), 'fks', len(segs), 'size', W, H)

LW, LH = 380, 88
lx, ly = 40, H
H += LH + 40

# ---- draw.io
esc = lambda s: html.escape(s, quote=True)
cells = ['<mxCell id="0" />', '<mxCell id="1" parent="0" />']
for t, cols in tables.items():
    x, y, w, h = boxes[t]
    cells.append(f'<mxCell id="t_{t}" value="{esc(t)}" style="swimlane;fontStyle=1;startSize={HEAD};html=1;whiteSpace=wrap;fillColor=#FFFFFF;swimlaneFillColor=#FFFFFF;strokeColor=#000000;fontColor=#000000;fontSize=13;collapsible=0;" vertex="1" parent="1"><mxGeometry x="{x}" y="{y}" width="{w}" height="{h}" as="geometry" /></mxCell>')
    for i, (c, ty) in enumerate(cols):
        txt = esc(label(t, c))
        if c in pk[t]: txt = f'<u>{txt}</u>'
        elif c in uq[t]: txt = f'<i>{txt}</i>'
        txt += f'&nbsp;&nbsp;<font color="#555555">{esc(ty)}</font>'
        cells.append(f'<mxCell id="t_{t}_{c}" value="{esc(txt)}" style="text;html=1;align=left;verticalAlign=middle;spacingLeft={PADX - 2};fontSize=12;fontColor=#000000;strokeColor=none;fillColor=none;" vertex="1" parent="t_{t}"><mxGeometry y="{HEAD + 2 + i * ROW}" width="{w}" height="{ROW}" as="geometry" /></mxCell>')
for t, c, r, a, b in segs:
    bs, bt = boxes[t], boxes[r]
    ex, ey = (a[0] - bs[0]) / bs[2], (a[1] - bs[1]) / bs[3]
    nx, ny = (b[0] - bt[0]) / bt[2], (b[1] - bt[1]) / bt[3]
    pts = f'exitX={ex:.4f};exitY={ey:.4f};exitDx=0;exitDy=0;exitPerimeter=0;entryX={nx:.4f};entryY={ny:.4f};entryDx=0;entryDy=0;entryPerimeter=0;'
    cells.append(f'<mxCell id="fk_{t}_{c}" style="endArrow=classic;endFill=1;html=1;rounded=0;strokeColor=#000000;{pts}" edge="1" parent="1" source="t_{t}" target="t_{r}"><mxGeometry relative="1" as="geometry" /></mxCell>')
leg = '<b>Légende</b><br><u>souligné</u> : clé primaire<br>#colonne : clé étrangère (flèche vers la table référencée)<br><i>italique</i> : valeur unique'
cells.append(f'<mxCell id="legende" value="{esc(leg)}" style="rounded=0;whiteSpace=wrap;html=1;align=left;verticalAlign=top;spacing=8;fontSize=12;fillColor=#FFFFFF;strokeColor=#000000;fontColor=#000000;" vertex="1" parent="1"><mxGeometry x="{lx}" y="{ly}" width="{LW}" height="{LH}" as="geometry" /></mxCell>')
xml = (f'<mxfile host="app.diagrams.net"><diagram id="rel" name="Schéma relationnel"><mxGraphModel dx="1600" dy="1200" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="{W}" pageHeight="{H}" math="0" shadow="0" background="#FFFFFF"><root>'
       + ''.join(cells) + '</root></mxGraphModel></diagram></mxfile>')
open(OUT + '.drawio', 'w', encoding='utf-8').write(xml)

# ---- PNG (same geometry)
im = Image.new('RGB', (W, H), 'white'); d = ImageDraw.Draw(im)
for t, c, r, a, b in segs:
    d.line([a, b], fill='black', width=1)
    ang = math.atan2(b[1] - a[1], b[0] - a[0]); L = 10
    d.polygon([b, (b[0] - L * math.cos(ang - 0.35), b[1] - L * math.sin(ang - 0.35)),
               (b[0] - L * math.cos(ang + 0.35), b[1] - L * math.sin(ang + 0.35))], fill='black')
for t, cols in tables.items():
    x, y, w, h = boxes[t]
    d.rectangle([x, y, x + w, y + h], fill='white', outline='black')
    d.line([x, y + HEAD, x + w, y + HEAD], fill='black')
    d.text((x + (w - fb.getlength(t)) / 2, y + 6), t, font=fb, fill='black')
    for i, (c, ty) in enumerate(cols):
        yy = y + HEAD + 2 + i * ROW + 2
        lab = label(t, c); ff = fi if (c in uq[t] and c not in pk[t]) else f
        d.text((x + PADX, yy), lab, font=ff, fill='black')
        lw = ff.getlength(lab)
        if c in pk[t]: d.line([x + PADX, yy + 14, x + PADX + lw, yy + 14], fill='black')
        d.text((x + PADX + lw + f.getlength('  '), yy), ty, font=f, fill='#555555')
d.rectangle([lx, ly, lx + LW, ly + LH], fill='white', outline='black')
d.text((lx + 8, ly + 8), 'Légende', font=fb, fill='black')
d.text((lx + 8, ly + 28), 'souligné', font=f, fill='black')
d.line([lx + 8, ly + 42, lx + 8 + f.getlength('souligné'), ly + 42], fill='black')
d.text((lx + 8 + f.getlength('souligné'), ly + 28), ' : clé primaire', font=f, fill='black')
d.text((lx + 8, ly + 46), '#colonne : clé étrangère (flèche vers la table référencée)', font=f, fill='black')
d.text((lx + 8, ly + 64), 'italique', font=fi, fill='black')
d.text((lx + 8 + fi.getlength('italique'), ly + 64), ' : valeur unique', font=f, fill='black')
im.save(OUT + '.png')
