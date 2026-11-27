# Guide import tool

Turns the Services SETA *Workplace Practical Expectations: Mentor Guide* (PDF) into
`api/database/data/guide/workplace-practical-expectations.json`, which `php artisan programmes:import` loads.

```bash
pip install pdfplumber
GUIDE_PDF=/path/to/Workplace_Practical_Expectations_Guide.pdf python3 tools/guide/build.py
```

- `parse.py` reads the 13 programme profiles (title, summary, reference points, "to confirm before use",
  occupations, workplace requirements, purpose, duty areas with activities and evidence, tools).
- `general.py` turns the general chapters into markdown reference pages.
- Outcome codes are `<PREFIX>-<duty area>.<activity>` (e.g. `TS-1.2`). Evidence types are a first guess
  (photo, observation, sign-off, feedback, record, document) for HQ to confirm in Programme Studio.

The generated JSON is reviewed in the pull request; `api/tests/Feature/Programmes` checks its counts.
