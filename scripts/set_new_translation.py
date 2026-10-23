"""Sets the "NEW" badge translation in every lang/<locale>.json.

usage: python scripts/set_new_translation.py [project root]
The root defaults to env AVD_I18N_ROOT, else the project this script belongs to.
Files are written in the format of scripts/i18n/I18n.php (LangFile::encode): keys sorted, 4-space indent, unescaped
UTF-8 and slashes, the file's own line endings and a final line break - and only when a value changed.
"""
import os
import sys
import json

BASE_DIR = (sys.argv[1] if len(sys.argv) > 1 else None) or os.environ.get("AVD_I18N_ROOT") \
    or os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG_DIR = os.path.join(BASE_DIR, "lang")
if not os.path.isfile(os.path.join(LANG_DIR, "en.json")):
    sys.exit("Not a project root (no lang/en.json): " + BASE_DIR)
print("root:", BASE_DIR)

NEW_TRANSLATIONS = {
    "en": "NEW",
    "ja": "新着",
    "zh": "最新",
    "th": "ใหม่",
    "vi": "MỚI",
    "ko": "신규",
    "fr": "NOUVEAU",
    "de": "NEU",
    "es": "NUEVO",
    "pt": "NOVO",
    "ru": "НОВОЕ",
    "ar": "جديد",
    "tr": "YENİ",
    "hi": "नया",
    "id": "BARU",
    "ms": "BARU",
    "tl": "BAGO",
    "fil": "BAGO"
}


def encode(data, eol):
    """LangFile::encode: byte-order sorted keys (= code point order for UTF-8), PHP JSON_PRETTY_PRINT layout."""
    if not data:
        return "{}" + eol
    text = json.dumps({k: data[k] for k in sorted(data)}, indent=4, ensure_ascii=False)
    return text.replace("\n", eol) + eol


changed = []
for lang, val in NEW_TRANSLATIONS.items():
    lfile = os.path.join(LANG_DIR, f"{lang}.json")
    if not os.path.exists(lfile):
        continue
    with open(lfile, 'rb') as f:
        raw = f.read().decode('utf-8-sig')
    eol = "\r\n" if "\r\n" in raw and raw.count("\r\n") * 2 >= raw.count("\n") else "\n"
    d = json.loads(raw)
    if d.get("NEW") == val:
        continue
    d["NEW"] = val
    with open(lfile, 'wb') as f:
        f.write(encode(d, eol).encode('utf-8'))
    changed.append(lang)
print("NEW badge set in:", ", ".join(changed) if changed else "nothing to change")
