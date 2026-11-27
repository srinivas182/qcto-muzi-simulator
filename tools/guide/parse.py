"""Turn the Workplace Practical Expectations guide (PDF) into structured programme JSON."""
import json, os, re, sys
import pdfplumber

SRC = os.environ.get('GUIDE_PDF', 'Workplace_Practical_Expectations_Guide.pdf')
TITLES = ['Technical Support', 'Systems Support', 'Systems Development', 'Business Process Outsourcing and Contact Centre',
          'Robotics', 'Data Analytics', 'Cellphone Repair', 'Laptop and PC Repair', 'Drone Piloting and Repairs',
          'Technopreneur', 'Cybersecurity Defender', 'Intermediate End User Computing', 'Advanced End User Computing']
KV_LABELS = {'Reference points': 'references', 'To confirm before use': 'to_confirm', 'Typical occupations': 'occupations',
             'What the workplace must provide': 'workplace_requirements'}

def rows_of(page):
    words = page.extract_words(extra_attrs=['fontname', 'size'])
    words = [w for w in words if w['top'] > 45 and not re.fullmatch(r'Page|\d+', w['text']) or (w['top'] > 45 and w['top'] < page.height - 40)]
    words = [w for w in words if w['top'] > 45 and w['top'] < page.height - 40]
    words.sort(key=lambda w: (w['top'], w['x0']))
    rows = []
    for w in words:
        if rows and abs(w['top'] - rows[-1]['top']) <= 2.5:
            rows[-1]['words'].append(w)
        else:
            rows.append({'top': w['top'], 'words': [w]})
    return rows

def bold(w): return 'Bold' in w['fontname']

def text(ws): return ' '.join(w['text'] for w in sorted(ws, key=lambda w: w['x0'])).strip()

class Items(list):
    """Bulleted list builder: '•' starts an item, other text continues the last one."""
    def feed(self, ws):
        ws = sorted(ws, key=lambda w: w['x0'])
        buf = []
        for w in ws:
            if w['text'] == '•':
                if buf: self._add(buf, False); buf = []
                self.append('')
            else:
                buf.append(w)
        if buf: self._add(buf, False)
    def _add(self, ws, new):
        t = text(ws)
        if not self or new: self.append(t)
        else: self[-1] = join(self[-1], t)

def join(a, b):
    if not a: return b
    if a.endswith('-') and not a.endswith(' -'):  # hyphenated line break
        return a + b if re.match(r'[a-z]', b) and not re.search(r'(walk|end|e|in|job|first|second|non|anti|pre|re|self|co)-$', a) else a + b
    return a + ' ' + b

def parse():
    pdf = pdfplumber.open(SRC)
    progs, cur, mode, kv_key = [], None, None, None
    cols = (148, 370)
    for pn in range(6, len(pdf.pages)):
        for row in rows_of(pdf.pages[pn]):
            ws = row['words']
            line = text(ws)
            first = min(ws, key=lambda w: w['x0'])
            size = max(w['size'] for w in ws)
            if bold(first) and size >= 15 and line in TITLES:
                cur = {'title': line, 'summary': '', 'references': Items(), 'to_confirm': Items(), 'occupations': '',
                       'workplace_requirements': Items(), 'purpose': '', 'duty_areas': [], 'tools': Items(), 'source_page': pn + 1}
                progs.append(cur); mode = 'summary'; continue
            if cur is None: continue
            if bold(first) and size >= 12 and line.startswith('Purpose of the workplace'):
                mode = 'purpose'; continue
            if bold(first) and size >= 12 and line.startswith('Tools, systems and equipment'):
                mode = 'tools'; continue
            if bold(first) and line.startswith('Duty area'):
                hdr = {w['text']: w['x0'] for w in ws}
                xs = sorted(w['x0'] for w in ws if w['text'] in ('What', 'Evidence'))
                if len(xs) == 2: cols = (xs[0] - 4, xs[1] - 4)
                mode = 'table'; continue
            if mode == 'table' and all(bold(w) for w in ws) and line in ('workplace should see', 'workplace', 'should see'):
                continue
            if mode == 'summary':
                if bold(first):
                    mode = 'kv'
                else:
                    cur['summary'] = join(cur['summary'], line); continue
            if mode == 'kv':
                label = [w for w in ws if w['x0'] < 165 and bold(w)]
                value = [w for w in ws if not (w['x0'] < 165 and bold(w))]
                if label:
                    lt = text(label)
                    match = next((k for k in KV_LABELS if k.startswith(lt) or lt.startswith(k)), None)
                    if match and (kv_key is None or match != kv_key or lt == match.split(' ')[0] + ' ' + match.split(' ')[1] if len(match.split()) > 1 else True):
                        if not (kv_key and KV_LABELS.get(match) == kv_key and not match.startswith(lt)):
                            kv_key = KV_LABELS[match]
                if not value: continue
                if kv_key == 'occupations': cur['occupations'] = join(cur['occupations'], text(value))
                elif kv_key: cur[kv_key].feed(value)
                continue
            if mode == 'purpose':
                cur['purpose'] = join(cur['purpose'], line); continue
            if mode == 'table':
                c1 = [w for w in ws if w['x0'] < cols[0]]
                c2 = [w for w in ws if cols[0] <= w['x0'] < cols[1]]
                c3 = [w for w in ws if w['x0'] >= cols[1]]
                if c1:
                    t1 = text(c1)
                    m = re.match(r'^(\d+)\.\s*(.*)$', t1)
                    if m:
                        cur['duty_areas'].append({'number': int(m.group(1)), 'title': m.group(2), 'activities': Items(), 'evidence': Items()})
                    elif cur['duty_areas']:
                        cur['duty_areas'][-1]['title'] = join(cur['duty_areas'][-1]['title'], t1)
                if cur['duty_areas']:
                    if c2: cur['duty_areas'][-1]['activities'].feed(c2)
                    if c3: cur['duty_areas'][-1]['evidence'].feed(c3)
                continue
            if mode == 'tools':
                cur['tools'].feed(ws)
    return progs

if __name__ == '__main__':
    progs = parse()
    print(json.dumps(progs, indent=1, ensure_ascii=False))
