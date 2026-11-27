"""General chapters of the guide (pages 2–6) → markdown reference pages."""
import json, os, re, sys
import pdfplumber
sys.path.insert(0, os.path.dirname(__file__))
from parse import SRC

def segments(page):
    ws = [w for w in page.extract_words(extra_attrs=['fontname', 'size']) if 45 < w['top'] < page.height - 40]
    ws.sort(key=lambda w: (round(w['top']), w['x0']))
    rows = []
    for w in ws:
        if rows and abs(w['top'] - rows[-1][0]['top']) <= 2.5: rows[-1].append(w)
        else: rows.append([w])
    out = []
    for r in rows:
        r.sort(key=lambda w: w['x0'])
        segs, seg = [], [r[0]]
        for w in r[1:]:
            if w['x0'] - seg[-1]['x1'] > 14: segs.append(seg); seg = [w]
            else: seg.append(w)
        segs.append(seg)
        out.append({'top': r[0]['top'], 'words': r, 'segs': [{'x': s[0]['x0'], 'bold': 'Bold' in s[0]['fontname'], 'size': round(max(w['size'] for w in s), 1), 'text': ' '.join(w['text'] for w in s)} for s in segs]})
    return out

def join(a, b):
    if not a: return b
    return a + b if a.endswith('-') else a + ' ' + b

def build():
    pdf = pdfplumber.open(SRC)
    lines = []
    for pn in range(1, 6):
        lines += segments(pdf.pages[pn])
    pages, md, para, table, item, last_top = [], None, '', None, None, 0

    def flush():
        nonlocal para, table, item
        if md is None: return
        if para: md['body'].append(para); para = ''
        if item: md['body'].append(item); item = None
        if table:
            cols = len(table['cols'])
            rows = [r for r in table['rows'] if any(c.strip() for c in r)]
            out = ['| ' + ' | '.join(table['head']) + ' |', '|' + '---|' * cols]
            out += ['| ' + ' | '.join(c.strip() for c in r) + ' |' for r in rows]
            md['body'].append('\n'.join(out)); table = None

    for ln in lines:
        segs = ln['segs']; first = segs[0]; gap = ln['top'] - last_top; last_top = ln['top']
        text = ' '.join(s['text'] for s in segs)
        if first['bold'] and first['size'] >= 20:
            flush(); md = {'title': text, 'body': []}; pages.append(md); continue
        if md is None: continue
        if first['bold'] and first['size'] >= 12.5:
            flush(); md['body'].append('## ' + text.rstrip(':')); continue
        if len(segs) >= 2 and all(s['bold'] and s['size'] <= 10.5 for s in segs) and not table:
            flush(); table = {'cols': [s['x'] - 6 for s in segs], 'head': [s['text'] for s in segs], 'rows': []}; continue
        if table and first['size'] <= 10.5:
            if not table['rows'] and all(s['bold'] for s in segs) and gap < 17:
                for s in segs:
                    i = max([0] + [k for k, x in enumerate(table['cols']) if s['x'] >= x - 4])
                    table['head'][i] = join(table['head'][i], s['text'])
                continue
            if gap > 17 or not table['rows']: table['rows'].append([''] * len(table['cols']))
            cells = [''] * len(table['cols'])
            for w in ln['words']:
                i = max([0] + [k for k, x in enumerate(table['cols']) if w['x0'] >= x - 4])
                cells[i] = (cells[i] + ' ' + w['text']).strip()
            for i, c in enumerate(cells):
                if c: table['rows'][-1][i] = join(table['rows'][-1][i], c)
            continue
        if table: flush()
        if text.startswith(('•', '□')):
            if para: md['body'].append(para); para = ''
            if item: md['body'].append(item)
            mark = '- ' if text.startswith('•') else '- [ ] '
            item = mark + text[1:].strip(); continue
        if item and first['x'] >= 80:
            item = join(item, text); continue
        if item: md['body'].append(item); item = None
        if gap > 20 and para: md['body'].append(para); para = ''
        para = join(para, text)
    flush()
    result = []
    for p in pages:
        body, out = p['body'], []
        for b in body:
            if out and b.startswith('- ') and out[-1].startswith('- '): out[-1] += '\n' + b
            else: out.append(b)
        result.append({'title': p['title'], 'markdown': '\n\n'.join(out)})
    return result

if __name__ == '__main__':
    for p in build():
        print('#####', p['title']); print(p['markdown']); print()
