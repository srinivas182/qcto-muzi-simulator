"""Build api/database/data/guide/workplace-practical-expectations.json from the Services SETA guide PDF.

    GUIDE_PDF=/path/to/Workplace_Practical_Expectations_Guide.pdf python3 tools/guide/build.py

Requires: pip install pdfplumber. The PDF itself is not stored in the repository.
"""
import hashlib, json, os, re, sys
sys.path.insert(0, os.path.dirname(__file__))
from parse import parse, SRC
from general import build as build_pages

OUT = os.path.join(os.path.dirname(__file__), '../../api/database/data/guide/workplace-practical-expectations.json')
PREFIX = {
    'Technical Support': ('technical-support', 'TS'), 'Systems Support': ('systems-support', 'SS'),
    'Systems Development': ('systems-development', 'SD'),
    'Business Process Outsourcing and Contact Centre': ('business-process-outsourcing', 'BPO'),
    'Robotics': ('robotics', 'ROB'), 'Data Analytics': ('data-analytics', 'DA'), 'Cellphone Repair': ('cellphone-repair', 'CPR'),
    'Laptop and PC Repair': ('laptop-pc-repair', 'LPR'), 'Drone Piloting and Repairs': ('drone-piloting-repairs', 'DRN'),
    'Technopreneur': ('technopreneur', 'TPR'), 'Cybersecurity Defender': ('cybersecurity-defender', 'CYD'),
    'Intermediate End User Computing': ('end-user-computing-intermediate', 'EUC-I'),
    'Advanced End User Computing': ('end-user-computing-advanced', 'EUC-A'),
}

def qualification(text):
    """One reference line → one or more structured references. Lines without a qualification become notes."""
    multi = re.findall(r'([A-Z][A-Za-z ]+?) \(SAQA ID (\d+), NQF (\d)\)', text)
    if len(multi) > 1:
        return [{'kind': 'related', 'saqa_id': sid, 'title': 'Occupational Certificate: ' + re.sub(r'^.*\binclude ', '', name.strip()), 'nqf_level': int(nqf),
                 'credits': None, 'source_text': text} for name, sid, nqf in multi]
    saqa = re.search(r'SAQA ID (\d{4,6})', text)
    nqf = re.search(r'NQF (?:level )?(\d)', text, re.I)
    credits = re.search(r'(\d{2,3}) credits', text)
    title = re.search(r'((?:National )?Occupational Certificate: .+?|Further Education and Training Certificate: .+?|'
                      r'National Certificate: [^(]+?|[A-Z]+ SETA [\w ]+Skills Programme: [^(]+?)(?=, NQF| \(NQF| \(short|, \d|\.$|$)', text)
    lower = text.lower()
    if title and ('legacy' in lower or 'past its registration end' in lower): kind = 'legacy'
    elif title and 'skills programme' in title.group(1).lower(): kind = 'skills_programme'
    elif title: kind = 'occupational'
    elif saqa: kind = 'related'
    else: kind = 'note'
    return [{'kind': kind, 'saqa_id': saqa.group(1) if saqa else None, 'title': re.sub(r',?\s*SAQA ID.*$', '', title.group(1)).strip().rstrip('.') if title else None,
             'nqf_level': int(nqf.group(1)) if nqf and kind != 'note' else None, 'credits': int(credits.group(1)) if credits else None,
             'source_text': text}]

def evidence_type(text):
    t = text.lower()
    if 'photo' in t: return 'photo'
    if 'observation' in t or 'observed' in t: return 'observation'
    if 'sign-off' in t or 'signed' in t: return 'sign_off'
    if 'feedback' in t or 'satisfaction' in t or 'review' in t: return 'feedback'
    if any(k in t for k in ('log', 'register', 'record', 'extract', 'report', 'summary')): return 'record'
    return 'document'

def main():
    programmes = []
    for i, p in enumerate(parse()):
        code, prefix = PREFIX[p['title']]
        prog = {
            'code': code, 'prefix': prefix, 'position': i + 1, 'title': p['title'], 'summary': p['summary'],
            'purpose': p['purpose'],
            'occupations': [o.strip().rstrip('.') for o in re.split(r',\s*(?![^()]*\))', p['occupations']) if o.strip()],
            'qualifications': [q for r in p['references'] for q in qualification(r)],
            'to_confirm': list(p['to_confirm']), 'workplace_requirements': list(p['workplace_requirements']), 'tools': list(p['tools']),
            'duty_areas': [{
                'number': d['number'], 'title': d['title'],
                'outcomes': [{'code': f"{prefix}-{d['number']}.{k + 1}", 'statement': a} for k, a in enumerate(d['activities'])],
                'evidence': [{'description': e, 'type': evidence_type(e)} for e in d['evidence']],
            } for d in p['duty_areas']],
            'source_page': p['source_page'],
        }
        prog['checksum'] = hashlib.sha256(json.dumps(prog, sort_keys=True, ensure_ascii=False).encode()).hexdigest()[:16]
        programmes.append(prog)
    pages = [{'slug': re.sub(r'[^a-z0-9]+', '-', pg['title'].lower()).strip('-'), 'position': i + 1, **pg} for i, pg in enumerate(build_pages())]
    data = {
        'source': {'document': 'Workplace Practical Expectations: Mentor Guide', 'publisher': 'Services SETA (QCTO and SAQA aligned)',
                   'edition': '2026', 'file': os.path.basename(SRC)},
        'family': 'Services SETA: ICT and business services',
        'programmes': programmes, 'reference_pages': pages,
    }
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2, ensure_ascii=False); f.write('\n')
    print(f'{len(programmes)} programmes, {sum(len(p["duty_areas"]) for p in programmes)} duty areas, '
          f'{sum(len(d["outcomes"]) for p in programmes for d in p["duty_areas"])} outcomes, '
          f'{sum(len(d["evidence"]) for p in programmes for d in p["duty_areas"])} evidence items, {len(pages)} reference pages')

if __name__ == '__main__':
    main()
