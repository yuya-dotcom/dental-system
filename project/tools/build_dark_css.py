"""
tools/build_dark_css.py — generates assets/css/es-dark.css (night mode for staff pages).

The staff stylesheets hard-code their light colours (white cards, pastel chips,
navy text). This script reads them and writes, for every rule that sets a light
background / border or a dark text colour, the same rule again under
`html.app-skin-dark` with the matching colour from one dark palette:

  white / near-white surfaces -> the dark surface steps (--esd-*)
  pastel tints (chips, icons)  -> a translucent version of the same hue
  light borders                -> the dark line colour
  dark text                    -> light text (neutral) or a lighter tint of the hue
  colour custom properties     -> mapped the same way (border/line names -> line)

Rules that already have a hand-written `html.app-skin-dark` version are skipped,
so a file's own dark design always wins. Re-run after changing a staff CSS file:

    python tools/build_dark_css.py        (from the project/ folder)

The shell (header, sidebar, cards, forms, tables, modals) is styled by hand in
assets/css/es-theme.css, which is loaded after this file.
"""
import colorsys
import os
import re

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'assets', 'css')
FILES = ['custom.css', 'es-records.css', 'appointments-records.css', 'treatments.css', 'patient-record.css',
         'odontogram.css', 'es-inventory.css', 'service-records.css', 'apt-schedule-picker.css',
         'custom-select.css', 'es-swal.css', 'es-admin.css', 'analytics.css', 'es-actions.css', 'admin.css',
         'es-notifications.css', 'es-settings.css', 'es-sidebar.css']
# Staff pages with their own <style> block (portal / print pages stay light)
PAGES = ['appointments-records.php', 'appointments-schedule.php', 'audit-records.php', 'dashboard.php',
         'feedback-records.php', 'notification-records.php', 'patient-records.php', 'treatments-active.php']
PAGE_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
OUT = os.path.join(ROOT, 'es-dark.css')
SKIP_SEL = re.compile(r'admin-login|\.al-|portal|print|@page')   # login page / portal / print are light only

# ── Dark palette (keep in step with --esd-* in es-theme.css) ──────────────
SURFACE, RAISED, HOVER, LINE, LINE_SOFT = '#121b2d', '#17223a', '#1d2a45', '#26344d', '#1d283d'
INK, TEXT, MUTED = '#e7ecf4', '#c5cedb', '#94a0b4'

HEX = re.compile(r'#([0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b')
NAMED = {'white': 'ffffff', '#fff': 'ffffff'}


def parse_hex(h):
    h = h.lstrip('#')
    if len(h) in (3, 4):
        h = ''.join(c * 2 for c in h)
    return tuple(int(h[i:i + 2], 16) / 255 for i in (0, 2, 4)), (int(h[6:8], 16) / 255 if len(h) == 8 else 1)


def lum(rgb):
    return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]


def to_hex(rgb):
    return '#' + ''.join('%02x' % max(0, min(255, round(c * 255))) for c in rgb)


def tint(rgb, alpha):
    """Translucent, mid-lightness version of a colour's hue (pastel chips on dark)."""
    h, l, s = colorsys.rgb_to_hls(*rgb)
    r, g, b = colorsys.hls_to_rgb(h, 0.58, max(min(s, 0.85), 0.45))
    return 'rgba(%d, %d, %d, %.2f)' % (r * 255, g * 255, b * 255, alpha)


def light_bg(rgb, a):
    h, l, s = colorsys.rgb_to_hls(*rgb)
    if a < 1:
        return None
    if l < 0.84:
        return None
    if s < 0.45 or l > 0.985:            # neutral white / grey steps
        if l > 0.985:
            return SURFACE
        if l > 0.955:
            return RAISED
        return HOVER
    return tint(rgb, 0.15)               # pastel tone


def light_border(rgb, a):
    h, l, s = colorsys.rgb_to_hls(*rgb)
    if l < 0.78:
        return None
    if s < 0.35 or l > 0.97:
        return LINE if l < 0.94 else LINE_SOFT
    return tint(rgb, 0.38)


def dark_text(rgb, a):
    h, l, s = colorsys.rgb_to_hls(*rgb)
    if l >= 0.52:
        return None
    if s < 0.3 or l < 0.16 or (l < 0.3 and s < 0.6):   # neutral ink / slate / navy
        return INK if l < 0.22 else (TEXT if l < 0.36 else MUTED)
    r, g, b = colorsys.hls_to_rgb(h, 0.72, min(s, 0.8))
    return to_hex((r, g, b))


def map_value(val, fn):
    """Replace every colour in a declaration value; None when nothing changed."""
    changed = [False]

    def rep(m):
        token = m.group(0)
        rgb, a = parse_hex(NAMED.get(token.lower(), token))
        new = fn(rgb, a)
        if new:
            changed[0] = True
            return new
        return token

    v = re.sub(r'#[0-9a-fA-F]{3,8}\b|\bwhite\b', rep, val)
    return v if changed[0] else None


def map_decl(prop, val):
    p = prop.lower()
    if 'gradient' in val or 'url(' in val:
        return None
    if p.startswith('--'):
        name = p
        if re.search(r'border|line|hair|row\b|divider', name):
            return map_value(val, light_border)
        out = map_value(val, light_bg)
        if out:
            return out
        if re.search(r'text|ink|navy|muted|primary|blue|green|red|amber|accent|dark|fg|color', name):
            return map_value(val, dark_text)
        return None
    if p in ('background', 'background-color'):
        return map_value(val, light_bg)
    if p.startswith('border') or p in ('outline', 'outline-color'):
        if 'radius' in p or 'width' in p or 'style' in p:
            return None
        return map_value(val, light_border)
    if p == 'color':
        return map_value(val, dark_text)
    if p in ('fill', 'stroke'):
        return None
    return None


def split_selectors(sel):
    parts, depth, cur = [], 0, ''
    for ch in sel:
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        if ch == ',' and depth == 0:
            parts.append(cur.strip())
            cur = ''
        else:
            cur += ch
    if cur.strip():
        parts.append(cur.strip())
    return parts


def darken_selector(s):
    if s.startswith(':root'):
        return 'html.app-skin-dark' + s[5:]
    if re.match(r'html[.\[:]', s):
        return 'html.app-skin-dark' + s[4:]
    if s == 'html' or s.startswith('html '):
        return 'html.app-skin-dark' + s[4:]
    return 'html.app-skin-dark ' + s


def parse(css):
    """Tiny CSS parser → list of ('rule', sel, body) / ('at', prelude, children)."""
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    i, n, out = 0, len(css), []

    def block(i):
        items = []
        while i < n:
            j = i
            while j < n and css[j] not in '{};':
                j += 1
            if j >= n:
                break
            if css[j] == '}':
                return items, j + 1
            if css[j] == ';':          # @import / @charset
                i = j + 1
                continue
            prelude = css[i:j].strip()
            if prelude.startswith('@media') or prelude.startswith('@supports'):
                kids, i = block(j + 1)
                items.append(('at', prelude, kids))
            elif prelude.startswith('@'):
                depth, k = 1, j + 1
                while k < n and depth:
                    depth += {'{': 1, '}': -1}.get(css[k], 0)
                    k += 1
                i = k
            else:
                k = css.index('}', j)
                items.append(('rule', prelude, css[j + 1:k]))
                i = k + 1
        return items, i

    out, _ = block(0)
    return out


DARK_VARS = set()   # custom properties a file already sets for dark mode


def handwritten(items, acc):
    for it in items:
        if it[0] == 'at':
            handwritten(it[2], acc)
        elif 'app-skin-dark' in it[1]:
            DARK_VARS.update(re.findall(r'(--[\w-]+)\s*:', it[2]))
            for s in split_selectors(it[1]):
                acc.add(re.sub(r'\s+', ' ', s.replace('html.app-skin-dark', 'html').replace('html ', '', 1).strip()))
    return acc


def emit(items, done, indent=''):
    lines = []
    for it in items:
        if it[0] == 'at':
            inner = emit(it[2], done, indent + '  ')
            if inner:
                lines.append(f'{indent}{it[1]} {{')
                lines += inner
                lines.append(f'{indent}}}')
            continue
        _, sel, body = it
        if 'app-skin-dark' in sel or 'app-navigation-dark' in sel or SKIP_SEL.search(sel):
            continue
        sels = [s for s in split_selectors(sel) if re.sub(r'\s+', ' ', s) not in done]
        if not sels:
            continue
        decls = []
        pairs = [tuple(x.strip() for x in d.split(':', 1)) for d in body.split(';') if ':' in d]
        # A solid mid-tone background that stays as it is (a yellow badge, a blue
        # button) keeps its text colour too, or the text would lose contrast.
        keeps_bg = any(p.lower() in ('background', 'background-color') and HEX.search(v)
                       and not map_decl(p, v.replace('!important', '').strip()) for p, v in pairs)
        for prop, val in pairs:
            imp = ''
            if val.endswith('!important'):
                val, imp = val[:-10].strip(), ' !important'
            if prop.startswith('--') and prop in DARK_VARS:
                continue
            if prop.lower() == 'color' and keeps_bg:
                continue
            new = map_decl(prop, val)
            if new:
                decls.append(f'{prop}: {new}{imp}')
        if decls:
            lines.append(f'{indent}{", ".join(darken_selector(s) for s in sels)} {{ {"; ".join(decls)}; }}')
    return lines


def main():
    out = ['/* =============================================================',
           '   assets/css/es-dark.css — GENERATED by tools/build_dark_css.py.',
           '   Do not edit by hand: change the source stylesheet (or es-theme.css)',
           '   and re-run the script. Night mode for colours the staff stylesheets',
           '   hard-code; the shell itself is styled in es-theme.css.',
           '   ============================================================= */']
    sources = [(f, os.path.join(ROOT, f), False) for f in FILES] + \
              [(p, os.path.join(PAGE_ROOT, p), True) for p in PAGES]
    for f, path, is_page in sources:
        if not os.path.exists(path):
            continue
        with open(path, encoding='utf8') as fh:
            text = fh.read()
        if is_page:
            # Real <style> blocks only — not ones built inside a JS string (print windows)
            text = '\n'.join(b for b in re.findall(r'<style[^>]*>(.*?)</style>', text, flags=re.S)
                             if not re.search(r"['\"`]\s*\+|\+\s*['\"`]|\$\{", b))
        items = parse(text)
        DARK_VARS.clear()
        lines = emit(items, handwritten(items, set()))
        if lines:
            out.append(f'\n/* ── {f} ── */')
            out += lines
    with open(OUT, 'w', encoding='utf8', newline='\n') as fh:
        fh.write('\n'.join(out) + '\n')
    print('wrote', OUT, sum(1 for l in out if '{' in l and not l.strip().startswith('@')), 'rules')


if __name__ == '__main__':
    main()
